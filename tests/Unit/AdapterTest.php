<?php
/**
 * The WordPress adapters against the transport contracts.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- tests.

namespace Missivus\Tests\Unit;

use Missivus\Adapter\HttpClient;
use Missivus\Adapter\TokenCache;
use Missivus\Tests\Support\MissivusTestCase;

class AdapterTest extends MissivusTestCase {

	public function testAWpErrorBecomesARuntimeExceptionPerTheContract() {
		\WpTestState::$remote_handler = function () {
			return new \WP_Error( 'http_request_failed', 'cURL error 6: could not resolve host' );
		};

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'could not resolve host' );

		( new HttpClient() )->post( 'https://example.test', '{}' );
	}

	public function testTheRequestIsPassedVerbatimAndTheResponseMapped() {
		$seen = array();

		\WpTestState::$remote_handler = function ( $url, $args ) use ( &$seen ) {
			$seen = array( $url, $args );

			return array(
				'response' => array( 'code' => 202 ),
				'body'     => '{"ok":true}',
				'headers'  => array( 'Content-Type' => 'application/json' ),
			);
		};

		$response = ( new HttpClient() )->put(
			'https://example.test/upload',
			'raw-bytes',
			array( 'Content-Range' => 'bytes 0-8/9' ),
			120
		);

		$this->assertSame( 'https://example.test/upload', $seen[0] );
		$this->assertSame( 'PUT', $seen[1]['method'] );
		$this->assertSame( 'raw-bytes', $seen[1]['body'] );
		$this->assertSame( 120, $seen[1]['timeout'] );
		$this->assertSame( 'bytes 0-8/9', $seen[1]['headers']['Content-Range'] );

		$this->assertSame( 202, $response->getStatus() );
		$this->assertSame( '{"ok":true}', $response->getBody() );
		$this->assertSame( 'application/json', $response->getHeader( 'content-type' ) );
	}

	public function testTheTokenCacheRefusesAForeverTtl() {
		$cache = new TokenCache();

		$cache->set( 'missivus.token.abc', 'a-token', 0 );
		$this->assertNull( $cache->get( 'missivus.token.abc' ), 'TTL 0 would mean "never expire" — refused for a bearer token' );

		$cache->set( 'missivus.token.abc', 'a-token', 300 );
		$this->assertSame( 'a-token', $cache->get( 'missivus.token.abc' ) );
		$this->assertSame( 300, \WpTestState::$transients['missivus.token.abc']['ttl'] );

		$cache->delete( 'missivus.token.abc' );
		$this->assertNull( $cache->get( 'missivus.token.abc' ) );
	}
}
