<?php
/**
 * Plugin-level tests for 0.3 S20's follow-up-run rules: allowed source
 * statuses, the forced pack, the source pack's run capability, and the new
 * `follow_up_of` column round-tripping through start()/get().
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use WP_Error;
use wpdb;

final class PluginFollowUpTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_user_caps_by_id'] = array();
		$GLOBALS['senroflux_test_user_caps']       = array();
		$GLOBALS['wpdb']                           = new wpdb();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_user_caps_by_id'][1]['edit_posts'] = true;
		// follow_up_of's capability check reads the CURRENT user (current_user_can()),
		// unlike S6's withheld-roles check (a per-owner user_can() check) — both
		// knobs are set so this file doesn't depend on which one start() uses.
		$GLOBALS['senroflux_test_user_caps']['edit_posts'] = true;
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_packs' );
		unset( $GLOBALS['wpdb'], $GLOBALS['senroflux_test_user_caps_by_id'], $GLOBALS['senroflux_test_user_caps'] );
		Plugin::reset();
	}

	private function registerFixturePack(): void {
		add_filter(
			'senroflux_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new class() extends Pack {
					public function __construct() {
						parent::__construct( array( 'read' => 'read-content' ) );
					}

					public function name(): string {
						return 'fixture';
					}

					public function verbMap(): array {
						return array( 'senroflux/read-content' => 0 );
					}

					public function runCapability(): string {
						return 'edit_posts';
					}

					protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
						unset( $user_id );

						return null;
					}
				};

				return $packs;
			}
		);
	}

	/** @return int Source run id. */
	private function seedFinishedSourceRun( string $status = 'completed', ?string $pack = 'fixture' ): int {
		$this->registerFixturePack();

		$prop = new \ReflectionMethod( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$runner = $prop->invoke( Plugin::instance() );
		$store  = $runner->store();

		$run_id = $store->createRun( 1, 'test-consumer', 'Original goal', array( 'senroflux/read-content' ), array(), $pack );
		$store->updateRun(
			$run_id,
			array(
				'status'      => $status,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'result_json' => array(
					'summary' => '',
					'changes' => array(),
				),
			)
		);

		return $run_id;
	}

	public function test_a_follow_up_forces_the_source_pack_and_records_the_source_id(): void {
		$source_id = $this->seedFinishedSourceRun( 'completed' );

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up on it', array(), array(), null, null, $source_id );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'fixture', $result['run']['pack'] );
		$this->assertSame( $source_id, $result['run']['follow_up_of'] );

		$fresh = Plugin::instance()->get( $result['run']['id'] );
		$this->assertIsArray( $fresh );
		$this->assertSame( $source_id, $fresh['run']['follow_up_of'] );
	}

	public function test_a_caller_supplied_pack_is_ignored_in_favour_of_the_source_pack(): void {
		$source_id = $this->seedFinishedSourceRun( 'completed' );

		// Ask for a pack that does not even exist; start() must still force
		// the source's pack rather than 400 on the caller's bad guess.
		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), 'not-a-real-pack', null, $source_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'fixture', $result['run']['pack'] );
	}

	public function test_a_running_source_is_not_finished(): void {
		$source_id = $this->seedFinishedSourceRun( 'running' );

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, $source_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'follow_up_not_finished', $result->get_error_code() );
	}

	public function test_failed_and_cancelled_sources_are_both_allowed(): void {
		foreach ( array( 'failed', 'cancelled' ) as $status ) {
			$source_id = $this->seedFinishedSourceRun( $status );

			$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, $source_id );

			$this->assertIsArray( $result, "status={$status}: " . ( is_object( $result ) ? $result->get_error_message() : '' ) );
		}
	}

	public function test_an_unknown_source_run_is_refused(): void {
		$this->registerFixturePack();

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, 999999 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'follow_up_not_found', $result->get_error_code() );
	}

	public function test_a_source_run_with_no_pack_is_unsupported(): void {
		$source_id = $this->seedFinishedSourceRun( 'completed', null );

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, $source_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'follow_up_unsupported', $result->get_error_code() );
	}

	public function test_a_starter_lacking_the_source_packs_capability_is_forbidden(): void {
		$source_id = $this->seedFinishedSourceRun( 'completed' );
		$GLOBALS['senroflux_test_user_caps_by_id'][1]['edit_posts'] = false;
		$GLOBALS['senroflux_test_user_caps']['edit_posts']          = false;

		$result = Plugin::instance()->start( 'specflux-mac', 'Follow up', array(), array(), null, null, $source_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'follow_up_forbidden', $result->get_error_code() );
	}
}
