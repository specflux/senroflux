<?php
/**
 * ThemePatterns tests (0.3 S21).
 *
 * TARGET REPO PATH: tests/Packs/Pages/ThemePatternsTest.php
 *
 * Fixtures under `tests/ThemePatterns/` are REAL Twenty Twenty-Five pattern
 * files (GPL, same licence as this plugin; original header comments kept
 * intact for provenance) rendered exactly as `WP_Theme::get_block_patterns()`
 * would (their `<?php ... ?>` interpolation executed via `include` +
 * output buffering), so `parse_blocks()` sees exactly what a real WordPress
 * install would register. Two entries below (marked SYNTHETIC) are
 * hand-written: no real Twenty Twenty-Five pattern isolates the
 * `postTypes`/`blockTypes` clause or the decorative-colour clause on its own
 * (every real file that carries a `postTypes` restriction already includes
 * `page`, and every real file with a decorative colour also uses a block
 * outside the pack's allow-list, so it would already fail a different clause
 * first).
 *
 * A one-off scan of the FULL Twenty Twenty-Five `patterns/` directory (98
 * files, not committed here — machine-local wp-env path) finds 9 eligible:
 * `banner-intro`, `cta-book-links`, `cta-book-locations`,
 * `cta-centered-heading`, `cta-events-list`, `format-link`, `pricing-3-col`,
 * `testimonials-6-col`, `text-faqs`.
 *
 * Five of those nine were rejected while the depth cap stood at S21's
 * original 5 — see {@see \Specflux\SenroFlux\Packs\Pages\ThemePatterns}'s
 * `MAX_DEPTH` for why the bound moved to 7. `binding-format` is still cut,
 * on a different clause: no text slot with any shipped words, being a
 * block-bindings paragraph with empty literal content.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;

final class ThemePatternsTest extends TestCase {

	/**
	 * The REAL Twenty Twenty-Five fixture files call a handful of WordPress
	 * i18n/escaping functions while rendering; {@see \tests\stubs\theme-patterns.php}
	 * (loaded globally, unlike this namespaced test class) declares them.
	 */
	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		ThemePatterns::resetCache();
		$GLOBALS['senroflux_test_theme_patterns'] = array();
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/ThemePatterns';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/ThemePatterns';
	}

	protected function tearDown(): void {
		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'] );
	}

	// --- fixture loading -----------------------------------------------

	/**
	 * Render one real fixture file exactly as `WP_Theme::get_block_patterns()`
	 * would: header comment parsed, body executed (output-buffered).
	 *
	 * @return array<string,mixed>
	 */
	private function loadFixture( string $slug ): array {
		$path = dirname( __DIR__, 2 ) . '/ThemePatterns/' . $slug . '.php';
		$raw  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.

		$header = array();
		foreach ( array( 'Title', 'Slug', 'Description', 'Categories', 'Inserter', 'Template Types', 'Post Types', 'Block Types' ) as $key ) {
			if ( preg_match( '/^\s*\*\s*' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $raw, $m ) ) {
				$header[ $key ] = trim( $m[1] );
			}
		}

		ob_start();
		include $path;
		$content = trim( (string) ob_get_clean() );

		$entry = array(
			'name'        => $header['Slug'] ?? $slug,
			'title'       => $header['Title'] ?? $slug,
			'description' => $header['Description'] ?? '',
			'content'     => $content,
			'filePath'    => $path,
		);

		if ( isset( $header['Categories'] ) ) {
			$entry['categories'] = array_map( 'trim', explode( ',', $header['Categories'] ) );
		}
		if ( isset( $header['Inserter'] ) ) {
			$entry['inserter'] = ! in_array( strtolower( $header['Inserter'] ), array( 'no', 'false' ), true );
		}
		if ( isset( $header['Template Types'] ) ) {
			$entry['templateTypes'] = array_map( 'trim', explode( ',', $header['Template Types'] ) );
		}
		if ( isset( $header['Post Types'] ) ) {
			$entry['postTypes'] = array_map( 'trim', explode( ',', $header['Post Types'] ) );
		}
		if ( isset( $header['Block Types'] ) ) {
			$entry['blockTypes'] = array_map( 'trim', explode( ',', $header['Block Types'] ) );
		}

		return $entry;
	}

	private function registerFixtures( string ...$slugs ): void {
		$GLOBALS['senroflux_test_theme_patterns'] = array_map( fn ( string $slug ) => $this->loadFixture( $slug ), $slugs );
		ThemePatterns::resetCache();
	}

	/** @param array<string,mixed> $entry */
	private function registerRaw( array $entry ): void {
		$GLOBALS['senroflux_test_theme_patterns'] = array( $entry );
		ThemePatterns::resetCache();
	}

	private function names( array $eligible ): array {
		return array_map( static fn ( array $p ): string => $p['name'], $eligible );
	}

	// --- eligibility, per rule (real fixtures) --------------------------

	public function test_eligible_admits_a_real_hero_pattern(): void {
		$this->registerFixtures( 'banner-intro' );

		$eligible = ThemePatterns::eligible();

		$this->assertCount( 1, $eligible );
		$this->assertSame( 'twentytwentyfive/banner-intro', $eligible[0]['name'] );
		$this->assertTrue( $eligible[0]['is_hero'] );
		$this->assertFalse( $eligible[0]['is_cta'] );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_eligible_admits_real_call_to_action_patterns(): void {
		$this->registerFixtures( 'cta-book-links', 'cta-centered-heading', 'format-link' );

		$names = $this->names( ThemePatterns::eligible() );

		$this->assertContains( 'twentytwentyfive/cta-book-links', $names );
		$this->assertContains( 'twentytwentyfive/cta-centered-heading', $names );
		$this->assertContains( 'twentytwentyfive/format-link', $names );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_inserter_false_is_cut(): void {
		$this->registerFixtures( 'hidden-blog-heading' );

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	public function test_template_types_is_cut(): void {
		$this->registerFixtures( 'template-page-vertical-header-blog' );

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	public function test_depth_over_seven_is_cut(): void {
		// SYNTHETIC: no real Twenty Twenty-Five pattern nests past 7 while
		// staying inside the block allow-list, so the clause can only be
		// isolated with a hand-built tree — seven groups around a paragraph.
		$open  = str_repeat( '<!-- wp:group -->', 7 );
		$close = str_repeat( '<!-- /wp:group -->', 7 );

		$this->registerRaw(
			array(
				'name'     => 'synthetic/too-deep',
				'title'    => 'Nested past the cap',
				'content'  => $open . '<!-- wp:paragraph --><p>Some real sample copy here.</p><!-- /wp:paragraph -->' . $close,
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	public function test_depth_of_exactly_seven_is_admitted(): void {
		// Real boundary: `cta-events-list` nests exactly 7 levels. It was cut
		// by S21's original cap of 5 and is admitted by the raised bound, so
		// this pins the edge in both directions.
		$this->registerFixtures( 'cta-events-list' );

		$this->assertSame( array( 'twentytwentyfive/cta-events-list' ), $this->names( ThemePatterns::eligible() ) );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_disallowed_block_is_cut(): void {
		// SYNTHETIC (0.3 quality feature 4 changed this clause's only real
		// example): `core/image` moved from outside the allow-list to inside
		// it — see `test_image_pattern_is_eligible_and_its_image_becomes_a_slot()`
		// below, using the same real `banner-about-book` fixture this test
		// used to cover. `core/video` is still outside it.
		$this->registerRaw(
			array(
				'name'     => 'synthetic/disallowed-block',
				'title'    => 'A video pattern',
				'content'  => '<!-- wp:paragraph --><p>Some real sample copy here.</p><!-- /wp:paragraph --><!-- wp:video --><figure class="wp-block-video"></figure><!-- /wp:video -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * 0.3 quality feature 4: `core/image` joined the allow-list so a theme
	 * pattern's own image can be filled with an attachment a run found or
	 * generated. `banner-about-book` is the real Twenty Twenty-Five fixture
	 * this uncovers — it was cut entirely before this feature.
	 */
	public function test_image_pattern_is_eligible_and_its_image_becomes_a_slot(): void {
		$this->registerFixtures( 'banner-about-book' );

		$eligible = ThemePatterns::eligible();
		$this->assertSame( array( 'twentytwentyfive/banner-about-book' ), $this->names( $eligible ) );
		$this->assertSame( 0, ThemePatterns::skippedCount() );

		$slots = $eligible[0]['text_slots'];
		$image = end( $slots );
		$this->assertSame( 'image', $image['kind'] );
		$this->assertSame( 'img', $image['tag'] );
		$this->assertNotSame( '', $image['shipped_src'] );
	}

	public function test_no_meaningful_text_slot_is_cut(): void {
		// Real: `binding-format` is a single block-bindings paragraph with no
		// literal content — a text ELEMENT exists, but it ships zero words.
		$this->registerFixtures( 'binding-format' );

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	public function test_post_types_excluding_page_is_cut(): void {
		// SYNTHETIC: no real Twenty Twenty-Five pattern restricts `postTypes`
		// without including `page`.
		$this->registerRaw(
			array(
				'name'      => 'synthetic/post-types-not-page',
				'title'     => 'Not a page pattern',
				'content'   => '<!-- wp:paragraph --><p>Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath'  => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
				'postTypes' => array( 'wp_template' ),
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	public function test_block_types_excluding_page_is_cut(): void {
		// SYNTHETIC: same reasoning as the postTypes case, for `blockTypes`.
		$this->registerRaw(
			array(
				'name'       => 'synthetic/block-types-not-page',
				'title'      => 'Not a page pattern',
				'content'    => '<!-- wp:paragraph --><p>Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath'   => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
				'blockTypes' => array( 'core/query' ),
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * D3a (S4): a theme pattern's own preset colour no longer cuts it. BEFORE
	 * this stage's change, `hasDecorativeColor()` cut ANY `backgroundColor`
	 * unconditionally, so this exact fixture was refused — this test failed
	 * (`assertCount(1, ...)` saw an empty array) until `hasIneligibleColor()`
	 * started checking the value against the active palette instead of just
	 * its presence. `accent-1` is in the default test palette (Twenty
	 * Twenty-Five's own, `tests/stubs/theme-patterns.php`).
	 */
	public function test_preset_color_is_eligible_under_d3a(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/preset-color',
				'title'    => 'Coloured paragraph',
				'content'  => '<!-- wp:paragraph {"backgroundColor":"accent-1"} --><p class="has-accent-1-background-color has-background">Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$eligible = ThemePatterns::eligible();

		$this->assertCount( 1, $eligible );
		$this->assertSame( 'synthetic/preset-color', $eligible[0]['name'] );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	/**
	 * D3a admits a preset colour, never a raw one. This is the fixture the
	 * old, unconditional `test_decorative_color_is_cut` used to cover in
	 * spirit; it is renamed and now genuinely raw (`style.color`, a literal
	 * hex value) so it keeps failing on colour specifically, not merely on
	 * "carries a colour attribute at all".
	 */
	public function test_raw_color_is_cut(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/raw-color',
				'title'    => 'Coloured paragraph',
				'content'  => '<!-- wp:paragraph {"style":{"color":{"background":"#5140a5"}}} --><p class="has-background" style="background-color:#5140a5">Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * A colour slug the active theme does not define is still ineligible —
	 * D3a admits preset slugs IN THE PALETTE, not every preset slug that
	 * merely looks like one.
	 */
	public function test_color_slug_not_in_palette_is_cut(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/unknown-slug-color',
				'title'    => 'Coloured paragraph',
				'content'  => '<!-- wp:paragraph {"backgroundColor":"not-a-real-slug"} --><p class="has-not-a-real-slug-background-color has-background">Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * Same rule for `gradient`, against the gradients list rather than the
	 * palette; the default test settings ship no gradients at all.
	 */
	public function test_gradient_not_in_palette_is_cut(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/unknown-gradient',
				'title'    => 'Gradient group',
				'content'  => '<!-- wp:group {"gradient":"not-a-real-gradient"} --><div class="wp-block-group has-not-a-real-gradient-gradient-background has-background"><!-- wp:paragraph --><p>Some real sample copy here.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * D3a's eligibility check reads the shipped HTML classes too, not only
	 * the comment attributes — a hand-authored `has-*-background-color`
	 * class naming a slug outside the palette cuts the pattern even with no
	 * matching `backgroundColor` attribute at all.
	 */
	public function test_ineligible_color_class_with_no_attribute_is_cut(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/class-only-color',
				'title'    => 'Coloured paragraph',
				'content'  => '<!-- wp:paragraph --><p class="has-not-a-real-slug-background-color has-background">Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * A real Ollie pattern (`card-lead-magnet`, scan-confirmed) hard-codes
	 * its cover's overlay via `customOverlayColor` rather than a preset
	 * `overlayColor` slug — core only ever writes that attribute with a
	 * literal hex value, so it is raw colour and must stay ineligible even
	 * though it names no palette slug at all to check.
	 */
	public function test_custom_color_attribute_is_always_raw_and_cut(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/custom-overlay-color',
				'title'    => 'Cover with a hard-coded overlay',
				'content'  => '<!-- wp:cover {"dimRatio":0,"customOverlayColor":"#b8b4b6","isUserOverlayColor":true} --><div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Some real sample copy here.</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	/**
	 * Core's own generic colour classes (no slug at all) never gate — they
	 * are not `has-<slug>-color`, they ARE `has-text-color` etc. literally.
	 */
	public function test_generic_core_color_class_does_not_gate(): void {
		$this->registerRaw(
			array(
				'name'     => 'synthetic/generic-color-class',
				'title'    => 'Linked paragraph',
				'content'  => '<!-- wp:paragraph --><p class="has-text-color has-link-color">Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$eligible = ThemePatterns::eligible();

		$this->assertCount( 1, $eligible );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_remote_and_wp_block_patterns_are_excluded_before_the_filter_even_runs(): void {
		$fixture          = $this->loadFixture( 'banner-intro' );
		$remote           = $fixture;
		$remote['source'] = 'core';
		$saved            = $fixture;
		$saved['name']    = 'wp_block/123';

		$GLOBALS['senroflux_test_theme_patterns'] = array( $remote, $saved );
		ThemePatterns::resetCache();

		$this->assertSame( array(), ThemePatterns::eligible() );
		// Neither counts against `theme_patterns_skipped` — they were never
		// this theme's OWN patterns to begin with.
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_a_pattern_outside_the_theme_directory_is_ignored(): void {
		$fixture             = $this->loadFixture( 'banner-intro' );
		$fixture['filePath'] = '/some/other/theme/patterns/banner-intro.php';

		$GLOBALS['senroflux_test_theme_patterns'] = array( $fixture );
		ThemePatterns::resetCache();

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_cache_is_resettable(): void {
		$this->registerFixtures( 'banner-intro' );
		$this->assertCount( 1, ThemePatterns::eligible() );

		// Mutate the source WITHOUT resetting: the memo must still answer 1.
		$GLOBALS['senroflux_test_theme_patterns'] = array();
		$this->assertCount( 1, ThemePatterns::eligible(), 'the memo must not re-read until reset' );

		ThemePatterns::resetCache();
		$this->assertSame( array(), ThemePatterns::eligible(), 'a reset memo re-reads the registry' );
	}

	// --- text slot derivation, on real fixtures -------------------------

	public function test_text_slots_on_banner_intro(): void {
		$fixture = $this->loadFixture( 'banner-intro' );
		$slots   = ThemePatterns::textSlots( $fixture['content'] );

		$this->assertCount( 1, $slots );
		$this->assertSame( 'text', $slots[0]['kind'] );
		$this->assertSame( 'h2', $slots[0]['tag'] );
		$this->assertGreaterThan( 0, $slots[0]['shipped_words'] );
		$this->assertSame( max( 3, (int) ceil( 1.5 * $slots[0]['shipped_words'] ) ), $slots[0]['max_words'] );
	}

	public function test_text_slots_on_cta_includes_a_url_slot(): void {
		$fixture = $this->loadFixture( 'cta-centered-heading' );
		$slots   = ThemePatterns::textSlots( $fixture['content'] );

		$kinds = array_map( static fn ( array $s ): string => $s['kind'], $slots );
		$this->assertContains( 'url', $kinds );
		$this->assertContains( 'text', $kinds );

		// Document order: the button's own text slot precedes its href slot
		// (the shipped button carries no href attribute at all — the model
		// must supply one).
		$url_index = array_search( 'url', $kinds, true );
		$this->assertSame( 'text', $slots[ $url_index - 1 ]['kind'] );
		$this->assertSame( 'a', $slots[ $url_index ]['tag'] );
	}

	public function test_text_slots_are_numbered_in_document_order(): void {
		$fixture = $this->loadFixture( 'cta-centered-heading' );
		$slots   = ThemePatterns::textSlots( $fixture['content'] );

		foreach ( $slots as $i => $slot ) {
			$this->assertSame( $i, $slot['index'] );
		}
		// heading, paragraph, the button's own text, then its href.
		$this->assertSame( array( 'h2', 'p', 'a', 'a' ), array_column( $slots, 'tag' ) );
		$this->assertSame( array( 'text', 'text', 'text', 'url' ), array_column( $slots, 'kind' ) );
	}

	/**
	 * LIMIT (documented in {@see \Specflux\SenroFlux\Packs\Pages\ThemePatterns}):
	 * `format-link`'s second paragraph wraps its ENTIRE anchor
	 * (`<p><a href="#">…</a></p>`) — a rich-text element nested inside
	 * another. The outer `<p>` capture swallows the anchor as flat text, so
	 * this pattern derives no `url` slot for that link at all; its shipped
	 * `href="#"` can never be changed by a `sections` write. This is a real
	 * residual gap (flagged in the stage report), not a passing assertion —
	 * this test pins the CURRENT behaviour so a future fix is a deliberate
	 * change, not a silent regression.
	 */
	public function test_text_slots_on_format_link_do_not_split_a_nested_anchor(): void {
		$fixture = $this->loadFixture( 'format-link' );
		$slots   = ThemePatterns::textSlots( $fixture['content'] );

		$kinds = array_map( static fn ( array $s ): string => $s['kind'], $slots );
		$this->assertNotContains( 'url', $kinds, 'documents the nested-anchor limitation' );
		$this->assertSame( array( 'p', 'p' ), array_column( $slots, 'tag' ) );
	}

	// --- fill() refusals -------------------------------------------------

	public function test_fill_refuses_a_missing_slot(): void {
		$fixture = $this->loadFixture( 'banner-intro' );

		$result = ThemePatterns::fill( $fixture['content'], array( '' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'slot_missing', $result['wp_error']->get_error_code() );
	}

	public function test_fill_refuses_the_shipped_sample_text(): void {
		$fixture = $this->loadFixture( 'banner-intro' );
		$shipped = ThemePatterns::textSlots( $fixture['content'] )[0]['shipped_text'];

		// Case- and whitespace-insensitive.
		$result = ThemePatterns::fill( $fixture['content'], array( '  ' . strtoupper( $shipped ) . '  ' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'slot_sample_text', $result['wp_error']->get_error_code() );
	}

	public function test_fill_refuses_text_over_the_word_cap(): void {
		$fixture = $this->loadFixture( 'banner-intro' );
		$slot    = ThemePatterns::textSlots( $fixture['content'] )[0];

		$too_long = implode( ' ', array_fill( 0, $slot['max_words'] + 1, 'word' ) );
		$result   = ThemePatterns::fill( $fixture['content'], array( $too_long ) );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'slot_too_long', $result['wp_error']->get_error_code() );
	}

	/**
	 * Every text slot on `cta-centered-heading` gets a short, safely-under-cap
	 * replacement ("New copy" — the tightest cap, the button's own text, is
	 * `max(3, ceil(1.5*2))` = 3 words), so a test that targets only the `url`
	 * slot never trips `slot_too_long` on an untargeted text slot.
	 *
	 * @return array{0: array<string,mixed>, 1: list<string>}
	 */
	private function ctaValuesWithUrl( string $url_value ): array {
		$fixture = $this->loadFixture( 'cta-centered-heading' );
		$slots   = ThemePatterns::textSlots( $fixture['content'] );

		$values = array();
		foreach ( $slots as $slot ) {
			$values[ $slot['index'] ] = 'text' === $slot['kind'] ? 'New copy' : $url_value;
		}

		return array( $fixture, $values );
	}

	public function test_fill_refuses_an_unsafe_url(): void {
		[ $fixture, $values ] = $this->ctaValuesWithUrl( 'javascript:alert(1)' );

		$result = ThemePatterns::fill( $fixture['content'], $values );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsafe_url', $result['wp_error']->get_error_code() );
	}

	public function test_fill_refuses_a_hash_or_empty_href_as_unsafe(): void {
		[ $fixture, $values ] = $this->ctaValuesWithUrl( '#' );

		$result = ThemePatterns::fill( $fixture['content'], $values );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsafe_url', $result['wp_error']->get_error_code() );
	}

	public function test_fill_succeeds_and_produces_matching_shape(): void {
		[ $fixture, $values ] = $this->ctaValuesWithUrl( 'https://example.com/real-destination' );

		$result = ThemePatterns::fill( $fixture['content'], $values );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'https://example.com/real-destination', $result['content'] );
		$this->assertStringContainsString( 'New copy', $result['content'] );

		// The filled markup keeps the same parsed shape as the shipped one —
		// this is what lets the pack's own Validator match it structurally.
		$shipped_blocks = parse_blocks( $fixture['content'] );
		$filled_blocks  = parse_blocks( $result['content'] );
		$this->assertSame( count( $shipped_blocks ), count( $filled_blocks ) );
	}

	// --- fill() image slots (0.3 quality feature 4) -----------------------

	/**
	 * @return array{0: array<string,mixed>, 1: list<string>}
	 */
	private function bookValues( string $image_value ): array {
		$fixture = $this->loadFixture( 'banner-about-book' );
		$slots   = ThemePatterns::textSlots( $fixture['content'] );

		$values = array();
		foreach ( $slots as $slot ) {
			$values[ $slot['index'] ] = 'image' === $slot['kind'] ? $image_value : 'New short heading';
		}
		// The paragraph slot's own word cap is generous (150); a short
		// replacement never trips it.

		return array( $fixture, $values );
	}

	public function test_fill_refuses_an_image_slot_with_no_alt(): void {
		[ $fixture, $values ] = $this->bookValues( 'https://example.test/new.jpg||' );

		$result = ThemePatterns::fill( $fixture['content'], $values );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'slot_missing', $result['wp_error']->get_error_code() );
	}

	public function test_fill_refuses_an_image_slot_with_an_unsafe_url(): void {
		[ $fixture, $values ] = $this->bookValues( 'javascript:alert(1)||A new cover' );

		$result = ThemePatterns::fill( $fixture['content'], $values );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unsafe_url', $result['wp_error']->get_error_code() );
	}

	public function test_fill_succeeds_for_an_image_slot_and_replaces_src_and_alt(): void {
		[ $fixture, $values ] = $this->bookValues( 'https://example.test/wp-content/uploads/new.jpg||A new book cover' );

		$result = ThemePatterns::fill( $fixture['content'], $values );

		$this->assertTrue( $result['ok'] );
		$this->assertStringContainsString( 'src="https://example.test/wp-content/uploads/new.jpg"', $result['content'] );
		$this->assertStringContainsString( 'alt="A new book cover"', $result['content'] );
		$this->assertStringNotContainsString( 'book-image-landing.webp', $result['content'] );

		// The filled markup keeps the same parsed shape as the shipped one —
		// this is what lets the pack's own Validator (with BlockShells'
		// core/image `id`-attribute exception) match it structurally.
		$shipped_blocks = parse_blocks( $fixture['content'] );
		$filled_blocks  = parse_blocks( $result['content'] );
		$this->assertSame( count( $shipped_blocks ), count( $filled_blocks ) );
	}

	/**
	 * 0.3 quality fix (theme patterns first). `pricing-3-col` is a REAL TT25
	 * pattern tagged `call-to-action, banner, services` all at once — a
	 * multi-purpose content pattern, not a page's hero or its one dedicated
	 * call-to-action section. Before this fix, `is_hero`/`is_cta` were true
	 * whenever the category list merely CONTAINED `banner`/`call-to-action`,
	 * so a page using `pricing-3-col` alongside a real, single-purpose CTA
	 * pattern (for example `cta-centered-heading`) was refused `max_cta`
	 * even though it has only one actual call-to-action section. Requiring
	 * an exact one-category match fixes that false refusal.
	 */
	public function test_a_pattern_with_multiple_categories_is_neither_hero_nor_cta(): void {
		$this->registerFixtures( 'pricing-3-col' );

		$eligible = ThemePatterns::eligible();

		$this->assertCount( 1, $eligible );
		$this->assertFalse( $eligible[0]['is_hero'], 'a multi-category pattern must not count as a dedicated hero' );
		$this->assertFalse( $eligible[0]['is_cta'], 'a multi-category pattern must not count toward the one-cta cap' );
	}
}
