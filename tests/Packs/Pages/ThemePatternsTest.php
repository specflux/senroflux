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
 * files, not committed here — machine-local wp-env path) found 4 eligible:
 * `banner-intro`, `cta-book-links`, `cta-centered-heading`, `format-link`.
 * The other 6 of the brief's 10-pattern calibration list are cut by real
 * clauses: `binding-format` (no text slot with any shipped words — a
 * block-bindings paragraph with empty literal content), and
 * `cta-book-locations`/`cta-events-list`/`pricing-3-col`/`testimonials-6-col`/
 * `text-faqs` all by DEPTH (6 or 7, over the limit of 5) — Twenty
 * Twenty-Five nests its richer patterns more deeply than the pack's own
 * curated seven. See the stage report for the full breakdown.
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

	public function test_depth_over_five_is_cut(): void {
		// Real: Twenty Twenty-Five's `cta-events-list` nests 7 levels deep.
		$this->registerFixtures( 'cta-events-list' );

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
	}

	public function test_disallowed_block_is_cut(): void {
		// Real: `banner-about-book` uses `core/image`, outside the pack's allow-list.
		$this->registerFixtures( 'banner-about-book' );

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
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

	public function test_decorative_color_is_cut(): void {
		// SYNTHETIC: same reasoning — a real pattern with a decorative colour
		// in this theme also uses a block outside the allow-list.
		$this->registerRaw(
			array(
				'name'     => 'synthetic/decorative-color',
				'title'    => 'Coloured paragraph',
				'content'  => '<!-- wp:paragraph {"backgroundColor":"accent-1"} --><p class="has-accent-1-background-color has-background">Some real sample copy here.</p><!-- /wp:paragraph -->',
				'filePath' => $GLOBALS['senroflux_test_stylesheet_dir'] . '/synthetic.php',
			)
		);

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( 1, ThemePatterns::skippedCount() );
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
}
