<?php
/**
 * Runner tests for The run's pinned (provider, model) pair (or null
 * for automatic) is passed to the gateway on every model call, unchanged
 * across ticks.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use wpdb;

final class ModelPinRunnerTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, $this->bridge );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	public function test_the_pinned_pair_is_passed_to_the_gateway_on_every_tick(): void {
		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Write a post',
			array( '*' ),
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::AgentSafety,
			array(),
			null,
			'openai',
			'gpt-4o'
		);

		$this->gateway->script[] = self::textTurn( 'First turn.' );
		$this->gateway->script[] = self::textTurn( 'First turn.' );
		$this->runner->tick( $run_id, 0, null );

		// A plain text turn with no tool calls finishes the run; force it back
		// to running so a second tick still reaches the gateway (mirrors
		// RunnerTest::test_goal_is_not_re_seeded_when_a_user_step_exists()).
		$this->store->updateRun( $run_id, array( 'status' => \Specflux\SenroFlux\Run\RunStatus::Running->value ) );
		$this->gateway->script[] = self::textTurn( 'Second turn.' );
		$this->gateway->script[] = self::textTurn( 'Second turn.' );
		$run                     = $this->store->getRun( $run_id );
		$this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertSame(
			array(
				array( 'openai', 'gpt-4o' ),
				array( 'openai', 'gpt-4o' ),
				array( 'openai', 'gpt-4o' ),
			),
			$this->gateway->modelPreferences
		);
	}

	public function test_an_automatic_run_passes_null_to_the_gateway(): void {
		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Write a post',
			array( '*' ),
			Budget::defaults()
		);

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, 0, null );

		$this->assertSame( array( null, null ), $this->gateway->modelPreferences );
	}
}
