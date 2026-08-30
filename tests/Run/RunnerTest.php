<?php
/**
 * Runner::tick() tests — the full S4 algorithm against a mocked gateway
 * (stage-6 failable check).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use SenroFlux_Test_Fake_Ability;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class RunnerTest extends TestCase {

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
		$GLOBALS['senroflux_test_abilities']       = array(
			'agsafe-smoke/spend'   => new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/spend' ),
			'agsafe-smoke/blocked' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/blocked',
				permission_result: new WP_Error(
					'approval_required',
					'requires approval',
					array(
						'status'      => 202,
						'verb'        => 'agsafe-smoke/blocked',
						'tier'        => 2,
						'approval_id' => 'apr_park1',
					)
				),
			),
		);
		// The S7 plan fence is separately tested in PlanParkTest. These tests
		// exercise the approval/park mechanics with fence-free (tier-0) verbs.
		add_filter(
			'senroflux_verb_map',
			static fn (): array => array(
				'agsafe-smoke/spend'   => 0,
				'agsafe-smoke/blocked' => 0,
				'agsafe-smoke/read'    => 0,
				'other-plugin/refund'  => 0,
			),
			10,
			0
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
	}


	private function createRun(): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Clear the cache',
			array( 'agsafe-smoke/*' ),
			Budget::defaults()
		);
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn(
			new ModelMessage( array( new MessagePart( $text ) ) ),
			10,
			5
		);
	}

	private static function callTurn( string $function_name, array $args ): ModelTurn {
		return new ModelTurn(
			new ModelMessage(
				array(
					new MessagePart( 'Working on it…' ),
					new MessagePart( new FunctionCall( 'call_x', $function_name, $args ) ),
				)
			),
			10,
			5
		);
	}

	public function test_optimistic_lock_rejects_stale_step_count(): void {
		$run_id = $this->createRun();

		$result = $this->runner->tick( $run_id, 99, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_conflict', $result->get_error_code() );
		$this->assertCount( 0, $this->gateway->calls, 'a stale echo must never reach the model' );
	}

	public function test_lock_transient_blocks_concurrent_tick_and_is_released(): void {
		$run_id = $this->createRun();

		set_transient( 'senroflux_lock_' . $run_id, 1, 30 );
		$result = $this->runner->tick( $run_id, 0, null );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_conflict', $result->get_error_code() );

		delete_transient( 'senroflux_lock_' . $run_id );
		unset( $GLOBALS['senroflux_test_transients'][ 'senroflux_lock_' . $run_id ] );
	}

	public function test_terminal_run_returns_state_without_model_calls(): void {
		$run_id = $this->createRun();
		$this->store->updateRun(
			$run_id,
			array( 'status' => \Specflux\SenroFlux\Run\RunStatus::Cancelled->value )
		);

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'cancelled', $result['run']['status'] );
		$this->assertCount( 0, $this->gateway->calls );
	}

	public function test_first_tick_seeds_goal_and_completes_on_text_only_turn(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'All done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 1, $this->gateway->calls );

		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'user', 'model' ), $kinds );

		// The goal was persisted as the first user message.
		$this->assertSame( 'Clear the cache', $result['new_steps'][0]['message']['parts'][0]['text'] ?? '' );
	}

	/**
	 * SF-BUG-1, found by the live proof bar (run 43).
	 *
	 * `Plugin::start()` appends an `allow_from_pack` system note whenever a
	 * pack derived the allow-list AND the caller also passed one — which the
	 * Runs screen ALWAYS does, because ConsumerPolicy refuses an empty allow.
	 * A seed guard of "the steps table is empty" therefore skipped the goal on
	 * every screen-started pack run, and the first model call went out with an
	 * empty history (`model_error "Cannot create a message from an empty
	 * array."`). The guard is "no `user` step".
	 */
	public function test_first_tick_seeds_goal_when_start_wrote_a_system_note_first(): void {
		$run_id = $this->createRun();
		$this->store->appendSystemNote(
			$run_id,
			array(
				'note'          => 'allow_from_pack',
				'pack'          => 'pages',
				'ignored_allow' => array( 'senroflux/*' ),
			)
		);

		$this->gateway->script[] = self::textTurn( 'All done.' );

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'user', 'model' ), array_column( $result['new_steps'], 'kind' ) );
		$this->assertSame( 'Clear the cache', $result['new_steps'][0]['message']['parts'][0]['text'] ?? '' );

		// The thing that actually broke: the first prompt carried the goal.
		$this->assertCount( 1, $this->gateway->calls );
		$this->assertSame( 1, $this->gateway->calls[0]['history_count'], 'the first model call must not be sent with an empty history' );
	}

	/** A user step already present is never seeded twice. */
	public function test_goal_is_not_re_seeded_when_a_user_step_exists(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'First.' );
		$this->runner->tick( $run_id, 0, null );

		$this->store->updateRun( $run_id, array( 'status' => \Specflux\SenroFlux\Run\RunStatus::Running->value ) );
		$this->gateway->script[] = self::textTurn( 'Second.' );
		$run                     = $this->store->getRun( $run_id );
		$this->runner->tick( $run_id, (int) $run->stepCount, null );

		$users = array_filter(
			$this->store->getSteps( $run_id ),
			static fn ( $step ): bool => StepKind::User === $step->kind
		);
		$this->assertCount( 1, $users );
	}

	public function test_crash_resume_executes_unconsumed_calls_without_a_new_model_turn(): void {
		// Simulate a crash after the model step: user + model(with call), no result.
		$run_id = $this->createRun();
		$this->store->appendStep( $run_id, StepKind::User, ( new UserMessage( array( new MessagePart( 'Clear the cache' ) ) ) )->toArray() );
		$this->store->appendStep( $run_id, StepKind::Model, self::callTurn( 'wpab__agsafe-smoke__spend', array( 'amount' => 25 ) )->message->toArray() );

		// After the pending call drains, the loop makes the NEXT model turn,
		// whose text-only reply completes the run.
		$this->gateway->script[] = self::textTurn( 'Cache cleared.' );

		$result = $this->runner->tick( $run_id, 2, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 1, $this->gateway->calls, 'exactly ONE new model turn after the pending call drained' );

		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'tool_result', 'model' ), $kinds, 'pending call drains, then the next model turn runs in the same tick' );
	}

	public function test_park_on_approval_required_sets_awaiting_approval_and_ui_payload(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_approval', $result['run']['status'] );
		$this->assertSame( 'apr_park1', $result['ui']['approval']['approval_id'] ?? '' );
		$this->assertSame( 'agsafe-smoke/blocked', $result['ui']['approval']['verb'] ?? '' );
		$this->assertStringContainsString( 'agent-safety-pending', (string) ( $result['ui']['approval']['review_url'] ?? '' ) );

		// A parked run without an action simply REMAINS parked (S6: the
		// consumer polls by ticking until the grant exists).
		$again = $this->runner->tick( $run_id, $result['run']['step_count'], null );
		$this->assertIsArray( $again );
		$this->assertSame( 'awaiting_approval', $again['run']['status'] );
		$this->assertSame( 'apr_park1', $again['ui']['approval']['approval_id'] ?? '' );
	}

	public function test_approve_resume_runs_the_parked_call_and_completes(): void {
		// Park first.
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->calls = array();

		// After "approval", the gate lets the call through.
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] =
			new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', permission_result: true );

		$this->gateway->script[] = self::textTurn( 'Cache cleared.' );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'action' => 'approve' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( 'Cache cleared.', $result['new_steps'][1]['message']['parts'][0]['text'] ?? '' );
		$this->assertTrue( $this->bridge->approvals['apr_park1'] ?? false, 'the bridge must grant via Agent Safety' );

		// The parked call itself must have run (not degraded to unknown_tool).
		$this->assertSame( 'tool_result', $result['new_steps'][0]['kind'] );
		$this->assertSame( 'ok', $result['new_steps'][0]['status'], 'resumed call executes against the mapped ability name' );
		$this->assertSame( 'wpab__agsafe-smoke__blocked', $result['new_steps'][0]['tool_name'] );
	}

	public function test_call_outside_allow_list_is_unknown_tool_and_never_executed(): void {
		$run_id              = $this->createRun(); // allow: agsafe-smoke/*
		$outside             = new SenroFlux_Test_Fake_Ability( 'other-plugin/refund', permission_result: true );
		$outside->on_execute = static function (): void {
			throw new \RuntimeException( 'must never execute' );
		};
		$GLOBALS['senroflux_test_abilities']['other-plugin/refund'] = $outside;

		$this->gateway->script[] = self::callTurn( 'wpab__other-plugin__refund', array( 'order' => 7 ) );
		$this->gateway->script[] = self::textTurn( 'Could not do that.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'user', 'model', 'tool_result', 'model' ), $kinds );
		$this->assertSame( 'error', $result['new_steps'][2]['status'] );
		$this->assertSame( array( 'error' => 'other-plugin/refund' ), $result['new_steps'][2]['message']['parts'][0]['functionResponse']['response'] ?? null, 'unknown_tool outcome, executor never reached' );
	}

	public function test_reject_resume_writes_rejected_by_user_result(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->calls    = array();
		$this->gateway->script[] = self::textTurn( 'Understood — not touching it.' );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'action' => 'reject' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		$rejected = $result['new_steps'][0] ?? array();
		$this->assertSame( 'tool_result', $rejected['kind'] ?? '' );
		$this->assertSame( 'rejected', $rejected['status'] ?? '' );
		$this->assertSame(
			'rejected_by_user',
			$rejected['message']['parts'][0]['functionResponse']['response']['error'] ?? ''
		);
		$this->assertSame( array(), $this->bridge->approvals, 'a reject never grants anything' );
	}

	public function test_budget_exhaustion_fails_the_run_with_budget_exceeded(): void {
		$run_id = $this->createRun();
		// Drive step_count right up to the shipped max_steps, whatever it is:
		// this test is about the ceiling being ENFORCED, not about its value.
		$max_steps = Budget::defaults()['max_steps'];
		for ( $i = 0; $i < $max_steps; ++$i ) {
			$this->store->appendStep( $run_id, StepKind::System, null );
		}
		$this->gateway->script[] = self::textTurn( 'never reached' );

		$result = $this->runner->tick( $run_id, $max_steps, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'budget_exceeded', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 0, $this->gateway->calls );
	}

	public function test_foreign_owner_is_forbidden(): void {
		$run_id                                    = $this->createRun(); // owned by user 1
		$GLOBALS['senroflux_test_current_user_id'] = 2;

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_forbidden', $result->get_error_code() );
	}
}
