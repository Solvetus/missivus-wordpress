<?php
/**
 * Shared fixtures for the unit suite.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- test support.

namespace Missivus\Tests\Support;

use Missivus\Mailer;
use Missivus\Settings;
use PHPUnit\Framework\TestCase;

abstract class MissivusTestCase extends TestCase {

	const SECRET = 'super-secret-value-123';

	protected function setUp(): void {
		\WpTestState::reset();
	}

	/**
	 * Stores a complete, enabled configuration in the fake option table.
	 *
	 * @param array $overrides Keys to change from the working baseline.
	 */
	protected function configure( array $overrides = array() ) {
		\WpTestState::$options[ Settings::OPTION ] = array_merge(
			array(
				'enabled'                => true,
				'tenant_id'              => '11111111-1111-1111-1111-111111111111',
				'client_id'              => '22222222-2222-2222-2222-222222222222',
				'auth_method'            => 'secret',
				'client_secret'          => self::SECRET,
				'certificate_path'       => '',
				'certificate_passphrase' => '',
				'sender_mailbox'         => 'noreply@example.com',
				'save_to_sent'           => false,
				'fallback_to_wp_mail'    => false,
			),
			$overrides
		);
	}

	/**
	 * A Mailer wired to the doubles.
	 *
	 * @return array [ Mailer, FakeHttpClient, FakeCache, RecordingLogger ]
	 */
	protected function makeMailer() {
		$http   = new FakeHttpClient();
		$cache  = new FakeCache();
		$logger = new RecordingLogger();
		$mailer = new Mailer( new Settings(), $http, $cache, $logger );

		return array( $mailer, $http, $cache, $logger );
	}

	/**
	 * The default wp_mail-style atts, overridable per test.
	 */
	protected function atts( array $overrides = array() ) {
		return array_merge(
			array(
				'to'          => 'reader@example.org',
				'subject'     => 'Hello',
				'message'     => 'A plain body.',
				'headers'     => '',
				'attachments' => array(),
			),
			$overrides
		);
	}

	/**
	 * The JSON payload of the request at $index in the fake client's log.
	 */
	protected function payload( FakeHttpClient $http, $index ) {
		$this->assertArrayHasKey( $index, $http->requests, 'expected a request at index ' . $index );

		return json_decode( $http->requests[ $index ][2], true );
	}
}
