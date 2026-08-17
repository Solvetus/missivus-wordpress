<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus;

/**
 * The plugin's own PSR-4 loader — the shipped zip carries no Composer autoloader.
 *
 * Two prefixes: the plugin's own `Missivus\` classes under src/, and the vendored,
 * platform-neutral `Solvetus\Missivus\` transport under src/Vendor/. The transport tree is
 * copied unchanged from missivus-matomo, which is why it keeps its own namespace rather than
 * being folded into the plugin's.
 */
class Autoloader {

	/**
	 * Registers the autoloader. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads one class if it belongs to either of our prefixes.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		$prefixes = array(
			'Missivus\\'          => __DIR__ . '/',
			'Solvetus\\Missivus\\' => __DIR__ . '/Vendor/Solvetus/Missivus/',
		);

		foreach ( $prefixes as $prefix => $base_dir ) {
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				continue;
			}

			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_file( $file ) ) {
				require_once $file;
			}

			return;
		}
	}
}
