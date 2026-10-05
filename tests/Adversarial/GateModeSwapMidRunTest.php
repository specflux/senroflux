<?php
/**
 * S22 gate robustness suite: a gate-mode swap between two ticks of the SAME
 * run (Agent Safety activated/deactivated mid-run) must FAIL the run, never
 * silently continue under the new mode.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Approval\GrantBridge;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tests\Run\FakeGateway;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use wpdb;

final class GateModeSwapMidRunTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private GateMode $currentMode = GateMode::BuiltIn;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->currentMode     = GateMode::BuiltIn;

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new ApprovalBridge(),
			null,
			null,
			null,
			null,
			null,
			new GrantBridge(),
			null,
			fn (): GateMode => $this->currentMode
		);

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_agent_safety']    = null;
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	public function test_the_environment_swapping_from_built_in_to_agent_safety_mid_run_fails_the_run(): void {
		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Do something spanning two ticks',
			array( 'adversarial/*' ),
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::BuiltIn
		);

		$this->gateway->script[] = self::textTurn( 'Working…' );
		$this->gateway->script[] = self::textTurn( 'Working…' );
		$first                   = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'completed', $first['run']['status'] );

		// Re-open it: a completed run has nothing left to govern, so give
		// the mismatch check something live to fail.
		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Running->value ) );

		// Agent Safety switches on between ticks: the environment no longer
		// agrees with the run's pinned built-in mode.
		$this->currentMode = GateMode::AgentSafety;

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'], 'a mid-run gate-mode swap must fail the run, not continue under it' );
		$this->assertArrayHasKey( 'report', $result['ui'] ?? array() );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( 'gate_mode_changed', $fresh->error['code'] ?? null );
	}

	public function test_the_environment_swapping_from_agent_safety_to_built_in_mid_run_also_fails_the_run(): void {
		$this->currentMode = GateMode::AgentSafety;
		$run_id            = $this->store->createRun(
			1,
			'test-consumer',
			'Do something spanning two ticks',
			array( 'adversarial/*' ),
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::AgentSafety
		);

		$this->gateway->script[] = self::textTurn( 'Working…' );
		$this->gateway->script[] = self::textTurn( 'Working…' );
		$first                   = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'completed', $first['run']['status'] );

		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Running->value ) );

		// Agent Safety goes away (deactivated, or its check starts failing)
		// mid-run: the environment falls back to built-in.
		$this->currentMode = GateMode::BuiltIn;

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( 'gate_mode_changed', $fresh->error['code'] ?? null );
	}
}
