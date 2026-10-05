<?php
/**
 * One-H1 fix (0.3 quality feature 2). TARGET REPO PATH:
 * src/Packs/Pages/HeroTemplate.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A theme's `page.html` template prints the post title as an H1; a hero
 * pattern (curated or theme-derived) prints its OWN H1. A page with both
 * renders two H1s. When the page's content already carries an H1 and the
 * active theme ships its own `templates/page-no-title.html` (Twenty
 * Twenty-Five does), the write path assigns that template instead — the
 * template never prints the title, so the hero's H1 is the only one. When
 * the theme has no such template, behaviour is left exactly as it was: the
 * post title stays the page's H1 and a hero pattern is not demoted.
 *
 * No model-facing guidance changes for this fix (S11): the model asks for
 * neither a template nor a title change; the write path decides on its own.
 */
final class HeroTemplate {

	/**
	 * The `_wp_page_template` value WordPress' block-template machinery
	 * resolves to `<active theme>/templates/page-no-title.html` (the same
	 * slug a block theme's own `get_block_templates()` entry carries).
	 */
	public const NO_TITLE_TEMPLATE = 'page-no-title';

	/**
	 * Whether `$content` (already-validated block markup) contains an H1
	 * element anywhere — a hero pattern's own heading, curated or
	 * theme-derived.
	 */
	public static function containsH1( string $content ): bool {
		return 1 === preg_match( '/<h1[\s>]/i', $content );
	}

	/**
	 * Whether the active theme ships its own `templates/page-no-title.html`
	 * (TT25 does). Reads the same `get_stylesheet_directory()`/
	 * `get_template_directory()` pair {@see ThemePatterns} already relies on;
	 * false (never fatal) when a real WordPress load order hasn't defined
	 * them, matching this class's fail-safe contract: no template found
	 * means "leave the page's own title as its H1", exactly like a theme
	 * that genuinely has no such file.
	 */
	public static function themeHasNoTitleTemplate(): bool {
		foreach ( array( 'get_stylesheet_directory', 'get_template_directory' ) as $fn ) {
			if ( ! function_exists( $fn ) ) {
				continue;
			}

			$dir = $fn();
			if ( '' !== $dir && is_file( $dir . '/templates/page-no-title.html' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a page write should assign {@see NO_TITLE_TEMPLATE}: the
	 * target is a page, its content carries an H1, and the active theme
	 * ships the template. Never true for a post (only a page's own
	 * `page.html` template prints the title as an H1).
	 */
	public static function shouldAssign( string $post_type, string $content ): bool {
		return 'page' === $post_type
			&& self::containsH1( $content )
			&& self::themeHasNoTitleTemplate();
	}
}
