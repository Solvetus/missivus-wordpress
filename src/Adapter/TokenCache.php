<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus\Adapter;

use Solvetus\Missivus\Contract\TokenCacheInterface;

/**
 * TokenCacheInterface over transients, which persist across requests — a token cached only for
 * the lifetime of one PHP request would mean a token round-trip on every single email.
 *
 * The key arrives as `missivus.token.<sha1>` — 55 characters, comfortably under the transient
 * name ceiling, and per-site on multisite, which matches per-site configuration.
 */
class TokenCache implements TokenCacheInterface {

	/**
	 * Reads one cached token.
	 *
	 * @param string $key Cache key.
	 * @return string|null The cached value, or null when absent or expired.
	 */
	public function get( $key ) {
		$value = get_transient( $key );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Stores one token.
	 *
	 * @param string $key        Cache key.
	 * @param string $value      The token.
	 * @param int    $ttl_seconds Seconds until the entry must be treated as absent.
	 * @return void
	 */
	public function set( $key, $value, $ttl_seconds ) {
		$ttl_seconds = (int) $ttl_seconds;

		// A TTL of zero would mean "never expire", which must not happen to a bearer token.
		if ( $ttl_seconds <= 0 ) {
			return;
		}

		set_transient( $key, $value, $ttl_seconds );
	}

	/**
	 * Drops one token. Called after a 401, so the single retry uses a fresh one.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function delete( $key ) {
		delete_transient( $key );
	}
}
