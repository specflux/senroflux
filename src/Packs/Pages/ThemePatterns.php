<?php
/**
 * Theme block patterns as feature patterns for the pages/site packs (0.3 S21).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Reads, filters and fills the active theme's own block patterns.
 *
 * TARGET REPO PATH: src/Packs/Pages/ThemePatterns.php
 *
 * `WP_Theme::get_block_patterns()` (`wp-includes/class-wp-theme.php:1856`)
 * registers every `patterns/*.php` file the active theme (or its parent) ships
 * on `WP_Block_Patterns_Registry`, on `init`
 * (`wp-includes/block-patterns.php:414-478`). Each entry's `filePath` is the
 * only theme attribution available — there is no `theme` key.
 *
 * ELIGIBILITY (S21). An entry from that registry joins the pages/site
 * vocabularies only when ALL of:
 *   - its `filePath` sits inside `get_stylesheet_directory()` or
 *     `get_template_directory()` (a theme's own pattern, not a remote or
 *     `wp_block` one — both are excluded before this list even runs);
 *   - `inserter` is not `false`;
 *   - it names no `templateTypes`, and any `postTypes`/`blockTypes`
 *     restriction includes `page`;
 *   - every block it uses (recursively) is in the pack's block allow-list —
 *     the SAME set {@see \Specflux\SenroFlux\Packs\Pages\Vocabulary::blockNames()}
 *     already fences curated patterns to;
 *   - its block tree is no more than 5 levels deep;
 *   - every `backgroundColor`/`textColor`/`gradient` a block carries, and
 *     every matching `has-*-color`/`has-*-background-color`/
 *     `has-*-gradient-background` class its markup carries, names a preset
 *     slug in the active theme's global settings (D3a, S4:
 *     {@see hasIneligibleColor()}) -- `wp_get_global_settings( array(
 *     'color', 'palette' ) )` for colours, `...'gradients'` for gradients,
 *     all merged origins. Raw colour (`style.color` with a hex/rgb/hsl
 *     value) still makes the pattern ineligible;
 *   - it has at least one text slot ({@see textSlots()}).
 *
 * Everything that fails is counted, never named — {@see skippedCount()}
 * feeds `list-patterns`' `theme_patterns_skipped`.
 *
 * TEXT SLOTS. Every rich-text element (`p`, `h1`-`h6`, `li`, `summary`,
 * `cite`, `figcaption`, an anchor's own text) and each anchor's `href`, in
 * document order — found by walking the pattern's rendered markup tag by
 * tag (block comments never match the element pattern, so they simply pass
 * through unchanged; see {@see walk()}), similarly to how
 * {@see \Specflux\SenroFlux\Packs\Pages\BlockShells} walks one block's
 * `innerHTML`. A `text` slot carries the shipped sample plus
 * its word count and the S21 length cap (`max(3, ceil(1.5 * shipped words))`);
 * a `url` slot carries the shipped `href`. LIMIT (documented): a rich-text
 * element nested INSIDE another (an inline link inside a paragraph) is not
 * split into two slots — the outer element's slot swallows it, matching every
 * shipped Twenty Twenty-Five pattern this stage was built against, none of
 * which nests an anchor inside a paragraph or heading.
 *
 * CACHING (S21): a static per-request memo, keyed on nothing (the registry
 * does not change mid-request) and cleared by {@see resetCache()} so PHPUnit
 * runs stay independent.
 */
final class ThemePatterns {

	/**
	 * Elements whose content is a text slot; `a` doubles as a `url` slot on
	 * its `href`.
	 *
	 * @var list<string>
	 */
	private const TEXT_TAGS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'summary', 'cite', 'figcaption', 'a' );

	/**
	 * S21: the maximum block-tree depth a theme pattern may reach.
	 *
	 * S21 first set this to 5, which is exactly where the pack's own curated
	 * seven top out — a bound fitted to this plugin's markup rather than to
	 * how themes actually nest. Twenty Twenty-Five wraps its richer patterns
	 * in an outer section group, putting its pricing table, FAQs and
	 * testimonials at 6 and two CTAs at 7, so a cap of 5 rejected every
	 * pattern that maps onto a curated shape and left 4 of 98 eligible. The
	 * bound is now our own maximum plus room for that wrapper.
	 */
	private const MAX_DEPTH = 7;

	/**
	 * @var array{eligible: list<array<string,mixed>>, skipped: int}|null
	 */
	private static ?array $memo = null;

	/**
	 * Forget the per-request memo (test-only).
	 */
	public static function resetCache(): void {
		self::$memo           = null;
		self::$palette_memo   = null;
		self::$gradients_memo = null;
	}

	/**
	 * The eligible theme patterns, vocabulary-shaped (S21): `slug`, `name`,
	 * `title`, `description`, `markup`, `repeatable` (always empty — S21: "no
	 * repeatable slots"), `constraints`, `theme_derived` (always true),
	 * `is_hero`/`is_cta` (S21's `banner`/`call-to-action` category counting)
	 * and `text_slots` (the derivation {@see textSlots()} already did, kept so
	 * callers never re-derive it).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function eligible(): array {
		return self::compute()['eligible'];
	}

	/**
	 * The count of this theme's own patterns that were NOT eligible (S21).
	 */
	public static function skippedCount(): int {
		return self::compute()['skipped'];
	}

	/**
	 * @return array{eligible: list<array<string,mixed>>, skipped: int}
	 */
	private static function compute(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}

		$eligible = array();
		$skipped  = 0;

		foreach ( self::themeOwnedEntries() as $entry ) {
			if ( self::isEligible( $entry ) ) {
				$eligible[] = self::toVocabularyEntry( $entry );
			} else {
				++$skipped;
			}
		}

		// D1 step 2 (S5b): on a theme with no profile, the patterns automatic
		// matching picks for a layout also play its page role and are adapted.
		if ( array() === Layouts::profile() ) {
			foreach ( Layouts::autoPicks( $eligible ) as $name => $layouts ) {
				foreach ( $eligible as $position => $entry ) {
					if ( $name !== $entry['name'] ) {
						continue;
					}
					$eligible[ $position ]['auto_layouts'] = $layouts;
					$eligible[ $position ]['is_hero']      = $entry['is_hero'] || in_array( 'hero', $layouts, true );
					$eligible[ $position ]['is_cta']       = $entry['is_cta'] || in_array( 'cta', $layouts, true );
				}
			}
		}

		self::$memo = array(
			'eligible' => $eligible,
			'skipped'  => $skipped,
		);

		return self::$memo;
	}

	/**
	 * Every registered pattern whose `filePath` sits inside the active
	 * theme's own stylesheet/template directory, with remote (`source` set)
	 * and `wp_block` patterns already excluded.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function themeOwnedEntries(): array {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' )
			|| ! function_exists( 'get_stylesheet_directory' )
			|| ! function_exists( 'get_template_directory' )
		) {
			return array();
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		if ( ! method_exists( $registry, 'get_all_registered' ) ) {
			return array();
		}

		$stylesheet_dir = self::normalizeDir( (string) get_stylesheet_directory() );
		$template_dir   = self::normalizeDir( (string) get_template_directory() );

		$declared = self::declaredNames();
		$owned    = array();
		foreach ( (array) $registry->get_all_registered() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( ! empty( $entry['source'] ) ) {
				continue; // Remote pattern.
			}
			$name = (string) ( $entry['name'] ?? '' );
			if ( str_starts_with( $name, 'wp_block/' ) ) {
				continue;
			}
			$file_path = isset( $entry['filePath'] ) ? (string) $entry['filePath'] : '';
			if ( '' === $file_path ) {
				// Core drops `filePath` once a pattern's content has been read (a
				// theme or plugin that lists patterns early does it), so a theme's
				// own pattern is then recognised by the slug its file declares.
				if ( ! isset( $declared[ $name ] ) ) {
					continue;
				}
			} elseif ( ! str_starts_with( $file_path, $stylesheet_dir ) && ! str_starts_with( $file_path, $template_dir ) ) {
				continue;
			}

			$owned[] = $entry;
		}

		return $owned;
	}

	/**
	 * The pattern slugs the active theme's (and its parent's) own pattern
	 * files declare, as `slug => true`.
	 *
	 * @return array<string,true>
	 */
	private static function declaredNames(): array {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array();
		}

		$theme  = wp_get_theme();
		$themes = array( $theme );
		$parent = $theme->parent();
		if ( $parent instanceof \WP_Theme ) {
			$themes[] = $parent;
		}

		$names = array();
		foreach ( $themes as $candidate ) {
			foreach ( (array) $candidate->get_block_patterns() as $data ) {
				if ( is_array( $data ) && isset( $data['slug'] ) ) {
					$names[ (string) $data['slug'] ] = true;
				}
			}
		}

		return $names;
	}

	private static function normalizeDir( string $dir ): string {
		return rtrim( $dir, '/\\' ) . '/';
	}

	/**
	 * S21's eligibility filter, over one registry entry.
	 *
	 * @param array<string,mixed> $entry One theme-owned registry entry.
	 */
	private static function isEligible( array $entry ): bool {
		if ( array_key_exists( 'inserter', $entry ) && false === $entry['inserter'] ) {
			return false;
		}

		if ( ! empty( $entry['templateTypes'] ) ) {
			return false;
		}

		if ( ! empty( $entry['postTypes'] ) && ! in_array( 'page', (array) $entry['postTypes'], true ) ) {
			return false;
		}

		if ( ! empty( $entry['blockTypes'] ) && ! in_array( 'page', (array) $entry['blockTypes'], true ) ) {
			return false;
		}

		$content = (string) ( $entry['content'] ?? '' );
		if ( '' === trim( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return false;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) || array() === $blocks ) {
			return false;
		}
		$blocks = array_values( $blocks );

		$allowed = array_flip( ( new Vocabulary() )->blockNames() );
		if ( ! self::blocksAllowed( $blocks, $allowed ) ) {
			return false;
		}

		if ( self::maxDepth( $blocks ) > self::MAX_DEPTH ) {
			return false;
		}

		if ( self::hasIneligibleColor( $blocks ) ) {
			return false;
		}

		// A `text` slot with zero shipped words (an empty rich-text element —
		// e.g. a block-bindings paragraph with no literal content) carries no
		// actual sample for the model to work from, so it does not count.
		foreach ( self::textSlots( $content ) as $slot ) {
			if ( 'text' === $slot['kind'] && $slot['shipped_words'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array<string,mixed>> $blocks  Parsed blocks.
	 * @param array<string,int>         $allowed Allowed block names (flipped).
	 */
	private static function blocksAllowed( array $blocks, array $allowed ): bool {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) ) {
				if ( ! isset( $allowed[ $name ] ) ) {
					return false;
				}
				if ( ! self::blocksAllowed( $block['innerBlocks'] ?? array(), $allowed ) ) {
					return false;
				}
			} elseif ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param list<array<string,mixed>> $blocks Parsed blocks.
	 */
	private static function maxDepth( array $blocks ): int {
		$max = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_string( $block['blockName'] ?? null ) ) {
				continue;
			}
			$depth = 1 + self::maxDepth( $block['innerBlocks'] ?? array() );
			$max   = max( $max, $depth );
		}

		return $max;
	}

	/**
	 * The active theme's palette, `wp_get_global_settings( array( 'color',
	 * 'palette' ) )`, every origin the theme leaves switched on (D3a). Memoised alongside
	 * {@see $memo}, cleared by {@see resetCache()}.
	 *
	 * @var list<string>|null
	 */
	private static ?array $palette_memo = null;

	/**
	 * @var list<string>|null
	 */
	private static ?array $gradients_memo = null;

	/**
	 * @return list<string>
	 */
	private static function paletteSlugs(): array {
		if ( null === self::$palette_memo ) {
			self::$palette_memo = self::presetSlugs( 'palette' );
		}

		return self::$palette_memo;
	}

	/**
	 * @return list<string>
	 */
	private static function gradientSlugs(): array {
		if ( null === self::$gradients_memo ) {
			self::$gradients_memo = self::presetSlugs( 'gradients' );
		}

		return self::$gradients_memo;
	}

	/**
	 * @return list<string>
	 */
	private static function presetSlugs( string $key ): array {
		$entries = PresetAdapter::presets( 'color', $key, 'palette' === $key ? 'defaultPalette' : 'defaultGradients' );

		return array_values( array_unique( array_map( 'strval', array_column( $entries['all'] ?? array(), 'slug' ) ) ) );
	}

	/**
	 * D3a (S4): a theme pattern is no longer cut just for carrying a colour —
	 * only for carrying one the active theme's own global settings do not
	 * define. Checked: `backgroundColor`/`textColor` against the palette,
	 * `gradient` against the gradients list, and the matching
	 * `has-*-background-color`/`has-*-gradient-background`/`has-*-color`
	 * classes each block's own markup carries (core's own generic classes —
	 * `has-text-color`, `has-link-color`, `has-border-color`, `has-icon-color`,
	 * `has-inline-color` — name no preset at all and never gate). Raw colour
	 * (`style.color` with a hex/rgb/hsl value, or a `custom*Color` attribute
	 * such as `core/cover`'s `customOverlayColor` — core only ever writes
	 * that one with a literal value, never a preset slug) still makes the
	 * pattern ineligible outright — nothing here admits it.
	 *
	 * @param list<array<string,mixed>> $blocks Parsed blocks.
	 */
	private static function hasIneligibleColor( array $blocks ): bool {
		$palette   = self::paletteSlugs();
		$gradients = self::gradientSlugs();

		foreach ( $blocks as $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			foreach ( array_keys( $attrs ) as $attr_key ) {
				if ( is_string( $attr_key ) && str_starts_with( $attr_key, 'custom' ) && str_ends_with( $attr_key, 'Color' ) ) {
					return true; // e.g. customOverlayColor -- always a raw literal, never a preset.
				}
			}

			foreach ( array( 'backgroundColor', 'textColor' ) as $key ) {
				if ( array_key_exists( $key, $attrs ) && ! in_array( (string) $attrs[ $key ], $palette, true ) ) {
					return true;
				}
			}
			if ( array_key_exists( 'gradient', $attrs ) && ! in_array( (string) $attrs['gradient'], $gradients, true ) ) {
				return true;
			}
			if ( is_array( $attrs['style'] ?? null ) && array_key_exists( 'color', $attrs['style'] ) ) {
				return true; // Raw colour (hex/rgb/hsl) -- D3a never admits it.
			}

			if ( self::hasIneligibleColorClass( (string) ( $block['innerHTML'] ?? '' ), $palette, $gradients ) ) {
				return true;
			}

			if ( self::hasIneligibleColor( $block['innerBlocks'] ?? array() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<string> $palette   Palette slugs.
	 * @param list<string> $gradients Gradient slugs.
	 */
	private static function hasIneligibleColorClass( string $html, array $palette, array $gradients ): bool {
		if ( preg_match_all( '/\bhas-([a-z0-9-]+?)-gradient-background\b/', $html, $matches ) ) {
			foreach ( $matches[1] as $slug ) {
				if ( ! in_array( $slug, $gradients, true ) ) {
					return true;
				}
			}
		}
		if ( preg_match_all( '/\bhas-([a-z0-9-]+?)-background-color\b/', $html, $matches ) ) {
			foreach ( $matches[1] as $slug ) {
				if ( ! in_array( $slug, $palette, true ) ) {
					return true;
				}
			}
		}

		// The two class shapes above both also end in `-color`; strip them
		// (and the unrelated, never-gated `has-*-border-color`) before the
		// generic `has-<slug>-color` scan, so their own slug is never
		// re-read as if it were a plain text-colour class.
		$remainder = (string) preg_replace(
			array( '/\bhas-[a-z0-9-]+-background-color\b/', '/\bhas-[a-z0-9-]+-gradient-background\b/', '/\bhas-[a-z0-9-]+-border-color\b/' ),
			'',
			$html
		);
		if ( preg_match_all( '/\bhas-([a-z0-9-]+?)-color\b/', $remainder, $matches ) ) {
			foreach ( $matches[1] as $slug ) {
				if ( in_array( $slug, array( 'text', 'link', 'border', 'icon', 'inline' ), true ) ) {
					continue; // Core's own generic classes -- name no preset.
				}
				if ( ! in_array( $slug, $palette, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $entry One eligible registry entry.
	 * @return array<string,mixed>
	 */
	private static function toVocabularyEntry( array $entry ): array {
		$name       = (string) ( $entry['name'] ?? '' );
		$content    = (string) ( $entry['content'] ?? '' );
		$slots      = self::textSlots( $content );
		$categories = array_map( 'strval', (array) ( $entry['categories'] ?? array() ) );

		return array(
			'slug'          => $name,
			'name'          => $name,
			'title'         => (string) ( $entry['title'] ?? $name ),
			'description'   => (string) ( $entry['description'] ?? '' ),
			'markup'        => $content,
			'repeatable'    => array(),
			'constraints'   => array(
				'slots'  => array(),
				'stated' => self::statedLines( $slots ),
			),
			'categories'    => $categories,
			'theme_derived' => true,
			// 0.3 quality fix: a pattern counts as a hero/cta for the page-shape
			// rules only when the theme dedicates it to EXACTLY that one role.
			// A real TT25 pattern (`pricing-3-col`) carries `banner,
			// call-to-action, services` all at once — a multi-purpose content
			// pattern, not a page's hero or its one call-to-action section.
			// Counting it under either would refuse (hero_first false match is
			// harmless, but max_cta) a good page that also uses a real,
			// single-purpose CTA pattern. Requiring an exact one-category match
			// keeps the rule meaningful for genuinely dedicated theme patterns.
			// S5: a pattern the active layout profile builds the hero or the
			// cta from counts for that role whatever its categories say (Ollie
			// files them under `ollie/hero` and `ollie/call-to-action`).
			'is_hero'       => array( 'banner' ) === $categories || 'hero' === Layouts::roleOf( $name ),
			'is_cta'        => array( 'call-to-action' ) === $categories || 'cta' === Layouts::roleOf( $name ),
			'text_slots'    => $slots,
		);
	}

	/**
	 * @param list<array<string,mixed>> $slots {@see textSlots()}.
	 * @return list<string>
	 */
	private static function statedLines( array $slots ): array {
		$lines = array();
		foreach ( $slots as $slot ) {
			if ( 'text' === $slot['kind'] ) {
				$lines[] = sprintf(
					/* translators: 1: slot number, 2: element tag, 3: shipped sample text, 4: maximum word count. */
					__( 'Slot %1$d (%2$s): new text, at most %4$d words, replacing the shipped sample "%3$s".', 'senroflux' ),
					$slot['index'] + 1,
					$slot['tag'],
					$slot['shipped_text'],
					$slot['max_words']
				);
			} elseif ( 'image' === $slot['kind'] ) {
				$lines[] = sprintf(
					/* translators: %d: slot number. */
					__( 'Slot %1$d: an image. Value is "URL||ALT TEXT" (the literal characters || separate the two) — the URL of an attachment you found or generated, and non-empty, descriptive alt text. Both parts are required.', 'senroflux' ),
					$slot['index'] + 1
				);
			} else {
				$lines[] = sprintf(
					/* translators: %d: slot number. */
					__( 'Slot %1$d: a safe link destination, never "#" or empty.', 'senroflux' ),
					$slot['index'] + 1
				);
			}
		}

		return $lines;
	}

	/**
	 * The numbered text/url slots of one pattern's rendered markup, in
	 * document order (S21). Each entry: `index` (int), `kind` ('text'|'url'),
	 * `tag` (the element name), `shipped_text` (string — the shipped sample,
	 * or the shipped `href` for a `url` slot), `shipped_words` (int) and
	 * `max_words` (int, the S21 length cap).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function textSlots( string $markup ): array {
		return self::walk( $markup, null )['slots'];
	}

	/**
	 * Fill a theme pattern's own shipped markup with model-provided slot
	 * text (S21 write path). Same return shape as
	 * {@see \Specflux\SenroFlux\Packs\Content\Validator::clean()}: `ok`
	 * false leaves `content` empty and carries the refusal; `ok` true carries
	 * the filled markup, still subject to the pack's OWN {@see Validator::clean()}
	 * afterwards (S21 decision: one validation path).
	 *
	 * @param string          $markup    The pattern's shipped markup.
	 * @param list<string>    $slots     The model's slot values, in slot order.
	 * @param array<int, int> $max_words Slot index => word limit, replacing
	 *                                   the derived limit for that slot.
	 * @return array{ok:bool, content:string, wp_error:WP_Error|null}
	 */
	public static function fill( string $markup, array $slots, array $max_words = array() ): array {
		$result = self::walk( $markup, $slots, $max_words );

		if ( null !== $result['error_code'] ) {
			return array(
				'ok'       => false,
				'content'  => '',
				'wp_error' => new WP_Error(
					$result['error_code'],
					self::refusalMessage( $result['error_code'], $result['error_data'] ),
					array_merge(
						array(
							'status' => 400,
							'index'  => $result['error_index'],
						),
						$result['error_data']
					)
				),
			);
		}

		return array(
			'ok'       => true,
			'content'  => (string) $result['output'],
			'wp_error' => null,
		);
	}

	/**
	 * @param array<string,mixed> $data Per-code message data.
	 */
	private static function refusalMessage( string $code, array $data ): string {
		return match ( $code ) {
			'slot_missing'      => __( 'A theme pattern slot was left empty, or an image slot is missing its alt text.', 'senroflux' ),
			'slot_sample_text'  => __( 'A theme pattern slot still holds the shipped sample text.', 'senroflux' ),
			'slot_too_long'     => sprintf(
				/* translators: %d: maximum word count. */
				__( 'A theme pattern slot is over its %d-word limit.', 'senroflux' ),
				$data['max_words'] ?? 0
			),
			'unsafe_url'        => __( 'A theme pattern slot is missing a safe URL (a link destination, or an image\'s source).', 'senroflux' ),
			default             => __( 'That theme pattern slot is invalid.', 'senroflux' ),
		};
	}

	/**
	 * The single pass over a pattern's markup that both {@see textSlots()}
	 * and {@see fill()} use, walked tag by tag in document order. Block
	 * comments (`<!-- wp:name {...} -->`) never match the strict element
	 * pattern below (they start `<!--`, never `<` + a letter or `/`), so they
	 * fall straight through to `$out`/the current buffer UNCHANGED — this is
	 * what lets {@see fill()} return markup that still re-parses as the exact
	 * same block tree. With `$values` null this only derives the slot list;
	 * with `$values` given it also produces the filled markup (or the first
	 * refusal).
	 *
	 * @param string            $markup The pattern's rendered/shipped markup.
	 * @param list<string>|null $values The model's slot values, or null to
	 *                                   only derive slots.
	 * @param array<int, int>   $limits Slot index => word limit override.
	 * @return array{slots:list<array<string,mixed>>, output:string|null, error_code:string|null, error_index:int|null, error_data:array<string,mixed>}
	 */
	private static function walk( string $markup, ?array $values, array $limits = array() ): array {
		$parts = preg_split( '/(<[^>]+>)/', $markup, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$parts = false === $parts ? array() : $parts;

		$out           = '';
		$slots         = array();
		$index         = 0;
		$open_tag      = null;
		$open_tag_raw  = '';
		$open_tag_href = null;
		$buffer        = '';
		$error_code    = null;
		$error_index   = null;
		$error_data    = array();

		foreach ( $parts as $part ) {
			$match  = array();
			$is_tag = (bool) preg_match( '#^<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)([^>]*)>$#', $part, $match );

			if ( null === $open_tag ) {
				// 0.3 quality feature 4: `<img>` is a void element (no
				// closing tag, no text content), so it is resolved to a slot
				// immediately, rather than via the open/close buffering the
				// TEXT_TAGS below use.
				if ( $is_tag && '' === $match[1] && 'img' === strtolower( $match[2] ) ) {
					$image_index = $index++;
					$shipped_src = (string) ( self::attrValue( $match[3], 'src' ) ?? '' );
					$shipped_alt = (string) ( self::attrValue( $match[3], 'alt' ) ?? '' );

					$slots[] = array(
						'index'         => $image_index,
						'kind'          => 'image',
						'tag'           => 'img',
						'shipped_text'  => $shipped_alt,
						'shipped_src'   => $shipped_src,
						'shipped_words' => 0,
						'max_words'     => 0,
					);

					$tag = $part;
					if ( null !== $values && null === $error_code ) {
						$provided = (string) ( $values[ $image_index ] ?? '' );
						$reason   = self::checkImageSlot( $provided );
						if ( null !== $reason ) {
							$error_code  = $reason;
							$error_index = $image_index;
							$error_data  = array();
						} else {
							[$url, $alt] = self::splitImageSlot( $provided );
							$tag         = self::replaceAttr( $tag, 'src', $url );
							$tag         = self::replaceAttr( $tag, 'alt', $alt );
						}
					}

					$out .= $tag;
					continue;
				}

				if ( $is_tag && '' === $match[1] && in_array( strtolower( $match[2] ), self::TEXT_TAGS, true ) ) {
					$open_tag      = strtolower( $match[2] );
					$open_tag_raw  = $part;
					$open_tag_href = 'a' === $open_tag ? self::attrValue( $match[3], 'href' ) : null;
					$buffer        = '';
					continue;
				}

				$out .= $part;
				continue;
			}

			if ( $is_tag && '/' === $match[1] && strtolower( $match[2] ) === $open_tag ) {
				$shipped_text  = trim( self::stripTags( $buffer ) );
				$shipped_words = self::wordCount( $shipped_text );
				$max_words     = $limits[ $index ] ?? max( 3, (int) ceil( 1.5 * $shipped_words ) );
				$text_index    = $index++;

				$slots[] = array(
					'index'         => $text_index,
					'kind'          => 'text',
					'tag'           => $open_tag,
					'shipped_text'  => $shipped_text,
					'shipped_words' => $shipped_words,
					'max_words'     => $max_words,
				);

				$open_raw  = $open_tag_raw;
				$inner_out = $buffer;

				if ( null !== $values && null === $error_code ) {
					$provided = (string) ( $values[ $text_index ] ?? '' );
					$reason   = self::checkText( $provided, $shipped_text, $max_words );
					if ( null !== $reason ) {
						$error_code  = $reason;
						$error_index = $text_index;
						$error_data  = array(
							'shipped'   => $shipped_text,
							'max_words' => $max_words,
						);
					} else {
						$inner_out = htmlspecialchars( $provided, ENT_QUOTES, 'UTF-8' );
					}
				}

				if ( 'a' === $open_tag ) {
					$url_index = $index++;
					$slots[]   = array(
						'index'         => $url_index,
						'kind'          => 'url',
						'tag'           => 'a',
						'shipped_text'  => (string) $open_tag_href,
						'shipped_words' => 0,
						'max_words'     => 0,
					);

					if ( null !== $values && null === $error_code ) {
						$provided_href = (string) ( $values[ $url_index ] ?? '' );
						if ( self::urlUnsafe( $provided_href ) ) {
							$error_code  = 'unsafe_url';
							$error_index = $url_index;
							$error_data  = array();
						} else {
							$open_raw = self::replaceAttr( $open_raw, 'href', $provided_href );
						}
					}
				}

				$out .= $open_raw . $inner_out . $part;

				$open_tag      = null;
				$open_tag_raw  = '';
				$open_tag_href = null;
				$buffer        = '';
				continue;
			}

			$buffer .= $part;
		}

		return array(
			'slots'       => $slots,
			'output'      => null !== $values ? $out : null,
			'error_code'  => $error_code,
			'error_index' => $error_index,
			'error_data'  => $error_data,
		);
	}

	/**
	 * @return string|null The single refusal code, or null when the value is fine.
	 */
	private static function checkText( string $provided, string $shipped, int $max_words ): ?string {
		$trimmed = trim( $provided );
		if ( '' === $trimmed ) {
			return 'slot_missing';
		}
		if ( self::normalize( $trimmed ) === self::normalize( $shipped ) ) {
			return 'slot_sample_text';
		}
		if ( self::wordCount( $trimmed ) > $max_words ) {
			return 'slot_too_long';
		}

		return null;
	}

	private static function urlUnsafe( string $value ): bool {
		$trimmed = trim( $value );
		if ( '' === $trimmed || '#' === $trimmed ) {
			return true;
		}

		return ! Validator::urlIsSafe( $trimmed );
	}

	/**
	 * An image slot's value is `"URL||ALT"` (0.3 quality feature 4) — the
	 * only two-part slot value this class produces, documented in
	 * {@see statedLines()}'s own line for it. Both parts are mandatory: no
	 * URL means nothing to point the `<img>` at, no alt means the write would
	 * only recreate the very `missing_alt` refusal
	 * {@see \Specflux\SenroFlux\Packs\Content\ImageAlt} exists to catch —
	 * catching it HERE, before the block is even assembled, gives a clearer
	 * `slot_missing`/`unsafe_url` message than a generic `missing_alt` would.
	 *
	 * @return string|null The single refusal code, or null when the value is fine.
	 */
	private static function checkImageSlot( string $provided ): ?string {
		[$url, $alt] = self::splitImageSlot( $provided );
		if ( '' === trim( $alt ) ) {
			return 'slot_missing';
		}
		if ( self::urlUnsafe( $url ) ) {
			return 'unsafe_url';
		}

		return null;
	}

	/**
	 * @return array{0:string, 1:string} [url, alt].
	 */
	private static function splitImageSlot( string $provided ): array {
		$parts = explode( '||', $provided, 2 );

		return array( trim( $parts[0] ?? '' ), trim( $parts[1] ?? '' ) );
	}

	private static function normalize( string $text ): string {
		return (string) preg_replace( '/\s+/', ' ', strtolower( trim( $text ) ) );
	}

	private static function wordCount( string $text ): int {
		$trimmed = trim( $text );
		if ( '' === $trimmed ) {
			return 0;
		}

		$words = preg_split( '/\s+/', $trimmed );
		$words = false === $words ? array() : $words;

		return count( array_filter( $words, static fn ( string $w ): bool => '' !== $w ) );
	}

	private static function stripTags( string $html ): string {
		$text = (string) preg_replace( '#<[^>]*>#', '', $html );

		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function attrValue( string $attribute_text, string $name ): ?string {
		if ( ! preg_match( '#' . preg_quote( $name, '#' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')#', $attribute_text, $match ) ) {
			return null;
		}

		return $match[2] ?? $match[3] ?? '';
	}

	private static function replaceAttr( string $open_tag, string $name, string $value ): string {
		$escaped = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		$pattern = '#' . preg_quote( $name, '#' ) . '\s*=\s*("[^"]*"|\'[^\']*\')#';

		if ( preg_match( $pattern, $open_tag ) ) {
			return (string) preg_replace( $pattern, $name . '="' . $escaped . '"', $open_tag, 1 );
		}

		// The shipped anchor carried no href at all — append one before the closing `>`.
		return (string) preg_replace( '#\s*/?>$#', ' ' . $name . '="' . $escaped . '">', $open_tag, 1 );
	}
}
