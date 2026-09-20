<?php
/**
 * 0.3 S14 ("Upgrade from 0.2"): every run that was live under 0.2 fails on
 * its next tick with the plain reason "started under 0.2" [assumed], while
 * a run that already completed under 0.2 keeps rendering untouched.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WP_Error;
use wpdb;

final class LegacyRunTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	/** Mutable so a single test can change the watermark mid-flight (or leave it null: "no watermark at all"). */
	private ?int $watermark = null;

	/** Mutable so a single test can flip the environment's gate mode. */
	private GateMode $currentMode = GateMode::AgentSafety;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->watermark       = null;
		$this->currentMode     = GateMode::AgentSafety;

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
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			null,
			fn (): GateMode => $this->currentMode,
			null,
			null,
			null,
			null,
			fn (): ?int => $this->watermark
		);

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_agent_safety']    = null;
	}

	private function createRun( GateMode $gate_mode = GateMode::AgentSafety ): int {
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

	// ------------------------------------------------------------------
	// A live (non-terminal) run at or below the watermark fails.
	// ------------------------------------------------------------------

	public function test_a_non_terminal_run_at_the_watermark_fails_on_its_next_tick_with_a_partial_report(): void {
		$run_id          = $this->createRun();
		$this->watermark = $run_id; // "at" the watermark.

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertArrayHasKey( 'report', $result['ui'] ?? array(), 'a legacy refusal fails with a partial report' );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( 'started_under_0_2', $fresh->error['code'] ?? null );
	}

	public function test_the_run_s_stored_status_is_failed_with_finished_at_set(): void {
		$run_id          = $this->createRun();
		$this->watermark = $run_id;

		$this->runner->tick( $run_id, 0, null );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $fresh->status );
		$this->assertNotNull( $fresh->finishedAtUtc );
		// Distinguishes this from any other fatal ending up Failed too (e.g. a
		// model error from an empty scripted gateway): it must be the legacy
		// refusal specifically, reached WITHOUT ever calling the gateway.
		$this->assertSame( 'started_under_0_2', $fresh->error['code'] ?? null );
		$this->assertSame( array(), $this->gateway->calls, 'refused before any model call' );
	}

	// ------------------------------------------------------------------
	// A run below the watermark that already completed is untouched.
	// ------------------------------------------------------------------

	public function test_a_completed_run_below_the_watermark_is_untouched_by_tick_and_still_renders(): void {
		$run_id = $this->createRun();
		$this->store->appendStep( $run_id, StepKind::User, array( 'text' => 'Clear the cache' ) );
		$this->store->updateRun(
			$run_id,
			array(
				'status'      => RunStatus::Completed->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'result_json' => array(
					'summary' => 'Done.',
					'changes' => array(),
				),
			)
		);
		$this->watermark = $run_id + 5; // Well below the watermark.

		$result = $this->runner->tick( $run_id, 1, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'a completed run is returned as-is, never refused' );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Completed, $fresh->status );
		$this->assertNull( $fresh->error, 'a completed 0.2 run carries no error' );
		$this->assertIsArray( $fresh->result, 'its report survives untouched' );
		$this->assertSame( 'Done.', $fresh->result['summary'] ?? null );

		$steps = $this->store->getSteps( $run_id );
		$this->assertCount( 1, $steps, 'its history survives untouched' );
	}

	// ------------------------------------------------------------------
	// A run above the watermark ticks normally.
	// ------------------------------------------------------------------

	public function test_a_run_above_the_watermark_ticks_normally(): void {
		$run_id          = $this->createRun();
		$this->watermark = $run_id - 1; // Below this run: not legacy.

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
	}

	// ------------------------------------------------------------------
	// No watermark at all: nothing is ever refused.
	// ------------------------------------------------------------------

	public function test_no_watermark_at_all_means_nothing_is_refused(): void {
		$run_id          = $this->createRun();
		$this->watermark = null;

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
	}

	// ------------------------------------------------------------------
	// Plugin::cancel() gets the same refusal, not a plain cancel.
	// ------------------------------------------------------------------

	public function test_plugin_cancel_on_a_legacy_run_yields_the_same_refusal_not_cancelled(): void {
		Plugin::reset();
		$GLOBALS['wpdb'] = $this->db;

		$run_id          = $this->createRun();
		$this->watermark = $run_id;

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $this->runner );

		$result = senroflux()->cancel( $run_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( 'started_under_0_2', $fresh->error['code'] ?? null );
		$this->assertNotSame( RunStatus::Cancelled, $fresh->status );

		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// The legacy check wins over a simultaneous gate-mode mismatch.
	// ------------------------------------------------------------------

	public function test_the_legacy_check_wins_over_a_gate_mode_mismatch_when_both_apply(): void {
		$run_id          = $this->createRun( GateMode::BuiltIn );
		$this->watermark = $run_id;
		// The environment no longer agrees with the run's pinned mode either.
		$this->currentMode = GateMode::AgentSafety;

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );

		$fresh = $this->store->getRun( $run_id );
		$this->assertSame( 'started_under_0_2', $fresh->error['code'] ?? null, 'the legacy refusal is checked first' );
	}

	// ------------------------------------------------------------------
	// S14's "a completed 0.2 run still renders" bullet: a run row in the
	// 0.2 SHAPE (withheld_roles_json NULL, follow_up_of NULL, gate_mode
	// 'agent_safety', no skills_disable_json at all) with steps must still
	// produce a complete detail payload from Plugin::get() -- status,
	// history and report all present, no notice, no error.
	// ------------------------------------------------------------------

	public function test_a_0_2_shape_completed_run_still_renders_a_complete_detail_payload(): void {
		Plugin::reset();
		$GLOBALS['wpdb'] = $this->db;

		$run_id = $this->createRun( GateMode::AgentSafety );
		$this->store->appendStep( $run_id, StepKind::User, array( 'text' => 'Clear the cache' ) );
		$this->store->appendStep( $run_id, StepKind::Model, array( 'text' => 'Done.' ) );

		// Force the row into the 0.2 shape directly: withheld_roles_json and
		// follow_up_of NULL (0.2 never had them), and skills_disable_json
		// entirely absent from the row (0.2 predates that column too).
		$table = \Specflux\SenroFlux\Schema::runsTable( $this->db );
		foreach ( $this->db->tables[ $table ] as $i => $row ) {
			if ( (int) $row['id'] === $run_id ) {
				$this->db->tables[ $table ][ $i ]['status']              = RunStatus::Completed->value;
				$this->db->tables[ $table ][ $i ]['finished_at']         = gmdate( 'Y-m-d H:i:s' );
				$this->db->tables[ $table ][ $i ]['result_json']         = (string) wp_json_encode(
					array(
						'summary' => 'Done.',
						'changes' => array(),
					)
				);
				$this->db->tables[ $table ][ $i ]['withheld_roles_json'] = null;
				$this->db->tables[ $table ][ $i ]['follow_up_of']        = null;
				unset( $this->db->tables[ $table ][ $i ]['skills_disable_json'] );
			}
		}

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $this->runner );

		$result = senroflux()->get( $run_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertNull( $result['run']['error'] );
		$this->assertIsArray( $result['run']['report'] );
		$this->assertSame( 'Done.', $result['run']['report']['summary'] ?? null );
		$this->assertCount( 2, $result['steps'] );
		$this->assertSame( array(), $result['run']['withheld_roles'] );
		$this->assertNull( $result['run']['follow_up_of'] );

		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}
}
