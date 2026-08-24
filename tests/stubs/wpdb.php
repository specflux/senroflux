<?php
/**
 * Test-only stand-in for WordPress's $wpdb, defined ONLY when a real WP load
 * order hasn't already loaded the genuine class.
 *
 * NOT a full SQL engine: it emulates exactly the access patterns WpdbRunStore
 * uses (INSERT / conditional UPDATE incl. the step_count bump / SELECT-by-id /
 * SELECT-by-run_id-ordered-by-seq) against in-memory rows, so Runner tests
 * exercise store + loop TOGETHER. Anything else falls back to the recorded-
 * query + canned-return knobs (queryReturn/varReturn/…), which remain
 * available for string-level assertions.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'wpdb', false ) ) {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	class wpdb {

		public string $prefix = 'wp_';

		/** @var list<string> Every SQL string passed through this class, in order. */
		public array $queries = array();

		/** Canned return for query() when no emulation applies. */
		public int|bool $queryReturn = 0;

		/** Canned return for get_var() when no queue entry or emulated read applies. */
		public mixed $varReturn = null;

		/** Ordered canned returns consumed by successive get_var() calls first. */
		public array $varQueue = array();

		public bool $emulateRows = true;

		/** @var array<string,list<array<string,mixed>>> table => rows */
		public array $tables = array();

		/** @var array<string,int> table => last assigned id */
		public array $lastId = array();

		/** @var array{table:string,data:array<string,mixed>}|null */
		public ?array $lastInsert = null;

		/** @var array{table:string,data:array<string,mixed>,where:array<string,mixed>}|null */
		public ?array $lastUpdate = null;

		/** Last insert id, like real wpdb. */
		public int $insert_id = 0;

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}

		/**
		 * Placeholder substitution good enough to produce readable SQL.
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

			// Emulate THE step_count bump (appendStep's position claim).
			if ( str_contains( $query, 'SET step_count = step_count + 1' )
				&& preg_match( '/WHERE id = (\d+)\b/', $query, $m ) ) {
				foreach ( array_keys( $this->tables ) as $name ) {
					if ( ! str_ends_with( $name, 'senroflux_runs' ) ) {
						continue;
					}
					$count = count( $this->tables[ $name ] );
					for ( $i = 0; $i < $count; ++$i ) {
						if ( (int) $this->tables[ $name ][ $i ]['id'] === (int) $m[1] ) {
							$this->tables[ $name ][ $i ]['step_count'] += 1;
							$this->tables[ $name ][ $i ]['updated_at']  = gmdate( 'Y-m-d H:i:s' );

							return 1;
						}
					}
				}

				return 1;
			}

			return $this->queryReturn;
		}

		public function get_var( ?string $query = null ): mixed {
			if ( null !== $query && str_contains( $query, 'SELECT step_count' )
				&& preg_match( '/WHERE id = (\d+)\b/', $query, $m ) ) {
				$this->queries[] = $query;
				foreach ( $this->tables as $rows ) {
					foreach ( $rows as $row ) {
						if ( (int) $row['id'] === (int) $m[1] ) {
							return (int) $row['step_count'];
						}
					}
				}
			} elseif ( null !== $query ) {
				$this->queries[] = $query;
			}

			if ( array() !== $this->varQueue ) {
				return array_shift( $this->varQueue );
			}

			return $this->varReturn;
		}

		/** @return array<string,mixed>|null */
		public function get_row( ?string $query = null, string $output = 'ARRAY_A' ): ?array {
			if ( null === $query ) {
				return $this->rowReturn;
			}
			$this->queries[] = $query;

			if (
				$this->emulateRows
				&& preg_match( '/SELECT \* FROM (\S+) WHERE id = (\d+)\b/', $query, $m )
			) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $row ) {
					if ( (int) $row['id'] === (int) $m[2] ) {
						return $row;
					}
				}

				return null;
			}

			return $this->rowReturn;
		}

		/** @return list<array<string,mixed>> */
		public function get_results( ?string $query = null, string $output = 'ARRAY_A' ): array {
			if ( null !== $query ) {
				$this->queries[] = $query;
			}

			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( '/SELECT \* FROM (\S+) WHERE run_id = (\d+) ORDER BY seq ASC/', $query, $m )
			) {
				$rows = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => (int) $row['run_id'] === (int) $m[2]
					)
				);
				usort(
					$rows,
					static fn ( array $a, array $b ): int => (int) $a['seq'] <=> (int) $b['seq']
				);

				return $rows;
			}

			return array();
		}

		/**
		 * Store a row with an auto-assigned id.
		 *
		 * @param string              $table Table.
		 * @param array<string,mixed> $data  Data.
		 * @param ?list<string>       $format Formats.
		 */
		public function insert( string $table, array $data, ?array $format = null ): int|bool {
			unset( $format );
			$id                       = ( $this->lastId[ $table ] ?? 0 ) + 1;
			$data['id']               = $id;
			$this->lastId[ $table ]   = $id;
			$this->tables[ $table ][] = $data;
			$this->lastInsert         = array(
				'table' => $table,
				'data'  => $data,
			);
			$this->insert_id          = $id;

			return 1;
		}

		/**
		 * Merge-update rows matching ALL where keys.
		 *
		 * @param string              $table Table.
		 * @param array<string,mixed> $data  Data.
		 * @param array<string,mixed> $where Where.
		 * @param ?list<string>       $format Data formats.
		 * @param ?list<string>       $where_format Where formats.
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|bool {
			unset( $format, $where_format );
			$this->lastUpdate = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);

			$rows     = $this->tables[ $table ] ?? array();
			$affected = 0;
			$count    = count( $rows );
			for ( $i = 0; $i < $count; ++$i ) {
				$match = true;
				foreach ( $where as $key => $value ) {
					if ( (int) $rows[ $i ][ $key ] !== (int) $value ) {
						$match = false;

						break;
					}
				}
				if ( $match ) {
					foreach ( $data as $key => $value ) {
						$this->tables[ $table ][ $i ][ $key ] = $value;
					}
					++$affected;
				}
			}

			return $affected;
		}
	}
}
