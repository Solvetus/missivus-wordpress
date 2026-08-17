<?php
/**
 * The wp-config.php constant tier. Each test runs in its own PHP process, because a defined
 * constant cannot be undone.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- tests.

namespace Missivus\Tests\Unit;

use Missivus\Settings;
use Missivus\Tests\Support\MissivusTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class ConstantOverridesTest extends MissivusTestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAConstantWinsOverTheStoredOption() {
		define( 'MISSIVUS_TENANT_ID', '99999999-9999-9999-9999-999999999999' );
		define( 'MISSIVUS_SENDER', 'constant@example.com' );

		$this->configure();
		$settings = new Settings();

		$this->assertSame( '99999999-9999-9999-9999-999999999999', $settings->get( 'tenant_id' ) );
		$this->assertSame( 'constant@example.com', $settings->get_sender_mailbox() );
		$this->assertTrue( $settings->has_constant( 'tenant_id' ) );
		$this->assertFalse( $settings->has_constant( 'client_id' ) );

		$credentials = $settings->get_credentials();
		$this->assertSame( '99999999-9999-9999-9999-999999999999', $credentials->getTenantId() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testAConstantDefinedSecretIsNeverWrittenToTheDatabase() {
		define( 'MISSIVUS_CLIENT_SECRET', 'constant-secret-value-456' );

		$this->configure( array( 'client_secret' => '' ) );
		$settings = new Settings();

		// The credential comes from the constant...
		$this->assertSame( 'constant-secret-value-456', $settings->get( 'client_secret' ) );

		// ...and a UI submission cannot smuggle it — or anything — into the option.
		$clean = $settings->sanitize( array( 'client_secret' => 'typed-into-the-ui-000' ) );
		$this->assertSame( '', $clean['client_secret'], 'a constant-backed secret must never land in wp_options' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheAuthMethodAndCertificateConstantsAssembleCertificateCredentials() {
		define( 'MISSIVUS_AUTH_METHOD', 'certificate' );
		define( 'MISSIVUS_CERTIFICATE_PATH', '/etc/wordpress/secrets/missivus.pem' );
		define( 'MISSIVUS_CERTIFICATE_PASSPHRASE', 'constant-passphrase-789' );

		$this->configure();
		$settings = new Settings();

		$credentials = $settings->get_credentials();
		$this->assertTrue( $credentials->usesCertificate() );
		$this->assertSame( '/etc/wordpress/secrets/missivus.pem', $credentials->getCertificatePath() );
		$this->assertSame( 'constant-passphrase-789', $credentials->getCertificatePassphrase() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function testTheBaseUrlConstantsAreHonoured() {
		define( 'MISSIVUS_GRAPH_BASE_URL', 'https://graph.microsoft.us' );
		define( 'MISSIVUS_LOGIN_BASE_URL', 'https://login.microsoftonline.us' );

		$settings = new Settings();

		$this->assertSame( 'https://graph.microsoft.us', $settings->get_graph_base_url() );
		$this->assertSame( 'https://login.microsoftonline.us', $settings->get_login_base_url() );
	}
}
