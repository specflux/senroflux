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
use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\SuggestBriefTool;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use SenroFlux_Test_Fake_Ability;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
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
		Clock::reset();
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

	/**
	 * Live site run (2026-09-28): a tick ran for over 400 seconds, the 30-second
	 * lock expired, and a second tick for the same run was accepted. Two loops
	 * then wrote to one run: a duplicate page, and a parked call that never got
	 * a result, so the model provider refused the next turn ("No tool output
	 * found for function call"). The lock must stay held for the whole tick.
	 */
	public function test_the_tick_lock_is_held_for_every_model_call(): void {
		$run_id = $this->createRun();
		$key    = 'senroflux_lock_' . $run_id;
		$seen   = array();

		$this->gateway->script[] = self::callTurn( 'wpab__nope__missing', array() );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->gateway->onCall   = static function ( int $call ) use ( $key, &$seen ): void {
			$seen[ $call ] = array(
				'held' => false !== get_transient( $key ),
				'ttl'  => $GLOBALS['senroflux_test_transient_ttls'][ $key ] ?? 0,
			);
			// Simulate the transient expiring while the model is thinking.
			delete_transient( $key );
		};

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $seen );
		foreach ( $seen as $call => $lock ) {
			$this->assertTrue( $lock['held'], "the lock must be held during model call $call" );
			$this->assertGreaterThanOrEqual( 300, $lock['ttl'], "the lock must outlast one model call and one tool call (call $call)" );
		}
		$this->assertFalse( get_transient( $key ), 'the lock is released when the tick ends' );
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
		$this->gateway->script[] = self::textTurn( 'All done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 2, $this->gateway->calls );

		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'user', 'model', 'system', 'user', 'model' ), $kinds );

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
		$this->gateway->script[] = self::textTurn( 'All done.' );

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'user', 'model', 'system', 'user', 'model' ), array_column( $result['new_steps'], 'kind' ) );
		$this->assertSame( 'Clear the cache', $result['new_steps'][0]['message']['parts'][0]['text'] ?? '' );

		// The thing that actually broke: the first prompt carried the goal.
		$this->assertCount( 2, $this->gateway->calls );
		$this->assertSame( 1, $this->gateway->calls[0]['history_count'], 'the first model call must not be sent with an empty history' );
	}

	/** A user step already present is never seeded twice. */
	public function test_goal_is_not_re_seeded_when_a_user_step_exists(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'First.' );
		$this->gateway->script[] = self::textTurn( 'First.' );
		$this->runner->tick( $run_id, 0, null );

		$this->store->updateRun( $run_id, array( 'status' => \Specflux\SenroFlux\Run\RunStatus::Running->value ) );
		$this->gateway->script[] = self::textTurn( 'Second.' );
		$this->gateway->script[] = self::textTurn( 'Second.' );
		$run                     = $this->store->getRun( $run_id );
		$this->runner->tick( $run_id, (int) $run->stepCount, null );

		$users = array_filter(
			$this->store->getSteps( $run_id ),
			static fn ( $step ): bool => StepKind::User === $step->kind && 'Clear the cache' === ( $step->messageArray['parts'][0]['text'] ?? '' )
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
		$this->gateway->script[] = self::textTurn( 'Cache cleared.' );

		$result = $this->runner->tick( $run_id, 2, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertCount( 2, $this->gateway->calls, 'one new model turn after the pending call drained, then the no-write nudge turn' );

		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'tool_result', 'model', 'system', 'user', 'model' ), $kinds, 'pending call drains, then the next model turn runs in the same tick' );
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

	public function test_approve_after_the_agent_safety_request_expired_re_parks_on_the_new_approval(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$this->runner->tick( $run_id, 0, null ); // Parked on apr_park1.

		// apr_park1 expired in Agent Safety: approving it has no effect and
		// the re-run demands a FRESH approval, apr_park2.
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] = new SenroFlux_Test_Fake_Ability(
			'agsafe-smoke/blocked',
			permission_result: new WP_Error(
				'approval_required',
				'requires approval',
				array(
					'status'      => 202,
					'verb'        => 'agsafe-smoke/blocked',
					'tier'        => 2,
					'approval_id' => 'apr_park2',
				)
			)
		);

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'action' => 'approve' ) );

		$this->assertSame( 'awaiting_approval', $result['run']['status'] );
		$this->assertSame( 'apr_park2', $result['ui']['approval']['approval_id'] ?? '' );
		$this->assertStringContainsString( 'earlier approval request expired', (string) ( $result['ui']['approval']['notice'] ?? '' ) );

		$parked = $this->runner->parkedApprovalUi( $this->store->getRun( $run_id ) );
		$this->assertSame( 'apr_park2', $parked['approval_id'] ?? '' );
		$this->assertStringContainsString( 'expired', (string) ( $parked['notice'] ?? '' ), 'the note survives a reload' );

		// A bare poll re-surfaces the NEW approval with the note.
		$poll = $this->runner->tick( $run_id, $result['run']['step_count'], null );
		$this->assertSame( 'apr_park2', $poll['ui']['approval']['approval_id'] ?? '' );

		// The next Approve targets the new id, and this time it executes.
		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] =
			new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', permission_result: true );
		$this->gateway->script[]                                     = self::textTurn( 'Cache cleared.' );
		$this->gateway->script[]                                     = self::textTurn( 'Cache cleared.' );

		$again = $this->runner->tick( $run_id, $poll['run']['step_count'], array( 'action' => 'approve' ) );

		$this->assertTrue( $this->bridge->approvals['apr_park2'] ?? false, 'the bridge approves the NEW id' );
		$this->assertSame( 'completed', $again['run']['status'] );
		$this->assertSame( 'ok', $again['new_steps'][0]['status'] );
	}

	public function test_a_normal_approve_carries_no_expired_notice_and_adds_no_second_park(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$parked_tick             = $this->runner->tick( $run_id, 0, null );
		$this->assertArrayNotHasKey( 'notice', $parked_tick['ui']['approval'] );

		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] =
			new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', permission_result: true );
		$this->gateway->script[]                                     = self::textTurn( 'Done.' );
		$this->gateway->script[]                                     = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, $this->store->getRun( $run_id )->stepCount, array( 'action' => 'approve' ) );

		$approvals = array_filter( $this->store->getSteps( $run_id ), static fn ( $s ) => 'approval' === $s->kind->value );
		$this->assertCount( 1, $approvals );
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
		$this->gateway->script[] = self::textTurn( 'Could not do that.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'user', 'model', 'tool_result', 'model', 'system', 'user', 'model' ), $kinds );
		$this->assertSame( 'error', $result['new_steps'][2]['status'] );
		$error = (string) ( $result['new_steps'][2]['message']['parts'][0]['functionResponse']['response']['error'] ?? '' );
		$this->assertStringStartsWith( 'Unknown tool "wpab__other-plugin__refund". Call one of the tools you were given, by its exact name.', $error, 'unknown_tool outcome, executor never reached' );
	}

	private function allowStockSearch( string ...$abilities ): array {
		$executed = array();
		foreach ( $abilities as $name ) {
			$ability = new SenroFlux_Test_Fake_Ability(
				$name,
				execute_result: array( 'results' => array() ),
				output_schema: array(
					'type'       => 'object',
					'properties' => array( 'results' => array( 'type' => 'array' ) ),
				)
			);

			$ability->on_execute = static function ( $input ) use ( &$executed, $name ): void {
				$executed[] = array( $name, $input );
			};

			$GLOBALS['senroflux_test_abilities'][ $name ] = $ability;
		}
		remove_all_filters( 'senroflux_verb_map' );
		add_filter(
			'senroflux_verb_map',
			static fn (): array => array_fill_keys( $abilities, 0 ),
			10,
			0
		);

		return array( &$executed );
	}

	public function test_a_bare_tool_name_resolves_to_the_one_allowed_tool_and_is_stored_under_the_full_name(): void {
		$state  = $this->allowStockSearch( 'agsafe-smoke/stock-image-search' );
		$run_id = $this->createRun();

		$this->gateway->script[] = self::callTurn( 'stock-image-search', array( 'query' => 'desk stretch' ) );
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( array( array( 'agsafe-smoke/stock-image-search', array( 'query' => 'desk stretch' ) ) ), $state[0] );
		$this->assertSame( 'tool_result', $result['new_steps'][2]['kind'] );
		$this->assertSame( 'ok', $result['new_steps'][2]['status'] );
		$this->assertSame( 'wpab__agsafe-smoke__stock-image-search', $result['new_steps'][2]['tool_name'] );
		$names = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::ToolResult === $step->kind ) {
				$names[] = $step->toolName;
			}
		}
		$this->assertSame( array( 'wpab__agsafe-smoke__stock-image-search' ), $names );
	}

	public function test_an_ambiguous_bare_tool_name_is_refused_naming_the_exact_tools(): void {
		$state  = $this->allowStockSearch( 'agsafe-smoke/stock-image-search', 'agsafe-two/stock-image-search' );
		$run_id = $this->store->createRun( 1, 'test-consumer', 'Find a photo', array( 'agsafe-smoke/*', 'agsafe-two/*' ), Budget::defaults() );

		$this->gateway->script[] = self::callTurn( 'stock-image-search', array( 'query' => 'desk' ) );
		$this->gateway->script[] = self::textTurn( 'Could not.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $state[0], 'an ambiguous name must never execute' );
		$this->assertSame( 'error', $result['new_steps'][2]['status'] );
		$error = (string) ( $result['new_steps'][2]['message']['parts'][0]['functionResponse']['response']['error'] ?? '' );
		$this->assertStringStartsWith( 'Unknown tool "stock-image-search". Call one of the tools you were given, by its exact name.', $error );
		$this->assertStringContainsString( 'wpab__agsafe-smoke__stock-image-search', $error );
		$this->assertStringContainsString( 'wpab__agsafe-two__stock-image-search', $error );
	}

	public function test_an_unknown_tool_name_is_refused_with_a_helpful_message(): void {
		$run_id = $this->createRun();

		$this->gateway->script[] = self::callTurn( 'teleport-user', array() );
		$this->gateway->script[] = self::textTurn( 'Could not.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'error', $result['new_steps'][2]['status'] );
		$this->assertSame(
			array( 'error' => 'Unknown tool "teleport-user". Call one of the tools you were given, by its exact name.' ),
			$result['new_steps'][2]['message']['parts'][0]['functionResponse']['response'] ?? null
		);
	}

	public function test_reject_resume_writes_rejected_by_user_result(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->calls    = array();
		$this->gateway->script[] = self::textTurn( 'Understood — not touching it.' );
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

	/**
	 * Live evidence (2026-09-28 fix1 scenario-1-1, seq 26/27): space-bunny
	 * called `senroflux__senroflux__propose-plan` — a doubled `senroflux__`
	 * namespace prefix — and the harness answered with the mangled ability
	 * name as a cryptic `unknown_tool` error, wasting a turn. The doubled
	 * call must resolve to the SAME harness tool as the correctly-named
	 * call: it parks a plan exactly like `senroflux__propose-plan` would.
	 */
	public function test_a_doubled_senroflux_prefix_still_parks_the_plan(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn(
			'senroflux__senroflux__propose-plan',
			array(
				'goal'        => 'Clear the cache',
				'steps'       => array(
					array(
						'text'  => 'Spend it',
						'verbs' => array( 'agsafe-smoke/spend' ),
					),
				),
				'assumptions' => array(),
			)
		);

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_plan', $result['run']['status'], 'the doubled prefix must resolve to the propose-plan harness tool, not fall through to unknown_tool' );
		$this->assertSame( 'parked', $result['new_steps'][2]['status'] ?? null );
		$this->assertSame( PlanTools::toolName(), $result['new_steps'][2]['tool_name'] ?? null, 'the recorded step must carry the canonical tool name' );
	}

	/**
	 * A function name that is neither a real harness tool nor a doubled
	 * form of one (a hallucinated/mistyped name) must get a helpful error —
	 * naming the failure and listing the real available functions — instead
	 * of the old behaviour of echoing the mangled name back verbatim.
	 */
	public function test_a_genuinely_unknown_senroflux_function_gets_a_helpful_error(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'senroflux__do-a-barrel-roll', array() );
		$this->gateway->script[] = self::textTurn( 'Could not do that.' );
		$this->gateway->script[] = self::textTurn( 'Could not do that.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( 'error', $result['new_steps'][2]['status'] ?? null );

		$response = $result['new_steps'][2]['message']['parts'][0]['functionResponse']['response'] ?? array();
		$this->assertSame( 'unknown_function', $response['error'] ?? null );
		$this->assertIsString( $response['message'] ?? null );
		$this->assertStringContainsString( 'senroflux__do-a-barrel-roll', $response['message'] );
		$this->assertStringNotContainsString( 'senroflux/do-a-barrel-roll', $response['message'], 'must not echo a mangled ability-name round-trip' );
		$this->assertStringContainsString( PlanTools::functionName(), $response['message'], 'must list the real available functions' );
		$this->assertStringContainsString( HarnessTools::functionName(), $response['message'] );
		$this->assertStringContainsString( SuggestBriefTool::functionName(), $response['message'] );
	}

	/**
	 * Live evidence (2026-09-28 bunny1/bunny4): a run publishes its pages, then
	 * runs out of `max_tokens` while re-reading them to verify. The writes are
	 * real; the run must not report `failed`. "Writes finished" is read from
	 * data the Runner already keeps: an accepted plan whose every write-tier
	 * verb has a successful tool_result, and the most recent write among those
	 * did not fail unresolved.
	 */
	public function test_budget_exceeded_after_writes_finished_completes_with_unverified_note(): void {
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array( 'agsafe-smoke/write' => VerbTier::TIER_1 ),
			20,
			1
		);

		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Publish the page',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), array( 'max_tokens' => 100 ) )
		);
		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Publish the page' ) ) ) )->toArray()
		);

		// An accepted plan naming exactly one write verb.
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'G',
				'steps'       => array(
					array(
						'text'  => 'Write it',
						'verbs' => array( 'agsafe-smoke/write' ),
						'tier'  => 1,
					),
				),
				'assumptions' => array(),
			),
			PlanTools::toolName(),
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		// The write itself succeeded.
		$write_message              = ( new UserMessage(
			array( new MessagePart( new FunctionResponse( 'call_w', 'agsafe_smoke_write', array( 'ok' => true ) ) ) )
		) )->toArray();
		$write_message['plan_verb'] = 'agsafe-smoke/write';
		$this->store->appendStep( $run_id, StepKind::ToolResult, $write_message, 'wpab__agsafe-smoke__write', null, 'ok' );

		// Tokens already exceed the (tiny) budget: the NEXT tick trips it.
		$this->store->updateRun(
			$run_id,
			array(
				'tokens_in'  => 90,
				'tokens_out' => 20,
			)
		);

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'writes were finished: a budget hit during verification must not fail the run' );
		$this->assertCount( 0, $this->gateway->calls );
		$this->assertNull( $result['run']['error'] ?? null );

		$note = $result['ui']['report']['unverified'] ?? '';
		$this->assertIsString( $note );
		$this->assertStringContainsString( 'budget_exceeded', $note );
		$this->assertStringContainsString( 'max_tokens', $note );
	}

	/**
	 * Same shape as above, but the plan's write verb never produced a
	 * successful tool_result: today's failed/budget_exceeded behaviour must
	 * be unchanged.
	 */
	public function test_budget_exceeded_with_an_undone_plan_write_step_still_fails(): void {
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array( 'agsafe-smoke/write' => VerbTier::TIER_1 ),
			20,
			1
		);

		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Publish the page',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), array( 'max_tokens' => 100 ) )
		);
		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Publish the page' ) ) ) )->toArray()
		);

		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'G',
				'steps'       => array(
					array(
						'text'  => 'Write it',
						'verbs' => array( 'agsafe-smoke/write' ),
						'tier'  => 1,
					),
				),
				'assumptions' => array(),
			),
			PlanTools::toolName(),
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		// No tool_result for the write verb was ever recorded: the write never happened.
		$this->store->updateRun(
			$run_id,
			array(
				'tokens_in'  => 90,
				'tokens_out' => 20,
			)
		);

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'budget_exceeded', $result['run']['error']['code'] ?? '' );
		$this->assertSame( 'max_tokens', $result['run']['error']['which'] ?? '' );
	}

	/**
	 * Live run (2026-09-28 fix1 scenario-3-1): a 4-step plan — create draft;
	 * search/import media and set alt text; set the featured image; read,
	 * update, and publish — ran to completion but died at max_steps because
	 * the old writesFinished() required EVERY plan-named write verb to have
	 * an ok result. Two things never happened exactly as named: `update-alt`
	 * (the pack tells models to name every verb that might apply, so plans
	 * routinely name unused verbs) and `publish` (the successful tool_result
	 * is tagged `update-live`, not `publish` — an aliasing quirk). Both must
	 * be tolerated: each non-final write step only needs ONE of its named
	 * verbs to succeed, and the final write step accepts any tier >= 1 write
	 * after the prior step's write, not only its own named verbs.
	 */
	public function test_budget_exceeded_after_multi_step_plan_with_unused_and_aliased_write_verbs_completes(): void {
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'create-draft'       => VerbTier::TIER_1,
				'media-search'       => VerbTier::TIER_0,
				'media-stock-search' => VerbTier::TIER_0,
				'media-stock-import' => VerbTier::TIER_1,
				'update-alt'         => VerbTier::TIER_1,
				'read-media'         => VerbTier::TIER_0,
				'set-featured-image' => VerbTier::TIER_1,
				'read'               => VerbTier::TIER_0,
				'update-draft'       => VerbTier::TIER_1,
				'publish'            => VerbTier::TIER_1,
				'update-live'        => VerbTier::TIER_1,
			),
			20,
			1
		);

		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Publish the page with media',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), array( 'max_tokens' => 100 ) )
		);
		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Publish the page with media' ) ) ) )->toArray()
		);

		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'G',
				'steps'       => array(
					array(
						'text'  => 'Create the draft',
						'verbs' => array( 'create-draft' ),
					),
					array(
						'text'  => 'Add media',
						'verbs' => array( 'media-search', 'media-stock-search', 'media-stock-import', 'update-alt', 'read-media' ),
					),
					array(
						'text'  => 'Set the featured image',
						'verbs' => array( 'set-featured-image' ),
					),
					array(
						'text'  => 'Publish',
						'verbs' => array( 'read', 'update-draft', 'publish' ),
					),
				),
				'assumptions' => array(),
			),
			PlanTools::toolName(),
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		foreach ( array( 'create-draft', 'media-stock-import', 'set-featured-image', 'update-draft', 'update-live' ) as $i => $verb ) {
			$message              = ( new UserMessage(
				array( new MessagePart( new FunctionResponse( 'call_' . $i, 'wpab__agsafe-smoke__' . $verb, array( 'ok' => true ) ) ) )
			) )->toArray();
			$message['plan_verb'] = $verb;
			$this->store->appendStep( $run_id, StepKind::ToolResult, $message, 'wpab__agsafe-smoke__' . $verb, null, 'ok' );
		}

		$this->store->updateRun(
			$run_id,
			array(
				'tokens_in'  => 90,
				'tokens_out' => 20,
			)
		);

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'writes were finished despite an unused plan-named verb and a publish->update-live alias' );
		$this->assertNull( $result['run']['error'] ?? null );
	}

	/**
	 * A 3-page plan (create page A, create page B, create page C) died before
	 * its LAST write step ever attempted a write: only A and B were created.
	 * All three steps name the same verb, so this also proves writesFinished()
	 * cannot simply ask "does verb X have any ok result anywhere" — it must
	 * track each plan step's write against a distinct, unconsumed attempt.
	 */
	public function test_budget_exceeded_with_a_died_before_last_page_write_still_fails(): void {
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array( 'pages/create' => VerbTier::TIER_1 ),
			20,
			1
		);

		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Create three pages',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), array( 'max_tokens' => 100 ) )
		);
		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Create three pages' ) ) ) )->toArray()
		);

		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'G',
				'steps'       => array(
					array(
						'text'  => 'Create page A',
						'verbs' => array( 'pages/create' ),
					),
					array(
						'text'  => 'Create page B',
						'verbs' => array( 'pages/create' ),
					),
					array(
						'text'  => 'Create page C',
						'verbs' => array( 'pages/create' ),
					),
				),
				'assumptions' => array(),
			),
			PlanTools::toolName(),
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		foreach ( array( 'A', 'B' ) as $i => $page ) {
			$message              = ( new UserMessage(
				array( new MessagePart( new FunctionResponse( 'call_' . $page, 'wpab__agsafe-smoke__create', array( 'ok' => true ) ) ) )
			) )->toArray();
			$message['plan_verb'] = 'pages/create';
			$this->store->appendStep( $run_id, StepKind::ToolResult, $message, 'wpab__agsafe-smoke__create', null, 'ok' );
		}

		$this->store->updateRun(
			$run_id,
			array(
				'tokens_in'  => 90,
				'tokens_out' => 20,
			)
		);

		$run    = $this->store->getRun( $run_id );
		$result = $this->runner->tick( $run_id, (int) $run->stepCount, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'], 'page C was never written: the run died before its last write step' );
		$this->assertSame( 'budget_exceeded', $result['run']['error']['code'] ?? '' );
	}

	public function test_no_elapsed_gap_sentence_on_a_runs_first_tick(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'All done.' );

		$this->runner->tick( $run_id, 0, null );

		$this->assertStringNotContainsString( 'Resumed after', $this->gateway->systemInstructions[0] );
	}

	public function test_no_elapsed_gap_sentence_five_minutes_after_the_previous_step(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$this->runner->tick( $run_id, 0, null ); // Parks.

		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] =
			new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', permission_result: true );
		$this->gateway->script[]                                     = self::textTurn( 'Cache cleared.' );

		// Only 5 minutes pass before the resume tick: below the S9 threshold.
		Clock::useFixed( time() + ( 5 * MINUTE_IN_SECONDS ) );
		$before = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'action' => 'approve' ) );

		$this->assertStringNotContainsString( 'Resumed after', $this->gateway->systemInstructions[1] );
	}

	public function test_elapsed_gap_sentence_appears_after_a_26_hour_gap_since_the_run_was_parked(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::callTurn( 'wpab__agsafe-smoke__blocked', array( 'target' => 'prod-cache' ) );
		$this->runner->tick( $run_id, 0, null ); // Parks (e.g. left overnight).

		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] =
			new SenroFlux_Test_Fake_Ability( 'agsafe-smoke/blocked', permission_result: true );
		$this->gateway->script[]                                     = self::textTurn( 'Cache cleared.' );

		// 26 hours pass before the run is resumed — the S9 example gap.
		Clock::useFixed( time() + ( 26 * HOUR_IN_SECONDS ) );
		$before = $this->store->getRun( $run_id )->stepCount;
		$this->runner->tick( $run_id, $before, array( 'action' => 'approve' ) );

		$this->assertStringContainsString(
			'Resumed after 26 hours. The site may have changed since your last read.',
			$this->gateway->systemInstructions[1]
		);
	}

	public function test_every_model_turn_carries_the_site_date_from_the_injected_clock(): void {
		$GLOBALS['senroflux_test_timezone'] = 'Asia/Singapore';
		Clock::useFixed( gmmktime( 23, 10, 0, 10, 2, 2026 ) );
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, 0, null );

		$this->assertStringContainsString(
			'Today is Saturday, 3 October 2026, 07:10 (site timezone Asia/Singapore, UTC+08:00).',
			$this->gateway->systemInstructions[0]
		);
		unset( $GLOBALS['senroflux_test_timezone'] );
	}

	public function test_foreign_owner_is_forbidden(): void {
		$run_id                                    = $this->createRun(); // owned by user 1
		$GLOBALS['senroflux_test_current_user_id'] = 2;

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_forbidden', $result->get_error_code() );
	}
}
