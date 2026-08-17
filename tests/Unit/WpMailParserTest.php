<?php
/**
 * The wp_mail argument parser on its own.
 *
 * @package Missivus\Tests
 */

// phpcs:ignoreFile -- tests.

namespace Missivus\Tests\Unit;

use Missivus\Tests\Support\MissivusTestCase;
use Missivus\Tests\Support\RecordingLogger;
use Missivus\WpMailParser;
use Solvetus\Missivus\Exception\GraphException;

class WpMailParserTest extends MissivusTestCase {

	private function parse( array $atts, ?RecordingLogger $logger = null ) {
		$parser = new WpMailParser( null === $logger ? new RecordingLogger() : $logger );

		return $parser->parse( array_merge( $this->atts(), $atts ) );
	}

	public function testRecipientsAsACommaStringWithDisplayNames() {
		$message = $this->parse( array( 'to' => 'plain@example.org, "Jane Doe" <jane@example.org>' ) );

		$to = $message->getTo();
		$this->assertCount( 2, $to );
		$this->assertSame( 'plain@example.org', $to[0]['address'] );
		$this->assertSame( 'jane@example.org', $to[1]['address'] );
		$this->assertSame( 'Jane Doe', $to[1]['name'] );
	}

	public function testRecipientsAsAnArray() {
		$message = $this->parse( array( 'to' => array( 'a@example.org', 'B <b@example.org>' ) ) );

		$to = $message->getTo();
		$this->assertCount( 2, $to );
		$this->assertSame( 'b@example.org', $to[1]['address'] );
		$this->assertSame( 'B', $to[1]['name'] );
	}

	public function testAnUnknownHeaderIsDroppedWithAWarningNamingIt() {
		$logger  = new RecordingLogger();
		$message = $this->parse( array( 'headers' => "X-Campaign-Id: 42\r\nCc: c@example.org" ), $logger );

		$this->assertCount( 1, $message->getCc() );
		$this->assertStringContainsString( 'x-campaign-id', implode( ' ', $logger->warnings ) );
	}

	public function testAttachmentsAsANewlineString() {
		$path_a = tempnam( sys_get_temp_dir(), 'missivus' );
		$path_b = tempnam( sys_get_temp_dir(), 'missivus' );
		file_put_contents( $path_a, 'aaa' );
		file_put_contents( $path_b, 'bbb' );

		$message = $this->parse( array( 'attachments' => $path_a . "\n" . $path_b ) );

		unlink( $path_a );
		unlink( $path_b );

		$attachments = $message->getAttachments();
		$this->assertCount( 2, $attachments );
		$this->assertSame( 'aaa', $attachments[0]->getBytes() );
		$this->assertSame( basename( $path_b ), $attachments[1]->getName() );
		$this->assertSame( 'application/octet-stream', $attachments[0]->getMimeType(), 'no extension means the generic type' );
	}

	public function testAnUnreadableAttachmentPathFailsTheSendLoudly() {
		$this->expectException( GraphException::class );
		$this->expectExceptionMessage( 'not readable' );

		$this->parse( array( 'attachments' => array( '/nonexistent/path/report.pdf' ) ) );
	}

	public function testABodyIsPlainTextUnlessTheContentTypeSaysOtherwise() {
		$message = $this->parse( array( 'message' => 'plain words' ) );

		$this->assertSame( 'plain words', $message->getTextBody() );
		$this->assertSame( '', $message->getHtmlBody() );
	}
}
