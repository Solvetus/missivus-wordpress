<?php
/**
 * Transport doubles, mirroring tests/Framework/Doubles.php in the Matomo sibling.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- test doubles.

namespace Missivus\Tests\Support;

use Solvetus\Missivus\Contract\HttpClientInterface;
use Solvetus\Missivus\Contract\HttpResponse;
use Solvetus\Missivus\Contract\LoggerInterface;
use Solvetus\Missivus\Contract\TokenCacheInterface;

/**
 * Records every request and answers from a scripted queue. Throws \RuntimeException — the
 * contract's transport failure — when told to.
 */
class FakeHttpClient implements HttpClientInterface {

	/** @var array Each: [method, url, body, headers, timeout]. */
	public $requests = array();

	/** @var HttpResponse[] */
	public $queue = array();

	/** @var callable|null fn(method, url): ?\RuntimeException */
	public $throw_on = null;

	public function post( $url, $body, array $headers = array(), $timeout = 30 ) {
		return $this->handle( 'POST', $url, $body, $headers, $timeout );
	}

	public function put( $url, $body, array $headers = array(), $timeout = 60 ) {
		return $this->handle( 'PUT', $url, $body, $headers, $timeout );
	}

	public function queueResponse( $status, $body = '', array $headers = array() ) {
		$this->queue[] = new HttpResponse( $status, $body, $headers );
		return $this;
	}

	public function queueToken( $token = 'test-access-token', $expires_in = 3600 ) {
		return $this->queueResponse( 200, json_encode( array( 'access_token' => $token, 'expires_in' => $expires_in ) ) );
	}

	private function handle( $method, $url, $body, array $headers, $timeout ) {
		$this->requests[] = array( $method, $url, $body, $headers, $timeout );

		if ( null !== $this->throw_on ) {
			$exception = call_user_func( $this->throw_on, $method, $url );
			if ( null !== $exception ) {
				throw $exception;
			}
		}

		if ( empty( $this->queue ) ) {
			throw new \LogicException( 'FakeHttpClient: no scripted response left for ' . $method . ' ' . $url );
		}

		return array_shift( $this->queue );
	}
}

class FakeCache implements TokenCacheInterface {

	public $store = array();

	/** @var array Each: [key, value, ttl]. */
	public $sets = array();

	public function get( $key ) {
		return isset( $this->store[ $key ] ) ? $this->store[ $key ] : null;
	}

	public function set( $key, $value, $ttl_seconds ) {
		$this->store[ $key ] = $value;
		$this->sets[] = array( $key, $value, $ttl_seconds );
	}

	public function delete( $key ) {
		unset( $this->store[ $key ] );
	}
}

class RecordingLogger implements LoggerInterface {

	public $errors = array();
	public $warnings = array();
	public $infos = array();

	public function error( $message ) {
		$this->errors[] = $message;
	}

	public function warning( $message ) {
		$this->warnings[] = $message;
	}

	public function info( $message ) {
		$this->infos[] = $message;
	}

	public function all() {
		return implode( "\n", array_merge( $this->errors, $this->warnings, $this->infos ) );
	}
}
