<?php
/**
 * Runner-level coverage for the style-variation abilities (0.3 quality
 * feature 5), mirroring {@see SiteReportTest}: a `set-style` write must open
 * exactly one report row that resolves to "Site style" (never "unknown"),
 * and a no-argument re-read verifies it without a nudge.
 *
 * Runs the REAL `senroflux/read-style` / `senroflux/set-style` abilities (via
 * {@see Style}) through the REAL {@see SitePack} verb/id-key/write-id seams
 * and the REAL {@see Runner}, with a `$post_lookup` that mirrors the
 * dispatcher wired in `Plugin::runner()`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Site\SitePack;
use Specflux\SenroFlux\Packs\Site\Style;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Report;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class StyleReportTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	private SitePack $pack;

	protected function setUp(): void {
		require_once __DIR__ . '/../stubs/blocks.php';
		require_once __DIR__ . '/../stubs/media.php';
		require_once __DIR__ . '/../stubs/style.php';

		$GLOBALS['senroflux_test_abilities']             = array();
		$GLOBALS['senroflux_test_ability_categories']    = array();
		$GLOBALS['senroflux_test_posts']                 = array();
		$GLOBALS['senroflux_test_next_post_id']          = 100;
		$GLOBALS['senroflux_test_postmeta']              = array();
		$GLOBALS['senroflux_test_user_caps']             = array( 'edit_theme_options' => true );
		$GLOBALS['senroflux_test_is_block_theme']        = true;
		$GLOBALS['senroflux_test_style_variations']      = array(
			array(
				'title' => 'Default',
				'slug'  => 'default',
			),
			array(
				'title' => 'Bold',
				'slug'  => 'bold',
			),
		);
		$GLOBALS['senroflux_test_global_styles_post_id'] = 0;
		$GLOBALS['senroflux_test_current_user_id']       = 1;

		Style::reset();
		Style::register();

		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->pack            = new SitePack();

		Style::useRunContext( null, null ); // Reset any leftover context.

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			$this->bridge,
			// Mirrors Plugin::runner()'s post_lookup dispatcher exactly.
			static function ( string|int $object_id ): array {
				$object_id = (string) $object_id;
				if ( Style::OBJECT_ID === $object_id ) {
					return Style::reportLookup();
				}

				return ( Report::wpPostLookup() )( $object_id );
			},
			fn () => $this->pack->verbMap(),
			fn ( $run, string $ability, array $args ) => $this->pack->verbFor( $ability, $args ),
			fn () => $this->pack,
			fn ( $run, string $verb ) => $this->pack->objectIdKey( $verb ),
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			null,
			null,
			null,
			null,
			fn ( $run, string $verb ) => $this->pack->objectIdPrefix( $verb ),
			fn ( $run, string $verb, array $args, array $output ) => $this->pack->objectIdForWrite( $verb, $args, $output ),
			null,
			fn ( $run, string $verb, array $args ) => $this->pack->objectIdForRead( $verb, $args )
		);
	}

	protected function tearDown(): void {
		Style::forgetRunContext();
		unset( $GLOBALS['wpdb'] );
	}

	private function createRun( array $plan_verbs ): int {
		$run_id = $this->store->createRun( 1, 'test', 'Try a bolder style', array( 'senroflux/*' ), Budget::defaults() );
		Style::useRunContext( $run_id, $this->store );

		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Try a bolder style' ) ) ) )->toArray()
		);

		if ( array() !== $plan_verbs ) {
			$plan_seq = $this->store->appendStep(
				$run_id,
				StepKind::Plan,
				array(
					'goal'        => 'Try a bolder style',
					'steps'       => array(
						array(
							'text'  => 'Switch to the Bold style',
							'verbs' => $plan_verbs,
							'tier'  => VerbTier::TIER_2,
						),
					),
					'assumptions' => array(),
				),
				'senroflux/propose-plan',
				null,
				'parked'
			);
			$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );
		}

		return $run_id;
	}

	private function stepCount( int $run_id ): int {
		return $this->store->getRun( $run_id )->stepCount;
	}

	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	public function test_a_set_style_write_reports_a_real_row_titled_site_style_not_unknown(): void {
		$run_id = $this->createRun( array( 'site/set-style' ) );

		// One model turn calling BOTH abilities: driveLoop() runs every
		// function call in a turn before asking the model again.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading, then setting the style.' ),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-style', array() ) ),
			new MessagePart( new FunctionCall( 'call_set', 'wpab__senroflux__set-style', array( 'slug' => 'bold' ) ) )
		);

		// S12: the first finish with an unverified write only nudges (stays
		// running) — the report is built on the SECOND finish attempt.
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$first                   = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->assertSame( 'running', $first['run']['status'] ?? null );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] ?? null );

		$report  = $result['ui']['report'] ?? array();
		$changes = $report['changes'] ?? array();
		$this->assertCount( 1, $changes, 'the style write must open exactly one change row' );

		$row = $changes[0];
		$this->assertNotSame( 'unknown', $row['object_type'] ?? null, 'the row must resolve, not fall back to unknown' );
		$this->assertSame( 'Site style', $row['title'] ?? null );
	}

	public function test_re_reading_the_style_after_writing_it_verifies_it_without_a_nudge(): void {
		$run_id = $this->createRun( array( 'site/set-style' ) );

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading, then setting the style.' ),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-style', array() ) ),
			new MessagePart( new FunctionCall( 'call_set', 'wpab__senroflux__set-style', array( 'slug' => 'bold' ) ) )
		);
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Checking the result.' ),
			new MessagePart( new FunctionCall( 'call_reread', 'wpab__senroflux__read-style', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'Done.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame(
			'completed',
			$result['run']['status'] ?? null,
			'a re-read after the write must count as verification — no verify nudge'
		);

		$changes = $result['ui']['report']['changes'] ?? array();
		$this->assertCount( 1, $changes );
		$this->assertTrue( $changes[0]['verified'] ?? null );
	}
}
