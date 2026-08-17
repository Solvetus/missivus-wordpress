<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus;

use Missivus\Admin\SettingsPage;
use Missivus\Admin\TestEmail;

/**
 * Wires the plugin into WordPress. The only class that calls add_action/add_filter.
 */
class Plugin {

	/**
	 * Registers every hook the plugin uses.
	 *
	 * @return void
	 */
	public static function boot() {
		$settings = new Settings();
		$mailer   = new Mailer( $settings );

		// The seam: wp_mail() short-circuits on a non-null return from this filter (WP >= 5.7).
		add_filter( 'pre_wp_mail', array( $mailer, 'handle' ), 10, 2 );

		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		if ( is_admin() ) {
			$page = new SettingsPage( $settings );
			$test = new TestEmail( $settings, $mailer );

			add_action( 'admin_menu', array( $page, 'add_menu' ) );
			add_action( 'admin_init', array( $page, 'register_settings' ) );
			add_action( 'admin_post_missivus_send_test', array( $test, 'handle' ) );
		}
	}

	/**
	 * Loads the shipped translations, for installs that never touch translate.wordpress.org.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- the shipped .mo files in /languages must load on installs that never touch wordpress.org.
		load_plugin_textdomain(
			'missivus',
			false,
			dirname( plugin_basename( MISSIVUS_PLUGIN_FILE ) ) . '/languages'
		);
	}
}
