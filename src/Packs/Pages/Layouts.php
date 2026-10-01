<?php
/**
 * Section layouts (0.3 quality trial: outline in, design out). A model names
 * a section's layout and supplies its copy as named fields; this class picks
 * the theme pattern and builds the markup, so the model never authors block
 * markup or counts numbered slots. Live runs never used the numbered-slot
 * `{pattern, slots}` form, and every page scored 1 on Visual.
 *
 * Each theme-backed layout maps its fields onto the pattern's own slots in
 * document order and is filled through {@see ThemePatterns::fill()}, so the
 * result goes through exactly the same validation as any other write.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use Specflux\SenroFlux\Tools\PlanTools;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class Layouts {

	/**
	 * The Twenty Twenty-Five layout profile: layout name => the theme pattern
	 * it builds and its slot map: one field
	 * path per slot of the pattern, in document order. `image` fields are
	 * `{url, alt}` objects; `button` and `button2` fields are `{label, url}`
	 * objects and take two slots (the link text, then its destination).
	 *
	 * `limits` raises a body-text slot's word limit above the theme sample's
	 * (1.5 times its words), which is too short to explain a service.
	 *
	 * `optional` names fields the model may leave out (`eyebrow`, `button2`);
	 * the block that holds each one is then dropped from the pattern, so no
	 * sample text stays on the page.
	 *
	 * @var array<string, array{pattern:string, slots:list<string>, limits?:array<string,int>, optional?:list<string>}>
	 */
	private const TWENTY_TWENTY_FIVE = array(
		'hero'            => array(
			'pattern' => 'twentytwentyfive/hero-full-width-image',
			'slots'   => array( 'image', 'heading', 'text', 'button.label', 'button.url' ),
			'limits'  => array( 'text' => 40 ),
		),
		'text-with-image' => array(
			'pattern' => 'twentytwentyfive/heading-and-paragraph-with-image',
			'slots'   => array( 'heading', 'text', 'image' ),
		),
		'services'        => array(
			'pattern' => 'twentytwentyfive/services-3-col',
			'slots'   => array(
				'heading',
				'items.0.image',
				'items.0.title',
				'items.0.text',
				'items.1.image',
				'items.1.title',
				'items.1.text',
				'items.2.image',
				'items.2.title',
				'items.2.text',
			),
			'limits'  => array(
				'heading' => 8,
				'title'   => 6,
				'text'    => 60,
			),
		),
		'faq'             => array(
			'pattern' => 'twentytwentyfive/text-faqs',
			'slots'   => array(
				'heading',
				'items.0.question',
				'items.0.answer',
				'items.1.question',
				'items.1.answer',
				'items.2.question',
				'items.2.answer',
				'items.3.question',
				'items.3.answer',
			),
			'limits'  => array(
				'question' => 12,
				'answer'   => 60,
			),
		),
		'cta'             => array(
			'pattern' => 'twentytwentyfive/cta-centered-heading',
			'slots'   => array( 'heading', 'text', 'button.label', 'button.url' ),
			'limits'  => array( 'text' => 40 ),
		),
	);

	/**
	 * The Ollie layout profile (S5). Ollie files its heroes and CTAs with an
	 * eyebrow paragraph before the heading and two buttons, so the profile maps
	 * those as `eyebrow`, `button` and `button2`. `hero-light` is the one hero
	 * with a single trailing image and a plain cover; `text-call-to-action-buttons`
	 * is its CTA with the same eyebrow and two buttons. (`text-call-to-action`, the
	 * one that already fits heading, text and one button, paints its photo with an
	 * inline `background-image:url()` that the validator refuses.) Ollie has no
	 * pattern that fits `text-with-image`, `services` or `faq` without an extra
	 * image, card or button the model's fields can't fill, so those three use the
	 * curated fallback.
	 *
	 * @var array<string, array{pattern:string, slots:list<string>, limits?:array<string,int>, optional?:list<string>}>
	 */
	private const OLLIE = array(
		'hero' => array(
			'pattern'  => 'ollie/hero-light',
			'slots'    => array( 'eyebrow', 'heading', 'text', 'button.label', 'button.url', 'button2.label', 'button2.url', 'image' ),
			'limits'   => array(
				'eyebrow' => 4,
				'text'    => 40,
			),
			'optional' => array( 'eyebrow', 'button2' ),
		),
		'cta'  => array(
			'pattern'  => 'ollie/text-call-to-action-buttons',
			'slots'    => array( 'eyebrow', 'heading', 'text', 'button.label', 'button.url', 'button2.label', 'button2.url' ),
			'limits'   => array(
				'eyebrow' => 4,
				'text'    => 40,
			),
			'optional' => array( 'eyebrow', 'button2' ),
		),
	);

	/** The block that holds each optional profile field. */
	private const OPTIONAL_BLOCKS = array(
		'eyebrow' => 'core/paragraph',
		'button2' => 'core/button',
	);

	/** Layout names, whatever the theme: the schema enum never varies with it. */
	private const THEME_LAYOUTS = array( 'hero', 'text-with-image', 'services', 'faq', 'cta' );

	/** The layout built from the curated text-section rather than a theme pattern. */
	public const TEXT = 'text';

	/** Words allowed in a `text` layout's heading, as the curated pattern states. */
	private const TEXT_HEADING_MAX_WORDS = 9;

	/** Paragraphs a `text` layout takes, as the curated pattern states. */
	private const TEXT_PARAGRAPHS = array( 2, 4 );

	/**
	 * The model-facing layout rules text (S11), as a list of lines — the
	 * source `PagesPack::skills()` joins into the `pages/layout-rules` skill
	 * body. Moved here (S23, F3) so `Api\LayoutVocabulary::rulesLines()` can
	 * expose it without depending on the pack. Content, never translated
	 * (S15 — skill bodies stay English).
	 *
	 * @return list<string>
	 */
	public static function rulesLines(): array {
		return array(
			'Write a page as `sections` items, each naming a `layout` (hero, text, text-with-image, services, faq, cta) with its fields; the theme design is built for you. Hero first. Give each image slot its own image, except services (all or none). Use `text` where a layout is too short. Write `markup` only when no layout fits.',
			'To rewrite an existing page, send new `sections` layouts with update-post or publish-post, keeping its facts; never edit the markup read-content returns.',
			'Give a visitor what they need to decide: for each service, who it is for and what happens; what happens at the first visit; how to book. Name every service the brief lists on Home and Services. Use services, text and faq layouts; most pages need 5–7 sections. Never put two text sections back to back; add faq, services or text-with-image between.',
			'If the brief gives a phone number or email, the cta button links to it (tel: or mailto:) and the text states it.',
			'Markup patterns, if none fits: hero, cover-hero, text-section, media-text, feature-grid, pricing-table, faq, testimonials, cta. Use at most one cta. A page is 2–8 sections. No pattern more than twice except text-section. An image belongs only in a layout\'s image slot, cover-hero or media-text (see pages/media-rules). No colour attributes except cover-hero\'s own preset overlayColor. Spacing and typography: standard preset slugs only. Re-read every object after writing.',
			'A pattern is NOT a block: it is a core/group (or core/cover, or core/media-text) you write yourself out of core blocks. Never write a block whose name starts with senroflux/. Use only these blocks: core/group, core/heading, core/paragraph, core/buttons, core/button, core/columns, core/column, core/list, core/details, core/quote, core/cover, core/media-text.',
			'Write each block comment with compact JSON (no spaces after : or ,). Give every top-level group `{"metadata":{"name":"senroflux/<slug>"},"layout":{"type":"constrained"}}`. Write list items as plain <li> inside one core/list block; never core/list-item. In an faq, the question is the <summary> element inside the core/details block and the answer is a core/paragraph block inside it.',
			'To publish a page you already created, call the update ability with the id and status only; omit content entirely (omitted content means unchanged). Never resend unchanged content.',
			'Close everything you open: every `<!-- wp:x -->` needs its matching `<!-- /wp:x -->`, and every wrapper element a block opens (a group\'s <div>, a details, a list) must be closed before that block ends. Markup that does not survive a parse-and-reserialise round trip is refused whole as invalid_markup.',
			// 0.3 quality fix (instruction ceiling): the full verb list used
			// to live here (~170 tokens); it now travels on the
			// propose-plan tool's own declaration instead (see
			// PlanTools::proposePlanDeclaration()), built per run from the
			// SAME verb set this pack's runProposePlan() check uses, so it
			// cannot drift from what is actually accepted.
			'When you propose a plan, spell each step\'s verbs exactly as the propose-plan tool\'s own verb list gives them. Creating the page as a draft is pages/create-draft; making a draft live is pages/publish. Any other word is refused as unknown_verb.',
			'Give a block ONLY the attributes its shape names below; any other (an extra align or layout) is refused as unknown_pattern. Only hero, cover-hero and cta give their buttons block `{"layout":{"type":"flex"}}`. For exact sample markup, call pages/list-patterns with the pattern names.',
			'Shapes (">" = child, "(n–m)" = how many of that child):',
			'hero: group align=full > heading level 1, paragraph align=center, buttons layout=flex > button (1–2)',
			'cover-hero: cover align=full > heading level 1, paragraph align=center, buttons layout=flex > button (1–2)',
			'text-section: group > heading level 2, paragraph (2–4)',
			'media-text: media-text > heading level 2, paragraph (1–3), buttons > button (0–1)',
			'feature-grid: group > heading level 2, columns > column (2–3) each > heading level 3, paragraph',
			'pricing-table: group > heading level 2, columns > column (1–3) each > heading level 3, paragraph, list (3–6 items), buttons > button',
			'faq: group > heading level 2, details (2–8) each > paragraph',
			'testimonials: group > heading level 2, quote (1–3)',
			'cta: group align=full > heading level 2, paragraph align=center, buttons layout=flex > button (1)',
		);
	}

	/**
	 * 0.3 layout profiles: the active theme's layout => pattern map. The
	 * stylesheet's profile wins outright (no merge with its parent's), else
	 * the parent theme's, else none. A layout the profile doesn't map, or whose
	 * pattern the theme doesn't offer, is built from the curated pattern.
	 *
	 * @return array<string, array{pattern:string, slots:list<string>, limits?:array<string,int>, optional?:list<string>}>
	 */
	public static function profile(): array {
		$profiles = array(
			'twentytwentyfive' => self::TWENTY_TWENTY_FIVE,
			'ollie'            => self::OLLIE,
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Layout profiles keyed by theme slug. `@internal`.
			 *
			 * @param array<string, array<string, array{pattern:string, slots:list<string>, limits?:array<string,int>, optional?:list<string>}>> $profiles Profiles.
			 */
			$profiles = apply_filters( 'senroflux_layout_profiles', $profiles );
		}
		if ( ! is_array( $profiles ) ) {
			return array();
		}

		foreach ( array( 'get_stylesheet', 'get_template' ) as $getter ) {
			if ( function_exists( $getter ) ) {
				$slug = (string) $getter();
				if ( isset( $profiles[ $slug ] ) && is_array( $profiles[ $slug ] ) ) {
					return $profiles[ $slug ];
				}
			}
		}

		return array();
	}

	/**
	 * Every layout name, for the schema enum and refusal messages.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_merge( self::THEME_LAYOUTS, array( self::TEXT ) );
	}

	/**
	 * Build one `sections` item's markup from its layout and fields.
	 *
	 * @param array<string,mixed> $section            The item: `layout` plus its fields.
	 * @param int                 $index               The item's position, for messages.
	 * @param bool                $images_budget_zero  0.3 quality fix (images budget 0): whether
	 *                                                  the run's `images` budget is 0 — never
	 *                                                  points an image-slot refusal at
	 *                                                  `media-generate` when true, since that
	 *                                                  ability is withheld from the run's tool
	 *                                                  surface entirely (see `ToolRegistry::forRun()`).
	 */
	public static function render( array $section, int $index, Vocabulary $vocabulary, bool $images_budget_zero = false ): string|WP_Error {
		$layout = (string) ( $section['layout'] ?? '' );

		if ( self::TEXT === $layout ) {
			return self::renderText( $section, $index, $vocabulary );
		}

		if ( ! in_array( $layout, self::THEME_LAYOUTS, true ) ) {
			return self::error(
				'layout_unknown',
				sprintf(
					/* translators: 1: section number, 2: the requested layout, 3: comma-separated layout names. */
					__( 'Section %1$d: "%2$s" is not a layout. Use one of: %3$s.', 'senroflux' ),
					$index + 1,
					$layout,
					implode( ', ', self::names() )
				),
				$index
			);
		}

		$map     = self::profile()[ $layout ] ?? null;
		$pattern = null !== $map ? $vocabulary->resolveThemePattern( $map['pattern'] ) : null;
		$markup  = null !== $pattern ? (string) $pattern['markup'] : '';
		$slots   = null !== $pattern ? ThemePatterns::textSlots( $markup ) : array();
		$curated = null === $map || null === $pattern || count( $slots ) !== count( $map['slots'] );
		if ( $curated ) {
			// D1 step 3: the theme has no pattern for this layout, so SenroFlux's
			// own pattern is filled from the same fields.
			$plan   = self::curatedPlan( $layout, $section );
			$map    = $plan['map'];
			$name   = $plan['pattern'];
			$markup = self::curatedMarkup( $vocabulary, $name, $plan['repeat'] );
			if ( '' === $markup ) {
				return self::unavailable( $layout, $index );
			}
			$slots = ThemePatterns::textSlots( $markup );
		} else {
			$name = (string) $map['pattern'];
		}

		$items_error = self::checkItemCount( $section, $map['slots'], $layout, $index );
		if ( null !== $items_error ) {
			return $items_error;
		}

		$paths = $map['slots'];
		if ( 'services' === $layout && ! $curated ) {
			$photos = self::servicesImagePlan( $section, $index );
			if ( $photos instanceof WP_Error ) {
				return $photos;
			}
			if ( $photos ) {
				// 0.3 quality fix (images budget 0): nobody gave a card photo,
				// so the pattern's own `core/image` blocks are dropped rather
				// than refused — there is rarely a third distinct, relevant
				// CC0 photo for every industry.
				$markup = self::stripImageBlocks( $markup );
				$paths  = array_values( array_filter( $paths, static fn ( string $p ): bool => ! str_ends_with( $p, '.image' ) ) );
				$slots  = ThemePatterns::textSlots( $markup );
			}
		}

		$absent = self::absentOptionalPositions( $section, $paths, $map['optional'] ?? array() );
		if ( array() !== $absent ) {
			$markup = self::withoutSlotBlocks( $markup, $absent );
			$paths  = array_values( array_diff_key( $paths, array_flip( $absent ) ) );
			$slots  = ThemePatterns::textSlots( $markup );
		}

		$values = array();
		$limits = array();
		foreach ( $paths as $position => $path ) {
			$slot  = $slots[ $position ];
			$limit = $map['limits'][ (string) preg_replace( '/^items\.\d+\./', '', $path ) ] ?? null;
			if ( null !== $limit && 'text' === $slot['kind'] ) {
				$slot['max_words'] = $limit;
				// The tolerated ceiling, not the raw limit: checkSlot() below
				// already accepted anything up to it, and fill() must not
				// refuse the SAME value a second time with a less forgiving
				// number.
				$limits[ $position ] = self::toleratedWordLimit( $limit );
			}
			$value = self::slotValue( $section, $path, (string) $slot['kind'] );
			$error = self::checkSlot( $slot, $value, $path, $layout, $index, $images_budget_zero );
			if ( null !== $error ) {
				return $error;
			}
			$values[] = $value;
		}

		$filled = ThemePatterns::fill( $markup, $values, $limits );
		if ( ! $filled['ok'] ) {
			/** @var WP_Error $error */
			$error = $filled['wp_error'];

			return $error;
		}

		$content = self::finish( $filled['content'], $section, $name );

		return $curated ? PresetAdapter::adapt( $content ) : $content;
	}

	/**
	 * The profile entries that name `$pattern`, as `layout => entry`.
	 *
	 * @return array<string, array{pattern:string, slots:list<string>, limits?:array<string,int>, optional?:list<string>}>
	 */
	private static function profileEntriesFor( string $pattern ): array {
		return array_filter( self::profile(), static fn ( $map ): bool => is_array( $map ) && ( $map['pattern'] ?? null ) === $pattern );
	}

	/**
	 * The page role (`hero` or `cta`) a theme pattern plays when the active
	 * profile builds that layout from it, else null. A profile's hero counts as
	 * the page's hero even when the theme files it under `ollie/hero` rather
	 * than the bare `banner` category.
	 */
	public static function roleOf( string $pattern ): ?string {
		$entries = self::profileEntriesFor( $pattern );
		foreach ( array( 'hero', 'cta' ) as $role ) {
			if ( isset( $entries[ $role ] ) ) {
				return $role;
			}
		}

		return null;
	}

	/**
	 * The block names the Validator may match zero or more times in a profile
	 * pattern: the card photos of `services` (all or none, see
	 * {@see servicesImagePlan()}) and the blocks of a profile's optional
	 * fields.
	 *
	 * @return list<string>
	 */
	public static function repeatableBlocks( string $pattern ): array {
		$blocks = array();
		foreach ( self::profileEntriesFor( $pattern ) as $layout => $map ) {
			if ( 'services' === $layout ) {
				$blocks[] = 'core/image';
			}
			foreach ( $map['optional'] ?? array() as $field ) {
				if ( isset( self::OPTIONAL_BLOCKS[ $field ] ) ) {
					$blocks[] = self::OPTIONAL_BLOCKS[ $field ];
				}
			}
		}

		return array_values( array_unique( $blocks ) );
	}

	/**
	 * D1 step 3: the curated pattern a layout falls back to and how its fields
	 * map onto that pattern's slots. Field limits and item counts match the
	 * theme profiles', so the model's contract doesn't change with the theme.
	 * `repeat` names the child block to grow to the item count (the curated
	 * feature-grid and faq ship two).
	 *
	 * @param array<string,mixed> $section The item.
	 * @return array{pattern:string, map:array{slots:list<string>, limits:array<string,int>}, repeat:array<string,int>}
	 */
	private static function curatedPlan( string $layout, array $section ): array {
		$image  = is_array( $section['image'] ?? null ) ? $section['image'] : array();
		$cover  = '' !== trim( (string) ( $image['url'] ?? '' ) ) || '' !== trim( (string) ( $image['alt'] ?? '' ) );
		$button = array( 'button.label', 'button.url' );
		$items  = static function ( array $fields, int $count ): array {
			$paths = array();
			for ( $i = 0; $i < $count; $i++ ) {
				foreach ( $fields as $field ) {
					$paths[] = 'items.' . $i . '.' . $field;
				}
			}

			return $paths;
		};

		return match ( $layout ) {
			'hero'            => array(
				'pattern' => $cover ? 'cover-hero' : 'hero',
				'map'     => array(
					'slots'  => array_merge( $cover ? array( 'image' ) : array(), array( 'heading', 'text' ), $button ),
					'limits' => array( 'text' => 40 ),
				),
				'repeat'  => array(),
			),
			'text-with-image' => array(
				'pattern' => 'media-text',
				'map'     => array(
					'slots'  => array( 'image', 'heading', 'text' ),
					'limits' => array(),
				),
				'repeat'  => array(),
			),
			'services'        => array(
				'pattern' => 'feature-grid',
				'map'     => array(
					'slots'  => array_merge( array( 'heading' ), $items( array( 'title', 'text' ), 3 ) ),
					'limits' => array(
						'heading' => 8,
						'title'   => 6,
						'text'    => 60,
					),
				),
				'repeat'  => array( 'core/column' => 3 ),
			),
			'faq'             => array(
				'pattern' => 'faq',
				'map'     => array(
					'slots'  => array_merge( array( 'heading' ), $items( array( 'question', 'answer' ), 4 ) ),
					'limits' => array(
						'question' => 12,
						'answer'   => 60,
					),
				),
				'repeat'  => array( 'core/details' => 4 ),
			),
			default           => array(
				'pattern' => 'cta',
				'map'     => array(
					'slots'  => array_merge( array( 'heading', 'text' ), $button ),
					'limits' => array( 'text' => 40 ),
				),
				'repeat'  => array(),
			),
		};
	}

	/**
	 * The curated pattern's markup with its repeatable children grown to the
	 * counts in `$repeat`, or an empty string when the pattern is missing.
	 *
	 * @param array<string,int> $repeat Child block name => how many it should have.
	 */
	private static function curatedMarkup( Vocabulary $vocabulary, string $slug, array $repeat ): string {
		foreach ( $vocabulary->curated() as $candidate ) {
			if ( $slug !== $candidate['slug'] ) {
				continue;
			}

			$markup = (string) $candidate['markup'];
			if ( array() === $repeat ) {
				return $markup;
			}

			$blocks = parse_blocks( $markup );
			foreach ( $repeat as $child => $count ) {
				$blocks = array_map( static fn ( array $block ): array => self::withChildCount( $block, $child, $count ), $blocks );
			}

			/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
			return serialize_blocks( $blocks );
		}

		return '';
	}

	/**
	 * Grow the children named `$child` (anywhere under `$block`) to `$count` by
	 * repeating the last one, keeping `innerContent`'s null markers in step.
	 *
	 * @param array<string,mixed> $block A parsed block.
	 * @return array<string,mixed>
	 */
	private static function withChildCount( array $block, string $child, int $count ): array {
		$inner = array_map(
			static fn ( array $candidate ): array => self::withChildCount( $candidate, $child, $count ),
			array_values( $block['innerBlocks'] ?? array() )
		);

		$have = 0;
		$last = null;
		foreach ( $inner as $position => $candidate ) {
			$candidate_name = $candidate['blockName'] ?? null;
			if ( $child === $candidate_name ) {
				++$have;
				$last = $position;
			}
		}

		if ( null !== $last && $have < $count ) {
			$extra   = $count - $have;
			$content = array();
			$cursor  = 0;
			foreach ( $block['innerContent'] ?? array() as $chunk ) {
				$content[] = $chunk;
				if ( null === $chunk && $cursor++ === $last ) {
					for ( $n = 0; $n < $extra; $n++ ) {
						$content[] = null;
					}
				}
			}

			array_splice( $inner, $last + 1, 0, array_fill( 0, $extra, $inner[ $last ] ) );
			$block['innerContent'] = $content;
		}

		$block['innerBlocks'] = $inner;

		return $block;
	}

	/**
	 * Slot positions of the optional profile fields the model left out: every
	 * slot of a field is empty. A field given in part (a button label with no
	 * link) stays, and `checkSlot()` names what is missing.
	 *
	 * @param array<string,mixed> $section  The item.
	 * @param list<string>        $paths    The slot map, in slot order.
	 * @param list<string>        $optional The profile's optional field names.
	 * @return list<int>
	 */
	private static function absentOptionalPositions( array $section, array $paths, array $optional ): array {
		$absent = array();
		foreach ( $optional as $field ) {
			$positions = array();
			foreach ( $paths as $position => $path ) {
				if ( $field === $path || str_starts_with( $path, $field . '.' ) ) {
					$positions[] = $position;
				}
			}

			$empty = array() !== $positions;
			foreach ( $positions as $position ) {
				$empty = $empty && '' === self::slotValue( $section, $paths[ $position ], 'text' );
			}
			if ( $empty ) {
				$absent = array_merge( $absent, $positions );
			}
		}

		return $absent;
	}

	/**
	 * Drop the blocks whose own text, link and image slots are all among
	 * `$positions` (slot numbers in document order, as
	 * {@see ThemePatterns::textSlots()} counts them).
	 *
	 * @param list<int> $positions Slot positions to drop.
	 */
	private static function withoutSlotBlocks( string $markup, array $positions ): string {
		$cursor = 0;
		$blocks = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			$kept = self::withoutBlocksAt( $block, $positions, $cursor );
			if ( null !== $kept ) {
				$blocks[] = $kept;
			}
		}

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array<string,mixed> $block     One parsed block.
	 * @param list<int>           $positions Slot positions to drop.
	 * @param int                 $cursor    The next slot number; advanced past this block's slots.
	 * @return array<string,mixed>|null Null when the block is dropped.
	 */
	private static function withoutBlocksAt( array $block, array $positions, int &$cursor ): ?array {
		$own     = count( ThemePatterns::textSlots( implode( '', array_filter( $block['innerContent'] ?? array(), 'is_string' ) ) ) );
		$first   = $cursor;
		$cursor += $own;

		if ( $own > 0 && array() === ( $block['innerBlocks'] ?? array() ) && array() === array_diff( range( $first, $cursor - 1 ), $positions ) ) {
			return null;
		}

		$content = array();
		$kept    = array();
		$given   = array_values( $block['innerBlocks'] ?? array() );
		$next    = 0;
		foreach ( $block['innerContent'] ?? array() as $chunk ) {
			if ( null !== $chunk ) {
				$content[] = $chunk;
				continue;
			}

			$child = $given[ $next++ ] ?? null;
			$child = null === $child ? null : self::withoutBlocksAt( $child, $positions, $cursor );
			if ( null !== $child ) {
				$kept[]    = $child;
				$content[] = null;
			}
		}

		$block['innerBlocks']  = $kept;
		$block['innerContent'] = $content;

		return $block;
	}

	private static function unavailable( string $layout, int $index ): WP_Error {
		return self::error(
			'layout_unavailable',
			sprintf(
				/* translators: 1: section number, 2: layout name. */
				__( 'Section %1$d: the "%2$s" layout has no pattern to build from. Write this section as `markup` instead.', 'senroflux' ),
				$index + 1,
				$layout
			),
			$index
		);
	}

	/**
	 * Every image URL a section's fields name, in field order.
	 *
	 * @param array<string,mixed> $section The item.
	 * @return list<string>
	 */
	public static function imageUrls( array $section ): array {
		$images = array( $section['image'] ?? null );
		foreach ( is_array( $section['items'] ?? null ) ? $section['items'] : array() as $item ) {
			$images[] = is_array( $item ) ? ( $item['image'] ?? null ) : null;
		}

		$urls = array();
		foreach ( $images as $image ) {
			$url = is_array( $image ) ? trim( (string) ( $image['url'] ?? '' ) ) : '';
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * The `services` layout's card-photo decision (0.3 quality fix, images
	 * budget 0): there is rarely a third distinct, relevant CC0 photo for
	 * every industry, so a card's photo is optional — but only for every
	 * card at once, never favouring any one business type by singling out
	 * which card goes without.
	 *
	 * Returns `true` when every item leaves its image out (drop the
	 * pattern's `core/image` blocks entirely), `false` when every item
	 * gives one (render exactly as before) or when an item gives only a
	 * `url` or only an `alt` — that incomplete pair is left to
	 * {@see checkSlot()}'s usual per-field refusal, not this method — and a
	 * `WP_Error` when some items give a full image and others give none at
	 * all.
	 *
	 * @param array<string,mixed> $section The item.
	 */
	private static function servicesImagePlan( array $section, int $index ): bool|WP_Error {
		$items  = is_array( $section['items'] ?? null ) ? $section['items'] : array();
		$states = array();
		foreach ( $items as $item ) {
			$image    = is_array( $item ) && is_array( $item['image'] ?? null ) ? $item['image'] : array();
			$url      = trim( (string) ( $image['url'] ?? '' ) );
			$alt      = trim( (string) ( $image['alt'] ?? '' ) );
			$states[] = match ( true ) {
				'' !== $url && '' !== $alt => 'full',
				'' === $url && '' === $alt => 'none',
				default                    => 'partial',
			};
		}

		if ( in_array( 'partial', $states, true ) ) {
			return false;
		}

		$full = count( array_filter( $states, static fn ( string $s ): bool => 'full' === $s ) );
		$none = count( array_filter( $states, static fn ( string $s ): bool => 'none' === $s ) );

		if ( 0 === $full ) {
			return true;
		}

		if ( 0 === $none ) {
			return false;
		}

		return self::error(
			'layout_field',
			sprintf(
				/* translators: %d: section number. */
				__( 'Section %1$d (services): give every card a photo, or none — a mix is not allowed.', 'senroflux' ),
				$index + 1
			),
			$index
		);
	}

	/**
	 * Drop every `core/image` block from a pattern's parsed markup (0.3
	 * quality fix, images budget 0), so {@see ThemePatterns::textSlots()}/
	 * {@see ThemePatterns::fill()} see a shape with no image slots at all.
	 * Removes each dropped block's `innerContent` placeholder along with
	 * it, keeping the two arrays in step the way {@see serialize_blocks()}
	 * (core's own null-marker walk) requires.
	 */
	private static function stripImageBlocks( string $markup ): string {
		$blocks = array_map(
			static fn ( array $block ): array => self::withoutImageBlock( $block ),
			parse_blocks( $markup )
		);

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array<string,mixed> $block One parsed block.
	 * @return array<string,mixed>
	 */
	private static function withoutImageBlock( array $block ): array {
		$content = array();
		$kept    = array();
		$given   = array_values( $block['innerBlocks'] ?? array() );
		$cursor  = 0;

		foreach ( $block['innerContent'] ?? array() as $chunk ) {
			if ( null !== $chunk ) {
				$content[] = $chunk;
				continue;
			}

			$child = $given[ $cursor++ ] ?? null;
			if ( null === $child ) {
				continue;
			}
			if ( 'core/image' === ( $child['blockName'] ?? null ) ) {
				continue; // Drop the block AND its placeholder.
			}

			$kept[]    = self::withoutImageBlock( $child );
			$content[] = null;
		}

		$block['innerBlocks']  = $kept;
		$block['innerContent'] = $content;

		return $block;
	}

	/**
	 * @param array<string,mixed> $section The item.
	 * @param list<string>        $paths   The layout's slot map.
	 */
	private static function checkItemCount( array $section, array $paths, string $layout, int $index ): ?WP_Error {
		$wanted = 0;
		foreach ( $paths as $path ) {
			if ( preg_match( '/^items\.(\d+)\./', $path, $match ) ) {
				$wanted = max( $wanted, (int) $match[1] + 1 );
			}
		}

		$items = $section['items'] ?? array();
		$given = is_array( $items ) ? count( $items ) : 0;
		if ( 0 === $wanted || $given === $wanted ) {
			return null;
		}

		return self::error(
			'layout_field',
			sprintf(
				/* translators: 1: section number, 2: layout name, 3: items needed, 4: items given. */
				__( 'Section %1$d (%2$s) takes exactly %3$d items; it has %4$d. Split or merge them, or use a second section.', 'senroflux' ),
				$index + 1,
				$layout,
				$wanted,
				$given
			),
			$index
		);
	}

	/**
	 * The slot value for one field path: an image becomes `URL||ALT`, the
	 * form {@see ThemePatterns::fill()} takes.
	 *
	 * @param array<string,mixed> $section The item.
	 */
	private static function slotValue( array $section, string $path, string $kind ): string {
		$value = $section;
		foreach ( explode( '.', $path ) as $key ) {
			$value = is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
		}

		if ( 'image' === $kind ) {
			$image = is_array( $value ) ? $value : array();

			return trim( (string) ( $image['url'] ?? '' ) ) . '||' . trim( (string) ( $image['alt'] ?? '' ) );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * The same checks {@see ThemePatterns::fill()} makes, reported by field
	 * name instead of slot number.
	 *
	 * @param array<string,mixed> $slot One entry of {@see ThemePatterns::textSlots()}.
	 */
	private static function checkSlot( array $slot, string $value, string $path, string $layout, int $index, bool $images_budget_zero = false ): ?WP_Error {
		$field = self::fieldLabel( $path );

		if ( 'image' === $slot['kind'] ) {
			[$url, $alt] = explode( '||', $value, 2 );
			if ( '' === $url || '' === $alt || ! Validator::urlIsSafe( $url ) ) {
				// 0.3 quality fix (images budget 0): a services card's photo is
				// optional (see servicesImagePlan()) — the refusal for a
				// PARTIAL image (a url with no alt, or the reverse) says so,
				// so the model knows dropping every card's photo is a way out
				// when there is no third distinct, relevant photo, not just a
				// dead end on this one field.
				if ( 'services' === $layout ) {
					return self::fieldError(
						$images_budget_zero
							/* translators: 1: section number, 2: layout name, 3: field name. */
							? __( 'Section %1$d (%2$s): `%3$s` needs a `url` from the media library (media-search, or stock-image-search then stock-image-import) and descriptive `alt` text — or leave every card without a photo if there are not three distinct, relevant ones.', 'senroflux' )
							/* translators: 1: section number, 2: layout name, 3: field name. */
							: __( 'Section %1$d (%2$s): `%3$s` needs a `url` from the media library (media-search or media-generate) and descriptive `alt` text — or leave every card without a photo if there are not three distinct, relevant ones.', 'senroflux' ),
						$index,
						$layout,
						$field
					);
				}

				return self::fieldError(
					// 0.3 quality fix (images budget 0): never point the model
					// at media-generate when this run's images budget is 0 —
					// that ability is withheld from its tool surface entirely
					// (see ToolRegistry::forRun()).
					$images_budget_zero
						/* translators: 1: section number, 2: layout name, 3: field name. */
						? __( 'Section %1$d (%2$s): `%3$s` needs a `url` from the media library (media-search, or stock-image-search then stock-image-import) and descriptive `alt` text.', 'senroflux' )
						/* translators: 1: section number, 2: layout name, 3: field name. */
						: __( 'Section %1$d (%2$s): `%3$s` needs a `url` from the media library (media-search or media-generate) and descriptive `alt` text.', 'senroflux' ),
					$index,
					$layout,
					$field
				);
			}

			return null;
		}

		if ( 'url' === $slot['kind'] ) {
			if ( '' === $value || '#' === $value || ! Validator::urlIsSafe( $value ) ) {
				return self::fieldError(
					/* translators: 1: section number, 2: layout name, 3: field name. */
					__( 'Section %1$d (%2$s): `%3$s` needs a real destination: a page URL, a tel: number or a mailto: address.', 'senroflux' ),
					$index,
					$layout,
					$field
				);
			}

			return null;
		}

		if ( '' === $value ) {
			return self::fieldError(
				/* translators: 1: section number, 2: layout name, 3: field name. */
				__( 'Section %1$d (%2$s): `%3$s` is missing.', 'senroflux' ),
				$index,
				$layout,
				$field
			);
		}

		$words = self::wordCount( $value );
		$max   = (int) $slot['max_words'];
		if ( $words > self::toleratedWordLimit( $max ) ) {
			return self::error(
				'layout_field',
				sprintf(
					/* translators: 1: section number, 2: layout name, 3: field name, 4: words given, 5: word limit. */
					__( 'Section %1$d (%2$s): `%3$s` has %4$d words; the limit is %5$d. Shorten it, or move the detail into a `text` section.', 'senroflux' ),
					$index + 1,
					$layout,
					$field,
					$words,
					$max
				),
				$index
			);
		}

		return null;
	}

	/**
	 * Name the section after its pattern, so the Validator keeps that identity
	 * where another theme pattern has the same block structure.
	 *
	 * A cover block keeps its image in its comment attributes as well as the
	 * `<img>`; the editor rebuilds the `<img>` from the attributes, so both
	 * must name the new image or the block opens as invalid. Its heading
	 * becomes the page's H1: the theme ships an h2, and the page template's
	 * own title is then dropped ({@see HeroTemplate}).
	 *
	 * @param array<string,mixed> $section The item.
	 * @param string              $pattern The theme pattern the section was built from.
	 */
	private static function finish( string $markup, array $section, string $pattern ): string {
		$blocks = parse_blocks( $markup );
		$named  = false;
		foreach ( $blocks as $offset => $block ) {
			if ( ! $named && null !== ( $block['blockName'] ?? null ) ) {
				$attrs                      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$attrs['metadata']          = array( 'name' => 'senroflux/' . $pattern );
				$blocks[ $offset ]['attrs'] = $attrs;
				$block                      = $blocks[ $offset ];
				$named                      = true;
			}

			if ( 'core/cover' !== ( $block['blockName'] ?? null ) ) {
				continue;
			}

			$image = is_array( $section['image'] ?? null ) ? $section['image'] : array();
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			// Only a cover that carries its own background photo takes the model's
			// image; Ollie's `hero-light` cover has none, and a CTA cover keeps the
			// theme's.
			if ( '' === trim( (string) ( $attrs['url'] ?? '' ) ) || '' === trim( (string) ( $image['url'] ?? '' ) ) ) {
				continue;
			}
			unset( $attrs['id'] );
			$attrs['url']               = trim( (string) ( $image['url'] ?? '' ) );
			$attrs['alt']               = trim( (string) ( $image['alt'] ?? '' ) );
			$blocks[ $offset ]['attrs'] = $attrs;
			$blocks[ $offset ]          = self::firstHeadingAsH1( $blocks[ $offset ] );
		}

		/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
		return serialize_blocks( $blocks );
	}

	/**
	 * @param array<string,mixed> $block A parsed block.
	 * @param bool                $done  Set once a heading has been promoted.
	 * @return array<string,mixed>
	 */
	private static function firstHeadingAsH1( array $block, bool &$done = false ): array {
		if ( ! $done && 'core/heading' === ( $block['blockName'] ?? null ) ) {
			$done                    = true;
			$block['attrs']['level'] = 1;
			$swap                    = static fn ( $chunk ) => is_string( $chunk ) ? (string) preg_replace( array( '#<h2(\s|>)#', '#</h2>#' ), array( '<h1$1', '</h1>' ), $chunk ) : $chunk;
			$block['innerHTML']      = $swap( $block['innerHTML'] ?? '' );
			$block['innerContent']   = array_map( $swap, $block['innerContent'] ?? array() );

			return $block;
		}

		foreach ( $block['innerBlocks'] ?? array() as $i => $child ) {
			$block['innerBlocks'][ $i ] = self::firstHeadingAsH1( $child, $done );
		}

		return $block;
	}

	/**
	 * `text`: the curated text-section, heading plus two to four paragraphs.
	 *
	 * @param array<string,mixed> $section The item.
	 */
	private static function renderText( array $section, int $index, Vocabulary $vocabulary ): string|WP_Error {
		$heading = is_scalar( $section['heading'] ?? null ) ? trim( (string) $section['heading'] ) : '';
		if ( '' === $heading ) {
			return self::fieldError(
				/* translators: 1: section number, 2: layout name, 3: field name. */
				__( 'Section %1$d (%2$s): `%3$s` is missing.', 'senroflux' ),
				$index,
				self::TEXT,
				'heading'
			);
		}
		if ( self::wordCount( $heading ) > self::toleratedWordLimit( self::TEXT_HEADING_MAX_WORDS ) ) {
			return self::error(
				'layout_field',
				sprintf(
					/* translators: 1: section number, 2: layout name, 3: field name, 4: words given, 5: word limit. */
					__( 'Section %1$d (%2$s): `%3$s` has %4$d words; the limit is %5$d. Shorten it, or move the detail into a `text` section.', 'senroflux' ),
					$index + 1,
					self::TEXT,
					'heading',
					self::wordCount( $heading ),
					self::TEXT_HEADING_MAX_WORDS
				),
				$index
			);
		}

		$paragraphs  = array_values(
			array_filter(
				array_map(
					static fn ( $p ): string => is_scalar( $p ) ? trim( (string) $p ) : '',
					is_array( $section['paragraphs'] ?? null ) ? $section['paragraphs'] : array()
				),
				static fn ( string $p ): bool => '' !== $p
			)
		);
		[$min, $max] = self::TEXT_PARAGRAPHS;
		if ( count( $paragraphs ) < $min || count( $paragraphs ) > $max ) {
			return self::error(
				'layout_field',
				sprintf(
					/* translators: 1: section number, 2: layout name, 3: minimum paragraphs, 4: maximum paragraphs, 5: paragraphs given. */
					__( 'Section %1$d (%2$s): `paragraphs` takes %3$d to %4$d paragraphs; it has %5$d.', 'senroflux' ),
					$index + 1,
					self::TEXT,
					$min,
					$max,
					count( $paragraphs )
				),
				$index
			);
		}

		$markup = '';
		foreach ( $vocabulary->curated() as $pattern ) {
			if ( 'text-section' === $pattern['slug'] ) {
				$markup = (string) $pattern['markup'];
			}
		}

		$first = strpos( $markup, '<!-- wp:paragraph -->' );
		$close = '<!-- /wp:paragraph -->';
		$last  = strrpos( $markup, $close );
		if ( false === $first || false === $last ) {
			return self::unavailable( self::TEXT, $index );
		}

		$head = (string) preg_replace(
			'#(<h2 class="wp-block-heading">).*?(</h2>)#s',
			'${1}' . str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), self::escape( $heading ) ) . '${2}',
			substr( $markup, 0, $first ),
			1
		);
		$body = implode(
			"\n",
			array_map(
				static fn ( string $p ): string => '<!-- wp:paragraph --><p>' . self::escape( $p ) . '</p>' . $close,
				$paragraphs
			)
		);

		return PresetAdapter::adapt( $head . $body . substr( $markup, $last + strlen( $close ) ) );
	}

	private static function fieldLabel( string $path ): string {
		return (string) preg_replace( '/\.(\d+)\./', '[$1].', $path );
	}

	private static function fieldError( string $format, int $index, string $layout, string $field ): WP_Error {
		return self::error( 'layout_field', sprintf( $format, $index + 1, $layout, $field ), $index );
	}

	private static function error( string $code, string $message, int $index ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => 400,
				'index'  => $index,
			)
		);
	}

	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	private static function wordCount( string $text ): int {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return false === $words ? 0 : count( $words );
	}

	/**
	 * The same near-miss tolerance {@see PlanTools::overCap()} gives character
	 * limits, applied to a WORD limit: a field a bit over its advertised limit
	 * still refuses, but not one within {@see PlanTools::LENGTH_TOLERANCE_PERCENT}
	 * of it — live evidence: a 6-word hero heading refused three times in a
	 * row against a 5-word limit. Floored, so a 5-word limit tolerates up to 6
	 * (`floor(5 * 1.25) = 6`), not 7.
	 */
	private static function toleratedWordLimit( int $limit ): int {
		return intdiv( $limit * ( 100 + PlanTools::LENGTH_TOLERANCE_PERCENT ), 100 );
	}
}
