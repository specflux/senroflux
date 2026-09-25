<?php
/**
 * Runner::tick() tests for 0.3 S3's gate mode: resolution, the mismatch
 * check, and the built-in gate's end-to-end park/approve loop.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use SenroFlux_Test_Fake_Ability;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class GateModeTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	/** Mutable so a single test can flip the environment's mode mid-flight. */
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
			// Real bridge: `agent_safety()` defaults to null in bare PHPUnit
			// (tests/stubs/agent-safety.php), so isAvailable() is false here —
			// exactly the "Agent Safety absent" state built-in mode assumes.
			new ApprovalBridge(),
			null,
			null,
			null,
			null,
			null,
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			null,
			fn (): GateMode => $this->currentMode
		);

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_agent_safety']    = null;
		$GLOBALS['senroflux_test_abilities']       = array(
			'agsafe-smoke/read'  => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/read', execute_result: array( 'ok' => true ) ),
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/write', execute_result: array( 'ok' => true ) ),
		);

		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'agsafe-smoke/read'  => VerbTier::TIER_0,
				'agsafe-smoke/write' => VerbTier::TIER_1,
				// agsafe-smoke/unmapped deliberately absent: fails closed to tier 2.
			),
			10,
			1
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
	}

	private function createRun( GateMode $gate_mode = GateMode::BuiltIn ): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Clear the cache',
			array( 'agsafe-smoke/*' ),
			Budget::defaults(),
			null,
			null,
			null,
			$gate_mode
		);
	}

	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	private static function textTurn( string $text ): ModelTurn {
		return self::turn( new MessagePart( $text ) );
	}

	/**
	 * @param array<string,mixed> $args Call arguments.
	 */
	private static function callTurn( string $call_id, string $tool, array $args = array() ): ModelTurn {
		return self::turn( new MessagePart( new FunctionCall( $call_id, $tool, $args ) ) );
	}

	private static function planTurn( string $call_id, array $verbs ): ModelTurn {
		return self::turn(
			new MessagePart(
				new FunctionCall(
					$call_id,
					PlanTools::FUNCTION_NAME,
					array(
						'goal'        => 'Clear the cache',
						'steps'       => array(
							array(
								'text'  => 'Write it',
								'verbs' => $verbs,
							),
						),
						'assumptions' => array(),
					)
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// Tier-0 reads never park, in built-in mode, without Agent Safety
	// ------------------------------------------------------------------

	public function test_a_tier_zero_read_completes_without_parking_in_built_in_mode(): void {
		$run_id = $this->createRun( GateMode::BuiltIn );

		$this->gateway->script[] = self::callTurn( 'call_1', 'agsafe-smoke/read' );
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertNotContains( 'approval', array_column( $result['new_steps'], 'kind' ) );
	}

	// ------------------------------------------------------------------
	// Tier-1+ (and unmapped) calls park in built-in mode, before any grant
	// ------------------------------------------------------------------

	public function test_a_tier_one_write_parks_in_built_in_mode_and_the_approved_resume_executes_it(): void {
		$run_id = $this->createRun( GateMode::BuiltIn );

		// Plan first (S7 fence): a Tier-1+ verb needs an accepted plan.
		$this->gateway->script[] = self::planTurn( 'call_p', array( 'agsafe-smoke/write' ) );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		// Accepting the plan re-enters the drive loop in the SAME tick, so the
		// write call's turn must already be queued before this call.
		$run                     = $this->store->getRun( $run_id );
		$this->gateway->script[] = self::callTurn( 'call_w', 'agsafe-smoke/write' );
		$result                  = $this->runner->tick( $run_id, (int) $run->stepCount, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_approval', $result['run']['status'] );
		$approval_id = $result['ui']['approval']['approval_id'] ?? '';
		$this->assertStringStartsWith( 'builtin:', (string) $approval_id, 'built-in parks carry a synthetic, non-Agent-Safety id' );

		// Approve on SenroFlux's own screen resolves it — the SAME tick path.
		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, array( 'action' => 'approve' ) );

		$this->assertIsArray( $result );
		$this->assertNotSame( 'awaiting_approval', $result['run']['status'], 'the approved call must not re-park itself' );
	}

	public function test_an_unmapped_verb_parks_in_built_in_mode(): void {
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/unmapped'] = new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/unmapped' );

		$run_id = $this->createRun( GateMode::BuiltIn );

		$this->gateway->script[] = self::planTurn( 'call_p', array( 'agsafe-smoke/unmapped' ) );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$run                     = $this->store->getRun( $run_id );
		$this->gateway->script[] = self::callTurn( 'call_u', 'agsafe-smoke/unmapped' );
		$result                  = $this->runner->tick( $run_id, (int) $run->stepCount, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_approval', $result['run']['status'], 'an unmapped verb fails closed to a park, never a free pass' );
	}

	// ------------------------------------------------------------------
	// A gate-mode flip between ticks fails the run (0.3 S3)
	// ------------------------------------------------------------------

	public function test_a_gate_mode_flip_between_ticks_fails_the_run_with_a_partial_report(): void {
		$run_id = $this->createRun( GateMode::BuiltIn );

		$this->gateway->script[] = self::textTurn( 'Working…' );
		$in_progress             = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'completed', $in_progress['run']['status'] );

		// A run that already completed has nothing left to govern; re-open it
		// to running so the mismatch check has something live to fail.
		$this->store->updateRun( $run_id, array( 'status' => \Specflux\SenroFlux\Run\RunStatus::Running->value ) );

		// Agent Safety is now (or again) active: the environment no longer
		// agrees with the run's pinned built-in mode.
		$this->currentMode = GateMode::AgentSafety;

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertArrayHasKey( 'report', $result['ui'] ?? array(), 'a mismatch fails with a partial report' );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( 'gate_mode_changed', $fresh->error['code'] ?? null );
	}
}
