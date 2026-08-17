<?php
/**
 * The pre_wp_mail handler, end to end against a mocked Graph.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- tests.

namespace Missivus\Tests\Unit;

use Missivus\Tests\Support\MissivusTestCase;
use Solvetus\Missivus\GraphMailer;

class MailerTest extends MissivusTestCase {

	public function testDisabledLeavesTheFilterUntouched() {
		$this->configure( array( 'enabled' => false ) );
		list( $mailer, $http ) = $this->makeMailer();

		$this->assertNull( $mailer->handle( null, $this->atts() ) );
		$this->assertSame( array(), $http->requests, 'a disabled plugin must not talk to the network' );
		$this->assertSame( array(), \WpTestState::firedActions( 'wp_mail_failed' ) );
	}

	public function testAnotherPluginsShortCircuitIsPassedThrough() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();

		$this->assertTrue( $mailer->handle( true, $this->atts() ) );
		$this->assertFalse( $mailer->handle( false, $this->atts() ) );
		$this->assertSame( array(), $http->requests );
	}

	public function testHappyPathSendsOverGraphAndReturnsTrue() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$result = $mailer->handle( null, $this->atts() );

		$this->assertTrue( $result );
		$this->assertCount( 2, $http->requests );

		// First the token, from the login origin, with the client-credentials grant.
		$this->assertStringContainsString(
			'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/oauth2/v2.0/token',
			$http->requests[0][1]
		);
		$this->assertStringContainsString( 'grant_type=client_credentials', $http->requests[0][2] );

		// Then the send, as the configured shared mailbox.
		$this->assertSame(
			'https://graph.microsoft.com/v1.0/users/noreply%40example.com/sendMail',
			$http->requests[1][1]
		);

		$payload = $this->payload( $http, 1 );
		$this->assertSame( 'Hello', $payload['message']['subject'] );
		$this->assertSame( 'Text', $payload['message']['body']['contentType'] );
		$this->assertSame( 'A plain body.', $payload['message']['body']['content'] );
		$this->assertSame( 'reader@example.org', $payload['message']['toRecipients'][0]['emailAddress']['address'] );
		$this->assertFalse( $payload['saveToSentItems'] );

		$this->assertCount( 1, \WpTestState::firedActions( 'wp_mail_succeeded' ) );
		$this->assertSame( array(), \WpTestState::firedActions( 'wp_mail_failed' ) );
	}

	public function testHtmlContentTypeHeaderMakesAnHtmlBody() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$mailer->handle( null, $this->atts( array(
			'message' => '<p>Rich</p>',
			'headers' => "Content-Type: text/html; charset=UTF-8",
		) ) );

		$payload = $this->payload( $http, 1 );
		$this->assertSame( 'HTML', $payload['message']['body']['contentType'] );
		$this->assertSame( '<p>Rich</p>', $payload['message']['body']['content'] );
	}

	public function testWpMailContentTypeFilterIsApplied() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		add_filter( 'wp_mail_content_type', function () {
			return 'text/html';
		} );

		$mailer->handle( null, $this->atts( array( 'message' => '<b>hi</b>' ) ) );

		$payload = $this->payload( $http, 1 );
		$this->assertSame( 'HTML', $payload['message']['body']['contentType'] );
	}

	public function testStringHeadersParseFromReplyToCcAndBcc() {
		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$headers = "From: Site Owner <owner@example.com>\r\n"
			. "Reply-To: helpdesk@example.com\r\n"
			. "Cc: one@example.com, Two Person <two@example.com>\r\n"
			. "Bcc: quiet@example.com";

		$mailer->handle( null, $this->atts( array( 'headers' => $headers ) ) );

		$payload = $this->payload( $http, 1 );
		$message = $payload['message'];

		$this->assertSame( 'helpdesk@example.com', $message['replyTo'][0]['emailAddress']['address'] );
		$this->assertSame( 'one@example.com', $message['ccRecipients'][0]['emailAddress']['address'] );
		$this->assertSame( 'two@example.com', $message['ccRecipients'][1]['emailAddress']['address'] );
		$this->assertSame( 'Two Person', $message['ccRecipients'][1]['emailAddress']['name'] );
		$this->assertSame( 'quiet@example.com', $message['bccRecipients'][0]['emailAddress']['address'] );

		// From is forced to the shared mailbox — but keeps the requested display name — and the
		// mismatch is warned about. The explicit Reply-To is never clobbered by the override.
		$this->assertSame( 'noreply@example.com', $message['from']['emailAddress']['address'] );
		$this->assertSame( 'Site Owner', $message['from']['emailAddress']['name'] );
		$this->assertStringContainsString( 'owner@example.com', implode( ' ', $logger->warnings ) );
		$this->assertCount( 1, $message['replyTo'] );
	}

	public function testArrayHeadersWorkToo() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$mailer->handle( null, $this->atts( array(
			'headers' => array( 'Cc: copy@example.com', 'Content-Type: text/html' ),
		) ) );

		$payload = $this->payload( $http, 1 );
		$this->assertSame( 'copy@example.com', $payload['message']['ccRecipients'][0]['emailAddress']['address'] );
		$this->assertSame( 'HTML', $payload['message']['body']['contentType'] );
	}

	public function testForcedFromKeepsTheRequestedAddressAsReplyTo() {
		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$mailer->handle( null, $this->atts( array( 'headers' => 'From: someone@else.example' ) ) );

		$payload = $this->payload( $http, 1 );
		$this->assertSame( 'noreply@example.com', $payload['message']['from']['emailAddress']['address'] );
		$this->assertSame( 'someone@else.example', $payload['message']['replyTo'][0]['emailAddress']['address'] );

		$warning = implode( ' ', $logger->warnings );
		$this->assertStringContainsString( 'noreply@example.com', $warning );
		$this->assertStringContainsString( 'someone@else.example', $warning );
	}

	public function testWpMailFromFilterCountsAsARequestedFrom() {
		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		add_filter( 'wp_mail_from', function () {
			return 'filtered@example.net';
		} );
		add_filter( 'wp_mail_from_name', function () {
			return 'Filtered Name';
		} );

		$mailer->handle( null, $this->atts() );

		$payload = $this->payload( $http, 1 );
		$this->assertSame( 'noreply@example.com', $payload['message']['from']['emailAddress']['address'] );
		$this->assertSame( 'Filtered Name', $payload['message']['from']['emailAddress']['name'] );
		$this->assertSame( 'filtered@example.net', $payload['message']['replyTo'][0]['emailAddress']['address'] );
		$this->assertStringContainsString( 'filtered@example.net', implode( ' ', $logger->warnings ) );
	}

	public function testAMatchingFromProducesNoWarning() {
		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$mailer->handle( null, $this->atts( array( 'headers' => 'From: NoReply <NOREPLY@example.com>' ) ) );

		$this->assertSame( array(), $logger->warnings );
	}

	public function testFallbackOffReturnsFalseFiresWpMailFailedAndLogs() {
		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 403, '{"error":{"code":"ErrorAccessDenied","message":"Access is denied."}}' );

		$result = $mailer->handle( null, $this->atts() );

		$this->assertFalse( $result );

		$failed = \WpTestState::firedActions( 'wp_mail_failed' );
		$this->assertCount( 1, $failed );

		$error = $failed[0][0];
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'wp_mail_failed', $error->get_error_code() );
		$this->assertStringContainsString( 'ErrorAccessDenied', $error->get_error_message() );

		$data = $error->get_error_data();
		$this->assertSame( 403, $data['graph_status'] );
		$this->assertStringContainsString( 'ErrorAccessDenied', $data['graph_body'] );
		$this->assertSame( 'reader@example.org', $data['to'] );

		$this->assertNotEmpty( $logger->errors, 'a Graph failure must reach the error log' );
		$this->assertSame( array(), \WpTestState::firedActions( 'wp_mail_succeeded' ) );
	}

	public function testFallbackOnReturnsNullSoTheWordPressMailerRuns() {
		$this->configure( array( 'fallback_to_wp_mail' => true ) );
		list( $mailer, $http, , $logger ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 500, '{"error":{"code":"InternalServerError"}}' );

		$result = $mailer->handle( null, $this->atts() );

		$this->assertNull( $result, 'null hands the email to the stock wp_mail path' );
		$this->assertCount( 1, \WpTestState::firedActions( 'wp_mail_failed' ) );
		$this->assertStringContainsString( 'falling back', implode( ' ', $logger->errors ) );
	}

	public function testEnabledButMisconfiguredFailsLoudlyInsteadOfPassingThrough() {
		$this->configure( array( 'client_secret' => '' ) );
		list( $mailer, $http, , $logger ) = $this->makeMailer();

		$result = $mailer->handle( null, $this->atts() );

		$this->assertFalse( $result );
		$this->assertSame( array(), $http->requests, 'nothing must leave the process without credentials' );

		$failed = \WpTestState::firedActions( 'wp_mail_failed' );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'client secret', $failed[0][0]->get_error_message() );
		$this->assertStringContainsString( 'client secret', implode( ' ', $logger->errors ) );
	}

	public function testASmallAttachmentGoesInline() {
		$this->configure( array( 'save_to_sent' => true ) );
		list( $mailer, $http ) = $this->makeMailer();
		$http->queueToken()->queueResponse( 202 );

		$path = tempnam( sys_get_temp_dir(), 'missivus' );
		file_put_contents( $path, 'tiny attachment bytes' );

		$mailer->handle( null, $this->atts( array(
			'attachments' => array( 'report.pdf' => $path ),
		) ) );

		unlink( $path );

		$payload = $this->payload( $http, 1 );
		$this->assertTrue( $payload['saveToSentItems'] );

		$attachment = $payload['message']['attachments'][0];
		$this->assertSame( '#microsoft.graph.fileAttachment', $attachment['@odata.type'] );
		$this->assertSame( 'report.pdf', $attachment['name'], 'the array key names the attachment, as wp_mail allows' );
		$this->assertSame( 'application/pdf', $attachment['contentType'] );
		$this->assertSame( base64_encode( 'tiny attachment bytes' ), $attachment['contentBytes'] );
	}

	public function testALargeAttachmentTakesTheDraftUploadSessionPath() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();

		// Bigger than one upload chunk, so the loop runs twice: an intermediate 200, a final 201.
		$size = GraphMailer::UPLOAD_CHUNK_BYTES + 100;
		$path = tempnam( sys_get_temp_dir(), 'missivus' );
		file_put_contents( $path, str_repeat( 'A', $size ) );

		$http->queueToken()
			->queueResponse( 201, '{"id":"draft-42"}' )
			->queueResponse( 201, '{"uploadUrl":"https://upload.example/session-1"}' )
			->queueResponse( 200 )   // first chunk
			->queueResponse( 201 )   // final chunk
			->queueResponse( 202 );  // send

		$result = $mailer->handle( null, $this->atts( array( 'attachments' => array( $path ) ) ) );

		unlink( $path );

		$this->assertTrue( $result );
		$this->assertCount( 6, $http->requests );

		$urls = array_map( function ( $request ) {
			return $request[0] . ' ' . $request[1];
		}, $http->requests );

		$this->assertStringEndsWith( '/users/noreply%40example.com/messages', $urls[1] );
		$this->assertStringEndsWith( '/messages/draft-42/attachments/createUploadSession', $urls[2] );
		$this->assertSame( 'PUT https://upload.example/session-1', $urls[3] );
		$this->assertSame( 'PUT https://upload.example/session-1', $urls[4] );
		$this->assertStringEndsWith( '/messages/draft-42/send', $urls[5] );

		// Chunks are content-ranged, and the upload URL is pre-authenticated: no bearer token.
		$this->assertSame( 'bytes 0-3276799/' . $size, $http->requests[3][3]['Content-Range'] );
		$this->assertArrayNotHasKey( 'Authorization', $http->requests[3][3] );

		$this->assertCount( 1, \WpTestState::firedActions( 'wp_mail_succeeded' ) );
	}

	public function testAnEntraErrorEchoingTheSecretIsRedactedEverywhere() {
		$this->configure();
		list( $mailer, $http, , $logger ) = $this->makeMailer();

		$http->queueResponse(
			400,
			'{"error":"invalid_client","error_description":"the secret ' . self::SECRET . ' was rejected","client_secret":"' . self::SECRET . '"}'
		);

		$result = $mailer->handle( null, $this->atts() );

		$this->assertFalse( $result );

		$everything = $logger->all();
		$failed     = \WpTestState::firedActions( 'wp_mail_failed' );
		$everything .= ' ' . $failed[0][0]->get_error_message();

		$this->assertStringNotContainsString( self::SECRET, $everything, 'the secret must never reach a log or an error' );
		$this->assertStringContainsString( '***redacted***', $everything );
		$this->assertStringContainsString( 'invalid_client', $everything, 'the useful part of the Entra error survives' );
	}

	public function testATransportFailureIsALoudFailureToo() {
		$this->configure();
		list( $mailer, $http ) = $this->makeMailer();

		$http->throw_on = function () {
			return new \RuntimeException( 'could not resolve host' );
		};

		$result = $mailer->handle( null, $this->atts() );

		$this->assertFalse( $result );
		$failed = \WpTestState::firedActions( 'wp_mail_failed' );
		$this->assertCount( 1, $failed );
		$this->assertStringContainsString( 'could not resolve host', $failed[0][0]->get_error_message() );
	}
}
