<?php
/**
 * Shared `core/image` alt-text walker (0.3 quality feature 4).
 *
 * TARGET REPO PATH: src/Packs/Content/ImageAlt.php
 *
 * Extracted from `Packs\Posts\Validator::findMissingAlt()` (S5's original
 * home for this check) so `Packs\Pages\Validator` (and by inheritance
 * `Packs\Site\Validator`) can require the SAME thing without re-implementing
 * the tree walk: every `core/image` needs non-empty, descriptive alt text,
 * wherever it appears in the block tree.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A pure, pack-agnostic tree walk — no WP_Error, no message copy: each
 * caller's own Validator builds its own refusal from the boolean this
 * returns, in its own pack's words.
 */
final class ImageAlt {

	/**
	 * Whether this block, or anything nested inside it, is a `core/image`
	 * with empty/missing alt text.
	 *
	 * @param array<string,mixed> $block One parsed block.
	 */
	/**
	 * Block name => the JSON comment attribute that carries its alt text,
	 * when it has one stored directly (0.3 quality fix, cover-hero/media-text):
	 * `core/cover` stores `alt` just like `core/image`; `core/media-text`
	 * does NOT — `mediaAlt` is `source: attribute` (sourced from the `<img>`
	 * on parse, never in the comment JSON), so it is checked via the HTML
	 * fallback below only, same as a theme pattern's own image slot.
	 *
	 * @var array<string,string>
	 */
	private const ALT_ATTRIBUTE = array(
		'core/image' => 'alt',
		'core/cover' => 'alt',
	);

	/**
	 * Block names whose alt text lives ONLY in the rendered HTML (an `<img>`
	 * `alt` attribute), never a comment attribute.
	 *
	 * @var list<string>
	 */
	private const ALT_FROM_HTML_ONLY = array( 'core/media-text' );

	/**
	 * @param array<string,mixed> $block One parsed block.
	 */
	public static function missing( array $block ): bool {
		$name = (string) ( $block['blockName'] ?? '' );

		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

		// A cover that is only a colour overlay (a theme hero such as Ollie's
		// `hero-light`) carries no image, so it has no alt text to require.
		$plain_cover = 'core/cover' === $name
			&& empty( $attrs['useFeaturedImage'] )
			&& '' === ( is_string( $attrs['url'] ?? null ) ? trim( $attrs['url'] ) : '' )
			&& 1 !== preg_match( '/<img\b/i', (string) ( $block['innerHTML'] ?? '' ) );

		if ( ! $plain_cover && ( isset( self::ALT_ATTRIBUTE[ $name ] ) || in_array( $name, self::ALT_FROM_HTML_ONLY, true ) ) ) {
			$key = self::ALT_ATTRIBUTE[ $name ] ?? null;
			$alt = null !== $key && isset( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ? trim( $attrs[ $key ] ) : '';

			// A model composing its own markup (the posts pack) writes `alt`
			// as a JSON comment attribute; a theme pattern's own `<img>` (the
			// pages/site packs' image slots, 0.3 quality feature 4), and
			// `core/media-text`'s `mediaAlt` (0.3 quality fix: cover-hero/
			// media-text), carry alt ONLY in the rendered HTML — real
			// WordPress never stores either in a comment attribute. Either
			// place is checked.
			if ( '' === $alt ) {
				$html = (string) ( $block['innerHTML'] ?? '' );
				if ( preg_match( '/<img\b[^>]*\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $match ) ) {
					$alt = trim( '' !== $match[1] ? $match[1] : ( $match[2] ?? '' ) );
				}
			}

			if ( '' === $alt ) {
				return true;
			}
		}

		$children = $block['innerBlocks'] ?? array();
		/** @var list<array<string,mixed>> $children */
		foreach ( $children as $child ) {
			if ( self::missing( $child ) ) {
				return true;
			}
		}

		return false;
	}
}
