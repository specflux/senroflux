<?php
/**
 * AiClientMediaGateway timeout tests (defect 2, live run evidence): image
 * generation routinely takes 30-90s but the AI Client's HTTP call had no
 * per-request timeout set, so it inherited WordPress's ~30s default and
 * failed with "cURL error 28: Operation timed out after 30002 milliseconds".
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Model;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\AiClientMediaGateway;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

final class AiClientMediaGatewayTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_prompt_builder_calls']  = array();
		$GLOBALS['senroflux_test_prompt_builder_script'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['senroflux_test_prompt_builder_calls'], $GLOBALS['senroflux_test_prompt_builder_script'] );
	}

	public function test_generate_image_sets_a_120_second_request_timeout(): void {
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () {
			return new class() {
				public function saveToDisk(): array {
					return array(
						'path'     => '/tmp/image.png',
						'filename' => 'image.png',
					);
				}
			};
		};

		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateImage( 'a red bicycle' );

		$this->assertIsArray( $result );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );
		/** @var RequestOptions|null $options */
		$options = $calls[0]['request_options'] ?? null;
		$this->assertInstanceOf( RequestOptions::class, $options, 'generate-image must set a request timeout, not rely on the WP HTTP default' );
		$this->assertSame( 120.0, $options->getTimeout(), 'image generation routinely takes 30-90s; the default (~30s) is what failed live' );
	}

	public function test_generate_alt_text_sets_a_60_second_request_timeout(): void {
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () {
			return new class() {
				public function toMessage(): Message {
					return new UserMessage( array( new MessagePart( 'A red bicycle.' ) ) );
				}
			};
		};

		$gateway = new AiClientMediaGateway();
		$result  = $gateway->generateAltText( 'https://example.test/image.png' );

		$this->assertSame( 'A red bicycle.', $result );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );
		/** @var RequestOptions|null $options */
		$options = $calls[0]['request_options'] ?? null;
		$this->assertInstanceOf( RequestOptions::class, $options );
		$this->assertSame( 60.0, $options->getTimeout(), 'alt text is a short text call: a shorter, still-explicit timeout' );
	}
}
