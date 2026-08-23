<?php
/**
 * wpdb-backed run store.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

use Specflux\SenroFlux\Schema;
use wpdb;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The one store implementation for 0.1. seq assignment bumps the run's
 * step_count and uses the new value as the position: single-writer by design
 * (browser-driven ticks, optimistic-locked in the Runner stage), and the
 * UNIQUE (run_id, seq) key is the hard backstop against any double-append.
 */
final class WpdbRunStore implements RunStore {

	public function __construct( private readonly wpdb $db ) {
	}

	/** {@inheritDoc} */
	public function createRun( int $user_id, string $consumer, string $goal, array $allow, array $budget ): int {
		$table = Schema::runsTable( $this->db );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$this->db->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'consumer'    => $consumer,
				'goal'        => $goal,
				'status'      => RunStatus::Pending->value,
				'allow_json'  => (string) wp_json_encode( array_values( $allow ) ),
				'budget_json' => (string) wp_json_encode( Budget::sanitize( $budget ) ),
				'step_count'  => 0,
				'tokens_in'   => 0,
				'tokens_out'  => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) $this->db->insert_id;
	}

	/** {@inheritDoc} */
	public function getRun( int $run_id ): ?Run {
		$table = Schema::runsTable( $this->db );
		$row   = $this->db->get_row(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
				"SELECT * FROM {$table} WHERE id = %d",
				$run_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? Run::fromRow( $row ) : null;
	}

	/** {@inheritDoc} */
	public function getSteps( int $run_id ): array {
		$table = Schema::stepsTable( $this->db );
		$rows  = $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
				"SELECT * FROM {$table} WHERE run_id = %d ORDER BY seq ASC",
				$run_id
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		/** @var list<array<string,mixed>> $rows Real wpdb returns row arrays with ARRAY_A. */
		$rows = $rows;

		return array_map(
			static fn ( array $row ): Step => Step::fromRow( $row ),
			$rows
		);
	}

	/** {@inheritDoc} */
	public function appendStep(
		int $run_id,
		StepKind $kind,
		?array $message_array = null,
		?string $tool_name = null,
		?string $approval_id = null,
		string $status = 'ok',
		int $tokens_in = 0,
		int $tokens_out = 0,
		int $duration_ms = 0
	): int {
		$runs_table  = Schema::runsTable( $this->db );
		$steps_table = Schema::stepsTable( $this->db );

		// Claim the next position on the run row itself, so step_count stays
		// authoritative for the tick protocol's optimistic lock.
		$this->db->query(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
				"UPDATE {$runs_table} SET step_count = step_count + 1, updated_at = %s WHERE id = %d",
				gmdate( 'Y-m-d H:i:s' ),
				$run_id
			)
		);
		$seq = (int) $this->db->get_var(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
				"SELECT step_count FROM {$runs_table} WHERE id = %d",
				$run_id
			)
		);

		$this->db->insert(
			$steps_table,
			array(
				'run_id'       => $run_id,
				'seq'          => $seq,
				'kind'         => $kind->value,
				'message_json' => null !== $message_array ? (string) wp_json_encode( $message_array ) : null,
				'tool_name'    => $tool_name,
				'approval_id'  => $approval_id,
				'status'       => $status,
				'tokens_in'    => $tokens_in,
				'tokens_out'   => $tokens_out,
				'duration_ms'  => $duration_ms,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $seq;
	}

	/** {@inheritDoc} */
	public function updateRun( int $run_id, array $fields ): void {
		$allowed = array(
			'status'      => '%s',
			'step_count'  => '%d',
			'tokens_in'   => '%d',
			'tokens_out'  => '%d',
			'error_json'  => '%s',
			'finished_at' => '%s',
		);

		$data   = array();
		$format = array();
		foreach ( $fields as $column => $value ) {
			if ( ! isset( $allowed[ $column ] ) ) {
				continue; // Unknown columns are dropped, never interpolated.
			}
			$data[ $column ] = is_array( $value ) ? (string) wp_json_encode( $value ) : $value;
			$format[]        = $allowed[ $column ];
		}
		if ( array() === $data ) {
			return;
		}

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$format[]           = '%s';

		$this->db->update( Schema::runsTable( $this->db ), $data, array( 'id' => $run_id ), $format, array( '%d' ) );
	}
}
