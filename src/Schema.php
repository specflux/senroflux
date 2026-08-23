<?php
/**
 * Table schema for runs and steps.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux;

use wpdb;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Owns the two custom tables' names, shapes and lifecycle. All timestamps are
 * UTC DATETIME strings (gmdate('Y-m-d H:i:s')), matching Agent Safety's
 * convention of never letting server timezone drift into stored comparisons.
 */
final class Schema {

	/**
	 * Runs table name for this site.
	 */
	public static function runsTable( wpdb $db ): string {
		return $db->prefix . 'senroflux_runs';
	}

	/**
	 * Steps table name for this site.
	 */
	public static function stepsTable( wpdb $db ): string {
		return $db->prefix . 'senroflux_steps';
	}

	/**
	 * Create/upgrade both tables via dbDelta().
	 *
	 * @return void
	 */
	public static function install( wpdb $db ): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $db->get_charset_collate();
		$runs    = self::runsTable( $db );
		$steps   = self::stepsTable( $db );

		dbDelta(
			array(
				"CREATE TABLE {$runs} (\n" . self::runsColumns() . "\n) {$charset}",
				"CREATE TABLE {$steps} (\n" . self::stepsColumns() . "\n) {$charset}",
			)
		);
	}

	/**
	 * Drop both tables (uninstall only).
	 *
	 * @return void
	 */
	public static function uninstall( wpdb $db ): void {
		$runs  = self::runsTable( $db );
		$steps = self::stepsTable( $db );
		$db->query( "DROP TABLE IF EXISTS {$steps}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
		$db->query( "DROP TABLE IF EXISTS {$runs}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
	}

	/**
	 * Runs table column definitions. Kept as a method (not inline SQL) so the
	 * safety-net CREATE IF NOT EXISTS path can never drift from dbDelta's.
	 */
	public static function runsColumns(): string {
		return implode(
			",\n",
			array(
				'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
				'user_id BIGINT(20) UNSIGNED NOT NULL',
				'consumer VARCHAR(64) NOT NULL',
				'goal TEXT NOT NULL',
				'status VARCHAR(20) NOT NULL DEFAULT \'pending\'',
				'allow_json LONGTEXT NULL',
				'budget_json TEXT NULL',
				'step_count INT(11) NOT NULL DEFAULT 0',
				'tokens_in INT(11) NOT NULL DEFAULT 0',
				'tokens_out INT(11) NOT NULL DEFAULT 0',
				'created_at DATETIME NOT NULL',
				'updated_at DATETIME NOT NULL',
				'finished_at DATETIME NULL',
				'error_json TEXT NULL',
				'PRIMARY KEY  (id)',
				'KEY user_id (user_id)',
				'KEY status (status)',
			)
		);
	}

	/**
	 * Steps table columns. The UNIQUE (run_id, seq) pair is the loop's
	 * ordering + optimistic-lock anchor: a retried append can never create
	 * two rows with the same position.
	 */
	public static function stepsColumns(): string {
		return implode(
			",\n",
			array(
				'id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',
				'run_id BIGINT(20) UNSIGNED NOT NULL',
				'seq INT(11) UNSIGNED NOT NULL',
				'kind VARCHAR(16) NOT NULL',
				'message_json LONGTEXT NULL',
				'tool_name VARCHAR(191) NULL',
				'approval_id VARCHAR(64) NULL',
				'status VARCHAR(20) NOT NULL DEFAULT \'ok\'',
				'tokens_in INT(11) NOT NULL DEFAULT 0',
				'tokens_out INT(11) NOT NULL DEFAULT 0',
				'duration_ms INT(11) NOT NULL DEFAULT 0',
				'created_at DATETIME NOT NULL',
				'PRIMARY KEY  (id)',
				'UNIQUE KEY run_seq (run_id, seq)',
				'KEY approval_id (approval_id)',
			)
		);
	}
}
