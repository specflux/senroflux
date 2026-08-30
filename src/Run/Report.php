<?php
/**
 * The harness-built report (0.2 S12).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the terminal `result_json` report from the written-object set.
 *
 * The harness is domain-agnostic: it knows each object id, whether it was
 * verified, and nothing else. The WordPress post shape (object_type, title,
 * status, edit/preview URLs) is resolved ONLY through the injectable
 * `$post_lookup` adapter, whose default is a statically wired wpAdapter that
 * guards every WP function call with `function_exists`. In tests the adapter
 * is replaced wholesale, so no WP function is ever invoked.
 *
 * Fail closed: a missing/corrupt object entry yields `verified: false` and an
 * `unknown` object_type; a WP function that is absent yields a null URL.
 * The report's `changes` order is the map's insertion order, so it is
 * deterministic for a given `objects_json`.
 */
final class Report {

	/**
	 * The shape one post_lookup call must return.
	 *
	 * @var array<int,string>
	 */
	private const LOOKUP_KEYS = array(
		'object_type',
		'title',
		'status',
		'edit_url',
		'preview_url',
	);

	/**
	 * Build the report.
	 *
	 * @param string              $summary     The model's final prose (or '' when none).
	 * @param array<string,mixed> $objects     The objects_json map.
	 * @param callable|null       $post_lookup (string|int $object_id): array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}.
	 *                                         Null means the default wpAdapter.
	 * @return array{summary:string,changes:list<array<string,mixed>>}
	 */
	public static function build( string $summary, array $objects, ?callable $post_lookup = null ): array {
		$lookup  = $post_lookup ?? self::wpPostLookup();
		$changes = array();

		foreach ( $objects as $object_id => $entry ) {
			// Keep the map's insertion order (deterministic), and only valid
			// entry shapes produce a change row.
			$object_id = (string) $object_id;

			if ( ! is_array( $entry ) ) {
				// Corrupt entry: still surface it, unverified (fail closed).
				$changes[] = self::changeRow( $object_id, false, $lookup, $object_id );
				continue;
			}

			$last_write  = $entry['last_write_seq'] ?? null;
			$verified    = $entry['verified_seq'] ?? null;
			$is_verified = is_int( $verified ) || is_numeric( $verified );
			$is_verified = $is_verified && null !== $last_write && ( (int) $verified >= (int) $last_write );

			$changes[] = self::changeRow( $object_id, $is_verified, $lookup, $object_id );
		}

		return array(
			'summary' => $summary,
			'changes' => $changes,
		);
	}

	/**
	 * One change row. Resolves the post via `$lookup` when given, otherwise
	 * emits the fail-closed defaults.
	 *
	 * @param string        $object_id     Object id.
	 * @param bool          $is_verified   Whether the object passed its re-read.
	 * @param callable|null $lookup        Lookup adapter.
	 * @param string|null   $lookup_object Id handed to the lookup (defaults to $object_id).
	 * @return array<string,mixed>
	 */
	private static function changeRow( string $object_id, bool $is_verified, ?callable $lookup = null, ?string $lookup_object = null ): array {
		$details = array(
			'object_type' => 'unknown',
			'title'       => '',
			'status'      => '',
			'edit_url'    => null,
			'preview_url' => null,
		);

		if ( null !== $lookup && null !== $lookup_object ) {
			try {
				$resolved = $lookup( $lookup_object );
				if ( is_array( $resolved ) ) {
					$details = array_merge( $details, self::normaliseLookup( $resolved ) );
				}
			} catch ( \Throwable $e ) {
				// Fail closed: a throwing adapter yields an unknown/unverified row.
				unset( $e );
			}
		}

		return array(
			'object_type' => $details['object_type'],
			'object_id'   => $object_id,
			'title'       => $details['title'],
			'status'      => $details['status'],
			'edit_url'    => $details['edit_url'],
			'preview_url' => $details['preview_url'],
			'verified'    => $is_verified,
		);
	}

	/**
	 * Coerce a lookup result to the canonical keys, tolerating missing/non-string
	 * values.
	 *
	 * @param array<string,mixed> $resolved Raw lookup result.
	 * @return array<string,mixed>
	 */
	private static function normaliseLookup( array $resolved ): array {
		$out = array();
		foreach ( self::LOOKUP_KEYS as $key ) {
			$value = $resolved[ $key ] ?? null;
			switch ( $key ) {
				case 'object_type':
				case 'title':
				case 'status':
					$out[ $key ] = is_string( $value ) ? $value : '';
					break;
				default:
					// urls: NULL when empty/false.
					$out[ $key ] = ( is_string( $value ) && '' !== $value ) ? $value : null;
			}
		}

		return $out;
	}

	/**
	 * The default wpAdapter: a closure resolving a WP post for an object id.
	 *
	 * Every WP call is guarded with `function_exists` so unit tests (which
	 * inject a fake `$post_lookup`) never require the functions to exist, and
	 * a bare-PHPUnit run falls back to the fail-closed unknown shape. URLs are
	 * null when the WP functions are absent or return an empty value.
	 *
	 * @return callable(string|int):array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	public static function wpPostLookup(): callable {
		/**
		 * @param string|int $object_id Object id.
		 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
		 */
		return static function ( string|int $object_id ): array {
			if ( ! function_exists( 'get_post' ) ) {
				return array(
					'object_type' => 'unknown',
					'title'       => '',
					'status'      => '',
					'edit_url'    => null,
					'preview_url' => null,
				);
			}

			// The post functions accept int|WP_Post; a non-numeric object id
			// is not a post id — fail closed to the unknown shape.
			$post = is_numeric( $object_id ) ? get_post( (int) $object_id ) : false;
			if ( ! $post ) {
				return array(
					'object_type' => 'unknown',
					'title'       => '',
					'status'      => '',
					'edit_url'    => null,
					'preview_url' => null,
				);
			}

			$type   = function_exists( 'get_post_type' ) ? get_post_type( (int) $object_id ) : false;
			$title  = function_exists( 'get_the_title' ) ? get_the_title( (int) $object_id ) : '';
			$status = function_exists( 'get_post_status' ) ? get_post_status( (int) $object_id ) : '';

			$edit = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( (int) $object_id, 'raw' ) : '';
			// get_preview_post_link() has a second signature (query args) but the
			// id-first call is the canonical form; a null preview stays null.
			$preview = function_exists( 'get_preview_post_link' ) ? get_preview_post_link( (int) $object_id ) : '';

			return array(
				'object_type' => ( is_string( $type ) && '' !== $type ) ? $type : 'unknown',
				'title'       => is_string( $title ) ? $title : '',
				'status'      => is_string( $status ) ? $status : '',
				'edit_url'    => ( is_string( $edit ) && '' !== $edit ) ? $edit : null,
				'preview_url' => ( is_string( $preview ) && '' !== $preview ) ? $preview : null,
			);
		};
	}
}
