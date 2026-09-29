<?php
/**
 * Shared "which images does this content use" tree walk (0.3 quality fixes:
 * hero-image reuse across pages, and a featured image repeated in a post's
 * own content).
 *
 * TARGET REPO PATH: src/Packs/Content/ContentImages.php
 *
 * Mirrors {@see HasImage} / {@see ImageAlt}: a pure, pack-agnostic tree walk
 * with no WP_Error and no message copy — each caller builds its own refusal
 * in its own pack's words.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Content;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class ContentImages {

	/**
	 * Every image URL anywhere in `$content`'s block tree: an `<img src>`
	 * in a block's own rendered HTML (catches `core/image`, a theme
	 * pattern's own filled image slot, and anything else opaque to this
	 * pack), plus a `core/cover`/`core/media-text` background image carried
	 * only in its comment attributes (`url`/`mediaUrl`, no `<img>` at all
	 * when the cover has a colour-only fallback rendered elsewhere).
	 *
	 * @return list<string>
	 */
	public static function urls( string $content ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}

		$urls = array();
		foreach ( self::walk( parse_blocks( $content ) ) as $url ) {
			$urls[ $url ] = true;
		}

		return array_keys( $urls );
	}

	/**
	 * Whether `$url` (trimmed) appears anywhere in `$content`'s images.
	 */
	public static function containsUrl( string $content, string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return false;
		}

		return in_array( $url, self::urls( $content ), true );
	}

	/**
	 * The page's FIRST cover-block image — the hero, when the page has one
	 * (a `core/cover` built by the `hero` layout, `cover-hero`, or a
	 * theme-derived hero pattern; any other cover further down the page is
	 * not "the" hero). Both `url` and `id` are returned (whichever the
	 * markup carries — {@see \Specflux\SenroFlux\Packs\Pages\Layouts::finish()}
	 * strips `id` from a layout-built cover, so `url` is the only signal
	 * there; hand-authored markup may carry a real attachment `id` instead).
	 *
	 * @return array{url:string,id:int}|null
	 */
	public static function firstCoverImage( string $content ): ?array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return null;
		}

		foreach ( parse_blocks( $content ) as $block ) {
			if ( 'core/cover' !== ( $block['blockName'] ?? null ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			$url   = trim( (string) ( $attrs['url'] ?? '' ) );
			$id    = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
			if ( '' === $url && 0 === $id ) {
				continue;
			}

			return array(
				'url' => $url,
				'id'  => $id,
			);
		}

		return null;
	}

	/**
	 * @param array<int|string,mixed> $blocks Parsed blocks.
	 * @return list<string>
	 */
	private static function walk( array $blocks ): array {
		$urls = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			foreach ( array( 'url', 'mediaUrl' ) as $key ) {
				$value = $attrs[ $key ] ?? null;
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$urls[] = trim( $value );
				}
			}

			$html = (string) ( $block['innerHTML'] ?? '' );
			if ( preg_match_all( '/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches ) ) {
				foreach ( $matches[1] as $i => $double_quoted ) {
					$url = '' !== $double_quoted ? $double_quoted : ( $matches[2][ $i ] ?? '' );
					if ( '' !== $url ) {
						$urls[] = $url;
					}
				}
			}

			$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			foreach ( self::walk( $children ) as $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}
}
