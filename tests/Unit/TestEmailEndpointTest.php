<?php
/**
 * The admin-post test-email endpoint: capability, nonce, validation, and loud failures.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- tests.

namespace Missivus\Tests\Unit;

use Missivus\Admin\TestEmail;
use Missivus\Mailer;
use Missivus\Settings;
use Missivus\Tests\Support\FakeCache;
use Missivus\Tests\Support\FakeHttpClient;
use Missivus\Tests\Support\MissivusTestCase;
use Missivus\Tests\Support\RecordingLogger;

class TestEmailEndpointTest extends MissivusTestCase {

	/** @var FakeHttpClient */
	private $http;

	private function makeEndpoint() {
		$settings   = new Settings();
		$this->http = new FakeHttpClient();
		$mailer     = new Mailer( $settings, $this->http, new FakeCache(), new RecordingLogger() );

		return new TestEmail( $settings, $mailer );
	}

	private function stored_result() {
		$stored = \WpTestState::$transients;
		$key    = 'missivus_test_result_' . \WpTestState::$current_user_id;

		return isset( $stored[ $key ] ) ? $stored[ $key ]['value'] : null;
	}

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
	}

	public function testWithoutManageOptionsTheEndpointDies() {
		$this->configure();
		\WpTestState::$user_can = false;

		$this->expectException( \WpDieException::class );

		$this->makeEndpoint()->handle();
	}

	public function testABadNonceDies() {
		$this->configure();
		\WpTestState::$valid_nonce = false;

		$this->expectException( \WpDieException::class );

		$this->makeEndpoint()->handle();
	}

	public function testAnInvalidRecipientIsRefusedBeforeAnythingIsSent() {
		$this->configure();
		$_POST['missivus_test_recipient'] = 'not-an-address';

		try {
			$this->makeEndpoint()->handle();
			$this->fail( 'expected the redirect' );
		} catch ( \WpRedirectException $e ) {
			// The redirect back to the settings page.
		}

		$result = $this->stored_result();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not a valid email address', $result['text'] );
		$this->assertSame( array(), $this->http->requests );
	}

	public function testASuccessfulTestReportsThatMicrosoftAccepted() {
		$this->configure();
		$_POST['missivus_test_recipient'] = 'owner@example.org';

		$endpoint = $this->makeEndpoint();
		$this->http->queueToken()->queueResponse( 202 );

		try {
			$endpoint->handle();
			$this->fail( 'expected the redirect' );
		} catch ( \WpRedirectException $e ) {
		}

		$result = $this->stored_result();
		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'owner@example.org', $result['text'] );
		$this->assertStringContainsString( 'options-general.php?page=missivus', \WpTestState::$redirects[0] );
	}

	public function testAFailedTestShowsTheMicrosoftErrorBodyRedacted() {
		$this->configure();
		$_POST['missivus_test_recipient'] = 'owner@example.org';

		$endpoint = $this->makeEndpoint();
		$this->http->queueResponse( 401, '{"error":"invalid_client","error_description":"secret ' . self::SECRET . ' expired"}' );

		try {
			$endpoint->handle();
			$this->fail( 'expected the redirect' );
		} catch ( \WpRedirectException $e ) {
		}

		$result = $this->stored_result();
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'invalid_client', $result['text'], 'the Microsoft error is shown to the operator' );
		$this->assertStringNotContainsString( self::SECRET, $result['text'], 'but never a secret' );
	}

	public function testTheTestNeverTakesTheFallback() {
		// Fallback ON, and Graph failing: wp_mail() would fall through, but the test must not.
		$this->configure( array( 'fallback_to_wp_mail' => true ) );
		$_POST['missivus_test_recipient'] = 'owner@example.org';

		$endpoint = $this->makeEndpoint();
		$this->http->queueToken()->queueResponse( 503, '{"error":{"code":"ServiceUnavailable"}}' );

		try {
			$endpoint->handle();
			$this->fail( 'expected the redirect' );
		} catch ( \WpRedirectException $e ) {
		}

		$result = $this->stored_result();
		$this->assertFalse( $result['ok'], 'a failing tenant must show as a failure, fallback or not' );
		$this->assertStringContainsString( 'ServiceUnavailable', $result['text'] );
	}
}
