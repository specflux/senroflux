<?php
/**
 * Schema tests (0.2 S4, plus the v3 skills_disable_json column, the v4
 * gate_mode column, the v5 withheld_roles_json column, and the v6
 * follow_up_of column): the new columns
 * exist, the version option is stamped, and the upgrade is idempotent.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Schema;
use wpdb;

final class SchemaUpgradeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_options']         = array();
		$GLOBALS['senroflux_test_dbdelta_queries'] = array();
	}

	public function test_schema_version_is_five(): void {
		$this->assertSame( 6, Schema::DB_VERSION );
	}

	/** @return list<string> The 0.2-0.3 columns every current runs table carries. */
	private function newColumnFragments(): array {
		return array(
			'pack VARCHAR(64) NULL',
			'skills_json LONGTEXT NULL',
			'skills_disable_json LONGTEXT NULL',
			'result_json LONGTEXT NULL',
			'objects_json LONGTEXT NULL',
			'accepted_plan_step_id BIGINT(20) UNSIGNED NULL',
			'conversation_locale VARCHAR(20) NULL',
			'content_locale VARCHAR(20) NULL',
			'gate_mode VARCHAR(20) NOT NULL DEFAULT \'agent_safety\'',
			'withheld_roles_json TEXT NULL',
			'follow_up_of BIGINT(20) UNSIGNED NULL',
		);
	}

	public function test_runs_columns_carry_the_additive_columns(): void {
		$columns = Schema::runsColumns();

		foreach ( $this->newColumnFragments() as $fragment ) {
			$this->assertStringContainsString( $fragment, $columns );
		}

		// Additive: the 0.1 columns are untouched.
		$this->assertStringContainsString( 'allow_json LONGTEXT NULL', $columns );
		$this->assertStringContainsString( 'budget_json TEXT NULL', $columns );
	}

	public function test_maybe_upgrade_installs_and_stamps_the_version_option(): void {
		$db = new wpdb();

		Schema::maybe_upgrade( $db );

		$this->assertSame( 6, get_option( 'senroflux_db_version' ) );
		$this->assertCount( 2, $GLOBALS['senroflux_test_dbdelta_queries'], 'runs + steps statements' );
	}

	public function test_maybe_upgrade_is_idempotent_at_the_current_version(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 6;

		Schema::maybe_upgrade( $db );

		$this->assertSame( array(), $GLOBALS['senroflux_test_dbdelta_queries'], 'no dbDelta ran at the current version' );
	}

	public function test_an_old_version_option_reruns_dbdelta(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 1;

		Schema::maybe_upgrade( $db );

		$this->assertSame( 6, get_option( 'senroflux_db_version' ) );
		$this->assertCount( 2, $GLOBALS['senroflux_test_dbdelta_queries'] );

		// dbDelta is idempotent by design: re-running the SAME statements is
		// exactly how a v1 table gains the later columns.
		$statements = implode( "\n", $GLOBALS['senroflux_test_dbdelta_queries'] );
		$this->assertStringContainsString( 'accepted_plan_step_id', $statements );
		$this->assertStringContainsString( 'skills_disable_json', $statements );
		$this->assertStringContainsString( 'withheld_roles_json', $statements );
		$this->assertStringContainsString( 'follow_up_of', $statements );
	}

	// ------------------------------------------------------------------
	// S14 ("Upgrade from 0.2"): the legacy-run watermark.
	// ------------------------------------------------------------------

	public function test_a_pre_0_3_install_with_a_live_run_writes_the_watermark_at_max_id(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 3;
		// Runner's own get_var() calls, in the order Schema issues them:
		// first the live (non-terminal) run count, then MAX(id).
		$db->varQueue = array( 1, 42 );

		Schema::maybe_upgrade( $db );

		$this->assertSame( 42, get_option( 'senroflux_legacy_run_watermark' ) );
	}

	public function test_a_pre_0_3_install_with_only_terminal_runs_writes_no_watermark(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 3;
		// Only the live-run-count call should happen; it answers zero, so
		// MAX(id) is never even asked for.
		$db->varQueue = array( 0 );

		Schema::maybe_upgrade( $db );

		$this->assertFalse( get_option( 'senroflux_legacy_run_watermark', false ) );
	}

	public function test_a_fresh_install_writes_no_watermark(): void {
		$db = new wpdb();
		// senroflux_db_version absent entirely (defaults to 0): a fresh
		// install has no rows to protect.
		$db->varQueue = array( 5, 99 ); // Would be misread as a live run if the guard were missing.

		Schema::maybe_upgrade( $db );

		$this->assertFalse( get_option( 'senroflux_legacy_run_watermark', false ) );
	}

	public function test_an_already_current_install_writes_no_watermark_and_does_not_rerun_dbdelta(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 6;
		$db->varQueue = array( 5, 99 );

		Schema::maybe_upgrade( $db );

		$this->assertSame( array(), $GLOBALS['senroflux_test_dbdelta_queries'], 'no dbDelta ran at the current version' );
		$this->assertFalse( get_option( 'senroflux_legacy_run_watermark', false ) );
	}

	public function test_re_running_the_upgrade_does_not_overwrite_an_existing_watermark(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version']           = 3;
		$GLOBALS['senroflux_test_options']['senroflux_legacy_run_watermark'] = 10;
		// If the guard were missing this would answer a live run at a higher
		// MAX(id), overwriting 10 with 99.
		$db->varQueue = array( 1, 99 );

		Schema::maybe_upgrade( $db );

		$this->assertSame( 10, get_option( 'senroflux_legacy_run_watermark' ), 'an existing watermark is never overwritten' );
	}
}
