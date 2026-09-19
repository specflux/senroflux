<?php
/**
 * Plugin::listRecent() row affordances (0.3 S9, stage 8): the
 * `senroflux_can_tick`-derived `viewer_may_tick` flag and the stalled-run
 * (`running`, no live 30-second tick lock) detection.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use WP_Error;
use wpdb;

final class PluginListRecentTest extends TestCase {

	private Runner $runner;

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( false );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
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
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	private function createRun( int $owner_id, RunStatus $status ): int {
		$run_id = $this->runner->store()->createRun( $owner_id, 'test-consumer', 'goal', array( 'agsafe-smoke/*' ), Budget::defaults() );
		$this->runner->store()->updateRun( $run_id, array( 'status' => $status->value ) );

		return $run_id;
	}

	private function row( array $rows, int $run_id ): array {
		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === $run_id ) {
				return $row;
			}
		}

		$this->fail( "run {$run_id} not found in listRecent()" );
	}

	public function test_viewer_may_tick_defaults_to_owner_only(): void {
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$own_run                                   = $this->createRun( 1, RunStatus::AwaitingApproval );
		$other_run                                 = $this->createRun( 2, RunStatus::AwaitingApproval );

		$rows = senroflux()->listRecent();

		$this->assertTrue( $this->row( $rows, $own_run )['viewer_may_tick'] );
		$this->assertFalse( $this->row( $rows, $other_run )['viewer_may_tick'] );
	}

	public function test_viewer_may_tick_honours_the_delegation_filter(): void {
		add_filter( 'senroflux_can_tick', static fn (): bool => true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$other_run                                 = $this->createRun( 2, RunStatus::AwaitingApproval );

		$rows = senroflux()->listRecent();

		$this->assertTrue( $this->row( $rows, $other_run )['viewer_may_tick'] );
	}

	public function test_running_run_with_no_lock_is_stalled(): void {
		$run_id = $this->createRun( 1, RunStatus::Running );

		$rows = senroflux()->listRecent();

		$this->assertTrue( $this->row( $rows, $run_id )['stalled'] );
	}

	public function test_running_run_with_a_live_lock_is_not_stalled(): void {
		$run_id = $this->createRun( 1, RunStatus::Running );
		set_transient( 'senroflux_lock_' . $run_id, 1, 30 );

		$rows = senroflux()->listRecent();

		$this->assertFalse( $this->row( $rows, $run_id )['stalled'] );

		delete_transient( 'senroflux_lock_' . $run_id );
	}

	public function test_a_parked_run_is_never_reported_stalled(): void {
		$run_id = $this->createRun( 1, RunStatus::AwaitingApproval );

		$rows = senroflux()->listRecent();

		$this->assertFalse( $this->row( $rows, $run_id )['stalled'] );
	}
}
