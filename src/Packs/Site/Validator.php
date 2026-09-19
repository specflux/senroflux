<?php
/**
 * The site pack's write validator (0.3 S7).
 *
 * TARGET REPO PATH: src/Packs/Site/Validator.php
 *
 * Extends {@see \Specflux\SenroFlux\Packs\Pages\Validator} rather than
 * re-implementing ~1000 lines of generic block-shape / markup-safety /
 * decorative-colour / editor-parity checking (`matchPatternSchema()` and
 * `BlockShells` already dispatch through `$this->vocabulary->all()`, so the
 * two new patterns are matched for free once they're in the vocabulary).
 * Only the two SLUG-SPECIFIC seams need a page-links/`cta`-aware override:
 *   - {@see countSlots()}: `page-links`' `columns` slot, counted exactly like
 *     `feature-grid`'s (same shape: a `core/columns` block's `core/column`
 *     children).
 *   - {@see checkPageShape()}: the S7 "repeatable once" rule generalises the
 *     inherited "at most one cta" rule to a small ONCE-per-page set
 *     (`cta`, `page-links`), instead of hardcoding `cta` alone.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

use Specflux\SenroFlux\Packs\Pages\Validator as PagesValidator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary as PagesVocabulary;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Validates and cleans site-pack block markup (pages' seven patterns plus
 * `page-links` and `intro`).
 */
final class Validator extends PagesValidator {

	/**
	 * Pattern slugs that may appear AT MOST ONCE per page (S7).
	 *
	 * @var list<string>
	 */
	private const ONCE_SLUGS = array( 'cta', 'page-links' );

	/**
	 * Adds the `page-links` slot count on top of the inherited seven.
	 *
	 * @param string              $slug  The matched pattern slug.
	 * @param array<string,mixed> $block One parsed top-level block.
	 * @return array<string, list<int>>
	 */
	protected function countSlots( string $slug, array $block ): array {
		if ( 'page-links' === $slug ) {
			$children = $block['innerBlocks'] ?? array();
			/** @var list<array<string,mixed>> $children */
			return array( 'columns' => array( count( $this->columns( $children ) ) ) );
		}

		return parent::countSlots( $slug, $block );
	}

	/**
	 * The S7 page-shape rules: identical to the pages pack's (pattern-count
	 * range, hero first, no pattern more than twice except text-section)
	 * except the "at most one" rule now covers `cta` AND `page-links`
	 * (S7: "repeatable once") instead of `cta` alone.
	 *
	 * @param array<int,string> $identities parse offset => slug, in page order.
	 */
	protected function checkPageShape( array $identities ): ?WP_Error {
		$slugs = array_values( $identities );
		$count = count( $slugs );

		if ( $count < PagesVocabulary::RULES_MIN_PATTERNS || $count > PagesVocabulary::RULES_MAX_PATTERNS ) {
			return new WP_Error(
				'page_shape',
				sprintf(
					/* translators: 1: minimum pattern count, 2: maximum pattern count. */
					__( 'A page must contain between %1$d and %2$d patterns.', 'senroflux' ),
					PagesVocabulary::RULES_MIN_PATTERNS,
					PagesVocabulary::RULES_MAX_PATTERNS
				),
				array(
					'status' => 400,
					'rule'   => 'pattern_count',
					'count'  => $count,
				)
			);
		}

		if ( 'hero' !== ( $slugs[0] ?? '' ) ) {
			return new WP_Error(
				'page_shape',
				__( 'The first pattern on a page must be the hero.', 'senroflux' ),
				array(
					'status' => 400,
					'rule'   => 'hero_first',
				)
			);
		}

		foreach ( self::ONCE_SLUGS as $once_slug ) {
			$seen_once = count( array_filter( $slugs, static fn ( string $s ): bool => $once_slug === $s ) );
			if ( $seen_once > 1 ) {
				return new WP_Error(
					'page_shape',
					sprintf(
						/* translators: %s: the pattern slug (e.g. "cta", "page-links"). */
						__( 'A page may contain at most one %s.', 'senroflux' ),
						$once_slug
					),
					array(
						'status' => 400,
						'rule'   => 'max_once',
						'slug'   => $once_slug,
						'count'  => $seen_once,
					)
				);
			}
		}

		$freq = array();
		foreach ( $slugs as $slug ) {
			$freq[ $slug ] = ( $freq[ $slug ] ?? 0 ) + 1;
		}
		foreach ( $freq as $slug => $seen ) {
			if ( 'text-section' === $slug || in_array( $slug, self::ONCE_SLUGS, true ) ) {
				continue;
			}
			if ( $seen > PagesVocabulary::RULES_MAX_REPEAT ) {
				return new WP_Error(
					'page_shape',
					sprintf(
						/* translators: 1: the pattern slug, 2: the maximum repeat count. */
						__( '%1$s may appear at most %2$d times.', 'senroflux' ),
						$slug,
						PagesVocabulary::RULES_MAX_REPEAT
					),
					array(
						'status' => 400,
						'rule'   => 'max_repeat',
						'slug'   => $slug,
						'seen'   => $seen,
					)
				);
			}
		}

		return null;
	}
}
