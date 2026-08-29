<?php
/**
 * Runner::tick() tests for the S6 ask-the-user park, against a mocked
 * gateway (stage-3 failable check).
 *
 * @package SenroFlux
 */


declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use SenroFlux_Test_Fake_Ability;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use wpdb;

final class AskUserParkTest extends TestCase {

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
			'agsafe-smoke/read' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/read',
				permission_result: true,
				execute_result: array( 'ok' => true )
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

	/** One ask-user call in a model message. */
	private static function askTurn( string $call_id, array $args ): ModelTurn {
		return self::turn(
			new MessagePart( 'I need to clarify something first.' ),
			new MessagePart( new FunctionCall( $call_id, HarnessTools::FUNCTION_NAME, $args ) )
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
	private function questionSteps( int $run_id ): array {
		$out = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::Question === $step->kind ) {
				$out[] = $step;
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// (a) a valid ask-user call parks the run
	// ------------------------------------------------------------------

	public function test_valid_ask_user_parks_as_question_step_and_surfaces_ui_question(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => 'Which color palette?',
				'choices'   => array( 'light', 'dark' ),
				'rationale' => 'Need the theme.',
				// allow_other deliberately omitted — must default to true.
			)
		);

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'awaiting_user', $result['run']['status'] );
		$this->assertSame( array( 'user', 'model', 'question' ), array_column( $result['new_steps'], 'kind' ) );

		$question = $result['new_steps'][2];
		$this->assertSame( StepKind::Question->value, $question['kind'] );
		$this->assertSame( HarnessTools::toolName(), $question['tool_name'] );
		$this->assertSame( 'parked', $question['status'] );

		// message_json = the VALIDATED payload, defaults applied.
		$payload = $question['message'];
		$this->assertSame( 'Which color palette?', $payload['text'] );
		$this->assertSame( array( 'light', 'dark' ), $payload['choices'] );
		$this->assertTrue( $payload['allow_other'], 'allow_other defaults to true when absent' );
		$this->assertSame( '', $payload['default'], 'default normalizes to the empty string' );
		$this->assertSame( 'Need the theme.', $payload['rationale'] );

		$ui = $result['ui']['question'] ?? array();
		$this->assertSame( $question['seq'], $ui['step_id'] ?? null );
		$this->assertSame( 'Which color palette?', $ui['text'] ?? null );
		$this->assertSame( array( 'light', 'dark' ), $ui['choices'] ?? null );
		$this->assertTrue( $ui['allow_other'] ?? false );
		$this->assertSame( '', $ui['default'] ?? null );
		$this->assertSame( 'Need the theme.', $ui['rationale'] ?? null );
		$this->assertSame( 4, $ui['remaining'] ?? -1, 'remaining = max_questions(5) − count(question steps)(1)' );
		$this->assertStringContainsString( 'senroflux-runs', (string) ( $ui['review_url'] ?? '' ) );
	}

	// ------------------------------------------------------------------
	// (b) the ask-user call does NOT count against max_tool_calls
	// ------------------------------------------------------------------

	public function test_ask_user_call_does_not_count_against_max_tool_calls(): void {
		$run_id = $this->createRun( array( 'max_tool_calls' => 1 ) );

		// Message = ask-user THEN an ordinary tool call. Budget is 1 call.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Ask, then act.' ),
			new MessagePart(
				new FunctionCall(
					'call_q',
					HarnessTools::FUNCTION_NAME,
					array(
						'text'      => 'Go left or right?',
						'choices'   => array( 'left', 'right' ),
						'rationale' => 'Pick a direction.',
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array() ) )
		);

		$parked = $this->runner->tick( $run_id, 0, null );
		$this->assertIsArray( $parked );
		$this->assertSame( 'awaiting_user', $parked['run']['status'], 'a valid ask-user parks, it never trips max_tool_calls' );

		// Resume: the SAME tick drains the sibling read (max_tool_calls = 1) and
		// then completes. Had ask-user counted, the single-call budget would be
		// exhausted and the run fail instead.
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'answer' => array( 'text' => 'left' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'ask-user must not consume the tool-call budget' );
		$this->assertSame( array( 'tool_result', 'tool_result', 'model' ), array_column( $result['new_steps'], 'kind' ) );
	}

	// ------------------------------------------------------------------
	// (c) resume with answer.text / answer.choice / skip
	// ------------------------------------------------------------------

	/** Park one ask-user call, then answer it; returns the resume result. */
	private function parkAndResume( array $args, array $resume, string $label ): array {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::askTurn( 'call_q', $args );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertIsArray( $parked );
		$this->assertSame( 'awaiting_user', $parked['run']['status'], $label . ': parks first' );

		$this->gateway->script[] = self::textTurn( 'Continuing.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;

		return $this->runner->tick( $run_id, $before, $resume );
	}

	public function test_resume_with_answer_choice_persists_answer_tool_result(): void {
		$result = $this->parkAndResume(
			array(
				'text'      => 'Pick a palette.',
				'choices'   => array( 'light', 'dark' ),
				'rationale' => 'Need the theme.',
			),
			array( 'answer' => array( 'choice' => 'light' ) ),
			'choice'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		$first = $result['new_steps'][0];
		$this->assertSame( 'tool_result', $first['kind'] );
		$this->assertSame( 'ok', $first['status'] );
		$this->assertSame( HarnessTools::toolName(), $first['tool_name'] );

		$responded = $first['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_q', $responded['id'] ?? null );
		$this->assertSame( HarnessTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame(
			array(
				'answer' => '',
				'choice' => 'light',
			),
			$responded['response'] ?? null
		);
	}

	public function test_resume_with_answer_text_persists_answer_tool_result(): void {
		$result = $this->parkAndResume(
			array(
				'text'      => 'What should the title be?',
				'rationale' => 'Need a heading.',
			),
			array( 'answer' => array( 'text' => 'Pricing' ) ),
			'text'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		$responded = $result['new_steps'][0]['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_q', $responded['id'] ?? null );
		$this->assertSame( HarnessTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame(
			array(
				'answer' => 'Pricing',
				'choice' => '',
			),
			$responded['response'] ?? null
		);
	}

	public function test_resume_with_skip_persists_skipped_tool_result(): void {
		$result = $this->parkAndResume(
			array(
				'text'      => 'Pick a palette.',
				'choices'   => array( 'light', 'dark' ),
				'rationale' => 'Need the theme.',
			),
			array( 'skip' => true ),
			'skip'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		$first     = $result['new_steps'][0];
		$responded = $first['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'tool_result', $first['kind'] );
		$this->assertSame( 'ok', $first['status'] );
		$this->assertSame( 'call_q', $responded['id'] ?? null );
		$this->assertSame( HarnessTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame( array( 'skipped' => true ), $responded['response'] ?? null );
	}

	public function test_resume_same_tick_continues_with_remaining_unconsumed_calls(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Ask then drain two siblings.' ),
			new MessagePart(
				new FunctionCall(
					'call_q',
					HarnessTools::FUNCTION_NAME,
					array(
						'text'      => 'Which palette?',
						'choices'   => array( 'a', 'b' ),
						'rationale' => 'Theme.',
					)
				)
			),
			new MessagePart( new FunctionCall( 'call_r1', 'wpab__agsafe-smoke__read', array() ) ),
			new MessagePart( new FunctionCall( 'call_r2', 'wpab__agsafe-smoke__read', array() ) )
		);
		$this->runner->tick( $run_id, 0, null );

		$this->gateway->script[] = self::textTurn( 'All done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'answer' => array( 'choice' => 'a' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( array( 'tool_result', 'tool_result', 'tool_result', 'model' ), array_column( $result['new_steps'], 'kind' ) );

		$answer = $result['new_steps'][0]['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_q', $answer['id'] ?? null );
		$this->assertSame(
			array(
				'answer' => '',
				'choice' => 'a',
			),
			$answer['response'] ?? null
		);
	}

	// ------------------------------------------------------------------
	// (d) a choice not offered is a 400, not a progress
	// ------------------------------------------------------------------

	public function test_answer_choice_not_in_stored_choices_is_rejected_400(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => 'Pick a palette.',
				'choices'   => array( 'light', 'dark' ),
				'rationale' => 'Need the theme.',
			)
		);
		$this->runner->tick( $run_id, 0, null );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick(
			$run_id,
			$before,
			array( 'answer' => array( 'choice' => 'neon' ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'choice_not_offered', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );

		// The run must still be parked; the refused answer changes nothing.
		$this->assertSame( RunStatus::AwaitingUser, $this->store->getRun( $run_id )->status );
		$this->assertCount( 1, $this->questionSteps( $run_id ) );
	}

	// ------------------------------------------------------------------
	// (e) withdrawal from the declaration list at zero remaining
	// ------------------------------------------------------------------

	public function test_ask_user_declaration_withdrawn_when_remaining_hits_zero(): void {
		$run_id = $this->createRun( array( 'max_questions' => 1 ) );

		// Tick 1: remaining is 1 → ask-user is declared.
		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => 'Pick a palette.',
				'choices'   => array( 'light', 'dark' ),
				'rationale' => 'Need the theme.',
			)
		);
		$this->runner->tick( $run_id, 0, null );

		$this->assertContains(
			HarnessTools::FUNCTION_NAME,
			self::declarationNames( $this->gateway->toolsLog[0] ),
			'ask-user IS declared while a question remains'
		);

		// Answer the one permitted question; the completion turn has 0 left.
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$result                  = $this->runner->tick( $run_id, $before, array( 'answer' => array( 'choice' => 'light' ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		$this->assertNotContains(
			HarnessTools::FUNCTION_NAME,
			self::declarationNames( $this->gateway->toolsLog[1] ),
			'ask-user is withdrawn once no questions remain'
		);
	}

	// ------------------------------------------------------------------
	// (f) invalid payload -> invalid_question tool_result, counted, keeps running
	// ------------------------------------------------------------------

	public function test_invalid_payload_text_over_300_is_invalid_question_and_keeps_running(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => str_repeat( 'x', 301 ),
				'rationale' => 'Too long.',
			)
		);
		$this->gateway->script[] = self::textTurn( 'Understood, rephrasing.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'an invalid ask-user is NOT a park; the run keeps going' );
		$this->assertSame( array( 'user', 'model', 'tool_result', 'model' ), array_column( $result['new_steps'], 'kind' ) );

		$error_step = $result['new_steps'][2];
		$this->assertSame( 'tool_result', $error_step['kind'] );
		$this->assertSame( 'error', $error_step['status'] );
		$this->assertSame( HarnessTools::toolName(), $error_step['tool_name'] );

		$responded = $error_step['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_q', $responded['id'] ?? null );
		$this->assertSame( HarnessTools::FUNCTION_NAME, $responded['name'] ?? null );
		$this->assertSame( array( 'error' => HarnessTools::ERROR_INVALID_QUESTION ), $responded['response'] ?? null );
	}

	public function test_invalid_payload_counts_as_a_tool_call_for_the_budget(): void {
		$run_id = $this->createRun( array( 'max_tool_calls' => 1 ) );

		// invalid ask-user (which counts) + one ordinary call -> budget (1) is
		// exhausted by the invalid ask-user, so the ordinary call is refused.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Invalid ask, then a real read.' ),
			new MessagePart(
				new FunctionCall(
					'call_q',
					HarnessTools::FUNCTION_NAME,
					array(
						'text'      => str_repeat( 'x', 301 ),
						'rationale' => 'Too long.',
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

	public function test_invalid_payload_missing_rationale_is_invalid_question(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::askTurn( 'call_q', array( 'text' => 'Pick one.' ) );
		$this->gateway->script[] = self::textTurn( 'Ok.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$responded = $result['new_steps'][2]['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( array( 'error' => HarnessTools::ERROR_INVALID_QUESTION ), $responded['response'] ?? null );
	}

	// ------------------------------------------------------------------
	// (g) budget exhaustion: zero remaining + a withdrawn ask call
	// ------------------------------------------------------------------

	public function test_ask_user_at_zero_remaining_is_refused_not_parked(): void {
		$run_id = $this->createRun( array( 'max_questions' => 1 ) );

		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => 'Pick one.',
				'choices'   => array( 'a', 'b' ),
				'rationale' => 'Must ask.',
			)
		);
		$this->runner->tick( $run_id, 0, null );
		$this->assertSame( 0, HarnessTools::remaining( 1, count( $this->questionSteps( $run_id ) ) ) );

		// The model still emits an ask-user call on the next turn — it is
		// refused (questions_exhausted), counted as a tool call, never parked.
		$this->gateway->script[] = self::askTurn(
			'call_q2',
			array(
				'text'      => 'Another?',
				'rationale' => 'Still open.',
			)
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'skip' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		// skip answer → model (emits call_q2) → refused tool_result → model (done).
		$this->assertSame( array( 'tool_result', 'model', 'tool_result', 'model' ), array_column( $result['new_steps'], 'kind' ) );

		$refused = $result['new_steps'][2]['message']['parts'][0]['functionResponse'] ?? array();
		$this->assertSame( 'call_q2', $refused['id'] ?? null );
		$this->assertSame( HarnessTools::FUNCTION_NAME, $refused['name'] ?? null );
		$this->assertSame( array( 'error' => HarnessTools::ERROR_QUESTIONS_EXHAUSTED ), $refused['response'] ?? null );
	}

	/**
	 * S6 acting-user rule: an admin answering ANOTHER user's run records a
	 * system step `{"note":"answered_by","user_id":N}` before the answer.
	 */
	public function test_an_admin_answering_another_users_run_records_answered_by(): void {
		$run_id                  = $this->createRun(); // owned by user 1
		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => 'Which color?',
				'rationale' => 'Need it.',
			)
		);
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->script = array( $this->textTurn( 'Thanks!' ) );

		// A delegated admin (user 2) answers: senroflux_can_tick must allow it.
		$GLOBALS['senroflux_test_current_user_id'] = 2;
		add_filter(
			'senroflux_can_tick',
			static fn (): bool => true,
			10,
			0
		);

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'answer' => array( 'text' => 'dark' ) ) );

		remove_all_filters( 'senroflux_can_tick' );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );

		// The answered_by system step precedes the answer tool_result.
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

	/** The owner answering their own run records NO answered_by step. */
	public function test_the_owner_answering_records_no_answered_by(): void {
		$run_id                  = $this->createRun(); // owned by user 1
		$this->gateway->script[] = self::askTurn(
			'call_q',
			array(
				'text'      => 'Which color?',
				'rationale' => 'Need it.',
			)
		);
		$this->runner->tick( $run_id, 0, null );
		$this->gateway->script = array( $this->textTurn( 'Thanks!' ) );

		$before = $this->store->getRun( $run_id )->stepCount;
		$result = $this->runner->tick( $run_id, $before, array( 'answer' => array( 'text' => 'dark' ) ) );

		$this->assertIsArray( $result );
		foreach ( $result['new_steps'] as $step ) {
			if ( StepKind::System->value === $step['kind'] ) {
				$this->assertNotSame( 'answered_by', $step['message']['note'] ?? '' );
			}
		}
	}
}
