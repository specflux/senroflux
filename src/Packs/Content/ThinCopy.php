<?php
/**
 * Minimum body copy for the curated text sections (0.3 quality fix: copy
 * depth). Live runs wrote 65–198-word pages whose sections were one or two
 * sentences restating the facts; the word limits alone never pushed back.
 *
 * Mirrors {@see HasImage}: a pure tree walk with no WP_Error — the caller
 * (`Content\Abilities`) builds the refusal in its own pack's words.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class ThinCopy {

	/**
	 * Pattern name => [minimum words, whether the minimum is per column].
	 * `text-section` and `media-text` count all their paragraphs together;
	 * `feature-grid` counts each column's paragraphs on their own.
	 *
	 * @var array<string, array{0:int, 1:bool}>
	 */
	public const MINIMUMS = array(
		'senroflux/text-section' => array( 60, false ),
		'senroflux/media-text'   => array( 30, false ),
		'senroflux/feature-grid' => array( 12, true ),
	);

	/**
	 * The first top-level section whose body copy is under its minimum, or
	 * null when every section is long enough. Theme patterns are never
	 * checked: their slots carry their own length rules.
	 *
	 * @param list<array<string,mixed>>           $blocks   Parsed top-level blocks.
	 * @param array<string, array{0:int, 1:bool}> $minimums Pattern name => [minimum, per column].
	 * @return array{index:int, pattern:string, words:int, minimum:int}|null
	 */
	public static function first( array $blocks, array $minimums = self::MINIMUMS ): ?array {
		$index = 0;
		foreach ( $blocks as $block ) {
			if ( null === ( $block['blockName'] ?? null ) ) {
				continue;
			}

			$name = (string) ( $block['attrs']['metadata']['name'] ?? '' );
			if ( isset( $minimums[ $name ] ) ) {
				list( $minimum, $per_column ) = $minimums[ $name ];

				/** @var list<array<string,mixed>> $children */
				$children = $block['innerBlocks'] ?? array();
				$counts   = $per_column ? self::columnWords( $children ) : array( self::paragraphWords( $children ) );
				foreach ( $counts as $words ) {
					if ( $words < $minimum ) {
						return array(
							'index'   => $index,
							'pattern' => $name,
							'words'   => $words,
							'minimum' => $minimum,
						);
					}
				}
			}

			++$index;
		}

		return null;
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 * @return list<int> Paragraph words in each column of the first `core/columns`.
	 */
	private static function columnWords( array $children ): array {
		foreach ( $children as $child ) {
			if ( 'core/columns' !== ( $child['blockName'] ?? '' ) ) {
				continue;
			}

			$counts = array();
			/** @var list<array<string,mixed>> $columns */
			$columns = $child['innerBlocks'] ?? array();
			foreach ( $columns as $column ) {
				/** @var list<array<string,mixed>> $inner */
				$inner    = $column['innerBlocks'] ?? array();
				$counts[] = self::paragraphWords( $inner );
			}

			return $counts;
		}

		return array();
	}

	/**
	 * @param list<array<string,mixed>> $children Parsed child blocks.
	 */
	private static function paragraphWords( array $children ): int {
		$words = 0;
		foreach ( $children as $child ) {
			if ( 'core/paragraph' === ( $child['blockName'] ?? '' ) ) {
				$text   = html_entity_decode( (string) preg_replace( '#<[^>]*>#', ' ', (string) ( $child['innerHTML'] ?? '' ) ), ENT_QUOTES );
				$split  = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
				$words += false === $split ? 0 : count( $split );
			}
		}

		return $words;
	}
}
