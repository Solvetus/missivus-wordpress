<?php
/**
 * Endpoint override URLs must never be repeated back — not into an exception, not into the
 * error log, and not onto the admin screen. Reported as missivus-matomo#1 by @textagroup.
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
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Solvetus\Missivus\Endpoint;
use Solvetus\Missivus\Exception\GraphException;
use Solvetus\Missivus\Redactor;

class EndpointRedactionTest extends MissivusTestCase {

	/** Everything that must never survive into a message, wherever it came from. */
	const SECRETS = array( 'hunter2-correct-horse', 'admin:', 'TOKEN-VALUE', 'SECRET-VALUE', 'FRAGMENT-VALUE' );

	private function assertNoSecretIn( $text, $where ) {
		foreach ( self::SECRETS as $secret ) {
			$this->assertStringNotContainsString( $secret, $text, 'leaked into ' . $where );
		}
	}

	public function testTheRefusalNeverRepeatsTheUserinfoBackAtTheOperator() {
		try {
			Endpoint::normalise( 'https://admin:hunter2-correct-horse@login.example.test', 'login_base_url' );
			$this->fail( 'expected a GraphException' );
		} catch ( GraphException $e ) {
			$this->assertNoSecretIn( $e->getMessage(), 'the exception' );
			$this->assertStringNotContainsString( '@', $e->getMessage() );
			$this->assertStringContainsString( 'login.example.test', $e->getMessage(), 'the host is safe, and useful' );
		}
	}

	public function testTheRefusalNeverRepeatsAQueryStringBackAtTheOperator() {
		$urls = array(
			'https://login.example.test/?access_token=TOKEN-VALUE',
			'https://login.example.test/?client_secret=SECRET-VALUE',
			'https://login.example.test/?code=TOKEN-VALUE',
		);

		foreach ( $urls as $url ) {
			try {
				Endpoint::normalise( $url, 'login_base_url' );
				$this->fail( 'expected a GraphException for ' . $url );
			} catch ( GraphException $e ) {
				$this->assertNoSecretIn( $e->getMessage(), 'the exception' );
				$this->assertStringNotContainsString( '?', $e->getMessage() );
				$this->assertStringNotContainsString( '=', $e->getMessage() );
			}
		}
	}

	public function testTheRefusalNeverRepeatsAFragmentBackAtTheOperator() {
		try {
			Endpoint::normalise( 'https://graph.example.test/v1.0#FRAGMENT-VALUE', 'graph_base_url' );
			$this->fail( 'expected a GraphException' );
		} catch ( GraphException $e ) {
			$this->assertNoSecretIn( $e->getMessage(), 'the exception' );
			$this->assertStringNotContainsString( '#', $e->getMessage() );
			$this->assertStringContainsString( 'https://graph.example.test/v1.0', $e->getMessage() );
		}
	}

	public function testSomethingUnparseableIsRefusedWithoutBeingEchoedAtAll() {
		try {
			Endpoint::normalise( 'WHOLE-THING-IS-A-SECRET', 'graph_base_url' );
			$this->fail( 'expected a GraphException' );
		} catch ( GraphException $e ) {
			$this->assertStringNotContainsString( 'WHOLE-THING-IS-A-SECRET', $e->getMessage() );
			$this->assertStringContainsString( 'not a valid endpoint URL', $e->getMessage() );
		}
	}

	public function testAnInvalidHostIsRefusedWithoutBeingEchoed() {
		try {
			Endpoint::normalise( 'https://ph_HOST-NOT-ECHOED.example.test', 'graph_base_url' );
			$this->fail( 'expected a GraphException' );
		} catch ( GraphException $e ) {
			$this->assertStringNotContainsString( 'HOST-NOT-ECHOED', $e->getMessage() );
		}
	}

	public function testTheRedactorBlanksCredentialsAndTokensCarriedInAUrl() {
		$redactor = new Redactor();

		$result = $redactor->redact(
			'base URL was https://admin:hunter2-correct-horse@login.evil.test/?access_token=TOKEN-VALUE&client_secret=SECRET-VALUE#FRAGMENT-VALUE'
		);

		$this->assertNoSecretIn( $result, 'the redaction pass' );
		$this->assertStringContainsString( 'login.evil.test', $result, 'the host survives, and is the useful part' );
	}

	public function testTheRedactorLeavesAnOrdinaryMailboxAddressAlone() {
		$redactor = new Redactor();
		$text     = 'Missivus: forcing From to noreply@example.com; see https://graph.example.test/v1.0/users/noreply@example.com/sendMail';

		$this->assertSame( $text, $redactor->redact( $text ), 'a mailbox is not a credential and must not be mangled' );
	}

	public function testTheMailersRedactionPassCoversAUrlThatSomehowSurvived() {
		$this->configure();
		list( $mailer ) = $this->makeMailer();

		$redacted = $mailer->redact(
			'talking to https://admin:hunter2-correct-horse@graph.evil.test/?access_token=TOKEN-VALUE#FRAGMENT-VALUE'
		);

		$this->assertNoSecretIn( $redacted, 'the mailer redaction pass' );
		$this->assertStringContainsString( 'graph.evil.test', $redacted );
	}

	public function testTheMailersRedactionPassAlsoBlanksTheConfiguredSecret() {
		$this->configure();
		list( $mailer ) = $this->makeMailer();

		$this->assertStringNotContainsString(
			self::SECRET,
			$mailer->redact( 'Entra says ' . self::SECRET . ' was rejected' )
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAPoisonedLoginBaseUrlReachesNeitherTheLogNorTheFailureHook() {
		define( 'MISSIVUS_LOGIN_BASE_URL', 'https://admin:hunter2-correct-horse@login.evil.test/?access_token=TOKEN-VALUE#FRAGMENT-VALUE' );

		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();

		$this->assertFalse( $mailer->handle( null, $this->atts() ) );
		$this->assertSame( array(), $http->requests, 'the secret must never leave the process' );

		$failed      = \WpTestState::firedActions( 'wp_mail_failed' );
		$everything  = $logger->all();
		$everything .= ' ' . $failed[0][0]->get_error_message();
		$everything .= ' ' . print_r( $failed[0][0]->get_error_data(), true );

		$this->assertNoSecretIn( $everything, 'the log and the wp_mail_failed hook' );
		$this->assertStringContainsString( 'login_base_url', $everything, 'the setting is still named' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAPoisonedGraphBaseUrlNeverReachesTheAdminScreen() {
		define( 'MISSIVUS_GRAPH_BASE_URL', 'https://admin:hunter2-correct-horse@graph.evil.test/?client_secret=SECRET-VALUE#FRAGMENT-VALUE' );

		$this->configure();
		$_POST                            = array();
		$_POST['missivus_test_recipient'] = 'owner@example.org';

		$settings = new Settings();
		$http     = new FakeHttpClient();
		$endpoint = new TestEmail( $settings, new Mailer( $settings, $http, new FakeCache(), new RecordingLogger() ) );

		try {
			$endpoint->handle();
			$this->fail( 'expected the redirect' );
		} catch ( \WpRedirectException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}

		$stored = \WpTestState::$transients[ 'missivus_test_result_' . \WpTestState::$current_user_id ]['value'];

		$this->assertFalse( $stored['ok'] );
		$this->assertNoSecretIn( $stored['text'], 'the admin notice' );
		$this->assertSame( array(), $http->requests, 'the token request must never have been built' );
	}
}
