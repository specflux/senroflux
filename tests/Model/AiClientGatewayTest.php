<?php
/**
 * AiClientGateway tests.
 *
 * Defect 2 (live run evidence, same root cause as AiClientMediaGatewayTest's
 * defect 2): with no per-request timeout set, a run's model turn inherited
 * WordPress core's own AI Client default (~30s) and failed outright on
 * reasoning models with large contexts — "cURL error 28: Operation timed out
 * after 30002 milliseconds with 0 bytes received". These tests assert the
 * timeout is wired through, default and filtered.
 *
 * Also covers the model-preference seam: `using_model_preference()` is a
 * PREFERENCE, not a mandate — when a run has no chosen model the gateway
 * must stay byte-identical to today (never call it at all); when a run
 * chose one, the gateway must pass the `[provider, model]` tuple through
 * untouched.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Model;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\AiClientGateway;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Run;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use wpdb;

final class AiClientGatewayTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_abilities']             = array();
		$GLOBALS['senroflux_test_prompt_builder_calls']  = array();
		$GLOBALS['senroflux_test_prompt_builder_script'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['senroflux_test_abilities'],
			$GLOBALS['senroflux_test_prompt_builder_calls'],
			$GLOBALS['senroflux_test_prompt_builder_script']
		);
		remove_all_filters( 'senroflux_model_request_timeout' );
	}

	private function registry(): ToolRegistry {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$store           = new WpdbRunStore( $db );
		$run_id          = $store->createRun( 1, 'test-consumer', 'Publish three pages', array(), Budget::defaults() );

		return ToolRegistry::forRun( $store->getRun( $run_id ) );
	}

	private function emptyTools(): ToolRegistry {
		return ToolRegistry::forRun(
			new Run(
				id: 1,
				userId: 1,
				consumer: 'test-consumer',
				goal: 'goal',
				status: RunStatus::Pending,
				allow: array(),
				budget: Budget::defaults()
			)
		);
	}

	private function scriptOneTextResult(): void {
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () {
			return new class() {
				public function toMessage(): Message {
					return new UserMessage( array( new MessagePart( 'hi' ) ) );
				}
			};
		};
	}

	/** A minimal stand-in for the AI Client's GenerativeAiResult. */
	private static function fakeResult(): object {
		return new class() {
			public function getTokenUsage(): object {
				return new class() {
					public function getPromptTokens(): int {
						return 12;
					}
					public function getCompletionTokens(): int {
						return 6;
					}
				};
			}
			public function toMessage(): ModelMessage {
				return new ModelMessage( array( new MessagePart( 'ok' ) ) );
			}
		};
	}

	private function history(): array {
		return array( new UserMessage( array( new MessagePart( 'hi' ) ) ) );
	}

	public function test_request_carries_the_default_120_second_timeout(): void {
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static fn () => self::fakeResult();

		$gateway = new AiClientGateway();
		$turn    = $gateway->generateTurn( $this->history(), 'system', $this->registry() );

		$this->assertInstanceOf( ModelTurn::class, $turn );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );
		$this->assertInstanceOf( RequestOptions::class, $calls[0]['request_options'] );
		$this->assertSame( 120.0, $calls[0]['request_options']->getTimeout() );
	}

	public function test_filter_overrides_the_timeout(): void {
		add_filter( 'senroflux_model_request_timeout', static fn (): float => 45.0 );
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static fn () => self::fakeResult();

		$gateway = new AiClientGateway();
		$gateway->generateTurn( $this->history(), 'system', $this->registry() );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertSame( 45.0, $calls[0]['request_options']->getTimeout() );
	}

	public function test_non_numeric_filter_value_keeps_the_default(): void {
		add_filter( 'senroflux_model_request_timeout', static fn (): string => 'not-a-number' );
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static fn () => self::fakeResult();

		$gateway = new AiClientGateway();
		$gateway->generateTurn( $this->history(), 'system', $this->registry() );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertSame( 120.0, $calls[0]['request_options']->getTimeout() );
	}

	public function test_negative_filter_value_keeps_the_default(): void {
		add_filter( 'senroflux_model_request_timeout', static fn (): float => -5.0 );
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static fn () => self::fakeResult();

		$gateway = new AiClientGateway();
		$gateway->generateTurn( $this->history(), 'system', $this->registry() );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertSame( 120.0, $calls[0]['request_options']->getTimeout() );
	}

	/**
	 * The AI Client HTTP exception's status code (ServerException/
	 * ClientException) rides along as WP_Error data so the Runner can tell a
	 * transient failure from a fatal one without parsing prose.
	 */
	public function test_a_thrown_exception_carries_its_code_as_error_data(): void {
		$GLOBALS['senroflux_test_prompt_builder_script'][] = static function () {
			throw new \RuntimeException(
				'Network error occurred while sending POST request to https://api.openai.com/v1/responses: cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received',
				0
			);
		};

		$gateway = new AiClientGateway();
		$result  = $gateway->generateTurn( $this->history(), 'system', $this->registry() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'gateway_failed', $result->get_error_code() );
		$this->assertSame( array( 'status' => 0 ), $result->get_error_data() );
	}

	public function test_no_preference_never_calls_using_model_preference(): void {
		$this->scriptOneTextResult();

		$gateway = new AiClientGateway();
		$result  = $gateway->generateTurn( array(), 'system', $this->emptyTools() );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );
		$this->assertNull( $calls[0]['model_preference'], 'byte-identical to today: automatic means the call is never made at all' );
	}

	public function test_a_preference_is_passed_through_as_the_provider_model_tuple(): void {
		$this->scriptOneTextResult();

		$gateway = new AiClientGateway();
		$result  = $gateway->generateTurn( array(), 'system', $this->emptyTools(), array( 'openai', 'gpt-4o' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'openai', 'gpt-4o' ), $calls[0]['model_preference'] );
	}

	public function test_system_instruction_is_always_set(): void {
		$this->scriptOneTextResult();

		$gateway = new AiClientGateway();
		$gateway->generateTurn( array(), 'be nice', $this->emptyTools() );

		$calls = $GLOBALS['senroflux_test_prompt_builder_calls'];
		$this->assertSame( 'be nice', $calls[0]['system_instruction'] );
	}
}
