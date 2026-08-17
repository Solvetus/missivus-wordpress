<?php
/**
 * The settings model: sanitisation, write-only secrets, and the configuration-problem report.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- tests.

namespace Missivus\Tests\Unit;

use Missivus\Settings;
use Missivus\Tests\Support\MissivusTestCase;

class SettingsTest extends MissivusTestCase {

	public function testDefaultsAreOffAndSafe() {
		$settings = new Settings();

		$this->assertFalse( $settings->is_enabled() );
		$this->assertFalse( $settings->should_fallback(), 'the fallback ships OFF, deliberately' );
		$this->assertFalse( $settings->should_save_to_sent() );
	}

	public function testAnEmptySecretSubmissionKeepsTheStoredSecret() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'client_secret' => '', 'enabled' => '1' ) );

		$this->assertSame( self::SECRET, $clean['client_secret'], 'the field is write-only; empty means unchanged' );
	}

	public function testASecretWithWhitespaceIsRejectedAndTheOldOneKept() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'client_secret' => "pasted with\nnewline" ) );

		$this->assertSame( self::SECRET, $clean['client_secret'] );
		$this->assertNotEmpty( \WpTestState::$settings_errors );
	}

	public function testABadClientIdIsRejectedWithAnError() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'client_id' => 'not-a-guid' ) );

		$this->assertSame( '22222222-2222-2222-2222-222222222222', $clean['client_id'] );
		$this->assertNotEmpty( \WpTestState::$settings_errors );
	}

	public function testATenantDomainIsAcceptedWhereAGuidIsNot() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'tenant_id' => 'contoso.onmicrosoft.com' ) );

		$this->assertSame( 'contoso.onmicrosoft.com', $clean['tenant_id'] );
		$this->assertSame( array(), \WpTestState::$settings_errors );
	}

	public function testTheSenderMailboxMustBeAnEmailAddress() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'sender_mailbox' => 'not an address' ) );

		$this->assertSame( 'noreply@example.com', $clean['sender_mailbox'] );
		$this->assertNotEmpty( \WpTestState::$settings_errors );
	}

	public function testTheCertificatePathMustBeAbsolute() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'certificate_path' => 'relative/missivus.pem' ) );

		$this->assertSame( '', $clean['certificate_path'] );
		$this->assertNotEmpty( \WpTestState::$settings_errors );
	}

	public function testTheAuthMethodOnlyTakesTheTwoKnownValues() {
		$this->configure();
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'auth_method' => 'certificate' ) );
		$this->assertSame( 'certificate', $clean['auth_method'] );

		$clean = $settings->sanitize( array( 'auth_method' => 'macaroni' ) );
		$this->assertSame( 'secret', $clean['auth_method'] );
	}

	public function testUncheckedCheckboxesComeBackFalse() {
		$this->configure( array( 'enabled' => true, 'fallback_to_wp_mail' => true ) );
		$settings = new Settings();

		$clean = $settings->sanitize( array( 'tenant_id' => '11111111-1111-1111-1111-111111111111' ) );

		$this->assertFalse( $clean['enabled'], 'an absent checkbox is an unticked checkbox' );
		$this->assertFalse( $clean['fallback_to_wp_mail'] );
	}

	public function testTheConfigurationProblemNamesWhatIsMissing() {
		$settings = new Settings();
		$this->assertStringContainsString( 'switched off', $settings->get_configuration_problem() );

		$this->configure( array( 'sender_mailbox' => '' ) );
		$this->assertStringContainsString( 'sender mailbox', $settings->get_configuration_problem() );

		$this->configure( array( 'client_secret' => '' ) );
		$this->assertStringContainsString( 'client secret', $settings->get_configuration_problem() );

		$this->configure();
		$this->assertSame( '', $settings->get_configuration_problem() );
		$this->assertTrue( $settings->is_configured() );
	}
}
