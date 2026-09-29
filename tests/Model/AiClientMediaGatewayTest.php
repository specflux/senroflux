<?php
/**
 * AiClientMediaGateway tests.
 *
 * Defect A (live run): `generate-image` always failed with "The image
 * provider returned an unexpected result." because the gateway checked the
 * AI Client result for an invented `saveToDisk()` method. The real terminal
 * accessor is `GenerativeAiResult::toImageFile(): File`
 * (vendor/wordpress/php-ai-client/src/Results/DTO/GenerativeAiResult.php:257);
 * `File::isInline()`/`isRemote()`/`getBase64Data()`/`getUrl()`/`getMimeType()`
 * (vendor/wordpress/php-ai-client/src/Files/DTO/File.php) are the real
 * accessors used to persist the image into uploads.
 *
 * The alt-text half of defect A: the prompt embedded the image URL as TEXT
 * instead of attaching the image, so the model never saw it. The real
 * attachment call is `PromptBuilder::withFile()`
 * (vendor/wordpress/php-ai-client/src/Builders/PromptBuilder.php:160), proxied
 * through the WP wrapper's snake_case `with_file()`.
 *
 * Defect 2 (live run, separate fix already landed): image generation
 * routinely takes 30-90s; with no per-request timeout set, the AI Client's
 * HTTP call inherited WordPress's own HTTP API default (~30s) and failed
 * outright — "cURL error 28: Operation timed out after 30002 milliseconds".
 * These tests still assert the timeouts stay wired through the real fix.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Model;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\AiClientMediaGateway;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WP_Error;

final class AiClientMediaGatewayTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/stubs/media.php';

		$GLOBALS['senroflux_test_prompt_builder_calls']  = array();
		$GLOBALS['senroflux_test_prompt_builder_script'] = array();
		$GLOBALS['senroflux_test_download_url_calls']    = array();
		$GLOBALS['senroflux_test_download_url_response'] = null;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['senroflux_test_prompt_builder_calls'],
			$GLOBALS['senroflux_test_prompt_builder_script'],
			$GLOBALS['senroflux_test_download_url_calls'],
			$GLOBALS['senroflux_test_download_url_response']
		);
	}

	/**
	 * Defect A: on current (pre-fix) code this fails because the gateway
	 * requires an invented `saveToDisk()` method and returns
	 * "The image provider returned an unexpected result." for any real
	 * `GenerativeAiResult`-shaped object exposing the REAL `toImageFile()`
	 * accessor instead.
	 */
	public function test_generate_image_persists_an_inline_base64_file_to_uploads(): void {
		// A 1x1 transparent PNG, inline (base64) — a real File, real MIME type.
		$png_base64 = base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a real inline-File fixture, not obfuscating code.
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a real 1x1 PNG fixture, not obfuscating code.
		);
		$file       = new File( 'data:image/png;base64,' . $png_base64 );

		$this->assertTrue( $file->isInline(), 'sanity: the fixture must be a real inline File' );

		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () use ( $file ) {
			return new class( $file ) {
				public function __construct( private File $file ) {}
				public function toImageFile(): File {
					return $this->file;
				}
			};
		};

		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateImage( 'a red bicycle' );

		$this->assertIsArray( $result, 'expected a persisted {path, filename} array, got: ' . ( $result instanceof WP_Error ? $result->get_error_message() : 'n/a' ) );
		$this->assertFileExists( $result['path'] );
		$this->assertSame( 'png', pathinfo( $result['path'], PATHINFO_EXTENSION ) );
		$this->assertSame(
			base64_decode( $png_base64, true ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the fixture back for comparison, not obfuscating code.
			file_get_contents( $result['path'] ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test-uploads file, not a remote URL.
		);

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );
		$this->assertInstanceOf( RequestOptions::class, $calls[0]['request_options'] );
		$this->assertSame( 120.0, $calls[0]['request_options']->getTimeout(), 'image generation routinely takes 30-90s; the default (~30s) is what failed live' );
	}

	/**
	 * Defect A: the remote-file branch — the AI Client can also return a
	 * File backed by a URL rather than inline base64 data. The gateway must
	 * download it (via the real `download_url()`, stubbed here) rather than
	 * assume every result is inline.
	 */
	public function test_generate_image_downloads_a_remote_file_to_uploads(): void {
		$file = new File( 'https://provider.example/generated/abc123.png', 'image/png' );
		$this->assertTrue( $file->isRemote(), 'sanity: the fixture must be a real remote File' );

		$binary = 'not-really-a-png-but-a-real-byte-stream';
		$GLOBALS['senroflux_test_download_url_response'] = $binary;

		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () use ( $file ) {
			return new class( $file ) {
				public function __construct( private File $file ) {}
				public function toImageFile(): File {
					return $this->file;
				}
			};
		};

		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateImage( 'a red bicycle' );

		$this->assertIsArray( $result, 'expected a persisted {path, filename} array, got: ' . ( $result instanceof WP_Error ? $result->get_error_message() : 'n/a' ) );
		$this->assertFileExists( $result['path'] );
		$this->assertSame( $binary, file_get_contents( $result['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test-uploads file, not a remote URL.
		$this->assertSame( array( 'https://provider.example/generated/abc123.png' ), $GLOBALS['senroflux_test_download_url_calls'] );
	}

	public function test_generate_image_refuses_a_non_image_mime_type(): void {
		$file = new File( 'https://provider.example/generated/report.pdf', 'application/pdf' );

		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () use ( $file ) {
			return new class( $file ) {
				public function __construct( private File $file ) {}
				public function toImageFile(): File {
					return $this->file;
				}
			};
		};

		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateImage( 'a red bicycle' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unsupported_mime_type', $result->get_error_code() );
	}

	/**
	 * Defect A (alt-text half): on current (pre-fix) code the gateway never
	 * calls `with_file()` at all — it only puts the URL into the text
	 * prompt — so this assertion (that the image was actually attached)
	 * fails against the unfixed gateway.
	 *
	 * 0.3 quality fix 3 (live run): fixing THAT surfaced a second bug — a
	 * REMOTE `File` (built from the attachment's public URL) is exactly what
	 * fails from a site the model's provider cannot reach itself
	 * (localhost/staging/password-protected/intranet): "Bad Request (400) -
	 * Error while downloading file. Upstream status code: 407." The gateway
	 * must attach the image as INLINE (base64) data, read from a LOCAL file
	 * path — never a URL the provider has to fetch back.
	 */
	public function test_generate_alt_text_attaches_the_image_as_inline_data_not_a_url(): void {
		$path = tempnam( sys_get_temp_dir(), 'senroflux-alt-' ) . '.png'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_tempnam -- test fixture, not a WP runtime path.
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture, not a WP runtime path.
			$path,
			base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a real 1x1 PNG fixture, not obfuscating code.
		);

		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () {
			return new class() {
				public function toMessage(): Message {
					return new UserMessage( array( new MessagePart( 'A red bicycle.' ) ) );
				}
			};
		};

		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateAltText( $path );
		wp_delete_file( $path );

		$this->assertSame( 'A red bicycle.', $result );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );

		/** @var RequestOptions|null $options */
		$options = $calls[0]['request_options'] ?? null;
		$this->assertInstanceOf( RequestOptions::class, $options );
		$this->assertSame( 60.0, $options->getTimeout(), 'alt text is a short text call: a shorter, still-explicit timeout' );

		/** @var File|null $file */
		$file = $calls[0]['file'] ?? null;
		$this->assertInstanceOf( File::class, $file, 'the image must be attached as a real File part, not just named in the text prompt' );
		$this->assertTrue( $file->isInline(), 'a LOCAL path must attach inline (base64) data, never a remote URL the provider has to download itself' );
		$this->assertFalse( $file->isRemote() );
		$this->assertNotEmpty( $file->getBase64Data() );
	}

	public function test_generate_alt_text_refuses_clearly_when_the_file_is_missing(): void {
		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateAltText( sys_get_temp_dir() . '/senroflux-does-not-exist.png' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_file_missing', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_prompt_builder_calls'], 'a missing file must never reach the model call at all' );
	}
}
