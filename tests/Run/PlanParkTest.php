<?php
/**
 * Runner::tick() tests for the S7 plan park, the plan fence, against a mocked
 * gateway (stage-4 check).
 *
 * @package SenroFlux
 */


declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Run;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use Specflux\SenroFlux\Tools\VerbTier;
use SenroFlux_Test_Fake_Ability;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use wpdb;

final class PlanParkTest extends TestCase {

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
			'agsafe-smoke/read'  => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/read',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
		);

		// Stage-4 verb map (the stage-6 seam): read is free (tier 0), write is
		// fenced (tier 1). Unknown verbs fail closed to tier 2 (VerbTier).
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'agsafe-smoke/read'  => VerbTier::TIER_0,
				'agsafe-smoke/write' => VerbTier::TIER_1,
			),
			10,
			1
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
		remove_all_filters( 'senroflux_enable_preapproval' );
	}

	/**
	 * @param array<string,int> $budget_override Keys to merge over Budget::defaults().
	 */
	private function createRun( array $budget_override = array() ): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Clear the cache',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), $budget_override )
		);
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn(
			new ModelMessage( array( new MessagePart( $text ) ) ),
			10,
			5
		);
	}

	/**
	 * A model turn made of arbitrary parts (text, call, wait).
	 *
	 * @param MessagePart ...$parts
	 */
	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	/** One propose-plan call in a model message. */
	private static function planTurn( string $call_id, array $args ): ModelTurn {
		return self::turn(
			new MessagePart( 'Let me propose a plan first.' ),
			new MessagePart( new FunctionCall( $call_id, PlanTools::FUNCTION_NAME, $args ) )
		);
	}

	/** A fully valid plan payload; the model is asking to read and write. */
	private static function validPlanArgs(): array {
		return array(
			'goal'        => 'Publish the page',
			'steps'       => array(
				array(
					'text'  => 'Read the draft',
					'verbs' => array( 'agsafe-smoke/read' ),
				),
				array(
					'text'  => 'Publish it',
					'verbs' => array( 'agsafe-smoke/write' ),
				),
			),
			'assumptions' => array( 'The draft is reviewed.' ),
		);
	}

	/** Declaration names a registry handed to the model carries. */
	private static function declarationNames( ToolRegistry $registry ): array {
		$names = array();
		foreach ( $registry->declarations() as $declaration ) {
			$names[] = $declaration instanceof FunctionDeclaration
				? $declaration->getName()
				: (string) ( $declaration['name'] ?? '' );
		}

		return $names;
	}

	/** @return list<object> */
	private function planSteps( int $run_id ): array {
		$out = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::Plan === $step->kind ) {
				$out[] = $step;
			}
		}

		return $out;
	}

	/** Park one valid propose-plan call; returns the tick result. */
	private function parkPlan( array $args = array() ): array {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn( 'call_p', ( array() !== $args ) ? $args : self::validPlanArgs() );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertIsArray( $parked );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		return $parked;
	}

	/** The seq of the newest plan step (0 if none). */
	private function latestPlanStepSeq( int $run_id ): int {
		$steps = $this->planSteps( $run_id );
		if ( array() === $steps ) {
			return 0;
		}

		return (int) $steps[ count( $steps ) - 1 ]->seq;
	}

	// ------------------------------------------------------------------
	// (a) a valid propose-plan call parks the run
	// ------------------------------------------------------------------

	public function test_valid_propose_plan_parks_as_plan_step_and_surfaces_ui_plan(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_plan', $result['run']['status'] );
		$this->assertSame( array( 'user', 'model', 'plan' ), array_column( $result['new_steps'], 'kind' ) );

		$plan = $result['new_steps'][2];
		$this->assertSame( StepKind::Plan->value, $plan['kind'] );
		$this->assertSame( PlanTools::toolName(), $plan['tool_name'] );
		$this->assertSame( 'parked', $plan['status'] );

		// message_json = the VALIDATED payload, with per-step tiers annotated.
		$payload = $plan['message'];
		$this->assertSame( 'Publish the page', $payload['goal'] );
		$this->assertSame( array( 'The draft is reviewed.' ), $payload['assumptions'] );
		$this->assertSame(
			array(
				array(
					'text'  => 'Read the draft',
					'verbs' => array( 'agsafe-smoke/read' ),
					'tier'  => 0,
				),
				array(
					'text'  => 'Publish it',
					'verbs' => array( 'agsafe-smoke/write' ),
					'tier'  => 1,
				),
			),
			$payload['steps']
		);

		$ui = $result['ui']['plan'] ?? array();
		$this->assertSame( $plan['seq'], $ui['step_id'] ?? null );
		$this->assertSame( 'Publish the page', $ui['goal'] ?? null );
		$this->assertSame(
			array(
				array(
					'text'  => 'Read the draft',
					'verbs' => array( 'agsafe-smoke/read' ),
					'tier'  => 0,
				),
				array(
					'text'  => 'Publish it',
					'verbs' => array( 'agsafe-smoke/write' ),
					'tier'  => 1,
				),
			),
			$ui['steps'] ?? null
		);
		$this->assertSame( array( 'The draft is reviewed.' ), $ui['assumptions'] ?? null );
		$this->assertSame( 2, $ui['remaining_plans'] ?? -1, 'remaining = max_plans(3) − count(plan steps)(1)' );
		$this->assertFalse( $ui['preapprove_available'] ?? true, 'preapprove is hidden in stage 4 (grants absent)' );
		$this->assertStringContainsString( 'senroflux-runs', (string) ( $ui['review_url'] ?? '' ) );
	}

	// ------------------------------------------------------------------
	// (b) propose-plan does NOT count against max_tool_calls
	// ------------------------------------------------------------------

	public function test_propose_plan_call_does_not_count_against_max_tool_calls(): void {
		$run_id = $this->createRun( array( 'max_tool_calls' => 1 ) );

		// Message = propose-plan THEN an ordinary (tier-0) tool call. Budget is
		// one call, so if propose-plan counted, the read would be refused.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Plan, then act.' ),
			new MessagePart(
				new FunctionCall(
					'call_p',
					PlanTools::FUNCTION_NAME,
					array(
						'goal'  => 'G',
						'steps' => array(
							array(
								'text'  => 'Read',
								'verbs' => array( 'agsafe-smoke/read' ),
							),
						),
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);

		$parked = $this->runner->tick( $run_id, 0, null );
		$this->assertIsArray( $parked );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'], 'a valid propose-plan parks, it never trips max_tool_calls' );

		// Resume accept: the SAME tick drains the sibling read (max_tool_calls
		// = 1) then completes. Had propose-plan counted, the single-call budget
		// would be exhausted and the run fail.
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'propose-plan must not consume the tool-call budget' );
		$this->assertSame( array( 'tool_result', 'tool_result', 'model' ), array_column( $result['new_steps'], 'kind' ) );
	}

	// ------------------------------------------------------------------
	// (c) invalid payload -> invalid_plan tool_result, counted, keeps running
	// ------------------------------------------------------------------

	public function test_invalid_payload_goal_over_200_is_invalid_plan_and_keeps_running(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn(
			'call_p',
			array(
				'goal'  => str_repeat( 'x', 201 ),
				'steps' => array(
					array(
						'text'  => 'Read',
						'verbs' => array( 'agsafe-smoke/read' ),
					),
				),
			)
		);
		$this->gateway->script[] = self::textTurn( 'Understood, rephrasing.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'an invalid propose-plan is NOT a park; the run keeps going' );
		$this->assertSame( array( 'user', 'model', 'tool_result', 'model' ), array_column( $result['new_steps'], 'kind' ) );

		$error_step = $result['new_steps'][2];
		$this->assertSame( 'tool_result', $error_step['kind'] );
		$this->assertSame( 'error', $error_step['status'] );
		$this->assertSame( PlanTools::toolName(), $error_step['tool_name'] );

		$responded = $error_step['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_p', $responded['id'] ?? null );
		$this->assertSame( PlanTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame( array( 'error' => PlanTools::ERROR_INVALID_PLAN ), $responded['response'] ?? null );
	}

	public function test_invalid_payload_too_many_steps_is_invalid_plan(): void {
		$run_id = $this->createRun();
		$steps  = array();
		for ( $i = 0; $i < 11; ++$i ) {
			$steps[] = array(
				'text'  => 'Step ' . $i,
				'verbs' => array( 'agsafe-smoke/read' ),
			);
		}
		$this->gateway->script[] = self::planTurn(
			'call_p',
			array(
				'goal'  => 'G',
				'steps' => $steps,
			)
		);
		$this->gateway->script[] = self::textTurn( 'Ok.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$responded = $result['new_steps'][2]['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( array( 'error' => PlanTools::ERROR_INVALID_PLAN ), $responded['response'] ?? null );
	}

	public function test_invalid_payload_empty_verbs_is_invalid_plan(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn(
			'call_p',
			array(
				'goal'  => 'G',
				'steps' => array(
					array(
						'text'  => 'Read',
						'verbs' => array(),
					),
				),
			)
		);
		$this->gateway->script[] = self::textTurn( 'Ok.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$responded = $result['new_steps'][2]['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( array( 'error' => PlanTools::ERROR_INVALID_PLAN ), $responded['response'] ?? null );
	}

	public function test_invalid_payload_counts_as_a_tool_call_for_the_budget(): void {
		$run_id = $this->createRun( array( 'max_tool_calls' => 1 ) );

		// invalid propose-plan (which counts) + one ordinary call -> budget (1)
		// is exhausted by the invalid propose-plan, so the read is refused.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Invalid plan, then a real read.' ),
			new MessagePart(
				new FunctionCall(
					'call_p',
					PlanTools::FUNCTION_NAME,
					array(
						'goal'  => str_repeat( 'x', 201 ),
						'steps' => array(
							array(
								'text'  => 'Read',
								'verbs' => array( 'agsafe-smoke/read' ),
							),
						),
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'budget_exceeded', $result['run']['error']['code'] ?? '' );
		$this->assertSame( 'max_tool_calls', $result['run']['error']['which'] ?? '' );
	}

	// ------------------------------------------------------------------
	// (d) resume plan.action=accept
	// ------------------------------------------------------------------

	public function test_resume_accept_persists_accept_result_and_sets_accepted_plan_step_id(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$plan_seq = $this->latestPlanStepSeq( $run_id );

		$this->gateway->script[] = self::textTurn( 'Continuing.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );
		// The park is cleared; the loop resumed and reached completion (a text
		// turn with no calls). "running" is the transient state between the
		// resume and the settled outcome of the resumed loop.
		$this->assertSame( 'completed', $result['run']['status'] );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertSame( $plan_seq, $run->acceptedPlanStepId, 'accept sets accepted_plan_step_id on the run row' );

		$first = $result['new_steps'][0];
		$this->assertSame( 'tool_result', $first['kind'] );
		$this->assertSame( 'ok', $first['status'] );
		$this->assertSame( PlanTools::toolName(), $first['tool_name'] );

		$responded = $first['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_p', $responded['id'] ?? null );
		$this->assertSame( PlanTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame(
			array(
				'accepted' => true,
				'mode'     => 'accept',
				'note'     => '',
			),
			$responded['response'] ?? null
		);
	}

	// ------------------------------------------------------------------
	// (e) resume plan.action=veto
	// ------------------------------------------------------------------

	public function test_resume_veto_persists_veto_result_and_clears_accepted_plan_step_id(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$this->gateway->script[] = self::textTurn( 'Re-planning.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick(
			$run_id,
			$before,
			array(
				'plan' => array(
					'action' => 'veto',
					'note'   => 'Too broad; narrow the scope.',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'a veto re-enters the loop; the model re-plans' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertNull( $run->acceptedPlanStepId, 'veto keeps accepted_plan_step_id NULL' );

		$first = $result['new_steps'][0];
		$this->assertSame( 'tool_result', $first['kind'] );
		$this->assertSame( 'ok', $first['status'] );

		$responded = $first['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_p', $responded['id'] ?? null );
		$this->assertSame( PlanTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame(
			array(
				'accepted' => false,
				'mode'     => 'veto',
				'note'     => 'Too broad; narrow the scope.',
			),
			$responded['response'] ?? null,
			'the veto note is returned verbatim'
		);
	}

	// ------------------------------------------------------------------
	// (f) vetoing the LAST allowed plan cancels the run
	// ------------------------------------------------------------------

	public function test_vetoing_the_last_allowed_plan_cancels_the_run_with_plan_rejected(): void {
		$run_id                  = $this->createRun( array( 'max_plans' => 1 ) );
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );
		$this->assertCount( 1, $this->planSteps( $run_id ) );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick(
			$run_id,
			$before,
			array(
				'plan' => array(
					'action' => 'veto',
					'note'   => 'Not acceptable.',
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'cancelled', $result['run']['status'], 'reaching max_plans on a veto cancels the run' );
		$this->assertSame( 'plan_rejected', $result['run']['error']['code'] ?? '' );
		$this->assertNull( $this->store->getRun( $run_id )->acceptedPlanStepId );

		// S12: a cancellation is a terminal transition like any other, so the
		// harness-built report is present and persisted.
		$this->assertArrayHasKey( 'report', $result['ui'], 'a terminal transition returns its report' );
		$this->assertSame( array(), $result['ui']['report']['changes'] ?? null, 'nothing was written before the veto' );
		$this->assertNotNull( $this->store->getRun( $run_id )->result, 'result_json is persisted' );
	}

	// ------------------------------------------------------------------
	// (g) THE FENCE: not_in_plan after an accepted plan, not counted
	// ------------------------------------------------------------------

	public function test_after_accept_a_tier1_verb_outside_the_plan_is_not_in_plan_and_not_counted(): void {
		$run_id = $this->createRun( array( 'max_tool_calls' => 1 ) );

		// A plan that only allows READ. Accept it; write is then out of scope.
		$this->gateway->script[] = self::planTurn(
			'call_p',
			array(
				'goal'  => 'G',
				'steps' => array(
					array(
						'text'  => 'Read',
						'verbs' => array( 'agsafe-smoke/read' ),
					),
				),
			)
		);
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		// After accept the model emits write (not in plan, tier 1) then read
		// (in plan, tier 0). Budget is one call: if the write refusal counted,
		// the read would fail the budget instead of executing.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Continuing.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array() ) ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'not_in_plan refusal must not consume the tool-call budget' );

		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( array( 'tool_result', 'model', 'tool_result', 'tool_result', 'model' ), $kinds );

		// Step 2 = the refused write; step 3 = the executed read.
		$write = $result['new_steps'][2];
		$this->assertSame( 'error', $write['status'] );
		$this->assertSame( 'wpab__agsafe-smoke__write', $write['tool_name'] );
		$write_response = $write['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_w', $write_response['id'] ?? null );
		$this->assertSame( array( 'error' => 'not_in_plan' ), $write_response['response'] ?? null );

		$read = $result['new_steps'][3];
		$this->assertSame( 'ok', $read['status'] );
		$this->assertSame( 'wpab__agsafe-smoke__read', $read['tool_name'] );
	}

	// ------------------------------------------------------------------
	// (h) THE FENCE: plan_required before any accepted plan, not counted
	// ------------------------------------------------------------------

	public function test_a_tier1_call_before_any_accepted_plan_is_plan_required_and_not_counted(): void {
		$run_id = $this->createRun( array( 'max_tool_calls' => 1 ) );

		// No plan yet. Write is tier 1 -> plan_required refusal (not counted);
		// read is tier 0 -> free. If the refusal counted, the read would fail.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Acting.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array() ) ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'plan_required refusal must not consume the tool-call budget' );

		// Step 2 = the refused write (plan_required); step 3 = the executed read.
		$write = $result['new_steps'][2];
		$this->assertSame( 'error', $write['status'] );
		$this->assertSame( 'wpab__agsafe-smoke__write', $write['tool_name'] );
		$write_response = $write['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( array( 'error' => 'plan_required' ), $write_response['response'] ?? null );

		$read = $result['new_steps'][3];
		$this->assertSame( 'ok', $read['status'] );
	}

	// ------------------------------------------------------------------
	// (i) tier-0 calls are free before the plan
	// ------------------------------------------------------------------

	public function test_tier0_reads_are_free_before_the_plan(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading.' ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'a tier-0 read is free before any plan' );

		$read = $result['new_steps'][2];
		$this->assertSame( 'tool_result', $read['kind'] );
		$this->assertSame( 'ok', $read['status'] );
		$this->assertSame( 'wpab__agsafe-smoke__read', $read['tool_name'] );
	}

	// ------------------------------------------------------------------
	// (j) a newly accepted plan replaces the old one
	// ------------------------------------------------------------------

	public function test_a_newly_accepted_plan_replaces_the_old_accepted_plan(): void {
		$run_id = $this->createRun();

		// Plan 1 -> park -> accept. The model then re-proposes (plan 2) which
		// parks again; accepted_plan_step_id is still plan 1 until plan 2 accepts.
		$this->gateway->script[] = self::planTurn( 'call_p1', self::validPlanArgs() );
		$this->runner->tick( $run_id, 0, null );

		$this->gateway->script[] = self::planTurn( 'call_p2', self::validPlanArgs() );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_plan', $result['run']['status'], 'the model re-proposes after plan 1 is accepted' );

		$first = $this->store->getRun( $run_id )->acceptedPlanStepId;
		$this->assertNotNull( $first );

		// Accept plan 2: accepted_plan_step_id MOVES to plan 2's step.
		$this->gateway->script[] = self::textTurn( 'Continuing.' );
		$before2                 = $this->store->getRun( $run_id )->stepCount;
		$result2                 = $this->runner->tick( $run_id, $before2, array( 'plan' => array( 'action' => 'accept' ) ) );
		$this->assertIsArray( $result2 );

		$second = $this->store->getRun( $run_id )->acceptedPlanStepId;
		$this->assertNotNull( $second );
		$this->assertNotSame( $first, $second, 'a newly accepted plan replaces the old one' );
	}

	// ------------------------------------------------------------------
	// (k) withdrawal from the declaration list at zero remaining
	// ------------------------------------------------------------------

	public function test_propose_plan_declaration_withdrawn_when_remaining_hits_zero(): void {
		$run_id = $this->createRun( array( 'max_plans' => 1 ) );

		// Tick 1: remaining is 1 -> propose-plan is declared.
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );
		$this->runner->tick( $run_id, 0, null );

		$this->assertContains(
			PlanTools::FUNCTION_NAME,
			self::declarationNames( $this->gateway->toolsLog[0] ),
			'propose-plan IS declared while a plan remains'
		);

		// Accept the one permitted plan; the completion turn has 0 left.
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		$this->assertNotContains(
			PlanTools::FUNCTION_NAME,
			self::declarationNames( $this->gateway->toolsLog[1] ),
			'propose-plan is withdrawn once no plans remain'
		);
	}

	// ------------------------------------------------------------------
	// (l) accept_preapprove is disabled by default
	// ------------------------------------------------------------------

	public function test_accept_preapprove_resume_with_filter_off_is_preapproval_disabled(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );
		$this->runner->tick( $run_id, 0, null );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept_preapprove' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'preapproval_disabled', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );

		// The run must still be parked and unaccepted; the refused action changes nothing.
		$this->assertSame( RunStatus::AwaitingPlan, $this->store->getRun( $run_id )->status );
		$this->assertNull( $this->store->getRun( $run_id )->acceptedPlanStepId );
	}

	// ------------------------------------------------------------------
	// Bonus: S7 acting-user rule (a delegated admin accepting records the actor)
	// ------------------------------------------------------------------

	public function test_a_delegated_admin_accepting_records_answered_by(): void {
		$run_id                  = $this->createRun(); // owned by user 1
		$this->gateway->script[] = self::planTurn( 'call_p', self::validPlanArgs() );
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->script = array( $this->textTurn( 'Thanks!' ) );

		// A delegated admin (user 2) accepts; senroflux_can_tick must allow it.
		$GLOBALS['senroflux_test_current_user_id'] = 2;
		add_filter(
			'senroflux_can_tick',
			static fn (): bool => true,
			10,
			0
		);

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		remove_all_filters( 'senroflux_can_tick' );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		// The answered_by system step precedes the accept tool_result.
		$kinds = array_column( $result['new_steps'], 'kind' );
		$this->assertSame( 'system', $kinds[0] ?? '' );
		$this->assertSame( 'tool_result', $kinds[1] ?? '' );
		$this->assertSame(
			array(
				'note'    => 'answered_by',
				'user_id' => 2,
			),
			$result['new_steps'][0]['message'] ?? array()
		);
	}

	// ------------------------------------------------------------------
	// (k) THE FENCE resolves ABILITY -> VERB before tiering
	// ------------------------------------------------------------------

	/**
	 * A Runner wired the way the composition root wires it for a pack run: the
	 * pack supplies both the verb map AND the ability+args => verb predicate.
	 * Without the predicate the fence would look the raw ability id up in a map
	 * keyed on `pages/*` verbs, miss every time, and fail closed to tier 2 —
	 * which made even a read `plan_required`.
	 */
	private function packRunner(): Runner {
		$pack = new PagesPack();

		return new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			$this->bridge,
			null,
			static function ( Run $run ) use ( $pack ): ?array {
				unset( $run );

				return $pack->verbMap();
			},
			static function ( Run $run, string $ability, array $args ) use ( $pack ): string {
				unset( $run );

				return $pack->verbFor( $ability, $args );
			}
		);
	}

	private function seedPagesAbilities(): void {
		$GLOBALS['senroflux_test_abilities'] = array(
			'senroflux/read-content' => new SenroFlux_Test_Fake_Ability(
				'senroflux/read-content',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
			'senroflux/update-post'  => new SenroFlux_Test_Fake_Ability(
				'senroflux/update-post',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
		);
	}

	private function createPagesRun(): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Design a pricing page',
			array( 'senroflux/*' ),
			Budget::defaults()
		);
	}

	public function test_fence_tiers_a_read_ability_as_tier0_through_the_pack_verb(): void {
		$this->seedPagesAbilities();
		$run_id = $this->createPagesRun();

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading.' ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__senroflux__read-content', array( 'id' => 7 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->packRunner()->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		// pages/read is tier 0: free before any plan, so the call EXECUTES.
		$read = $result['new_steps'][2];
		$this->assertSame( 'ok', $read['status'] );
		$this->assertSame( 'wpab__senroflux__read-content', $read['tool_name'] );
	}

	public function test_fence_tiers_a_publish_transition_as_tier2_through_the_pack_verb(): void {
		$this->seedPagesAbilities();
		$run_id = $this->createPagesRun();

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Publishing.' ),
			new MessagePart(
				new FunctionCall(
					'call_p',
					'wpab__senroflux__update-post',
					array(
						'id'     => 7,
						'status' => 'publish',
					)
				)
			)
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->packRunner()->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		// The SAME ability, tiered 2 because of its args: refused for want of a plan.
		$publish = $result['new_steps'][2];
		$this->assertSame( 'error', $publish['status'] );
		$response = $publish['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( array( 'error' => 'plan_required' ), $response['response'] ?? null );
	}

	public function test_the_same_ability_is_in_plan_as_a_draft_edit_and_not_in_plan_as_a_publish(): void {
		$this->seedPagesAbilities();
		$run_id = $this->createPagesRun();
		$runner = $this->packRunner();

		// A plan that allows draft edits only.
		$this->gateway->script[] = self::planTurn(
			'call_p',
			array(
				'goal'  => 'Draft the pricing page',
				'steps' => array(
					array(
						'text'  => 'Edit the draft',
						'verbs' => array( 'pages/update-draft' ),
					),
				),
			)
		);
		$parked                  = $runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		// One ability, two calls: the publish is outside the plan's verb set,
		// the draft edit is inside it. Only an args-aware verb can tell them
		// apart — on the ability id alone both would land the same way.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Continuing.' ),
			new MessagePart(
				new FunctionCall(
					'call_pub',
					'wpab__senroflux__update-post',
					array(
						'id'     => 7,
						'status' => 'publish',
					)
				)
			),
			new MessagePart(
				new FunctionCall(
					'call_draft',
					'wpab__senroflux__update-post',
					array(
						'id'     => 7,
						'status' => 'draft',
					)
				)
			)
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $runner->tick( $run_id, $before, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );

		$publish  = $result['new_steps'][2];
		$response = $publish['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'error', $publish['status'] );
		$this->assertSame( array( 'error' => 'not_in_plan' ), $response['response'] ?? null );

		$draft = $result['new_steps'][3];
		$this->assertSame( 'ok', $draft['status'] );
	}

	public function test_a_run_without_a_pack_keeps_ability_names_as_verbs(): void {
		// No verb resolver at all: $this->runner is the direct-allow wiring, and
		// the stage-4 filter map keys on ability names (S9).
		$run_id = $this->createRun();

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading.' ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'ok', $result['new_steps'][2]['status'] );
	}
}
