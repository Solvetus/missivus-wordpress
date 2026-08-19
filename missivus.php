<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://missivus.com
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * Plugin Name:       Missivus
 * Plugin URI:        https://missivus.com
 * Description:       Send all WordPress email through Microsoft 365 via the Graph API — application permissions and a shared mailbox; no SMTP, no user login, no paid add-on.
 * Version:           0.1.2
 * Requires at least: 5.7
 * Requires PHP:      7.2
 * Author:            Solvetus
 * Author URI:        https://solvetus.com
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       missivus
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MISSIVUS_VERSION', '0.1.2' );
define( 'MISSIVUS_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/src/Autoloader.php';

Missivus\Autoloader::register();
Missivus\Plugin::boot();
