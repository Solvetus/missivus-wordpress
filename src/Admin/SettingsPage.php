<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus\Admin;

use Missivus\Settings;
use Solvetus\Missivus\Auth\Credentials;

/**
 * Settings → Missivus. Capability manage_options on everything; secrets are write-only and
 * never redisplayed; a field supplied by a wp-config.php constant says so instead of showing
 * its value.
 */
class SettingsPage {

	const PAGE_SLUG = 'missivus';

	/**
	 * The effective configuration.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings The effective configuration.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the Settings → Missivus entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Missivus', 'missivus' ),
			__( 'Missivus', 'missivus' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the option with its sanitising callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'missivus',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->settings, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Renders the whole page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Missivus.', 'missivus' ), 403 );
		}

		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? array_merge( Settings::defaults(), $stored ) : Settings::defaults();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Missivus', 'missivus' ); ?></h1>

			<p><?php esc_html_e( 'Send all WordPress email through Microsoft 365 via the Graph API — application permissions and one shared mailbox. No SMTP, no user login.', 'missivus' ); ?></p>

			<?php settings_errors(); ?>
			<?php $this->render_test_result(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'missivus' ); ?>

				<table class="form-table" role="presentation">
					<?php $this->render_checkbox( 'enabled', __( 'Send email through Microsoft Graph', 'missivus' ), __( 'The master switch. Off means WordPress keeps using its own mailer, untouched.', 'missivus' ), $stored ); ?>
					<?php $this->render_text( 'tenant_id', __( 'Directory (tenant) ID', 'missivus' ), __( 'From your app registration’s Overview page in Microsoft Entra.', 'missivus' ), $stored ); ?>
					<?php $this->render_text( 'client_id', __( 'Application (client) ID', 'missivus' ), __( 'From the same Overview page.', 'missivus' ), $stored ); ?>
					<?php $this->render_auth_method( $stored ); ?>
					<?php $this->render_secret( 'client_secret', __( 'Client secret', 'missivus' ), __( 'The secret Value from Certificates & secrets — not the Secret ID. Write-only: it is never shown again here.', 'missivus' ), $stored ); ?>
					<?php $this->render_text( 'certificate_path', __( 'Certificate path', 'missivus' ), __( 'Absolute path to a PEM holding the private key and the certificate. Only used when the method is Certificate.', 'missivus' ), $stored ); ?>
					<?php $this->render_secret( 'certificate_passphrase', __( 'Certificate passphrase', 'missivus' ), __( 'Only if the key is encrypted. Write-only.', 'missivus' ), $stored ); ?>
					<?php $this->render_text( 'sender_mailbox', __( 'Sender mailbox', 'missivus' ), __( 'The shared mailbox every email is sent as. Application-only Graph cannot send as anything else, so this always wins over a From header.', 'missivus' ), $stored ); ?>
					<?php $this->render_checkbox( 'save_to_sent', __( 'Save sent email to the mailbox’s Sent Items', 'missivus' ), __( 'Keeps an audit trail in the shared mailbox.', 'missivus' ), $stored ); ?>
					<?php $this->render_checkbox( 'fallback_to_wp_mail', __( 'Fall back to the WordPress mailer when Graph fails', 'missivus' ), __( 'Off by default, deliberately: a failure you can see beats an email that quietly goes out some other way. Every failure is logged either way.', 'missivus' ), $stored ); ?>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Send a test email', 'missivus' ); ?></h2>
			<?php $this->render_test_form(); ?>

			<hr />

			<h2><?php esc_html_e( 'External services', 'missivus' ); ?></h2>
			<p>
				<?php esc_html_e( 'This plugin talks to two Microsoft endpoints, and only when it sends email: login.microsoftonline.com, to obtain an access token (your Directory ID, Application ID and credential are sent there), and graph.microsoft.com, to send the message (the email’s content, recipients and attachments are sent there). Nothing else leaves your site, and nothing is sent anywhere while the plugin is switched off.', 'missivus' ); ?>
				<a href="https://www.microsoft.com/licensing/terms/product/PrivacyandSecurityTerms/all" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Microsoft’s privacy terms', 'missivus' ); ?></a>
			</p>

			<p>
				<?php esc_html_e( 'Missivus is free and open source. If you would rather not do the Entra and Exchange setup yourself, Solvetus offers paid installation and support.', 'missivus' ); ?>
				<a href="https://solvetus.com" target="_blank" rel="noopener noreferrer">solvetus.com</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The test-email form — its own admin-post form, gated on the saved configuration being
	 * able to send: the test uses what is stored, not what is typed on screen.
	 *
	 * @return void
	 */
	private function render_test_form() {
		$problem = $this->settings->get_configuration_problem();
		$ready   = '' === $problem;

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="missivus_send_test" />
			<?php wp_nonce_field( 'missivus_send_test' ); ?>
			<p>
				<label for="missivus_test_recipient"><?php esc_html_e( 'Recipient', 'missivus' ); ?></label>
				<input type="email" id="missivus_test_recipient" name="missivus_test_recipient" class="regular-text" required="required" <?php disabled( ! $ready ); ?> value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
				<?php submit_button( __( 'Send test email', 'missivus' ), 'secondary', 'submit', false, $ready ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</p>
			<?php if ( ! $ready ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: the reason the test cannot run yet, e.g. "missing sender mailbox". */
						esc_html__( 'The test sends with the saved settings, and they cannot send yet: %s. Fill in the missing part and click Save Changes first.', 'missivus' ),
						esc_html( $problem )
					);
					?>
				</p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Sends one message through Microsoft Graph with the saved settings — the fallback is never used for a test. Failures show the exact error Microsoft returned.', 'missivus' ); ?></p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Shows the outcome of the last test email, if one just ran.
	 *
	 * @return void
	 */
	private function render_test_result() {
		$key    = 'missivus_test_result_' . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) || ! isset( $result['text'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice %s"><p>%s</p></div>',
			empty( $result['ok'] ) ? 'notice-error' : 'notice-success',
			esc_html( $result['text'] )
		);
	}

	/**
	 * One text-ish row. A constant-supplied field is disabled and says where it came from
	 * instead of showing the value.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Translated label.
	 * @param string $description Translated description.
	 * @param array  $stored      The stored option, merged over defaults.
	 * @return void
	 */
	private function render_text( $key, $label, $description, array $stored ) {
		$overridden = $this->settings->has_constant( $key );
		$field_id   = 'missivus_' . $key;

		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $overridden ) : ?>
					<input type="text" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text" disabled="disabled" value="" placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'missivus' ); ?>" />
					<p class="description"><?php $this->describe_constant( $key ); ?></p>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" class="regular-text" value="<?php echo esc_attr( (string) $stored[ $key ] ); ?>" />
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * One write-only secret row: the value is never echoed, whatever its source.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Translated label.
	 * @param string $description Translated description.
	 * @param array  $stored      The stored option, merged over defaults.
	 * @return void
	 */
	private function render_secret( $key, $label, $description, array $stored ) {
		$overridden = $this->settings->has_constant( $key );
		$field_id   = 'missivus_' . $key;

		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $overridden ) : ?>
					<input type="password" id="<?php echo esc_attr( $field_id ); ?>" class="regular-text" disabled="disabled" value="" placeholder="<?php esc_attr_e( 'Defined in wp-config.php', 'missivus' ); ?>" autocomplete="new-password" />
					<p class="description"><?php $this->describe_constant( $key ); ?></p>
				<?php else : ?>
					<input type="password" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" class="regular-text" value="" placeholder="<?php echo esc_attr( '' !== (string) $stored[ $key ] ? __( 'Saved — enter a new value to replace it', 'missivus' ) : '' ); ?>" autocomplete="new-password" />
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The authentication-method radio row.
	 *
	 * @param array $stored The stored option, merged over defaults.
	 * @return void
	 */
	private function render_auth_method( array $stored ) {
		$overridden = $this->settings->has_constant( 'auth_method' );
		$current    = $overridden ? (string) $this->settings->get( 'auth_method' ) : (string) $stored['auth_method'];

		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Authentication method', 'missivus' ); ?></th>
			<td>
				<?php if ( $overridden ) : ?>
					<p><em><?php esc_attr_e( 'Defined in wp-config.php', 'missivus' ); ?></em></p>
					<p class="description"><?php $this->describe_constant( 'auth_method' ); ?></p>
				<?php else : ?>
					<fieldset>
						<label>
							<input type="radio" name="<?php echo esc_attr( Settings::OPTION . '[auth_method]' ); ?>" value="<?php echo esc_attr( Credentials::METHOD_SECRET ); ?>" <?php checked( Credentials::METHOD_SECRET, $current ); ?> />
							<?php esc_html_e( 'Client secret (start here — two clicks in Entra)', 'missivus' ); ?>
						</label>
						<br />
						<label>
							<input type="radio" name="<?php echo esc_attr( Settings::OPTION . '[auth_method]' ); ?>" value="<?php echo esc_attr( Credentials::METHOD_CERTIFICATE ); ?>" <?php checked( Credentials::METHOD_CERTIFICATE, $current ); ?> />
							<?php esc_html_e( 'Certificate (optional hardening — the credential never travels in a request body)', 'missivus' ); ?>
						</label>
					</fieldset>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * One checkbox row.
	 *
	 * @param string $key         Option key.
	 * @param string $label       Translated label.
	 * @param string $description Translated description.
	 * @param array  $stored      The stored option, merged over defaults.
	 * @return void
	 */
	private function render_checkbox( $key, $label, $description, array $stored ) {
		$field_id = 'missivus_' . $key;

		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label for="<?php echo esc_attr( $field_id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( ! empty( $stored[ $key ] ) ); ?> />
					<?php echo esc_html( $description ); ?>
				</label>
			</td>
		</tr>
		<?php
	}

	/**
	 * Names the constant that supplies a field.
	 *
	 * @param string $key Option key.
	 * @return void
	 */
	private function describe_constant( $key ) {
		printf(
			/* translators: %s: a wp-config.php constant name, e.g. MISSIVUS_TENANT_ID. */
			esc_html__( 'This value comes from the %s constant in wp-config.php and cannot be edited here.', 'missivus' ),
			'<code>' . esc_html( Settings::CONSTANTS[ $key ] ) . '</code>'
		);
	}
}
