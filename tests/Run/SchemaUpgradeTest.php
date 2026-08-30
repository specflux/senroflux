<?php
/**
 * Schema tests (0.2 S4, plus the v3 skills_disable_json column): the new
 * columns exist, the version option is stamped, and the upgrade is idempotent.
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

	public function test_schema_version_is_three(): void {
		$this->assertSame( 3, Schema::DB_VERSION );
	}

	/** @return list<string> The 0.2 columns every current runs table carries. */
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

		$this->assertSame( 3, get_option( 'senroflux_db_version' ) );
		$this->assertCount( 2, $GLOBALS['senroflux_test_dbdelta_queries'], 'runs + steps statements' );
	}

	public function test_maybe_upgrade_is_idempotent_at_the_current_version(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 3;

		Schema::maybe_upgrade( $db );

		$this->assertSame( array(), $GLOBALS['senroflux_test_dbdelta_queries'], 'no dbDelta ran at the current version' );
	}

	public function test_an_old_version_option_reruns_dbdelta(): void {
		$db = new wpdb();
		$GLOBALS['senroflux_test_options']['senroflux_db_version'] = 1;

		Schema::maybe_upgrade( $db );

		$this->assertSame( 3, get_option( 'senroflux_db_version' ) );
		$this->assertCount( 2, $GLOBALS['senroflux_test_dbdelta_queries'] );

		// dbDelta is idempotent by design: re-running the SAME statements is
		// exactly how a v1 table gains the v2 columns.
		$statements = implode( "\n", $GLOBALS['senroflux_test_dbdelta_queries'] );
		$this->assertStringContainsString( 'accepted_plan_step_id', $statements );
		$this->assertStringContainsString( 'skills_disable_json', $statements );
	}
}
