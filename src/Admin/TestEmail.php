<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus\Admin;

use Missivus\Mailer;
use Missivus\Settings;
use Solvetus\Missivus\Exception\GraphException;

/**
 * The admin-post handler behind the "Send test email" button.
 *
 * Order matters: capability, then nonce, then input — and the send itself never takes the
 * fallback, because a test that quietly succeeded over PHPMailer would tell the operator
 * nothing about their tenant.
 */
class TestEmail {

	/**
	 * The effective configuration.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * The delivery path.
	 *
	 * @var Mailer
	 */
	private $mailer;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings The effective configuration.
	 * @param Mailer   $mailer   The delivery path.
	 */
	public function __construct( Settings $settings, Mailer $mailer ) {
		$this->settings = $settings;
		$this->mailer   = $mailer;
	}

	/**
	 * Handles the POST from the settings page.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send a Missivus test email.', 'missivus' ), 403 );
		}

		check_admin_referer( 'missivus_send_test' );

		$raw = isset( $_POST['missivus_test_recipient'] ) ? wp_unslash( $_POST['missivus_test_recipient'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on the next line; sanitize_email() is the sanitiser.
		$to  = sanitize_email( $raw );

		if ( ! is_email( $to ) ) {
			$this->finish( false, __( 'That is not a valid email address.', 'missivus' ) );

			return;
		}

		try {
			$this->mailer->send_test( $to );

			$this->finish(
				true,
				sprintf(
					/* translators: %s: the recipient email address. */
					__( 'Test email sent to %s. Microsoft accepted it — check the inbox to confirm delivery.', 'missivus' ),
					$to
				)
			);
		} catch ( GraphException $e ) {
			// The message is already redacted by the transport; it is the exact Microsoft
			// error, which is the single most useful thing for diagnosing a broken tenant.
			$this->finish( false, $e->getMessage() );
		}
	}

	/**
	 * Stashes the outcome for the settings page to render, then redirects back.
	 *
	 * @param bool   $ok   Whether the send succeeded.
	 * @param string $text What to show. Already redacted where it came from Graph.
	 * @return void
	 */
	private function finish( $ok, $text ) {
		set_transient(
			'missivus_test_result_' . get_current_user_id(),
			array(
				'ok'   => $ok,
				'text' => $text,
			),
			60
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . SettingsPage::PAGE_SLUG ) );

		exit;
	}
}
