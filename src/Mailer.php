<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus;

use Missivus\Adapter\HttpClient;
use Missivus\Adapter\Logger;
use Missivus\Adapter\TokenCache;
use Solvetus\Missivus\Auth\TokenProvider;
use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\LoggerInterface;
use Solvetus\Missivus\Contract\TokenCacheInterface;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\GraphMailer;
use Solvetus\Missivus\Message;
use Solvetus\Missivus\Redactor;

/**
 * The pre_wp_mail handler: wp_mail() arguments in, Microsoft Graph send out.
 *
 * Return semantics on the filter: null means "not ours, let WordPress's own mailer run" —
 * which is both the disabled state and the optional fallback; true and false become
 * wp_mail()'s return value. Failures are never silent: every one is logged at error level and
 * announced on the wp_mail_failed action with the Graph detail attached.
 */
class Mailer {

	/**
	 * The effective configuration.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * HTTP seam, swappable in tests.
	 *
	 * @var HttpClientInterface
	 */
	private $http;

	/**
	 * Token cache seam, swappable in tests.
	 *
	 * @var TokenCacheInterface
	 */
	private $cache;

	/**
	 * Logger seam, swappable in tests.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * Constructor. The nulls default to the WordPress adapters; tests inject doubles.
	 *
	 * @param Settings                 $settings The effective configuration.
	 * @param HttpClientInterface|null $http     HTTP client, or null for the wp_remote_request adapter.
	 * @param TokenCacheInterface|null $cache    Token cache, or null for the transient adapter.
	 * @param LoggerInterface|null     $logger   Logger, or null for the error_log adapter.
	 */
	public function __construct(
		Settings $settings,
		?HttpClientInterface $http = null,
		?TokenCacheInterface $cache = null,
		?LoggerInterface $logger = null
	) {
		$this->settings = $settings;
		$this->http     = null === $http ? new HttpClient() : $http;
		$this->cache    = null === $cache ? new TokenCache() : $cache;
		$this->logger   = null === $logger ? new Logger() : $logger;
	}

	/**
	 * The pre_wp_mail callback.
	 *
	 * @param null|bool $short_circuit The filter value; non-null means another plugin already
	 *                                 claimed this email, and it is passed through untouched.
	 * @param array     $atts          wp_mail()'s arguments: to, subject, message, headers, attachments.
	 * @return null|bool Null to let WordPress's own mailer run; otherwise wp_mail()'s return value.
	 */
	public function handle( $short_circuit, $atts ) {
		if ( null !== $short_circuit ) {
			return $short_circuit;
		}

		if ( ! $this->settings->is_enabled() ) {
			return null;
		}

		$atts = is_array( $atts ) ? $atts : array();

		try {
			$this->deliver( $atts );
		} catch ( GraphException $e ) {
			return $this->handle_failure( $e, $atts );
		}

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_succeeded', $atts ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- deliberately firing core's own hook so observers keep working when wp_mail() is short-circuited.

		return true;
	}

	/**
	 * Sends the test email: same delivery path, but never the fallback — a test that quietly
	 * succeeded over PHPMailer would tell the operator nothing about their tenant.
	 *
	 * @param string $to The recipient address, already validated.
	 * @return void
	 * @throws GraphException On any failure.
	 */
	public function send_test( $to ) {
		$this->deliver(
			array(
				'to'          => $to,
				'subject'     => __( 'Missivus test email', 'missivus' ),
				'message'     => __( 'This message was sent by Missivus through the Microsoft Graph API. If you are reading it, the tenant, the app registration and the shared mailbox all work.', 'missivus' ),
				'headers'     => array(),
				'attachments' => array(),
			)
		);
	}

	/**
	 * Builds the transport from the current configuration and sends one message.
	 *
	 * @param array $atts wp_mail()'s arguments.
	 * @return void
	 * @throws GraphException On any failure, configuration included.
	 */
	private function deliver( array $atts ) {
		$problem = $this->settings->get_configuration_problem();

		if ( '' !== $problem ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- log-bound; escaped by whoever renders it.
			throw new GraphException( $problem );
		}

		$credentials = $this->settings->get_credentials();
		$redactor    = new Redactor( $credentials->getSecretLiterals() );

		$tokens = new TokenProvider(
			$credentials,
			$this->http,
			$this->cache,
			$redactor,
			$this->settings->get_login_base_url()
		);

		$mailer = new GraphMailer(
			$tokens,
			$this->http,
			$redactor,
			$this->settings->get_sender_mailbox(),
			$this->settings->should_save_to_sent(),
			$this->settings->get_graph_base_url(),
			$this->logger
		);

		$parser  = new WpMailParser( $this->logger );
		$message = $parser->parse( $atts );

		$this->apply_forced_from( $message );

		$mailer->send( $message );
	}

	/**
	 * App-only Graph sends as /users/{sender} and Exchange rejects a mismatched From, so the
	 * configured mailbox always wins. When the site asked for something else, say so loudly and
	 * keep the requested address reachable as a Reply-To rather than dropping it.
	 *
	 * The wp_mail_from and wp_mail_from_name filters are applied first, as wp_mail() itself
	 * would, so a site that sets its From that way is honoured for the name and warned about
	 * the address. WordPress's fabricated wordpress@site default only exists inside core's own
	 * PHPMailer path and is deliberately not reproduced here — it would warn on every send.
	 *
	 * @param Message $message The parsed message, carrying any requested From.
	 * @return void
	 */
	private function apply_forced_from( Message $message ) {
		/** This filter is documented in wp-includes/pluggable.php */
		$requested = trim( (string) apply_filters( 'wp_mail_from', $message->getFromAddress() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied as wp_mail() itself would.

		/** This filter is documented in wp-includes/pluggable.php */
		$name = trim( (string) apply_filters( 'wp_mail_from_name', $message->getFromName() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied as wp_mail() itself would.

		$sender = $this->settings->get_sender_mailbox();

		$message->setFrom( $sender, $name );

		if ( '' === $requested || 0 === strcasecmp( $requested, $sender ) ) {
			return;
		}

		$this->logger->warning(
			'Missivus: forcing From to ' . $sender . '; wp_mail asked for ' . $requested
			. '. Set the From address to the shared mailbox to silence this.'
		);

		// Only when nothing else claimed Reply-To, so an explicit one is never clobbered.
		if ( ! $message->hasReplyTo() ) {
			$message->addReplyTo( $requested, $name );
		}
	}

	/**
	 * The failure policy: log at error level (never swallowed), announce on wp_mail_failed
	 * with the Graph detail, then either report failure or hand the email to WordPress's own
	 * mailer — but only when the operator explicitly opted into that.
	 *
	 * @param GraphException $e    The failure, message already redacted.
	 * @param array          $atts wp_mail()'s arguments.
	 * @return null|false Null to run the fallback; false to make wp_mail() report failure.
	 */
	private function handle_failure( GraphException $e, array $atts ) {
		$this->logger->error( 'Missivus: sending over Microsoft Graph failed: ' . $e->getMessage() );

		$error_data                 = $atts;
		$error_data['graph_status'] = $e->getHttpStatus();
		$error_data['graph_body']   = $e->getResponseBody();

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new \WP_Error( 'wp_mail_failed', $e->getMessage(), $error_data ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own failure hook; loggers listen for it.

		if ( ! $this->settings->should_fallback() ) {
			return false;
		}

		$this->logger->error( 'Missivus: falling back to the WordPress default mailer' );

		return null;
	}
}
