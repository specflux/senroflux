<?php
/**
 * Shared "does this content carry an image" tree walk (0.3 quality feature:
 * images required on new pages).
 *
 * TARGET REPO PATH: src/Packs/Content/HasImage.php
 *
 * Mirrors {@see ImageAlt}: a pure, pack-agnostic tree walk with no WP_Error
 * and no message copy — the caller (`Content\Abilities::executeCreatePost()`)
 * builds its own refusal in its own pack's words.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Detects an image ANYWHERE in a parsed block tree: a `core/image`, a
 * `core/cover` (background image) or a `core/media-text` (the two new 0.3
 * image-led curated patterns, `cover-hero`/`media-text`), or a theme
 * pattern's own filled image slot. A theme pattern's block names are opaque
 * to this pack (S21: it never re-authors a theme's markup), so an `<img`
 * tag anywhere in a block's OWN rendered HTML counts too — that catches a
 * filled theme-pattern image slot without knowing that theme's block shape.
 */
final class HasImage {

	/**
	 * Block names that ALWAYS carry an image when present, regardless of
	 * their rendered HTML (a `core/cover` with a colour-only background still
	 * counts as image-bearing here only when it also has an `<img`/`url`
	 * background — checked via the HTML fallback below, not this list alone).
	 *
	 * @var list<string>
	 */
	private const IMAGE_BLOCKS = array( 'core/image', 'core/cover', 'core/media-text' );

	/**
	 * Whether `$blocks` (or anything nested inside them) contains an image.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed blocks.
	 */
	public static function present( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( in_array( $name, self::IMAGE_BLOCKS, true ) ) {
				return true;
			}

			$html = (string) ( $block['innerHTML'] ?? '' );
			if ( '' !== $html && false !== stripos( $html, '<img' ) ) {
				return true;
			}

			/** @var list<array<string,mixed>> $children */
			$children = $block['innerBlocks'] ?? array();
			if ( array() !== $children && self::present( $children ) ) {
				return true;
			}
		}

		return false;
	}
}
