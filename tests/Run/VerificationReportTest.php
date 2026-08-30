<?php
/**
 * Runner::tick() tests for the S12 written-object set, the verify nudge, and
 * the harness-built terminal report.
 *
 * S12 scopes the written-object set to Tier >= 1 writes, so the write verb here
 * really is tier 1 and every run seeds an ACCEPTED plan covering it — the S7
 * fence is not what these tests are about, but a tier-0 write would make them
 * test nothing.
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
use WordPress\AiClient\Messages\DTO\UserMessage;
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
			// A tier-1 call that confirms nothing: the model may pass the id
			// but it is not a read-back.
			'agsafe-smoke/touch' => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/touch',
				permission_result: true,
				execute_result: array( 'ok' => true )
			),
			// A tier-0 read that happens to echo an id of its own.
			'agsafe-smoke/list'  => new SenroFlux_Test_Fake_Ability(
				'agsafe-smoke/list',
				permission_result: true,
				execute_result: array( 'id' => 77 )
			),
		);

		add_filter(
			'senroflux_verb_map',
			static fn ( array $map ): array => $map + array(
				'agsafe-smoke/read'  => VerbTier::TIER_0,
				'agsafe-smoke/list'  => VerbTier::TIER_0,
				'agsafe-smoke/write' => VerbTier::TIER_1,
				'agsafe-smoke/touch' => VerbTier::TIER_1,
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
		$run_id = $this->store->createRun(
			1,
			'test-consumer',
			'Clear the cache',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), $budget_override )
		);

		// The goal step the loop would seed itself, then an ACCEPTED plan
		// covering the tier-1 verbs (S7) so the writes below actually execute.
		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Clear the cache' ) ) ) )->toArray()
		);
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'Write the page',
				'steps'       => array(
					array(
						'text'  => 'Write it',
						'verbs' => array( 'agsafe-smoke/write', 'agsafe-smoke/touch' ),
						'tier'  => VerbTier::TIER_1,
					),
				),
				'assumptions' => array(),
			),
			'senroflux/propose-plan',
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		return $run_id;
	}

	/** The run's current step_count — the optimistic-lock echo for tick(). */
	private function stepCount( int $run_id ): int {
		return $this->store->getRun( $run_id )->stepCount;
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

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		// S12: nudged means KEEP RUNNING — not left in the status the run
		// started life with.
		$this->assertSame( 'running', $result['run']['status'], 'a first finish with an unverified write stays running' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->objects, 'objects_json is recorded after a write' );
		$this->assertArrayHasKey( '42', $run->objects );
		$this->assertSame( 4, $run->objects['42']['last_write_seq'], 'user(1)+plan(2)+model(3)+tool_result(4)' );
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
		$first                   = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->assertNotSame( 'completed', $first['run']['status'] );
		$this->assertCount( 1, $this->verifyNudges( $run_id ) );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$second                  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

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

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'a verification read lets the finish complete in the same tick' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertNotNull( $run->objects );
		$this->assertSame( 6, $run->objects['42']['verified_seq'] ?? null, 'read-back tool_result seq' );

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
		$first                   = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->assertNotSame( 'completed', $first['run']['status'] );

		$this->gateway->script[] = self::textTurn( 'https://model.example/evil' );
		$final                   = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

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

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );

		$report = $result['ui']['report'] ?? array();
		$this->assertSame( 'Writing the page.', $report['summary'] ?? null, 'a partial report keeps the model prose' );
		$this->assertCount( 1, $report['changes'] ?? array() );
		$this->assertFalse( $report['changes'][0]['verified'] ?? true );
	}

	// ------------------------------------------------------------------
	// (g) a tier-0 read is not a change, however id-shaped its result
	// ------------------------------------------------------------------

	public function test_g_a_tier_0_read_returning_an_id_is_not_a_change(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Listing.' ),
			new MessagePart( new FunctionCall( 'call_l', 'wpab__agsafe-smoke__list', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], 'nothing was written, so nothing needs re-reading' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertSame( array(), $run->objects ?? array(), 'a tier-0 read never opens a change' );
		$this->assertSame( array(), $result['ui']['report']['changes'] ?? array() );
		$this->assertCount( 0, $this->verifyNudges( $run_id ) );
	}

	// ------------------------------------------------------------------
	// (h) verification is a READ, not the model passing the id to a write
	// ------------------------------------------------------------------

	public function test_h_a_tier_1_call_carrying_the_id_does_not_verify_it(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		// A successful tier-1 call whose args carry the id: model-attestable
		// "verification" the harness must refuse to count.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Touching it.' ),
			new MessagePart( new FunctionCall( 'call_t', 'wpab__agsafe-smoke__touch', array( 'id' => 42 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertNotSame( 'completed', $result['run']['status'], 'the object is still unverified' );

		$run = $this->store->getRun( $run_id );
		$this->assertNotNull( $run );
		$this->assertArrayHasKey( '42', $run->objects ?? array() );
		$this->assertNull( $run->objects['42']['verified_seq'], 'only a read-role call verifies' );
		$this->assertCount( 1, $this->verifyNudges( $run_id ) );
	}

	// ------------------------------------------------------------------
	// (i) the object-id key is resolvable per verb (a post_id read counts)
	// ------------------------------------------------------------------

	public function test_i_a_read_verifies_through_the_resolved_object_id_key(): void {
		$runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			$this->bridge,
			self::lookup(),
			null,
			null,
			null,
			// The pack's read ability names the id `post_id`; the write still
			// returns plain `id`.
			static function ( \Specflux\SenroFlux\Run\Run $run, string $verb ): string {
				unset( $run );

				return 'agsafe-smoke/read' === $verb ? 'post_id' : 'id';
			}
		);

		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading it back.' ),
			new MessagePart( new FunctionCall( 'call_r', 'wpab__agsafe-smoke__read', array( 'post_id' => 42 ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame( 6, $this->store->getRun( $run_id )->objects['42']['verified_seq'] ?? null );
		$this->assertTrue( $result['ui']['report']['changes'][0]['verified'] ?? false );
	}

	// ------------------------------------------------------------------
	// (j) the nudge reaches the MODEL, through the tail, on the next tick
	// ------------------------------------------------------------------

	public function test_j_the_next_tick_tells_the_model_what_to_re_read(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Writing the page.' ),
			new MessagePart( new FunctionCall( 'call_w', 'wpab__agsafe-smoke__write', array( 'title' => 'Draft' ) ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->assertCount( 1, $this->verifyNudges( $run_id ) );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		// One tick can drive several model calls; the nudged tick's instruction
		// is the LAST one the gateway saw.
		$nudged = (string) end( $this->gateway->systemInstructions );
		$this->assertStringContainsString( 'Before finishing, re-read:', $nudged );
		$this->assertStringContainsString( 'The draft for 42 (42)', $nudged, 'named by title, not by bare id' );
	}

	// ------------------------------------------------------------------
	// (f) Plugin::get() surfaces the persisted report (DIFF-dependent)
	// ------------------------------------------------------------------

	/**
	 * The run's result_json is seeded directly and read back through
	 * Plugin::get(), which surfaces it as `report`.
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
