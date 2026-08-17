<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus;

use Solvetus\Missivus\Auth\ClientAssertion;
use Solvetus\Missivus\Auth\Credentials;

/**
 * The single place that decides what the effective configuration is.
 *
 * Precedence, highest first:
 *
 *   1. a MISSIVUS_* constant in wp-config.php
 *   2. the missivus_settings option
 *
 * A credential defined as a constant must never be copied into the database, so sanitize()
 * refuses to store a value for any key whose constant is defined — the same rule the Matomo
 * sibling applies to its config-file and environment tiers.
 */
class Settings {

	const OPTION = 'missivus_settings';

	/**
	 * Option key => wp-config.php constant, for every constant-overridable value.
	 *
	 * @var array
	 */
	const CONSTANTS = array(
		'tenant_id'              => 'MISSIVUS_TENANT_ID',
		'client_id'              => 'MISSIVUS_CLIENT_ID',
		'auth_method'            => 'MISSIVUS_AUTH_METHOD',
		'client_secret'          => 'MISSIVUS_CLIENT_SECRET',
		'certificate_path'       => 'MISSIVUS_CERTIFICATE_PATH',
		'certificate_passphrase' => 'MISSIVUS_CERTIFICATE_PASSPHRASE',
		'sender_mailbox'         => 'MISSIVUS_SENDER',
	);

	/**
	 * Every stored key and its default.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'                => false,
			'tenant_id'              => '',
			'client_id'              => '',
			'auth_method'            => Credentials::METHOD_SECRET,
			'client_secret'          => '',
			'certificate_path'       => '',
			'certificate_passphrase' => '',
			'sender_mailbox'         => '',
			'save_to_sent'           => false,
			'fallback_to_wp_mail'    => false,
		);
	}

	/**
	 * The effective value for one key: constant first, then the stored option, then the default.
	 *
	 * @param string $key One of the defaults() keys.
	 * @return mixed
	 */
	public function get( $key ) {
		$constant = $this->get_constant_value( $key );

		if ( null !== $constant ) {
			return $constant;
		}

		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();

		if ( is_array( $stored ) && array_key_exists( $key, $stored ) ) {
			$value = $stored[ $key ];

			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			if ( '' !== $value && null !== $value ) {
				return $value;
			}
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	/**
	 * Whether a wp-config.php constant supplies this key. The settings page shows such a field
	 * read-only, and sanitize() refuses to store a value for it.
	 *
	 * @param string $key One of the defaults() keys.
	 * @return bool
	 */
	public function has_constant( $key ) {
		return null !== $this->get_constant_value( $key );
	}

	/**
	 * Reads the constant tier for one key.
	 *
	 * @param string $key One of the defaults() keys.
	 * @return string|null The trimmed constant value, or null when absent or empty.
	 */
	private function get_constant_value( $key ) {
		if ( ! isset( self::CONSTANTS[ $key ] ) ) {
			return null;
		}

		$constant = self::CONSTANTS[ $key ];

		if ( ! defined( $constant ) ) {
			return null;
		}

		$value = trim( (string) constant( $constant ) );

		return '' === $value ? null : $value;
	}

	/**
	 * The master switch.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && ! empty( $stored['enabled'] );
	}

	/**
	 * Whether a Graph failure should fall through to WordPress's own mailer. Default off:
	 * a failure you can see beats an email that quietly goes out some other way.
	 *
	 * @return bool
	 */
	public function should_fallback() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && ! empty( $stored['fallback_to_wp_mail'] );
	}

	/**
	 * Whether Graph should keep a copy in the shared mailbox's Sent Items.
	 *
	 * @return bool
	 */
	public function should_save_to_sent() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && ! empty( $stored['save_to_sent'] );
	}

	/**
	 * The shared mailbox every email is sent as.
	 *
	 * @return string
	 */
	public function get_sender_mailbox() {
		return (string) $this->get( 'sender_mailbox' );
	}

	/**
	 * The Graph origin. Constant-only override, for sovereign clouds and the test suite;
	 * Endpoint::normalise() in the transport refuses anything but a bare https origin.
	 *
	 * @return string
	 */
	public function get_graph_base_url() {
		if ( defined( 'MISSIVUS_GRAPH_BASE_URL' ) && '' !== trim( (string) MISSIVUS_GRAPH_BASE_URL ) ) {
			return trim( (string) MISSIVUS_GRAPH_BASE_URL );
		}

		return 'https://graph.microsoft.com';
	}

	/**
	 * The Entra login origin. Constant-only override, same rules as the Graph one.
	 *
	 * @return string
	 */
	public function get_login_base_url() {
		if ( defined( 'MISSIVUS_LOGIN_BASE_URL' ) && '' !== trim( (string) MISSIVUS_LOGIN_BASE_URL ) ) {
			return trim( (string) MISSIVUS_LOGIN_BASE_URL );
		}

		return 'https://login.microsoftonline.com';
	}

	/**
	 * Everything needed to obtain a token, assembled with the same precedence rules.
	 *
	 * @return Credentials
	 */
	public function get_credentials() {
		$method = (string) $this->get( 'auth_method' );

		$credentials = new Credentials(
			(string) $this->get( 'tenant_id' ),
			(string) $this->get( 'client_id' ),
			$method
		);

		if ( Credentials::METHOD_CERTIFICATE === $method ) {
			return $credentials->withCertificate(
				(string) $this->get( 'certificate_path' ),
				(string) $this->get( 'certificate_passphrase' ),
				$this->get_certificate_algorithm()
			);
		}

		return $credentials->withClientSecret( (string) $this->get( 'client_secret' ) );
	}

	/**
	 * PS256 is what Microsoft's current certificate-credentials reference specifies. RS256
	 * exists only as a wp-config.php escape hatch for a tenant that rejects the PSS assertion;
	 * there is deliberately no UI for it.
	 *
	 * @return string
	 */
	public function get_certificate_algorithm() {
		$algorithm = defined( 'MISSIVUS_CERTIFICATE_ALGORITHM' )
			? strtoupper( (string) MISSIVUS_CERTIFICATE_ALGORITHM )
			: '';

		return ClientAssertion::ALG_RS256 === $algorithm
			? ClientAssertion::ALG_RS256
			: ClientAssertion::ALG_PS256;
	}

	/**
	 * Whether the saved configuration can attempt a send at all.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' === $this->get_configuration_problem();
	}

	/**
	 * A human-readable reason the plugin cannot send, for the settings page and the logs.
	 *
	 * @return string Empty when everything is in place.
	 */
	public function get_configuration_problem() {
		if ( ! $this->is_enabled() ) {
			return __( 'Missivus is switched off in the settings', 'missivus' );
		}

		if ( '' === $this->get_sender_mailbox() ) {
			return __( 'Missivus is not configured: missing sender mailbox', 'missivus' );
		}

		try {
			$this->get_credentials()->validate();
		} catch ( \Exception $e ) {
			return $e->getMessage();
		}

		return '';
	}

	/**
	 * Sanitises a settings-page submission into the stored option.
	 *
	 * Invalid values keep whatever was stored before, with a settings error explaining why. An
	 * empty secret field keeps the stored secret (the field is write-only and never redisplays).
	 * A key supplied by a wp-config.php constant is never written — the posted value, whatever
	 * it was, is discarded in favour of what the database already held.
	 *
	 * @param mixed $input The raw option value from the Settings API.
	 * @return array The value to store.
	 */
	public function sanitize( $input ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? array_merge( self::defaults(), $stored ) : self::defaults();
		$input  = is_array( $input ) ? $input : array();
		$clean  = $stored;

		foreach ( array( 'enabled', 'save_to_sent', 'fallback_to_wp_mail' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$clean = $this->sanitize_identifier( $clean, $stored, $input, 'tenant_id', true );
		$clean = $this->sanitize_identifier( $clean, $stored, $input, 'client_id', false );

		if ( ! $this->has_constant( 'auth_method' ) && isset( $input['auth_method'] ) ) {
			$clean['auth_method'] = Credentials::METHOD_CERTIFICATE === $input['auth_method']
				? Credentials::METHOD_CERTIFICATE
				: Credentials::METHOD_SECRET;
		}

		$clean = $this->sanitize_secret( $clean, $stored, $input, 'client_secret', __( 'Client secret', 'missivus' ) );
		$clean = $this->sanitize_secret( $clean, $stored, $input, 'certificate_passphrase', __( 'Certificate passphrase', 'missivus' ) );

		if ( ! $this->has_constant( 'certificate_path' ) && isset( $input['certificate_path'] ) ) {
			$path = sanitize_text_field( $input['certificate_path'] );

			if ( '' === $path ) {
				$clean['certificate_path'] = '';
			} elseif ( false !== strpos( $path, "\0" ) || ! path_is_absolute( $path ) ) {
				add_settings_error(
					self::OPTION,
					'missivus_certificate_path',
					__( 'The certificate path must be an absolute filesystem path.', 'missivus' )
				);
			} else {
				$clean['certificate_path'] = $path;
			}
		}

		if ( ! $this->has_constant( 'sender_mailbox' ) && isset( $input['sender_mailbox'] ) ) {
			$mailbox = sanitize_email( $input['sender_mailbox'] );

			if ( '' === trim( (string) $input['sender_mailbox'] ) ) {
				$clean['sender_mailbox'] = '';
			} elseif ( ! is_email( $mailbox ) ) {
				add_settings_error(
					self::OPTION,
					'missivus_sender_mailbox',
					__( 'The sender mailbox must be an email address.', 'missivus' )
				);
			} else {
				$clean['sender_mailbox'] = $mailbox;
			}
		}

		return $clean;
	}

	/**
	 * Sanitises the tenant or client ID: a GUID, or (for the tenant) a DNS domain such as
	 * contoso.onmicrosoft.com. Emptiness is never an error — the master switch is off by
	 * default precisely so a half-filled settings page is a legitimate state.
	 *
	 * @param array  $clean        The option being assembled.
	 * @param array  $stored       The previously stored option.
	 * @param array  $input        The raw submission.
	 * @param string $key          'tenant_id' or 'client_id'.
	 * @param bool   $allow_domain Whether a DNS domain is acceptable as well as a GUID.
	 * @return array The updated $clean.
	 */
	private function sanitize_identifier( array $clean, array $stored, array $input, $key, $allow_domain ) {
		if ( $this->has_constant( $key ) || ! isset( $input[ $key ] ) ) {
			return $clean;
		}

		$value = sanitize_text_field( $input[ $key ] );

		if ( '' === $value ) {
			$clean[ $key ] = '';

			return $clean;
		}

		$is_guid   = (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
		$is_domain = $allow_domain && (bool) preg_match( '/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/i', $value );

		if ( ! $is_guid && ! $is_domain ) {
			add_settings_error(
				self::OPTION,
				'missivus_' . $key,
				'tenant_id' === $key
					? __( 'The Directory (tenant) ID must be a GUID, or a domain like contoso.onmicrosoft.com.', 'missivus' )
					: __( 'The Application (client) ID must be a GUID.', 'missivus' )
			);

			return $clean;
		}

		$clean[ $key ] = $value;

		return $clean;
	}

	/**
	 * Sanitises a write-only secret field. Empty keeps the stored value; whitespace or control
	 * characters are always a copy-paste accident and are rejected on entry, because nothing is
	 * allowed to print the value back for the operator to inspect.
	 *
	 * @param array  $clean  The option being assembled.
	 * @param array  $stored The previously stored option.
	 * @param array  $input  The raw submission.
	 * @param string $key    'client_secret' or 'certificate_passphrase'.
	 * @param string $label  Translated field label, for the error message.
	 * @return array The updated $clean.
	 */
	private function sanitize_secret( array $clean, array $stored, array $input, $key, $label ) {
		if ( $this->has_constant( $key ) || ! isset( $input[ $key ] ) ) {
			return $clean;
		}

		$value = (string) $input[ $key ];

		if ( '' === $value ) {
			return $clean;
		}

		if ( strlen( $value ) > 1024 || preg_match( '/[\s\x00-\x1f\x7f]/', $value ) ) {
			add_settings_error(
				self::OPTION,
				'missivus_' . $key,
				sprintf(
					/* translators: %s: field label, e.g. "Client secret". */
					__( '%s not saved: it contains whitespace or control characters, which is always a copy-paste accident.', 'missivus' ),
					$label
				)
			);

			return $clean;
		}

		$clean[ $key ] = $value;

		return $clean;
	}
}
