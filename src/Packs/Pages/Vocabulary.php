<?php
/**
 * The pages pack's seven pattern vocabulary (S11).
 *
 * TARGET REPO PATH: src/Packs/Pages/Vocabulary.php
 *
 * The vocabulary is the single authoring source for:
 *   - the patterns registered on `register_block_pattern` (names
 *     `senroflux/<slug>`, category `senroflux-pages`),
 *   - the structural identity the Validator matches against (blockName tree +
 *     layout-defining attrs + slot min/max),
 *   - the `constraints.stated` copy lines the `pages/copy-rules` skill body is
 *     rendered from (so the tool payload and the instruction can never drift),
 *   - the `list-patterns` payload (S11 — only this category, no theme patterns
 *     in 0.2; documented gap).
 *
 * The registry cannot carry the constraint data (S11), so this class holds the
 * data and `register()` wires it into WordPress.
 *
 * ROUND-TRIP DECISION. The shipped markup carries REAL default copy, never a
 * `{{placeholder}}`, and its `metadata.name` is already the canonical
 * `senroflux/<slug>`. A pattern a human inserts from the editor therefore
 * survives `create-post` / `update-post` untouched: placeholders would trip the
 * `unresolved_placeholder` refusal on the very first write-back. The model
 * never receives markup — it receives `list-patterns`' `constraints.stated`
 * prose — so nothing is lost by keeping the markup placeholder-free.
 *
 * S11 COMPLIANCE. No colour attribute appears anywhere (`backgroundColor`,
 * `textColor`, `style.color`); spacing is expressed only as the standard
 * `var:preset|spacing|NN` slugs plus the matching CSS custom properties the
 * editor itself emits, and typography only as `fontSize` preset slugs.
 *
 * `repeatable` names the child block a pattern may repeat. The Validator uses
 * it to allow variation in exactly that one slot and to hold every OTHER child
 * to the count the pattern ships — without it, extra headings or paragraphs
 * ride along inside an otherwise-matching pattern.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use Specflux\SenroFlux\Packs\Content\ThemePatternSource;
use Specflux\SenroFlux\Packs\Content\Vocabulary as ContentVocabulary;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The seven-page vocabulary. Implements the S4 {@see ContentVocabulary} seam
 * so `Packs\Content\Abilities` can answer `list-patterns` without knowing any
 * pack's concrete pattern set, and the 0.3 S21 {@see ThemePatternSource} seam
 * so the same registrar can resolve a `sections` write item naming a theme
 * pattern.
 *
 * NOT final (0.3 S7): the site pack's {@see \Specflux\SenroFlux\Packs\Site\Vocabulary}
 * extends this to add its two homepage-only patterns on top of the same
 * seven, rather than re-authoring them. `all()`/`listPayload()`/`register()`
 * all dispatch through `$this->all()`/`$this->curated()`, so the extension
 * needs no other override.
 *
 * CURATED VS THEME-DERIVED (0.3 S21). `all()` is `curated()` plus
 * `themeDerived()` — every consumer that needs BOTH (the Validator's
 * structural match, `BlockShells`, `list-patterns`) keeps calling `all()`
 * unchanged. `register()` — which puts patterns on the block editor's own
 * inserter under this pack's OWN category — iterates `curated()` only: a
 * theme's pattern is already registered by the theme itself, and
 * re-registering it here would be a second, redundant registration under a
 * category it doesn't belong to.
 */
class Vocabulary implements ContentVocabulary, ThemePatternSource {

	/**
	 * The pattern category registered with the block editor.
	 */
	public const CATEGORY = 'senroflux-pages';

	/**
	 * The page-shape rules (S11), consumed by the Validator and the layout skill.
	 * "Hero first" needs no constant — it is a position, not a bound.
	 */
	public const RULES_MAX_CTA      = 1;
	public const RULES_MIN_PATTERNS = 2;
	public const RULES_MAX_PATTERNS = 8;
	public const RULES_MAX_REPEAT   = 2;

	/**
	 * The core block names pages may use (Validator step 2). A block outside
	 * this set is `unknown_block`.
	 *
	 * `core/cite` is intentionally absent: the testimonials `cite` is inner
	 * content of the quote block, never a standalone block.
	 *
	 * `core/image` (0.3 quality feature 4) never appears in one of the seven
	 * curated patterns' own shapes — only inside a theme pattern's own image
	 * slot, and only with mandatory alt text ({@see Validator::checkImageAlt()}).
	 *
	 * @return list<string>
	 */
	public function blockNames(): array {
		return array(
			'core/group',
			'core/heading',
			'core/paragraph',
			'core/buttons',
			'core/button',
			'core/columns',
			'core/column',
			'core/list',
			'core/details',
			'core/summary',
			'core/quote',
			'core/image',
			// 0.3 quality fix (images required on new pages): the two
			// image-led curated patterns, `cover-hero` and `media-text`.
			'core/cover',
			'core/media-text',
		);
	}

	/**
	 * The seven curated pattern definitions, in authoring order.
	 *
	 * Each definition: { slug, name, title, description, markup, repeatable,
	 * constraints }. `repeatable`: list<string> of the child block names this
	 * pattern may repeat. `constraints`: { slots: {<slot>:{min,max}}, stated:
	 * list<string> }.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function curated(): array {
		return array(
			$this->hero(),
			$this->textSection(),
			$this->featureGrid(),
			$this->pricingTable(),
			$this->faq(),
			$this->testimonials(),
			$this->cta(),
			// 0.3 quality fix (images required on new pages): appended, not
			// interleaved — several callers (tests, `sections` composition)
			// index the ORIGINAL seven positionally (`all()[0]` is hero,
			// `all()[1]` is text-section); appending keeps every one of them
			// correct without an audit.
			$this->coverHero(),
			$this->mediaText(),
		);
	}

	/**
	 * The active theme's own eligible patterns (0.3 S21), vocabulary-shaped
	 * and appended AFTER the curated ones — the order every consumer of
	 * `all()` relies on: {@see \Specflux\SenroFlux\Packs\Pages\Validator}
	 * matches the FIRST structural hit, so a curated pattern wins a shape
	 * tie against a theme one for free.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function themeDerived(): array {
		return array_map( array( self::class, 'withProfileRepeatables' ), ThemePatterns::eligible() );
	}

	/**
	 * 0.3 quality fix (images budget 0): {@see Layouts} renders the
	 * `services` layout's card photos only when every item supplies one,
	 * and drops the pattern's `core/image` blocks entirely when none do —
	 * there is rarely a third distinct, relevant CC0 photo for every
	 * industry. The Validator's structural match ({@see Validator::matchesShape()})
	 * must accept both shapes, so THIS ONE theme pattern's `core/image`
	 * becomes a 0..n repeatable child instead of the fixed one the theme
	 * ships. A profile's optional fields (S5: Ollie's hero eyebrow and second
	 * button) get the same treatment for their block. Every other theme
	 * pattern (the hero, text-with-image) keeps requiring its blocks exactly
	 * as the theme shipped them.
	 *
	 * @param array<string,mixed> $pattern One theme-derived vocabulary entry.
	 * @return array<string,mixed>
	 */
	private static function withProfileRepeatables( array $pattern ): array {
		// S5b: a pattern a layout is built from by choice or automatic match is
		// recognised as its shipped tree with the three adaptations.
		$pattern['adapt'] = Layouts::adaptsPattern( $pattern );

		$blocks = Layouts::repeatableBlocks( (string) ( $pattern['name'] ?? '' ) );
		if ( array() === $blocks ) {
			return $pattern;
		}

		$pattern['repeatable'] = array_values(
			array_unique( array_merge( $pattern['repeatable'] ?? array(), $blocks ) )
		);

		return $pattern;
	}

	/**
	 * The curated seven plus every eligible theme-derived pattern (0.3 S21).
	 * Every consumer that needs the FULL matchable set — the Validator, the
	 * `list-patterns` payload, {@see BlockShells} — calls this, never
	 * `curated()` alone.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function all(): array {
		return array_merge( $this->curated(), $this->themeDerived() );
	}

	/**
	 * The eligible theme pattern registered under `$name`, or null (0.3 S21,
	 * {@see \Specflux\SenroFlux\Packs\Content\ThemePatternSource}).
	 *
	 * @return array<string,mixed>|null
	 */
	public function resolveThemePattern( string $name ): ?array {
		foreach ( $this->themeDerived() as $pattern ) {
			if ( $name === $pattern['name'] ) {
				return $pattern;
			}
		}

		return null;
	}

	/**
	 * The count of the active theme's own patterns that were NOT eligible
	 * (0.3 S21, {@see \Specflux\SenroFlux\Packs\Content\ThemePatternSource}).
	 */
	public function themePatternsSkippedCount(): int {
		return ThemePatterns::skippedCount();
	}

	/**
	 * Whether `$name` names one of the curated seven, not a theme-derived
	 * pattern (0.3 quality fix, {@see \Specflux\SenroFlux\Packs\Content\ThemePatternSource}).
	 */
	public function isCuratedPatternName( string $name ): bool {
		foreach ( $this->curated() as $curated ) {
			if ( $name === $curated['name'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The names of every eligible theme-derived pattern (0.3 quality fix,
	 * {@see \Specflux\SenroFlux\Packs\Content\ThemePatternSource}).
	 *
	 * @return list<string>
	 */
	public function themePatternNames(): array {
		return array_map(
			static fn ( array $entry ): string => (string) $entry['name'],
			$this->themeDerived()
		);
	}

	/**
	 * Whether `list-patterns` offers this theme's patterns for numbered-slot
	 * filling. The pages pack builds them through
	 * {@see \Specflux\SenroFlux\Packs\Pages\Layouts} instead: a live run
	 * offered both mixed them and left a pattern's sample text on the page.
	 */
	public function offersThemeSlots(): bool {
		return false;
	}

	/**
	 * The `senroflux/list-patterns` payload (0.3 S7 gap fix): metadata,
	 * constraints AND the pattern's shipped sample markup. Prose shape lines
	 * (in the pack's `layout-rules`/`page-links` skill) are a lossy summary —
	 * a live run got the shape right from the prose alone and was STILL
	 * refused, because the prose never said the group also carries
	 * `style.spacing.padding`. The markup is the exact block the Validator's
	 * `BlockShells` check matches against, so it is the one input a model can
	 * copy verbatim and always pass editor parity: `style.spacing.padding` is
	 * optional (a model may drop it, add it, or keep it as shipped — see
	 * {@see \Specflux\SenroFlux\Packs\Pages\BlockShells}), everything else in
	 * the sample is load-bearing.
	 *
	 * 0.3 S21: a theme-derived pattern carries `theme_derived: true` and
	 * `slots` (its numbered text/url slots) INSTEAD of `markup` — the model
	 * never authors its markup, it sends `{pattern, slots}`. The payload also
	 * carries `theme_patterns_skipped`, the count this theme's patterns that
	 * did not qualify.
	 *
	 * Token cost: every full entry's `markup`/`constraints`/`slots`
	 * cost real conversation tokens on every later turn once returned, so
	 * with `$names` empty this returns a compact INDEX — name, title,
	 * description, `theme_derived` when true — and no markup/constraints/
	 * slots at all. With `$names` given, returns full entries (today's shape)
	 * for exactly those names, in vocabulary order; a name not found in this
	 * vocabulary is reported back in `not_found` instead of failing the call.
	 *
	 * @param list<string> $names Pattern names to return full entries for.
	 * @return array<string,mixed> { patterns: list<array<string,mixed>>, theme_patterns_skipped: int, not_found?: list<string> }
	 */
	public function listPayload( array $names = array() ): array {
		if ( empty( $names ) ) {
			$patterns = array();
			foreach ( $this->listable() as $pattern ) {
				$entry = array(
					'name'        => $pattern['name'],
					'title'       => $pattern['title'],
					'description' => $pattern['description'],
				);

				if ( ! empty( $pattern['theme_derived'] ) ) {
					$entry['theme_derived'] = true;
					// 0.3 quality fix (theme patterns first): a one-line slot
					// summary in the INDEX itself — "3 text, 1 image" — so a
					// theme pattern with an image slot is easy to prefer for a
					// page that needs one, without a second `list-patterns`
					// round trip just to find out. Named `slots_summary`
					// (never `slots`) so its shape never collides with the
					// full entry's `slots` array below. Costs a few words per
					// entry, not the ~9 KB a full `text_slots` array would.
					$entry['slots_summary'] = self::slotSummary( $pattern['text_slots'] ?? array() );
				}

				$patterns[] = $entry;
			}

			return array(
				'patterns'               => $patterns,
				'theme_patterns_skipped' => $this->themePatternsSkippedCount(),
			);
		}

		$wanted   = array_flip( $names );
		$patterns = array();
		foreach ( $this->listable() as $pattern ) {
			if ( ! isset( $wanted[ $pattern['name'] ] ) ) {
				continue;
			}
			unset( $wanted[ $pattern['name'] ] );

			$entry = array(
				'name'        => $pattern['name'],
				'title'       => $pattern['title'],
				'description' => $pattern['description'],
				'constraints' => $pattern['constraints'],
			);

			if ( ! empty( $pattern['theme_derived'] ) ) {
				$entry['theme_derived'] = true;
				$entry['slots']         = $pattern['text_slots'] ?? array();
			} else {
				$entry['markup'] = $pattern['markup'];
			}

			$patterns[] = $entry;
		}

		$payload = array(
			'patterns'               => $patterns,
			'theme_patterns_skipped' => $this->themePatternsSkippedCount(),
		);

		if ( ! empty( $wanted ) ) {
			$payload['not_found'] = array_keys( $wanted );
		}

		return $payload;
	}

	/**
	 * The patterns `list-patterns` may name: all of them, less this theme's
	 * own where {@see offersThemeSlots()} is false.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function listable(): array {
		return $this->offersThemeSlots() ? $this->all() : $this->curated();
	}

	/**
	 * Register the curated seven on WordPress (S11), guarded so a bare-PHPUnit
	 * run (or a pre-Gutenberg load) is a no-op. Returns the count registered.
	 *
	 * 0.3 S21: iterates {@see curated()}, never {@see all()} — a theme's own
	 * pattern is already registered by the theme itself; re-registering it
	 * here would be a second registration under a category it doesn't belong
	 * to.
	 */
	public function register(): int {
		if ( ! function_exists( 'register_block_pattern_category' ) ) {
			return 0;
		}

		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SenroFlux pages', 'senroflux' ),
				'description' => __( 'Page building blocks for SenroFlux content.', 'senroflux' ),
			)
		);

		if ( ! function_exists( 'register_block_pattern' ) ) {
			return 0;
		}

		$registered = 0;
		foreach ( $this->curated() as $pattern ) {
			register_block_pattern(
				$pattern['name'],
				array(
					'title'       => $pattern['title'],
					'description' => $pattern['description'],
					'categories'  => array( self::CATEGORY ),
					'content'     => $pattern['markup'],
				)
			);
			++$registered;
		}

		return $registered;
	}

	/**
	 * A short slot summary for the `list-patterns` compact index — "3 text,
	 * 1 image" — never the full slot list (0.3 quality fix, theme patterns
	 * first). Omits a kind with zero slots; "no slots" when none at all
	 * (S21's `theme_derived` eligibility already requires at least one text
	 * slot, so this only happens for a pattern with url slots alone).
	 *
	 * @param list<array<string,mixed>> $slots {@see ThemePatterns::textSlots()}.
	 */
	private static function slotSummary( array $slots ): string {
		$counts = array(
			'text'  => 0,
			'image' => 0,
			'url'   => 0,
		);

		foreach ( $slots as $slot ) {
			$kind = (string) ( $slot['kind'] ?? '' );
			if ( isset( $counts[ $kind ] ) ) {
				++$counts[ $kind ];
			}
		}

		$parts = array();
		foreach ( $counts as $kind => $count ) {
			if ( $count > 0 ) {
				$parts[] = $count . ' ' . $kind;
			}
		}

		return array() === $parts ? 'no slots' : implode( ', ', $parts );
	}

	/**
	 * hero — group[align=full, layout=constrained] › heading(h1) › paragraph ›
	 * buttons › button.
	 *
	 * @return array<string,mixed>
	 */
	private function hero(): array {
		return array(
			'slug'        => 'hero',
			'name'        => 'senroflux/hero',
			'title'       => 'Hero',
			'description' => __( 'A full-width hero: one headline, one subheadline and up to two calls to action.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/hero"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="wp-block-heading has-text-align-center">A headline that states the promise</h1><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","fontSize":"large"} --><p class="has-text-align-center has-large-font-size">One supporting sentence saying who this is for and what they get.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get started</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/button' ),
			'constraints' => array(
				'slots'  => array(
					'buttons' => array(
						'min' => 1,
						'max' => 2,
					),
				),
				'stated' => array(
					'Headline: one clear promise, at most 12 words.',
					'Subheadline: one supporting sentence, at most 35 words.',
					'Buttons: verb-first labels, at most 4 words each.',
				),
			),
		);
	}

	/**
	 * cover-hero — a `core/cover` hero: a full-width background image with
	 * heading(h1) › paragraph › buttons › button, exactly like {@see hero()}
	 * except the group is a cover carrying a real photo (0.3 quality fix:
	 * images required on new pages). An alternative to `hero`, not a
	 * replacement — {@see \Specflux\SenroFlux\Packs\Pages\Validator::isHeroSlug()}
	 * treats it as a hero for "hero first", and its own H1 triggers the
	 * no-title template the same way `hero`'s does ({@see HeroTemplate}, which
	 * matches on ANY `<h1` in the content, not the pattern name).
	 *
	 * `dimRatio`/`overlayColor` are fixed to the shipped default, the same as
	 * every other decorative attribute in this vocabulary (spacing presets are
	 * the one documented exception) — a run varies the image and the copy,
	 * never the chrome. `overlayColor` is a THEME PRESET SLUG, never
	 * `customOverlayColor`/a hex value, so this still carries no raw colour
	 * value (S11 compliance, the same rule {@see Validator::findDecorativeColor()}
	 * enforces for `backgroundColor`/`textColor`/`gradient`).
	 *
	 * 0.3 quality fix (hero readability): `dimRatio` ships at 60, not core's
	 * own 50 default — live pages put white hero text straight over a bright
	 * stock photo, and 50 was not always enough overlay to keep it readable.
	 * `dimRatio` itself is excluded from {@see BlockShells}'s shape identity
	 * (the same treatment as `id`/`url`/`alt`/`focalPoint`, above) so a run's
	 * own lower value never blocks the match — {@see Validator::MIN_COVER_DIM_RATIO}
	 * silently raises it instead of refusing.
	 *
	 * The `id`/`url`/`alt` triple is a real attachment's — never the sample's
	 * — on every write, exactly like `core/image` (0.3 quality feature 4):
	 * {@see BlockShells::attributeKey()} excludes `id`/`url`/`alt` from this
	 * block's identity the same way it already excludes `core/image`'s `id`,
	 * and the `wp-image-<n>` class WordPress derives from `id` is excluded
	 * from the HTML-shell comparison for the same reason (0.3 quality fix).
	 *
	 * @return array<string,mixed>
	 */
	private function coverHero(): array {
		return array(
			'slug'        => 'cover-hero',
			'name'        => 'senroflux/cover-hero',
			'title'       => 'Cover hero',
			'description' => __( 'An image-led hero: a full-width background photo with one headline, one subheadline and up to two calls to action.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:cover {"metadata":{"name":"senroflux/cover-hero"},"url":"https://example.test/photo.jpg","id":501,"alt":"A descriptive alt","dimRatio":60,"overlayColor":"contrast","align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)"><img class="wp-block-cover__image-background wp-image-501" alt="A descriptive alt" src="https://example.test/photo.jpg" data-object-fit="cover"/><span aria-hidden="true" class="wp-block-cover__background has-contrast-background-color has-background-dim-60 has-background-dim"></span>
<div class="wp-block-cover__inner-container">
<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="wp-block-heading has-text-align-center">A headline that states the promise</h1><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","fontSize":"large"} --><p class="has-text-align-center has-large-font-size">One supporting sentence saying who this is for and what they get.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get started</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div>
</div>
<!-- /wp:cover -->
HTML
			,
			'repeatable'  => array( 'core/button' ),
			'constraints' => array(
				'slots'  => array(
					'buttons' => array(
						'min' => 1,
						'max' => 2,
					),
				),
				'stated' => array(
					'Headline: one clear promise, at most 12 words.',
					'Subheadline: one supporting sentence, at most 35 words.',
					'Buttons: verb-first labels, at most 4 words each.',
					'Image: a real attachment from read-media/media-search/media-generate; alt text required.',
				),
			),
		);
	}

	/**
	 * text-section — group › heading(h2) › paragraph*.
	 *
	 * @return array<string,mixed>
	 */
	private function textSection(): array {
		return array(
			'slug'        => 'text-section',
			'name'        => 'senroflux/text-section',
			'title'       => 'Text section',
			'description' => __( 'A plain prose section: an H2 heading followed by two to four paragraphs.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/text-section"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading --><h2 class="wp-block-heading">What this section covers</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Open with the point a visitor came for: what this is, who it suits and what they get from it. Use the business's own facts, such as its services, place, hours and people, and name them exactly.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Then answer the next question they would ask, such as what happens at a first visit, how long it takes or how to book. One idea per paragraph, no filler, and nothing invented.</p><!-- /wp:paragraph -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/paragraph' ),
			'constraints' => array(
				'slots'  => array(
					'paragraphs' => array(
						'min' => 2,
						'max' => 4,
					),
				),
				'stated' => array(
					'Heading: states the section subject, at most 9 words.',
					'Paragraphs: two to four, each 40 to 90 words, one concrete idea each.',
				),
			),
		);
	}

	/**
	 * media-text — a `core/media-text`: an image on one side, heading(h2) ›
	 * paragraph* › buttons? on the other (0.3 quality fix: images required on
	 * new pages). `mediaPosition` is fixed to `left`: a `right` variant would
	 * need a second shell identity, so the model is told left only.
	 *
	 * `mediaId`/`mediaSizeSlug` are the block's own stored attributes;
	 * `mediaAlt`/`mediaUrl` are NOT — WordPress derives both from the `<img>`
	 * itself (`source: attribute`, {@see https://schemas.wp.org/}), so they
	 * never appear in the comment JSON here, only in the HTML. `mediaId` is
	 * excluded from this block's identity the same way `core/image`'s `id`
	 * is (0.3 quality feature 4); the `wp-image-<n>`/`size-<slug>` classes it
	 * drives are excluded from the HTML-shell comparison for the same reason.
	 *
	 * @return array<string,mixed>
	 */
	private function mediaText(): array {
		return array(
			'slug'        => 'media-text',
			'name'        => 'senroflux/media-text',
			'title'       => 'Media and text',
			'description' => __( 'An image beside a short text block: a photo on one side, an H2 heading and one to three paragraphs (with an optional button) on the other.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:media-text {"metadata":{"name":"senroflux/media-text"},"mediaId":502,"mediaType":"image","mediaWidth":50,"mediaSizeSlug":"full","mediaPosition":"left"} -->
<div class="wp-block-media-text is-stacked-on-mobile alignwide"><figure class="wp-block-media-text__media"><img src="https://example.test/photo.jpg" alt="A descriptive alt" class="wp-image-502 size-full"/></figure>
<div class="wp-block-media-text__content">
<!-- wp:heading --><h2 class="wp-block-heading">What this section covers</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Say what the photo shows and why it matters to the visitor: what they will experience, who does the work and what to do next. Two to four sentences built from the business's own facts.</p><!-- /wp:paragraph -->
</div>
</div>
<!-- /wp:media-text -->
HTML
			,
			'repeatable'  => array( 'core/paragraph', 'core/buttons', 'core/button' ),
			'constraints' => array(
				'slots'  => array(
					'paragraphs' => array(
						'min' => 1,
						'max' => 3,
					),
					'buttons'    => array(
						'min' => 0,
						'max' => 1,
					),
				),
				'stated' => array(
					'Heading: states the section subject, at most 9 words.',
					'Paragraphs: one to three, at least 30 words in total, concrete.',
					'Button: optional, verb-first label, at most 4 words.',
					'Keep mediaPosition "left" exactly as the sample has it.',
					'Image: a real attachment from read-media/media-search/media-generate; alt text required.',
				),
			),
		);
	}

	/**
	 * feature-grid — group › heading(h2) › columns › column* › (heading(h3) ›
	 * paragraph).
	 *
	 * @return array<string,mixed>
	 */
	private function featureGrid(): array {
		return array(
			'slug'        => 'feature-grid',
			'name'        => 'senroflux/feature-grid',
			'title'       => 'Feature grid',
			'description' => __( 'A two-to-three-column feature grid: an H2 heading and one H3 + paragraph per column.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/feature-grid"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">What you get</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">First feature</h3><!-- /wp:heading --><!-- wp:paragraph --><p>What this feature does for the reader and why it matters, in one or two concrete sentences.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Second feature</h3><!-- /wp:heading --><!-- wp:paragraph --><p>What this feature does for the reader and why it matters, in one or two concrete sentences.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/column' ),
			'constraints' => array(
				'slots'  => array(
					'columns' => array(
						'min' => 2,
						'max' => 3,
					),
				),
				'stated' => array(
					'Heading: the shared benefit of the features, at most 9 words.',
					'Each column title: at most 6 words.',
					'Each column body: 12 to 40 words (one or two sentences).',
				),
			),
		);
	}

	/**
	 * pricing-table — group › heading(h2) › columns › column* › (heading(h3) ›
	 * paragraph › list › buttons › button). `list_items` is counted PER COLUMN,
	 * not across the table.
	 *
	 * @return array<string,mixed>
	 */
	private function pricingTable(): array {
		return array(
			'slug'        => 'pricing-table',
			'name'        => 'senroflux/pricing-table',
			'title'       => 'Pricing table',
			'description' => __( 'A pricing table: an H2 heading and one-to-three plan columns, each with a plan, price, feature list and a call to action.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/pricing-table"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Pricing</h2><!-- /wp:heading -->
<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Starter</h3><!-- /wp:heading --><!-- wp:paragraph --><p>$&mdash;/month (price TBC)</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><li>First thing this plan includes</li><li>Second thing this plan includes</li><li>Third thing this plan includes</li></ul><!-- /wp:list --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Choose plan</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Standard</h3><!-- /wp:heading --><!-- wp:paragraph --><p>$&mdash;/month (price TBC)</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><li>First thing this plan includes</li><li>Second thing this plan includes</li><li>Third thing this plan includes</li></ul><!-- /wp:list --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Choose plan</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:column --></div><!-- /wp:columns -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/column' ),
			'constraints' => array(
				'slots'  => array(
					'columns'    => array(
						'min' => 1,
						'max' => 3,
					),
					'list_items' => array(
						'min' => 3,
						'max' => 6,
					),
				),
				'stated' => array(
					'Heading: the pricing question answered, at most 9 words.',
					'Price: only a price the user gave.',
					'Feature list: 3 to 6 items, each at most 12 words.',
					'Buttons: verb-first labels, at most 4 words each.',
				),
			),
		);
	}

	/**
	 * faq — group › heading(h2) › details* › (summary › paragraph); the
	 * `<summary>` is inner content of the details block, not a block.
	 *
	 * @return array<string,mixed>
	 */
	private function faq(): array {
		return array(
			'slug'        => 'faq',
			'name'        => 'senroflux/faq',
			'title'       => 'FAQ',
			'description' => __( 'An FAQ: an H2 heading and two-to-eight collapsible details blocks.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/faq"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Questions people ask</h2><!-- /wp:heading -->
<!-- wp:details --><details class="wp-block-details"><summary>The first question a reader actually asks</summary><!-- wp:paragraph --><p>A direct answer in one or two short sentences.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
<!-- wp:details --><details class="wp-block-details"><summary>The second question a reader actually asks</summary><!-- wp:paragraph --><p>A direct answer in one or two short sentences.</p><!-- /wp:paragraph --></details><!-- /wp:details -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/details' ),
			'constraints' => array(
				'slots'  => array(
					'details' => array(
						'min' => 2,
						'max' => 8,
					),
				),
				'stated' => array(
					'Question: asks what a reader actually asks, at most 11 words.',
					'Answer: direct, up to 70 words.',
				),
			),
		);
	}

	/**
	 * testimonials — group › heading(h2) › quote* (with cite); the `<cite>` is
	 * inner content of the quote block.
	 *
	 * @return array<string,mixed>
	 */
	private function testimonials(): array {
		return array(
			'slug'        => 'testimonials',
			'name'        => 'senroflux/testimonials',
			'title'       => 'Testimonials',
			'description' => __( 'A social-proof section: an H2 heading and one-to-three quotes with attribution.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/testimonials"},"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">What customers say</h2><!-- /wp:heading -->
<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>A short outcome in the customer&#8217;s own words.</p><!-- /wp:paragraph --><cite>Customer name, role</cite></blockquote><!-- /wp:quote -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/quote' ),
			'constraints' => array(
				'slots'  => array(
					'quotes' => array(
						'min' => 1,
						'max' => 3,
					),
				),
				'stated' => array(
					'Quote: a real-sounding outcome in the customer\'s voice, at most 30 words.',
					'Attribution: name and role, at most 8 words.',
				),
			),
		);
	}

	/**
	 * cta — group[align=full] › heading(h2) › paragraph › buttons › button.
	 *
	 * @return array<string,mixed>
	 */
	private function cta(): array {
		return array(
			'slug'        => 'cta',
			'name'        => 'senroflux/cta',
			'title'       => 'Call to action',
			'description' => __( 'A full-width closing call to action: an H2 heading, one supporting line and one button.', 'senroflux' ),
			'markup'      => <<<'HTML'
<!-- wp:group {"metadata":{"name":"senroflux/cta"},"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)">
<!-- wp:heading {"textAlign":"center"} --><h2 class="wp-block-heading has-text-align-center">Start today</h2><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">One line of supporting benefit before the button.</p><!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Get started</a></div><!-- /wp:button --></div><!-- /wp:buttons -->
</div><!-- /wp:group -->
HTML
			,
			'repeatable'  => array( 'core/button' ),
			'constraints' => array(
				'slots'  => array(
					'buttons' => array(
						'min' => 1,
						'max' => 1,
					),
				),
				'stated' => array(
					'Headline: an imperative that states exactly what the reader should do, at most 9 words.',
					'Body: one line of supporting benefit, up to 30 words.',
					'Button: verb-first label, at most 4 words.',
				),
			),
		);
	}
}
