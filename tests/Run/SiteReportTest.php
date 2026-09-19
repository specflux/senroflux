<?php
/**
 * Report-defect regression (live run 56, 0.3 S8/S12, defect 2): a
 * READ-ONLY object (site navigation, read but never written) must never
 * open a verify-nudge or a report row, and an ACTUAL write
 * (`update-navigation`) must resolve to a real "navigation" row, not
 * "unknown".
 *
 * Runs the REAL `senroflux/read-navigation` / `senroflux/update-navigation`
 * abilities (via {@see Navigation}) through the REAL {@see SitePack}
 * verb/id-key/id-prefix/write-id seams and the REAL {@see Runner}, with a
 * `$post_lookup` and `write_object_id_resolver` that mirror the dispatchers
 * wired in `Plugin::runner()` — the same seams a real run uses.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Packs\Site\Navigation;
use Specflux\SenroFlux\Packs\Site\SitePack;
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

final class SiteReportTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	private SitePack $pack;

	protected function setUp(): void {
		require_once __DIR__ . '/../stubs/blocks.php';
		require_once __DIR__ . '/../stubs/navigation.php';

		$GLOBALS['senroflux_test_abilities']               = array();
		$GLOBALS['senroflux_test_ability_categories']      = array();
		$GLOBALS['senroflux_test_posts']                   = array();
		$GLOBALS['senroflux_test_next_post_id']            = 100;
		$GLOBALS['senroflux_test_user_caps']               = array( 'edit_theme_options' => true );
		$GLOBALS['senroflux_test_is_block_theme']          = true;
		$GLOBALS['senroflux_test_header_template_content'] = null;
		$GLOBALS['senroflux_test_nav_fallback']            = null;
		$GLOBALS['senroflux_test_registered_nav_menus']    = array();
		$GLOBALS['senroflux_test_nav_menu_locations']      = array();
		$GLOBALS['senroflux_test_nav_menu_items']          = array();
		$GLOBALS['senroflux_test_current_user_id']         = 1;
		$GLOBALS['senroflux_test_transients']              = array();

		Navigation::reset();
		Navigation::registerCategory();
		Navigation::register();

		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->pack            = new SitePack();

		Navigation::useRunContext( null, null ); // Reset any leftover context.

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			$this->bridge,
			// Mirrors Plugin::runner()'s post_lookup dispatcher exactly.
			static function ( string|int $object_id ): array {
				$object_id = (string) $object_id;
				if ( Navigation::OBJECT_ID === $object_id ) {
					return Navigation::reportLookup();
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
			fn ( $run, string $verb, array $args, array $output ) => $this->pack->objectIdForWrite( $verb, $args, $output )
		);
	}

	protected function tearDown(): void {
		Navigation::forgetRunContext();
		unset( $GLOBALS['wpdb'] );
	}

	/** Insert a wp_navigation post with the given content, return its id. */
	private function insertNav( string $content ): int {
		return wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Navigation',
				'post_content' => $content,
			)
		);
	}

	private function createRun( array $plan_verbs ): int {
		$run_id = $this->store->createRun( 1, 'test', 'Tidy up the site navigation', array( 'senroflux/*' ), Budget::defaults() );
		Navigation::useRunContext( $run_id, $this->store );

		$this->store->appendStep(
			$run_id,
			StepKind::User,
			( new UserMessage( array( new MessagePart( 'Tidy up the site navigation' ) ) ) )->toArray()
		);

		if ( array() !== $plan_verbs ) {
			$plan_seq = $this->store->appendStep(
				$run_id,
				StepKind::Plan,
				array(
					'goal'        => 'Tidy up the site navigation',
					'steps'       => array(
						array(
							'text'  => 'Update the navigation',
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

	// ------------------------------------------------------------------
	// Defect 2a: a READ-ONLY object never nudges and never reports.
	// ------------------------------------------------------------------

	public function test_a_read_only_navigation_never_nudges_and_opens_no_report_row(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Home","url":"https://example.test/"} /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$run_id = $this->createRun( array() );

		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading the navigation.' ),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-navigation', array() ) )
		);
		$this->gateway->script[] = self::textTurn( 'The navigation lists Home. Nothing to change.' );

		$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame(
			'completed',
			$result['run']['status'] ?? null,
			'a read with no write must complete on the FIRST finish attempt — no verify nudge'
		);

		$report  = $result['ui']['report'] ?? array();
		$changes = $report['changes'] ?? array();
		$this->assertSame( array(), $changes, 'a read-only object must never open a report row' );
	}

	// ------------------------------------------------------------------
	// Defect 2b: an ACTUAL write resolves to a real row, not "unknown".
	// ------------------------------------------------------------------

	public function test_an_update_navigation_write_reports_a_real_row_not_unknown(): void {
		$nav_id = $this->insertNav( '<!-- wp:navigation-link {"label":"Old","url":"https://example.test/old"} /-->' );
		$GLOBALS['senroflux_test_header_template_content'] = '<!-- wp:navigation {"ref":' . $nav_id . '} /-->';

		$run_id = $this->createRun( array( 'site/update-navigation' ) );

		// One model turn calling BOTH abilities: driveLoop() runs every
		// function call in a turn before asking the model again, exactly
		// like the site pack's own "read, then write" sequencing.
		$this->gateway->script[] = self::turn(
			new MessagePart( 'Reading, then updating the navigation.' ),
			new MessagePart( new FunctionCall( 'call_read', 'wpab__senroflux__read-navigation', array() ) ),
			new MessagePart(
				new FunctionCall(
					'call_update',
					'wpab__senroflux__update-navigation',
					array(
						'items' => array(
							array(
								'label' => 'New',
								'url'   => 'https://example.test/new',
								'order' => 0,
							),
						),
					)
				)
			)
		);

		// S12: the first finish with an unverified write only nudges (stays
		// running) — the report is built on the SECOND finish attempt,
		// exactly like AttachmentReportTest.
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$first                   = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$this->assertSame( 'running', $first['run']['status'] ?? null );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'] ?? null );

		$report  = $result['ui']['report'] ?? array();
		$changes = $report['changes'] ?? array();
		$this->assertCount( 1, $changes, 'the navigation write must open exactly one change row' );

		$row = $changes[0];
		$this->assertNotSame( 'unknown', $row['object_type'] ?? null, 'defect 2: the row must resolve, not fall back to unknown' );
		$this->assertSame( 'navigation', $row['object_type'] ?? null );
		$this->assertNotSame( '', $row['title'] ?? '' );
	}
}
