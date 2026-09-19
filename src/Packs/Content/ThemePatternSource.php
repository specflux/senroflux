<?php
/**
 * The optional seam a content pack's {@see Vocabulary} implements when it
 * carries theme-derived patterns (0.3 S21).
 *
 * TARGET REPO PATH: src/Packs/Content/ThemePatternSource.php
 *
 * `Content\Abilities` checks `instanceof ThemePatternSource` before treating a
 * `sections` write item's `pattern`/`slots` form as anything but a refusal —
 * this keeps the posts pack (whose {@see \Specflux\SenroFlux\Packs\Posts\Vocabulary}
 * does NOT implement this interface) from ever seeing a theme pattern, without
 * changing the shared {@see Vocabulary} contract every content pack must
 * implement.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A vocabulary that also carries theme-derived patterns.
 */
interface ThemePatternSource {

	/**
	 * The eligible theme pattern (S21) registered under the given name (its
	 * fully-qualified pattern name, e.g. `twentytwentyfive/banner-intro`), or
	 * null when no eligible theme pattern carries that name.
	 *
	 * @return array<string,mixed>|null
	 */
	public function resolveThemePattern( string $name ): ?array;

	/**
	 * The number of this theme's registered patterns that were NOT eligible
	 * (S21), for the `list-patterns` payload's `theme_patterns_skipped`.
	 */
	public function themePatternsSkippedCount(): int;
}
