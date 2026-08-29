<?php
/**
 * Runner::tick() tests for the S12 written-object set, the verify nudge, and
 * the harness-built terminal report (stage-5 draft).
 *
 * Unlike PlanParkTest (S7), the verb map marks BOTH read and write as tier 0,
 * so a write executes WITHOUT the plan fence — the point here is the S12
 * write/verify/report mechanics, not the fence.
 *
 * @package SenroFlux
 */


declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use SenroFlux_Test_Fake_Ability;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class VerificationReportTest extends TestCase {

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
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, $this->bridge, self::lookup() );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_abilities']       = array(
			'agsafe-smoke/write' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/write',
				permission_result: true,
				execute_result: array( 'id' => 42 )
			),
			'agsafe-smoke/read'  => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/read',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
		);

		// S12 needs the write to EXECUTE (no plan fence), so both verbs are
		// tier 0 here — unlike PlanParkTest where write is tier 1.
		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'agsafe-smoke/read'  => VerbTier::TIER_0,
				'agsafe-smoke/write' => VerbTier::TIER_0,
			),
			10,
			1
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * The fake post lookup: shape the report would otherwise get from WP.
	 *
	 * @return callable(string|int):array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	private static function lookup(): callable {
		return static function ( string|int $id ): array {
			return array(
				'object_type' => 'post',
				'title'       => 'The draft for ' . $id,
				'status'      => 'draft',
				'edit_url'    => 'https://example.test/edit/' . $id,
				'preview_url' => 'https://example.test/preview/' . $id,
			);
		};
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
	 * A model turn made of arbitrary parts (text, call).
	 *
	 * @param MessagePart ...$parts
	 */
	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	/** @return list<\Specflux\SenroFlux\Run\Step> */
	private function verifyNudges( int $run_id ): array {
		$out = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::System === $step->kind
				&& null !== $step->messageArray
				&& 'verify_nudge' === ( $step->messageArray['note'] ?? null ) ) {
				$out[] = $step;
			}
		}

		return $out;
	}

	// ------------------------------------------------------------------
	// (a) a write tracks objects_json; the first finish parks with ONE nudge
	// ------------------------------------------------------------------

	public function test_a_write_tracks_object_and_first_finish_parks_with_one_verify_nudge(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertNotSame( 'completed', $result['run']['status'], 'a first finish with an unverified write stays running' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->objects, 'objects_json is recorded after a write' );
		$this->assertArrayHasKey( '42', $run->objects );
		$this->assertSame( 3, $run->objects['42']['last_write_seq'], 'user(1)+model(2)+tool_result(3)' );
		$this->assertNull( $run->objects['42']['verified_seq'], 'a new write resets verified_seq to null' );

		// EXACTLY ONE nudge is appended this tick.
		$nudges = $this->verifyNudges( $run_id );
		$this->assertCount( 1, $nudges );
		$this->assertSame( 'verify_nudge', $nudges[0]->messageArray['note'] ?? null );
		$this->assertSame( array( '42' ), $nudges[0]->messageArray['objects'] ?? null );
	}

	// ------------------------------------------------------------------
	// (b) a second finish completes regardless; the object reports verified:false
	// ------------------------------------------------------------------

	public function test_b_second_finish_completes_and_marks_unverified_false(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$first                   = $this->runner->tick( $run_id, 0, null );
		$this->assertNotSame( 'completed', $first['run']['status'] );
		$this->assertCount( 1, $this->verifyNudges( $run_id ) );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$second                  = $this->runner->tick( $run_id, $before, null );

		$this->assertIsArray( $second );
		$this->assertSame( 'completed', $second['run']['status'], 'the second finish attempt completes anyway' );

		$report = $second['ui']['report'] ?? array();
		$this->assertSame( 'Done.', $report['summary'] ?? null );
		$this->assertCount( 1, $report['changes'] ?? array() );
		$this->assertSame( '42', $report['changes'][0]['object_id'] ?? null );
		$this->assertFalse( $report['changes'][0]['verified'] ?? true, 'an unverified object is reported verified:false' );
	}

	// ------------------------------------------------------------------
	// (c) a read-back between the write and the finish verifies the object
	// ------------------------------------------------------------------

	public function test_c_a_read_back_between_write_and_finish_marks_verified_true(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading it back.' ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array( 'id' => 42 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'a verification read lets the finish complete in the same tick' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->objects );
		$this->assertSame( 5, $run->objects['42']['verified_seq'] ?? null, 'read-back tool_result seq' );

		$report = $result['ui']['report'] ?? array();
		$this->assertSame( 'Done.', $report['summary'] ?? null );
		$this->assertSame( '42', $report['changes'][0]['object_id'] ?? null );
		$this->assertTrue( $report['changes'][0]['verified'] ?? false );
	}

	// ------------------------------------------------------------------
	// (d) report URLs come from the injected lookup, never from model text
	// ------------------------------------------------------------------

	public function test_d_report_urls_come_from_the_lookup_never_model_text(): void {
		$run_id = $this->createRun();
		// The model's prose tries to smuggle a URL; the report must ignore it.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Published at https://model.example/evil' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'https://model.example/evil' );
		$first                   = $this->runner->tick( $run_id, 0, null );
		$this->assertNotSame( 'completed', $first['run']['status'] );

		$this->gateway->script[] = self::textTurn( 'https://model.example/evil' );
		$before                  = $this->store->getRun( $run_id )->stepCount;
		$final                   = $this->runner->tick( $run_id, $before, null );

		$report = $final['ui']['report'] ?? array();
		$this->assertSame( 'https://model.example/evil', $report['summary'] ?? null, 'summary IS the model text' );

		$change = $report['changes'][0] ?? array();
		$this->assertSame( 'post', $change['object_type'] ?? null );
		$this->assertSame( 'https://example.test/edit/42', $change['edit_url'] ?? null, 'edit_url comes from the injected lookup' );
		$this->assertSame( 'https://example.test/preview/42', $change['preview_url'] ?? null, 'preview_url comes from the injected lookup' );
	}

	// ------------------------------------------------------------------
	// (e) a failed run still gets a partial report (failError path)
	// ------------------------------------------------------------------

	public function test_e_a_failed_run_still_gets_a_partial_report(): void {
		$run_id = $this->createRun();
		// Only the write turn is scripted; the loop's next generateTurn has no
		// script -> FakeGateway returns a WP_Error -> model_error -> partial report.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );

		$report = $result['ui']['report'] ?? array();
		$this->assertSame( 'Writing the page.', $report['summary'] ?? null, 'a partial report keeps the model prose' );
		$this->assertCount( 1, $report['changes'] ?? array() );
		$this->assertFalse( $report['changes'][0]['verified'] ?? true );
	}

	// ------------------------------------------------------------------
	// (f) Plugin::get() surfaces the persisted report (DIFF-dependent)
	// ------------------------------------------------------------------

	/**
	 * Requires Plugin.diff.md (get() adds 'report'); the run's result_json is
	 * seeded directly and read back through the plugin API.
	 */
	public function test_f_plugin_get_surfaces_persisted_report(): void {
		$run_id = $this->createRun();
		$report = array(
			'summary' => 'Done',
			'changes' => array(
				array(
					'object_id' => '42',
					'verified'  => false,
				),
			),
		);
		$this->store->updateRun( $run_id, array( 'result_json' => $report ) );

		$GLOBALS['wpdb'] = $this->db;
		Plugin::set_dependency_probe( true );
		$plugin = Plugin::instance();

		$out = $plugin->get( $run_id );

		$this->assertIsArray( $out );
		$this->assertSame( $report, $out['run']['report'] ?? null, 'Plugin::get() returns the report on the run' );
	}
}
