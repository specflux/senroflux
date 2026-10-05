<?php
/**
 * Runner::tick() tests for transient model-error retry.
 *
 * Live run evidence: a single dropped connection to the model
 * ("cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes
 * received") used to fail the run outright via `failError( $run, 'model_error', ... )`
 * — even after the run had already published pages, set the front page, and
 * updated navigation. A transient network/timeout error (or a 429/5xx from
 * the AI Client's own HTTP exceptions, surfaced via
 * {@see \Specflux\SenroFlux\Model\AiClientGateway}'s WP_Error data) now keeps
 * the run `running` and retries on the caller's next tick, failing as
 * `model_error` only after 3 CONSECUTIVE transient failures with no
 * successful model turn in between. A non-transient error (e.g. an auth
 * failure) still fails immediately.
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
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use wpdb;

final class TransientModelErrorTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	/** @var list<int> Seconds passed to the injected backoff sleeper, in call order. */
	private array $sleeps = array();

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->sleeps          = array();
		$this->runner          = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			$this->bridge,
			transient_backoff_sleeper: function ( int $seconds ): void {
				$this->sleeps[] = $seconds;
			}
		);

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_abilities']       = array();
	}

	private function createRun(): int {
		return $this->store->createRun( 1, 'test-consumer', 'Publish three pages', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	private function stepCount( int $run_id ): int {
		return (int) $this->store->getRun( $run_id )->stepCount;
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	/** The exact live-run evidence string, cURL error 28 via NetworkException (status data absent, code 0). */
	private static function timeoutError(): WP_Error {
		return new WP_Error(
			'gateway_failed',
			'Network error occurred while sending POST request to https://api.openai.com/v1/responses: cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received',
			array( 'status' => 0 )
		);
	}

	private static function rateLimitError(): WP_Error {
		return new WP_Error( 'gateway_failed', 'Too Many Requests (429)', array( 'status' => 429 ) );
	}

	private static function authError(): WP_Error {
		return new WP_Error( 'gateway_failed', 'Unauthorized (401) - invalid API key', array( 'status' => 401 ) );
	}

	public function test_run_stays_running_after_a_transient_error_and_completes_on_retry(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::timeoutError();
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$first = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $first );
		$this->assertSame( 'running', $first['run']['status'] );
		$this->assertSame( array(), $first['ui'], 'mirrors the nudge path: the client just retries on its next tick' );
		$this->assertSame( array( 'user', 'system' ), array_column( $first['new_steps'], 'kind' ) );

		$second = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $second['run']['status'] );
		$this->assertSame( array( 'model', 'system', 'user', 'model' ), array_column( $second['new_steps'], 'kind' ) );
		$this->assertCount( 3, $this->gateway->calls, 'the failed attempt, the retry, and the no-write nudge turn' );
	}

	public function test_retry_marker_is_never_sent_to_the_model_as_history(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::timeoutError();
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$this->runner->tick( $run_id, 0, null );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		// The failed attempt and the retry both carry only the seeded goal —
		// the system note the failure left behind never re-enters the prompt.
		// A third call is the no-write nudge turn.
		$this->assertCount( 3, $this->gateway->histories );
		foreach ( array_slice( $this->gateway->histories, 0, 2 ) as $history ) {
			$this->assertCount( 1, $history, 'history must contain only the seeded goal, never the transient-error note' );
		}

		$system_steps = array_filter(
			$this->store->getSteps( $run_id ),
			static fn ( $step ): bool => StepKind::System === $step->kind
				&& 'transient_model_error' === ( $step->messageArray['note'] ?? null )
		);
		$this->assertCount( 1, $system_steps, 'the transient-error note was recorded exactly once' );
		$note = reset( $system_steps );
		$this->assertSame( 'transient_model_error', $note->messageArray['note'] ?? null );
	}

	/**
	 * Live batch 2026-09-28-fix3: space-bunny's provider answered roughly one
	 * call in two with "Provider returned an empty response" for half an hour;
	 * three attempts ~10 s apart killed runs that the next call would have
	 * carried on.
	 */
	public function test_five_consecutive_transient_errors_still_retry_and_complete(): void {
		$run_id = $this->createRun();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->gateway->script[] = self::rateLimitError();
		}
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$result = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		}

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame(
			array( 2, 4, 8, 16, 30 ),
			$this->sleeps,
			'one backoff wait per retry, spaced 2/4/8/16/30s — the 6th (successful) call still waits out the 5th error\'s backoff first'
		);
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-4-1: six "Missing the choices
	 * key" provider errors landed 2-3 s apart (all within 13 s) because
	 * nothing spaced the retries out. Before the fix, {@see Runner::tick()}
	 * called the gateway again immediately on every retry — this test failed
	 * with `$this->sleeps` empty (no backoff mechanism existed to record into
	 * it) rather than the expected `[2, 4, 8, 16, 30]`.
	 */
	public function test_backoff_is_measured_from_the_last_error_not_from_now(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::rateLimitError();
		$this->gateway->script[] = self::rateLimitError();
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$this->runner->tick( $run_id, 0, null );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( array( 2, 4 ), $this->sleeps );
	}

	public function test_six_consecutive_transient_errors_fail_as_model_error(): void {
		$run_id = $this->createRun();
		for ( $i = 0; $i < 6; $i++ ) {
			$this->gateway->script[] = 0 === $i % 2 ? self::timeoutError() : self::rateLimitError();
		}

		$run_status = 'pending';
		for ( $i = 0; $i < 6; $i++ ) {
			$result     = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
			$run_status = $result['run']['status'];
		}

		$this->assertSame( 'failed', $run_status );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 6, $this->gateway->calls, 'no seventh call: the run failed instead of retrying again' );

		$system_notes = array_values(
			array_filter(
				$this->store->getSteps( $run_id ),
				static fn ( $step ): bool => StepKind::System === $step->kind
					&& 'transient_model_error' === ( $step->messageArray['note'] ?? null )
			)
		);
		$this->assertCount( 5, $system_notes, 'the 6th (final) failure fails the run rather than adding a 6th note' );
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-4-1: all of scenario 4's pages
	 * were already published when six consecutive "Missing the choices key"
	 * provider errors exhausted the retry budget and failed the run outright.
	 * Same treatment as {@see Runner::failBudget()}: writes finished, so this
	 * reads as "cut short during verification", not a failure.
	 */
	public function test_writes_finished_completes_unverified_when_transient_errors_exhaust_the_retry_budget(): void {
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array( 'agsafe-smoke/write' => VerbTier::TIER_1 ),
			20,
			1
		);

		$run_id = $this->createRun();

		// An accepted plan naming exactly one write verb, already satisfied.
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

		$write_message              = ( new UserMessage(
			array( new MessagePart( new FunctionResponse( 'call_w', 'agsafe_smoke_write', array( 'ok' => true ) ) ) )
		) )->toArray();
		$write_message['plan_verb'] = 'agsafe-smoke/write';
		$this->store->appendStep( $run_id, StepKind::ToolResult, $write_message, 'wpab__agsafe-smoke__write', null, 'ok' );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->gateway->script[] = 0 === $i % 2 ? self::timeoutError() : self::rateLimitError();
		}

		$result = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		}

		$this->assertSame( 'completed', $result['run']['status'], 'writes were finished: an exhausted retry budget must not fail the run' );
		$this->assertNull( $result['run']['error'] ?? null );

		$note = $result['ui']['report']['unverified'] ?? '';
		$this->assertIsString( $note );
		$this->assertStringContainsString( 'model_error', $note );
	}

	public function test_a_non_transient_error_fails_immediately_without_retrying(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::authError();
		$this->gateway->script[] = self::textTurn( 'Should never be reached.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 1, $this->gateway->calls, 'a non-transient error never retries' );
	}

	public function test_an_exhausted_account_429_fails_immediately_without_retrying(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new WP_Error(
			'gateway_failed',
			'Too Many Requests (429) - You have no credits remaining. Add credits to continue using the API at https://platform.openai.com/settings/organization/billing/.',
			array( 'status' => 429 )
		);
		$this->gateway->script[] = self::textTurn( 'Should never be reached.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 1, $this->gateway->calls, 'an exhausted account never retries' );
	}
}
