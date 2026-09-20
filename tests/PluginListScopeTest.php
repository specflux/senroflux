<?php
/**
 * Plugin::listRecent() visibility scoping (0.3 S10, owner decision
 * 2026-09-20): a viewer sees a run they own, a run they may DRIVE (the S13
 * delegation seam), or every run if they hold the Runs-screen capability.
 *
 * Until 0.3 this method returned every run on the site, which was looser than
 * {@see \Specflux\SenroFlux\Plugin::get()} on the same run. The screen
 * capability is a pack RUN capability (S10) — `edit_pages` opens the screen —
 * so the old behaviour showed one author's goals to every other author.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use wpdb;

final class PluginListScopeTest extends TestCase {

	private const VIEWER = 7;
	private const OTHER  = 99;

	private Runner $runner;

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( false );
		$GLOBALS['senroflux_test_current_user_id'] = self::VIEWER;
		$GLOBALS['senroflux_test_user_caps']       = array();
		$GLOBALS['senroflux_test_transients']      = array();

		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$this->runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $this->runner );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_can_tick' );
		remove_all_filters( 'senroflux_runs_capability' );
		Plugin::reset();
		unset( $GLOBALS['wpdb'], $GLOBALS['senroflux_test_user_caps'] );
	}

	private function createRun( int $owner_id ): int {
		return $this->runner->store()->createRun( $owner_id, 'test-consumer', 'goal', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	/** @param list<array<string,mixed>> $rows */
	private function ids( array $rows ): array {
		return array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
	}

	public function test_viewer_sees_their_own_run(): void {
		$mine = $this->createRun( self::VIEWER );

		$this->assertSame( array( $mine ), $this->ids( Plugin::instance()->listRecent() ) );
	}

	public function test_viewer_does_not_see_another_users_run(): void {
		$this->createRun( self::OTHER );

		$this->assertSame( array(), Plugin::instance()->listRecent() );
	}

	public function test_runs_capability_holder_sees_every_run(): void {
		$mine   = $this->createRun( self::VIEWER );
		$theirs = $this->createRun( self::OTHER );

		$GLOBALS['senroflux_test_user_caps']['manage_options'] = true;

		$ids = $this->ids( Plugin::instance()->listRecent() );
		$this->assertContains( $mine, $ids );
		$this->assertContains( $theirs, $ids );
	}

	/**
	 * S13: a screen-capability holder may resolve a parked run they do not
	 * own. Scoping the list must not take that away — a run the viewer may
	 * TICK is a run the viewer must be able to SEE.
	 */
	public function test_a_run_the_viewer_may_drive_stays_visible(): void {
		$theirs = $this->createRun( self::OTHER );

		add_filter( 'senroflux_can_tick', static fn (): bool => true );

		$this->assertSame( array( $theirs ), $this->ids( Plugin::instance()->listRecent() ) );
	}

	/**
	 * The screen capability is filterable, and `maySee()` reads the filtered
	 * value — a site that lowers it must widen the list to match, or the
	 * screen would list nothing for the very role it was lowered for.
	 */
	public function test_a_lowered_runs_capability_widens_the_list(): void {
		$theirs = $this->createRun( self::OTHER );

		add_filter( 'senroflux_runs_capability', static fn (): string => 'edit_pages' );
		$GLOBALS['senroflux_test_user_caps']['edit_pages'] = true;

		$this->assertSame( array( $theirs ), $this->ids( Plugin::instance()->listRecent() ) );
	}
}
