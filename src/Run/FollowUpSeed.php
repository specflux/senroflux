<?php
/**
 * The follow-up run's seed text (0.3 S20).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Renders a source run's harness-built {@see Report} change rows into the
 * plain-text block the follow-up's first user turn carries. Domain-agnostic
 * by construction: a change row is exactly {@see Report::build()}'s shape
 * (object_type, object_id, title, status, edit_url) — generic labels the
 * harness already knows how to print, never pack vocabulary.
 */
final class FollowUpSeed {

	/**
	 * Render the seed block, or '' when there is nothing to seed (no
	 * changes) — the caller must omit the notice entirely in that case
	 * rather than claim an earlier run touched objects it did not.
	 *
	 * @param list<array<string,mixed>> $changes The source report's `changes` rows.
	 */
	public static function render( array $changes ): string {
		if ( array() === $changes ) {
			return '';
		}

		$lines   = array();
		$lines[] = 'An earlier run touched these objects. Re-read any object before you change it.';

		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			$lines[] = self::line( $change );
		}

		return implode( "\n", $lines );
	}

	/**
	 * One "- <type> <id>: <title> (<status>) — <edit_url>" line, tolerating a
	 * missing/blank field rather than throwing — a corrupt row is still
	 * named, not skipped silently.
	 *
	 * @param array<string,mixed> $change One change row.
	 */
	private static function line( array $change ): string {
		$type   = is_string( $change['object_type'] ?? null ) && '' !== $change['object_type'] ? $change['object_type'] : 'object';
		$id     = (string) ( $change['object_id'] ?? '' );
		$title  = is_string( $change['title'] ?? null ) ? $change['title'] : '';
		$status = is_string( $change['status'] ?? null ) ? $change['status'] : '';
		$url    = is_string( $change['edit_url'] ?? null ) && '' !== $change['edit_url'] ? $change['edit_url'] : '';

		$label = '' !== $title ? $title : $id;
		$line  = '- ' . $type . ' ' . $id . ': ' . $label;
		if ( '' !== $status ) {
			$line .= ' (' . $status . ')';
		}
		if ( '' !== $url ) {
			$line .= ' — ' . $url;
		}

		return $line;
	}
}
