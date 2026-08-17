<?php
/**
 * Minimal WordPress function stubs for the unit suite. No WordPress is loaded — the same
 * pattern as tests/Framework/PiwikStubs.php in the Matomo sibling. Each stub records into
 * WpTestState so tests can assert on what the plugin did.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- test doubles for WordPress core functions; deliberately not WPCS-shaped.

class WpTestState {
	public static $options = array();
	public static $transients = array();
	public static $filters = array();
	public static $actions_fired = array();
	public static $settings_errors = array();
	public static $user_can = true;
	public static $valid_nonce = true;
	public static $redirects = array();
	public static $remote_handler = null;
	public static $current_user_id = 7;

	public static function reset() {
		self::$options = array();
		self::$transients = array();
		self::$filters = array();
		self::$actions_fired = array();
		self::$settings_errors = array();
		self::$user_can = true;
		self::$valid_nonce = true;
		self::$redirects = array();
		self::$remote_handler = null;
		self::$current_user_id = 7;
	}

	public static function firedActions( $tag ) {
		$fired = array();
		foreach ( self::$actions_fired as $entry ) {
			if ( $entry[0] === $tag ) {
				$fired[] = $entry[1];
			}
		}
		return $fired;
	}
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WpDieException extends \Exception {
}

class WpRedirectException extends \Exception {
}

function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, WpTestState::$options ) ? WpTestState::$options[ $name ] : $default_value;
}

function update_option( $name, $value ) {
	WpTestState::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( WpTestState::$options[ $name ] );
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, WpTestState::$transients ) ? WpTestState::$transients[ $key ]['value'] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	WpTestState::$transients[ $key ] = array( 'value' => $value, 'ttl' => $ttl );
	return true;
}

function delete_transient( $key ) {
	unset( WpTestState::$transients[ $key ] );
	return true;
}

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	WpTestState::$filters[ $tag ][] = $callback;
	return true;
}

function apply_filters( $tag, $value ) {
	$args = func_get_args();
	array_shift( $args );

	foreach ( isset( WpTestState::$filters[ $tag ] ) ? WpTestState::$filters[ $tag ] : array() as $callback ) {
		$args[0] = call_user_func_array( $callback, $args );
	}

	return $args[0];
}

function do_action( $tag, ...$args ) {
	WpTestState::$actions_fired[] = array( $tag, $args );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_attr__( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text );
}

function esc_attr_e( $text, $domain = 'default' ) {
	echo esc_attr( $text );
}

function sanitize_text_field( $value ) {
	$value = trim( strip_tags( (string) $value ) );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
}

function sanitize_email( $email ) {
	$email = trim( (string) $email );
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}

function is_email( $email ) {
	return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function path_is_absolute( $path ) {
	return isset( $path[0] ) && '/' === $path[0];
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	WpTestState::$settings_errors[] = compact( 'setting', 'code', 'message', 'type' );
}

function wp_check_filetype( $filename ) {
	$map = array(
		'pdf' => 'application/pdf',
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'txt' => 'text/plain',
		'csv' => 'text/csv',
	);
	$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );

	return array(
		'ext'  => '' !== $ext ? $ext : false,
		'type' => isset( $map[ $ext ] ) ? $map[ $ext ] : false,
	);
}

function current_user_can( $capability ) {
	return WpTestState::$user_can;
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	if ( ! WpTestState::$valid_nonce ) {
		wp_die( 'The link you followed has expired.', 403 );
	}
	return 1;
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_safe_redirect( $location, $status = 302 ) {
	WpTestState::$redirects[] = $location;
	// Throwing here keeps the `exit` after the call from killing the test process.
	throw new WpRedirectException( $location );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function get_current_user_id() {
	return WpTestState::$current_user_id;
}

function wp_die( $message = '', $code = 0 ) {
	throw new WpDieException( is_string( $message ) ? $message : 'wp_die', is_int( $code ) ? $code : 0 );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_request( $url, $args = array() ) {
	$handler = WpTestState::$remote_handler;

	if ( null === $handler ) {
		return new WP_Error( 'http_request_failed', 'No remote handler scripted for this test.' );
	}

	return call_user_func( $handler, $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_headers( $response ) {
	return isset( $response['headers'] ) ? $response['headers'] : array();
}
