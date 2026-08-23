<?php
/**
 * Test-only stand-in for WordPress's $wpdb, defined ONLY when a real WP load
 * order hasn't already loaded the genuine class. Not a SQL engine: methods
 * record what they were given and return whatever the test pre-loaded onto
 * the matching *Return property (house style: hand-rolled fakes, no mocking
 * framework).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'wpdb', false ) ) {
	// Row-output constants the store passes explicitly.
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	class wpdb {

		public string $prefix = 'wp_';

		/** @var list<string> Every SQL string passed to query/get_var/get_row/get_results, in order. */
		public array $queries = array();

		/** Canned return for the next query() call. */
		public int|bool $queryReturn = 0;

		/** Canned return for the next get_var() call. */
		public mixed $varReturn = null;

		/** @var array<string,mixed>|null Canned return for the next get_row() call. */
		public ?array $rowReturn = null;

		/** @var list<array<string,mixed>> Canned return for the next get_results() call. */
		public array $resultsReturn = array();

		/** @var array{table:string,data:array<string,mixed>}|null Last insert() payload. */
		public ?array $lastInsert = null;

		/** @var array{table:string,data:array<string,mixed>,format:?list<string>}|null Last update() payload. */
		public ?array $lastUpdate = null;

		/**
		 * Insert id, as a real wpdb exposes after an insert.
		 *
		 * @var int
		 */
		public int $insert_id = 0;

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}

		/**
		 * Approximates real wpdb::prepare()'s placeholder substitution closely
		 * enough to produce assertable SQL — never use outside tests.
		 *
		 * @param mixed ...$args Args.
		 */
		public function prepare( string $query, ...$args ): string {
			if ( count( $args ) === 1 && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			$quoted = (string) preg_replace( '/(?<!%)%s/', "'%s'", $query );

			return vsprintf( $quoted, $args );
		}

		public function query( string $query ): int|bool {
			$this->queries[] = $query;

			return $this->queryReturn;
		}

		public function get_var( ?string $query = null ): mixed {
			if ( null !== $query ) {
				$this->queries[] = $query;
			}

			return $this->varReturn;
		}

		/** @return array<string,mixed>|null */
		public function get_row( ?string $query = null, string $output = 'ARRAY_A' ): ?array {
			if ( null !== $query ) {
				$this->queries[] = $query;
			}

			return $this->rowReturn;
		}

		/** @return list<array<string,mixed>> */
		public function get_results( ?string $query = null, string $output = 'ARRAY_A' ): array {
			if ( null !== $query ) {
				$this->queries[] = $query;
			}

			return $this->resultsReturn;
		}

		/**
		 * Record an insert payload.
		 *
		 * @param string            $table  Table.
		 * @param array<string,mixed> $data  Data.
		 * @param ?list<string>     $format Formats.
		 */
		public function insert( string $table, array $data, ?array $format = null ): int|bool {
			$this->lastInsert = array(
				'table' => $table,
				'data'  => $data,
			);
			$this->insert_id  = 42;

			return 1;
		}

		/**
		 * Record an update payload.
		 *
		 * @param string                 $table Table.
		 * @param array<string,mixed>    $data  Data.
		 * @param array<string,mixed>    $where Where.
		 * @param ?list<string>          $format Data formats.
		 * @param ?list<string>          $where_format Where formats.
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|bool {
			$this->lastUpdate = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);

			return 1;
		}
	}
}
