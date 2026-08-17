<?php
/**
 * Missivus — send WordPress email through the Microsoft Graph API.
 *
 * @package Missivus
 * @link    https://github.com/Solvetus/missivus-wordpress
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Missivus\Adapter;

use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\HttpResponse;

/**
 * HttpClientInterface over the WordPress HTTP API, so Missivus inherits the site's proxy
 * settings (WP_PROXY_*), CA bundle and transport choice rather than opening its own curl
 * handles.
 *
 * The contract: an HTTP error status is a normal return with that status set; throw — a
 * \RuntimeException — only when the request could not be made at all.
 */
class HttpClient implements HttpClientInterface {

	/**
	 * POST a body and return the response.
	 *
	 * @param string $url     Where to.
	 * @param string $body    Already serialised — JSON or form-encoded. Sent verbatim.
	 * @param array  $headers Header name => value.
	 * @param int    $timeout Seconds.
	 * @return HttpResponse
	 * @throws \RuntimeException When the request could not be completed at all.
	 */
	public function post( $url, $body, array $headers = array(), $timeout = 30 ) {
		return $this->request( 'POST', $url, $body, $headers, $timeout );
	}

	/**
	 * PUT raw bytes and return the response. Used for attachment upload-session chunks.
	 *
	 * @param string $url     Where to.
	 * @param string $body    Raw bytes.
	 * @param array  $headers Header name => value.
	 * @param int    $timeout Seconds.
	 * @return HttpResponse
	 * @throws \RuntimeException When the request could not be completed at all.
	 */
	public function put( $url, $body, array $headers = array(), $timeout = 60 ) {
		return $this->request( 'PUT', $url, $body, $headers, $timeout );
	}

	/**
	 * One request through wp_remote_request().
	 *
	 * @param string $method  POST or PUT.
	 * @param string $url     Where to.
	 * @param string $body    Sent verbatim — a string body is never query-encoded.
	 * @param array  $headers Header name => value.
	 * @param int    $timeout Seconds.
	 * @return HttpResponse
	 * @throws \RuntimeException When the request could not be completed at all.
	 */
	private function request( $method, $url, $body, array $headers, $timeout ) {
		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'body'    => (string) $body,
				'headers' => $headers,
				'timeout' => (int) $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		$header_bag = wp_remote_retrieve_headers( $response );

		if ( is_object( $header_bag ) && method_exists( $header_bag, 'getAll' ) ) {
			$header_bag = $header_bag->getAll();
		}

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			is_array( $header_bag ) ? $header_bag : array()
		);
	}
}
