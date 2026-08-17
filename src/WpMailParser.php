<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus;

use Solvetus\Missivus\Attachment;
use Solvetus\Missivus\Contract\LoggerInterface;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\Message;

/**
 * Everything between "what wp_mail() was given" and a transport-neutral Message.
 *
 * The parsing mirrors wp_mail()'s own (wp-includes/pluggable.php), so behaviour matches what
 * site owners already observe: headers as a string or an array, addresses bare or in
 * `Name <address>` form, comma-separated lists, attachments as paths with an optional filename
 * as the array key.
 */
class WpMailParser {

	/**
	 * Headers wp_mail() understands and Missivus carries over.
	 *
	 * @var string[]
	 */
	private static $known_headers = array( 'from', 'reply-to', 'cc', 'bcc', 'content-type' );

	/**
	 * Where dropped-header notes go.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives a warning for each header Graph cannot carry.
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Builds a Message from the pre_wp_mail $atts array.
	 *
	 * @param array $atts Keys as compact()ed by wp_mail(): to, subject, message, headers, attachments.
	 * @return Message
	 * @throws GraphException When an attachment path cannot be read.
	 */
	public function parse( array $atts ) {
		$message = new Message();

		$message->setSubject( isset( $atts['subject'] ) ? (string) $atts['subject'] : '' );

		foreach ( $this->parse_address_list( isset( $atts['to'] ) ? $atts['to'] : '' ) as $recipient ) {
			$message->addTo( $recipient['address'], $recipient['name'] );
		}

		$headers      = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : '' );
		$content_type = 'text/plain';

		foreach ( $headers as $header ) {
			list( $name, $content ) = $header;

			switch ( $name ) {
				case 'from':
					$from = $this->parse_one_address( $content );

					if ( '' !== $from['address'] || '' !== $from['name'] ) {
						$message->setFrom( $from['address'], $from['name'] );
					}
					break;

				case 'reply-to':
					foreach ( $this->parse_address_list( $content ) as $recipient ) {
						$message->addReplyTo( $recipient['address'], $recipient['name'] );
					}
					break;

				case 'cc':
					foreach ( $this->parse_address_list( $content ) as $recipient ) {
						$message->addCc( $recipient['address'], $recipient['name'] );
					}
					break;

				case 'bcc':
					foreach ( $this->parse_address_list( $content ) as $recipient ) {
						$message->addBcc( $recipient['address'], $recipient['name'] );
					}
					break;

				case 'content-type':
					if ( false !== strpos( $content, ';' ) ) {
						list( $type ) = explode( ';', $content );
						$content_type = strtolower( trim( $type ) );
					} else {
						$content_type = strtolower( trim( $content ) );
					}
					break;
			}
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$content_type = apply_filters( 'wp_mail_content_type', $content_type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, applied as wp_mail() itself would.

		$body = isset( $atts['message'] ) ? (string) $atts['message'] : '';

		if ( 'text/html' === $content_type ) {
			$message->setHtmlBody( $body );
		} else {
			$message->setTextBody( $body );
		}

		foreach ( $this->parse_attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() ) as $attachment ) {
			$message->addAttachment( $attachment );
		}

		return $message;
	}

	/**
	 * Normalises the headers argument into [ [ lowercased-name, content ], ... ], keeping only
	 * the headers Graph can carry. Each dropped one is named in a warning rather than vanishing:
	 * sendMail accepts no arbitrary headers, and pretending otherwise would be worse.
	 *
	 * @param string|array $headers Newline-separated string, or an array of "Name: value" lines.
	 * @return array
	 */
	private function parse_headers( $headers ) {
		if ( empty( $headers ) ) {
			return array();
		}

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}

		$parsed = array();

		foreach ( $headers as $header ) {
			$header = (string) $header;

			if ( false === strpos( $header, ':' ) ) {
				continue;
			}

			list( $name, $content ) = explode( ':', trim( $header ), 2 );

			$name    = strtolower( trim( $name ) );
			$content = trim( $content );

			if ( '' === $content ) {
				continue;
			}

			if ( ! in_array( $name, self::$known_headers, true ) ) {
				$this->logger->warning(
					'Missivus: dropping the "' . $name . '" header — Microsoft Graph sendMail does not carry custom headers'
				);
				continue;
			}

			$parsed[] = array( $name, $content );
		}

		return $parsed;
	}

	/**
	 * Splits a recipients value — string with commas, or array — into address/name pairs.
	 *
	 * @param string|array $value The to/cc/bcc/reply-to content.
	 * @return array Each entry: [ 'address' => string, 'name' => string ].
	 */
	private function parse_address_list( $value ) {
		if ( empty( $value ) ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			$value = explode( ',', (string) $value );
		}

		$recipients = array();

		foreach ( $value as $entry ) {
			$recipient = $this->parse_one_address( (string) $entry );

			if ( '' !== $recipient['address'] ) {
				$recipients[] = $recipient;
			}
		}

		return $recipients;
	}

	/**
	 * Takes one `Name <address>` or bare address, the same pattern wp_mail() itself applies.
	 *
	 * @param string $entry One recipient.
	 * @return array [ 'address' => string, 'name' => string ].
	 */
	private function parse_one_address( $entry ) {
		$entry = trim( $entry );
		$name  = '';

		if ( preg_match( '/(.*)<(.+)>/', $entry, $matches ) ) {
			$name  = trim( trim( $matches[1] ), '\'"' );
			$entry = trim( $matches[2] );
		}

		return array(
			'address' => $entry,
			'name'    => $name,
		);
	}

	/**
	 * Reads each attachment path into a transport Attachment. The bytes live in memory — that
	 * is how the transport works, and how PHPMailer worked before it.
	 *
	 * @param string|array $attachments Newline-separated paths, or an array of paths where a
	 *                                  string key names the attachment (as wp_mail allows).
	 * @return Attachment[]
	 * @throws GraphException When a path cannot be read — the send must fail loudly, exactly as
	 *                        it would have under PHPMailer.
	 */
	private function parse_attachments( $attachments ) {
		if ( empty( $attachments ) ) {
			return array();
		}

		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$parsed = array();

		foreach ( $attachments as $filename => $path ) {
			$path = trim( (string) $path );

			if ( '' === $path ) {
				continue;
			}

			$name = is_string( $filename ) && '' !== $filename ? $filename : basename( $path );

			if ( ! is_readable( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message goes to error_log and is escaped by whoever renders it; entity-escaping a filesystem path here would mangle the log.
				throw new GraphException( 'Missivus: attachment file is not readable at ' . $path );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local attachment path, not a remote URL; WP_Filesystem is for writes.
			$bytes = file_get_contents( $path );

			if ( false === $bytes ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- same as above: log-bound, escaped at output.
				throw new GraphException( 'Missivus: attachment file could not be read at ' . $path );
			}

			$filetype = wp_check_filetype( $name );
			$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

			$parsed[] = new Attachment( $name, $bytes, $mime );
		}

		return $parsed;
	}
}
