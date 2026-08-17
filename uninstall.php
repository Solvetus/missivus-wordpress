<?php
/**
 * Missivus — uninstall cleanup.
 *
 * Deletes the stored settings. The token transients are not enumerated here: their keys are
 * derived from the credentials and they expire on their own within the hour.
 *
 * @package Missivus
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'missivus_settings' );
