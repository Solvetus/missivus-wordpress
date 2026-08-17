<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus\Adapter;

use Solvetus\Missivus\Contract\LoggerInterface;

/**
 * LoggerInterface over error_log(). Messages arrive already redacted — the transport passes
 * everything through Redactor before it gets here, and the plugin's own messages carry no
 * secrets.
 *
 * Errors and warnings are written unconditionally, not gated on WP_DEBUG: "nothing fails
 * silently" is the product promise, and a Graph failure on a production site must land in the
 * server's error log. Info is debug-only chatter.
 */
class Logger implements LoggerInterface {

	/**
	 * An error. Always logged.
	 *
	 * @param string $message Already redacted.
	 * @return void
	 */
	public function error( $message ) {
		$this->write( 'error', $message );
	}

	/**
	 * A warning. Always logged — the forced-From notice is the operator's cue to fix their
	 * From address, and it must not depend on debug mode.
	 *
	 * @param string $message Already redacted.
	 * @return void
	 */
	public function warning( $message ) {
		$this->write( 'warning', $message );
	}

	/**
	 * Debug chatter, only when the site opted into WP_DEBUG.
	 *
	 * @param string $message Already redacted.
	 * @return void
	 */
	public function info( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->write( 'info', $message );
		}
	}

	/**
	 * One line to the PHP error log.
	 *
	 * @param string $level   error, warning or info.
	 * @param string $message Already redacted.
	 * @return void
	 */
	private function write( $level, $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the brief's contract: failures are logged via error_log and never swallowed, whatever the site's logging setup.
		error_log( '[missivus] ' . $level . ': ' . $message );
	}
}
