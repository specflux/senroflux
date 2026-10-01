<?php
/**
 * Dynamic slot filling and automatic mapping (D1 amendment, S5b): the three
 * bounded adaptations (drop, clone or trim, insert the heading) on real Ollie
 * and Spectra One pattern files, the safety boundary around them (the
 * Validator recognises a pattern with those three changes and nothing else),
 * the Ollie profile's five layouts and a theme with no profile.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use WP_Error;

final class ThemeAdaptationTest extends TestCase {

	/** Ollie's palette slugs (`theme.json`). */
	private const OLLIE_PALETTE = array( 'primary', 'primary-accent', 'primary-alt', 'primary-alt-accent', 'main', 'main-accent', 'base', 'secondary', 'tertiary', 'border-light', 'border-dark' );

	/** Spectra One's palette slugs plus the core defaults it leaves on. */
	private const SPECTRA_PALETTE = array( 'primary', 'secondary', 'heading', 'body', 'background', 'tertiary', 'quaternary', 'surface', 'foreground', 'outline', 'neutral', 'transparent', 'white', 'black' );

	private const OLLIE_FILES = array( 'hero-light', 'text-call-to-action-buttons', 'features-with-emojis', 'faq', 'image-and-numbered-features', 'numbers-stacked' );

	private const SPECTRA_FILES = array( 'hero-banner-2', 'split-image-right', 'faq-2', 'call-to-action-2', 'feature-6', 'text' );

	protected function setUp(): void {
		require_once __DIR__ . '/LayoutsTest.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';
	}

	protected function tearDown(): void {
		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'], $GLOBALS['senroflux_test_stylesheet'], $GLOBALS['senroflux_test_template'], $GLOBALS['senroflux_test_global_settings'], $GLOBALS['senroflux_test_options'] );
		remove_all_filters( 'senroflux_layout_profiles' );
		remove_all_filters( 'senroflux_theme_patterns' );
	}

	/**
	 * Register real pattern files from tests/ThemePatterns/<theme> as the
	 * active theme's patterns, the way `WP_Theme::get_block_patterns()` does.
	 *
	 * @param list<string> $files   File names without `.php`.
	 * @param list<string> $palette Palette slugs the theme defines.
	 */
	private static function register( string $theme, array $files, array $palette ): void {
		$dir      = dirname( __DIR__, 2 ) . '/ThemePatterns/' . $theme;
		$patterns = array();
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file . '.php';
			$raw  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.
			preg_match( '/^\s*\*\s*Slug:\s*(.+)$/mi', $raw, $name );
			preg_match( '/^\s*\*\s*Categories:\s*(.+)$/mi', $raw, $categories );
			ob_start();
			include $path;
			$content = trim( (string) ob_get_clean() );

			$patterns[] = array(
				'name'       => trim( $name[1] ),
				'title'      => $file,
				'content'    => $content,
				'filePath'   => $path,
				'categories' => array_map( 'trim', explode( ',', $categories[1] ) ),
			);
		}

		$GLOBALS['senroflux_test_theme_patterns']  = $patterns;
		$GLOBALS['senroflux_test_stylesheet_dir']  = $dir;
		$GLOBALS['senroflux_test_template_dir']    = $dir;
		$GLOBALS['senroflux_test_stylesheet']      = $theme;
		$GLOBALS['senroflux_test_global_settings'] = array(
			'color' => array(
				'palette'   => array_map( static fn ( string $slug ): array => array( 'slug' => $slug ), $palette ),
				'gradients' => array(),
			),
		);
		ThemePatterns::resetCache();
	}

	private static function ollie( ?array $files = null ): void {
		self::register( 'ollie', $files ?? self::OLLIE_FILES, self::OLLIE_PALETTE );
	}

	private static function spectra( ?array $files = null ): void {
		self::register( 'spectra-one', $files ?? self::SPECTRA_FILES, self::SPECTRA_PALETTE );
	}

	/**
	 * One section of the outline, with the photos taken off the services
	 * cards when `$photos` is false (a text-only card pattern has no slot for
	 * them).
	 *
	 * @return array<string,mixed>
	 */
	private static function section( int $which, bool $photos = true ): array {
		$section = LayoutsTest::outline()[ $which ];
		if ( ! $photos && isset( $section['items'] ) ) {
			foreach ( array_keys( $section['items'] ) as $key ) {
				unset( $section['items'][ $key ]['image'] );
			}
		}

		return $section;
	}

	/**
	 * @param array<string,mixed> $section One item.
	 */
	private static function render( array $section, ?Vocabulary $vocabulary = null ): string {
		$built = Layouts::render( $section, 0, $vocabulary ?? new Vocabulary() );
		self::assertIsString( $built, $built instanceof WP_Error ? $built->get_error_code() . ': ' . $built->get_error_message() : '' );

		return (string) $built;
	}

	private static function patternName( string $markup ): string {
		return (string) ( parse_blocks( $markup )[0]['attrs']['metadata']['name'] ?? '' );
	}

	/**
	 * One section wrapped into a valid page (a hero first, two to eight
	 * sections), so a refusal is the section's own and never the page's shape.
	 * `$hero` says the section is itself the hero.
	 */
	private static function page( string $section, bool $hero ): string {
		$curated = array();
		foreach ( ( new Vocabulary() )->curated() as $pattern ) {
			$curated[ (string) $pattern['slug'] ] = (string) $pattern['markup'];
		}

		return $hero
			? $section . "\n\n" . $curated['text-section']
			: $curated['hero'] . "\n\n" . $section;
	}

	private function assertClean( string $section, bool $hero = false ): void {
		$clean = ( new Validator( new Vocabulary() ) )->clean( self::page( $section, $hero ) );
		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_code() . ': ' . $clean['wp_error']->get_error_message() : '' );
	}

	private function assertPageClean( string $page ): void {
		$clean = ( new Validator( new Vocabulary() ) )->clean( $page );
		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_code() . ': ' . $clean['wp_error']->get_error_message() : '' );
	}

	/**
	 * The section is refused as a pattern the validator does not know, or for a
	 * colour it does not ship (never for the page's shape).
	 */
	private function assertRefused( string $section, string $why, bool $hero = false ): void {
		$clean = ( new Validator( new Vocabulary() ) )->clean( self::page( $section, $hero ) );
		$this->assertFalse( $clean['ok'], $why );
		$this->assertContains( $clean['wp_error']?->get_error_code(), array( 'unknown_pattern', 'decorative_color' ), $why );
	}

	// --- Drop ------------------------------------------------------------

	public function test_drop_takes_the_block_of_a_slot_the_fields_leave_unfilled(): void {
		self::ollie();
		$built = self::render( self::section( 4 ) );

		$this->assertSame( 'senroflux/ollie/faq', self::patternName( $built ) );
		// The pattern's trailing "Still have questions?" bar (an image, two paragraphs and a button) is gone whole.
		$this->assertStringNotContainsString( 'Still have questions', $built );
		$this->assertStringNotContainsString( 'Contact Us', $built );
		$this->assertStringNotContainsString( '<img', $built );
		// And so is the emptied wrapper it sat in: no `core/buttons` left without a button.
		$this->assertStringNotContainsString( 'wp-block-buttons', $built );
		$this->assertStringNotContainsString( 'Got a question', $built );
		$this->assertStringContainsString( 'Do I need a referral?', $built );
		$this->assertClean( $built );
	}

	public function test_drop_takes_the_emoji_paragraph_of_every_card(): void {
		self::ollie();
		$built = self::render( self::section( 2, false ) );

		$this->assertSame( 'senroflux/ollie/features-with-emojis', self::patternName( $built ) );
		foreach ( array( '😍', '😎', '🤓', '🤣', 'Ollie Patterns', 'Ollie Templates' ) as $sample ) {
			$this->assertStringNotContainsString( $sample, $built );
		}
		$this->assertStringContainsString( 'Sports injuries', $built );
		$this->assertClean( $built );
	}

	public function test_drop_leaves_no_sample_text_when_a_hero_has_no_eyebrow_or_second_button(): void {
		self::ollie();
		$section = self::section( 0 );

		$built = self::render( $section );

		$this->assertSame( 'senroflux/ollie/hero-light', self::patternName( $built ) );
		$this->assertStringNotContainsString( 'WordPress Reimagined', $built );
		$this->assertStringNotContainsString( 'Ollie Features', $built );
		$this->assertSame( 1, substr_count( $built, 'wp-block-button__link' ) );
		$this->assertClean( $built, true );
	}

	public function test_drop_takes_the_image_when_the_cta_has_none_to_give(): void {
		self::spectra( array( 'hero-banner-2', 'call-to-action-4' ) );
		$built = self::render( self::section( 5 ) );

		$this->assertSame( 'senroflux/spectra-one/call-to-action-4', self::patternName( $built ) );
		$this->assertStringNotContainsString( '<img', $built );
		$this->assertStringNotContainsString( 'Everything you need', $built );
		$this->assertClean( $built );
	}

	// --- Clone and trim --------------------------------------------------

	public function test_trim_cuts_a_repeated_group_to_the_item_count(): void {
		self::ollie();
		$section = self::section( 2, false );

		$built = self::render( $section );

		// features-with-emojis ships four cards, the section has three.
		$this->assertSame( 3, substr_count( $built, '<h3' ) );
		$this->assertStringNotContainsString( 'Ollie Block Theme', $built );
		$this->assertClean( $built );

		$section['items'] = array_slice( $section['items'], 0, 2 );
		$this->assertSame( 2, substr_count( self::render( $section ), '<h3' ) );
	}

	public function test_clone_grows_a_repeated_group_to_the_item_count(): void {
		self::ollie();
		$section = self::section( 4 );
		$items   = $section['items'];
		for ( $i = 0; $i < 3; $i++ ) {
			$section['items'][] = array(
				'question' => 'Another question number ' . $i . '?',
				'answer'   => 'Another answer number ' . $i . '.',
			);
		}
		$this->assertCount( 7, $section['items'] );

		$built = self::render( $section );

		// ollie/faq ships four question and answer groups across two columns.
		$this->assertSame( 7, substr_count( $built, '<h3' ) );
		$this->assertStringContainsString( 'Another question number 2?', $built );
		$this->assertStringContainsString( $items[0]['question'], $built );
		$this->assertClean( $built );
	}

	public function test_trim_removes_the_column_it_empties(): void {
		self::spectra();
		$section          = self::section( 4 );
		$section['items'] = array_slice( $section['items'], 0, 3 );

		$built = self::render( $section );

		$this->assertSame( 'senroflux/spectra-one/faq-2', self::patternName( $built ) );
		$this->assertSame( 3, substr_count( $built, '<h5' ) );
		$this->assertSame( 3, substr_count( $built, 'class="wp-block-column"' ) );
		$this->assertClean( $built );
	}

	public function test_an_item_count_outside_the_layouts_range_is_refused_by_name(): void {
		self::ollie();
		$section          = self::section( 4 );
		$section['items'] = array_slice( $section['items'], 0, 1 );

		$built = Layouts::render( $section, 0, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_field', $built->get_error_code() );
		$this->assertStringContainsString( 'items', $built->get_error_message() );
	}

	// --- Insert the heading ----------------------------------------------

	public function test_insert_puts_the_layouts_heading_first_when_the_pattern_has_none(): void {
		self::ollie();
		$built = self::render( self::section( 2, false ) );

		$top = parse_blocks( $built )[0];
		$this->assertSame( 'senroflux/ollie/features-with-emojis', $top['attrs']['metadata']['name'] ?? '' );
		$first = $top['innerBlocks'][0];
		$this->assertSame( 'core/heading', $first['blockName'] );
		$this->assertStringContainsString( 'How we help', $first['innerHTML'] );
		$this->assertSame( 1, substr_count( $built, '<h2' ) );
	}

	public function test_insert_is_not_made_when_the_pattern_has_its_own_heading(): void {
		self::ollie();
		$built = self::render( self::section( 4 ) );

		$this->assertSame( 1, substr_count( $built, '<h2' ) );
		$this->assertStringContainsString( '>Questions before booking</h2>', $built );
	}

	// --- The Ollie profile covers all five layouts -----------------------

	public function test_the_ollie_profile_builds_every_layout_from_an_ollie_pattern(): void {
		self::ollie();
		$vocabulary = new Vocabulary();
		$names      = array();
		$parts      = array();
		foreach ( array( 0, 3, 2, 4, 5 ) as $which ) {
			$built   = self::render( self::section( $which ), $vocabulary );
			$names[] = self::patternName( $built );
			$parts[] = $built;
		}

		$this->assertSame(
			array(
				'senroflux/ollie/hero-light',
				'senroflux/ollie/image-and-numbered-features',
				'senroflux/ollie/features-with-emojis',
				'senroflux/ollie/faq',
				'senroflux/ollie/text-call-to-action-buttons',
			),
			$names
		);
		$page = implode( "\n\n", $parts );
		foreach ( array( 'WordPress Reimagined', 'Ollie', 'Download', 'Get Started', 'Frequently Asked' ) as $sample ) {
			$this->assertStringNotContainsString( $sample, $page );
		}
		$this->assertPageClean( $page );
	}

	public function test_every_ollie_layouts_body_copy_lands_in_a_plain_paragraph(): void {
		self::ollie();
		foreach ( array( 0, 3, 2, 4, 5 ) as $which ) {
			$section = self::section( $which );
			$body    = (string) ( $section['text'] ?? ( $section['items'][0]['text'] ?? $section['items'][0]['answer'] ) );
			$built   = self::render( $section );

			$at = strpos( $built, '>' . $body . '<' );
			$this->assertNotFalse( $at, $section['layout'] . ': the body copy is on the page' );
			$opening = substr( $built, (int) strrpos( substr( $built, 0, (int) $at ), '<!-- wp:' ) );
			$opening = (string) strstr( $opening, '-->', true );
			$this->assertStringStartsWith( '<!-- wp:paragraph', $opening, $section['layout'] . ': body copy is a paragraph block' );
			foreach ( array( 'fontSize', 'fontWeight', 'typography' ) as $styled ) {
				$this->assertStringNotContainsString( $styled, $opening, $section['layout'] . ': the paragraph carries no title styling' );
			}
		}
	}

	public function test_an_inserted_heading_is_centred_over_the_content_it_heads(): void {
		self::ollie();
		$built = self::render( self::section( 2, false ) );

		$heading = parse_blocks( $built )[0]['innerBlocks'][0];
		$this->assertSame( 'core/heading', $heading['blockName'] );
		$this->assertSame( 'center', $heading['attrs']['textAlign'] ?? null );
		$this->assertClean( $built );
	}

	public function test_a_section_the_pattern_cannot_fit_falls_back_to_the_curated_section(): void {
		self::ollie();
		// A profile pattern with no paragraph and no button for a hero to fill.
		add_filter(
			'senroflux_layout_profiles',
			static fn ( array $profiles ): array => array_replace_recursive( $profiles, array( 'ollie' => array( 'hero' => array( 'pattern' => 'ollie/numbers-stacked' ) ) ) )
		);

		$built = self::render( self::section( 0 ) );

		$this->assertSame( 'senroflux/cover-hero', self::patternName( $built ) );
	}

	// --- D2: the site switch and the filter -----------------------------

	/** The curated pattern each of the six outline sections falls back to. */
	private const CURATED = array( 'senroflux/cover-hero', 'senroflux/text-section', 'senroflux/feature-grid', 'senroflux/media-text', 'senroflux/faq', 'senroflux/cta' );

	/**
	 * @return list<string> The pattern each outline section renders as.
	 */
	private static function renderedNames(): array {
		$vocabulary = new Vocabulary();
		$names      = array();
		foreach ( array( 0, 1, 2, 3, 4, 5 ) as $which ) {
			$names[] = self::patternName( self::render( self::section( $which ), $vocabulary ) );
		}

		return $names;
	}

	public function test_the_switch_defaults_to_on(): void {
		self::ollie();

		$this->assertNotEmpty( ThemePatterns::eligible() );
		$this->assertTrue( ThemePatterns::enabled() );
	}

	public function test_switched_off_a_theme_with_a_profile_takes_the_curated_fallback_for_every_layout(): void {
		self::ollie();
		$this->assertNotSame( self::CURATED, self::renderedNames(), 'precondition: Ollie builds theme sections while the switch is on' );

		update_option( ThemePatterns::OPTION, false );
		ThemePatterns::resetCache();

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( self::CURATED, self::renderedNames() );
	}

	public function test_switched_off_a_theme_with_no_profile_takes_the_curated_fallback_for_every_layout(): void {
		self::spectra();
		$this->assertNotSame( self::CURATED, self::renderedNames(), 'precondition: Spectra One is auto-mapped while the switch is on' );

		update_option( ThemePatterns::OPTION, false );
		ThemePatterns::resetCache();

		$this->assertSame( array(), ThemePatterns::eligible() );
		$this->assertSame( self::CURATED, self::renderedNames() );
	}

	public function test_switched_off_an_explicit_pattern_section_finds_no_theme_pattern(): void {
		self::ollie();
		$this->assertNotNull( ( new Vocabulary() )->resolveThemePattern( 'ollie/faq' ), 'precondition: the pattern resolves while the switch is on' );

		update_option( ThemePatterns::OPTION, false );
		ThemePatterns::resetCache();

		$this->assertNull( ( new Vocabulary() )->resolveThemePattern( 'ollie/faq' ) );
	}

	public function test_the_option_is_read_as_a_bool_whatever_it_was_stored_as(): void {
		self::ollie();
		foreach ( array( '0', 0, '', false ) as $stored ) {
			update_option( ThemePatterns::OPTION, $stored );
			ThemePatterns::resetCache();
			$this->assertSame( array(), ThemePatterns::eligible(), wp_json_encode( $stored ) );
		}
		foreach ( array( '1', 1, true ) as $stored ) {
			update_option( ThemePatterns::OPTION, $stored );
			ThemePatterns::resetCache();
			$this->assertNotEmpty( ThemePatterns::eligible(), wp_json_encode( $stored ) );
		}
	}

	public function test_the_filter_can_remove_a_pattern(): void {
		self::ollie();
		$all = array_column( ThemePatterns::eligible(), 'name' );
		$this->assertContains( 'ollie/faq', $all );

		add_filter(
			'senroflux_theme_patterns',
			static fn ( array $patterns ): array => array_values( array_filter( $patterns, static fn ( array $p ): bool => 'ollie/faq' !== $p['name'] ) )
		);
		ThemePatterns::resetCache();

		$this->assertSame( array_values( array_diff( $all, array( 'ollie/faq' ) ) ), array_column( ThemePatterns::eligible(), 'name' ) );
		$this->assertSame( 'senroflux/faq', self::patternName( self::render( self::section( 4 ) ) ), 'the profile names a removed pattern, so the layout takes its curated fallback' );
	}

	public function test_the_filter_cannot_add_a_pattern_or_change_one(): void {
		self::ollie();
		$before = ThemePatterns::eligible();

		add_filter(
			'senroflux_theme_patterns',
			static function ( array $patterns ): array {
				$patterns[]            = array_replace( $patterns[0], array( 'name' => 'plugin/injected' ) );
				$patterns[1]['markup'] = '<!-- wp:paragraph --><p>tampered</p><!-- /wp:paragraph -->';

				return $patterns;
			}
		);
		ThemePatterns::resetCache();

		$this->assertSame( $before, ThemePatterns::eligible() );
	}

	public function test_a_filter_that_returns_a_non_array_is_ignored(): void {
		self::ollie();
		$before = ThemePatterns::eligible();

		add_filter( 'senroflux_theme_patterns', static fn (): string => 'nothing' );
		ThemePatterns::resetCache();

		$this->assertSame( $before, ThemePatterns::eligible() );
		$this->assertSame( 0, ThemePatterns::skippedCount() );
	}

	public function test_the_filter_does_not_bring_patterns_back_when_the_switch_is_off(): void {
		self::ollie();
		update_option( ThemePatterns::OPTION, false );
		add_filter( 'senroflux_theme_patterns', static fn (): array => array( array( 'name' => 'ollie/faq' ) ) );
		ThemePatterns::resetCache();

		$this->assertSame( array(), ThemePatterns::eligible() );
	}

	public function test_the_skipped_count_includes_what_the_filter_removed(): void {
		self::ollie();
		$skipped  = ThemePatterns::skippedCount();
		$eligible = count( ThemePatterns::eligible() );

		add_filter( 'senroflux_theme_patterns', static fn ( array $patterns ): array => array_slice( $patterns, 2 ) );
		ThemePatterns::resetCache();

		$this->assertSame( $eligible - 2, count( ThemePatterns::eligible() ) );
		$this->assertSame( $skipped + 2, ThemePatterns::skippedCount() );
	}

	public function test_the_skipped_count_includes_what_the_switch_removed(): void {
		self::ollie();
		$skipped  = ThemePatterns::skippedCount();
		$eligible = count( ThemePatterns::eligible() );
		$this->assertGreaterThan( 0, $eligible );

		update_option( ThemePatterns::OPTION, false );
		ThemePatterns::resetCache();

		$this->assertSame( $skipped + $eligible, ThemePatterns::skippedCount() );
	}

	// --- A theme with no profile maps automatically ----------------------

	public function test_a_theme_with_no_profile_maps_its_patterns_by_category_and_slot_fit(): void {
		self::spectra();
		$vocabulary = new Vocabulary();

		$this->assertSame( 'senroflux/spectra-one/hero-banner-2', self::patternName( self::render( self::section( 0 ), $vocabulary ) ) );
		$this->assertSame( 'senroflux/spectra-one/split-image-right', self::patternName( self::render( self::section( 3 ), $vocabulary ) ) );
		$this->assertSame( 'senroflux/spectra-one/faq-2', self::patternName( self::render( self::section( 4 ), $vocabulary ) ) );
		$this->assertSame( 'senroflux/spectra-one/call-to-action-2', self::patternName( self::render( self::section( 5 ), $vocabulary ) ) );
		$this->assertSame( 'senroflux/spectra-one/feature-6', self::patternName( self::render( self::section( 2, false ), $vocabulary ) ) );
	}

	public function test_services_with_photos_fall_back_when_no_matched_pattern_has_a_slot_for_them(): void {
		self::spectra();

		$this->assertSame( 'senroflux/feature-grid', self::patternName( self::render( self::section( 2 ) ) ) );
	}

	public function test_a_page_of_every_layout_on_a_theme_with_no_profile_passes_the_validator(): void {
		self::spectra();
		$vocabulary = new Vocabulary();
		$parts      = array();
		foreach ( array( 0, 1, 2, 3, 4, 5 ) as $which ) {
			$parts[] = self::render( self::section( $which, false ), $vocabulary );
		}

		$this->assertPageClean( implode( "\n\n", $parts ) );
	}

	public function test_a_theme_with_no_matching_pattern_falls_back_for_every_layout_and_refuses_none(): void {
		// Spectra One's `text` stat columns match no layout's categories.
		self::spectra( array( 'text' ) );
		$vocabulary = new Vocabulary();
		$names      = array();
		foreach ( array( 0, 1, 2, 3, 4, 5 ) as $which ) {
			$names[] = self::patternName( self::render( self::section( $which ), $vocabulary ) );
		}

		$this->assertSame(
			array( 'senroflux/cover-hero', 'senroflux/text-section', 'senroflux/feature-grid', 'senroflux/media-text', 'senroflux/faq', 'senroflux/cta' ),
			$names
		);
	}

	public function test_automatic_matching_is_restrictive_when_a_pattern_would_lose_most_of_its_slots(): void {
		// `alternate-image-text` has seventeen slots; a text with image keeps three of them.
		self::register( 'spectra-one', array( 'alternate-image-text' ), self::SPECTRA_PALETTE );

		$this->assertSame( 'senroflux/media-text', self::patternName( self::render( self::section( 3 ) ) ) );
	}

	public function test_a_profiles_text_only_services_pattern_leaves_the_cards_photos_out_while_a_matched_one_may_not(): void {
		self::ollie();
		$built = self::render( self::section( 2 ) );

		$this->assertSame( 'senroflux/ollie/features-with-emojis', self::patternName( $built ) );
		$this->assertStringNotContainsString( '<img', $built );
		$this->assertStringContainsString( 'Sports injuries', $built );
	}

	public function test_a_section_with_too_many_items_for_its_layout_is_refused_whatever_the_theme(): void {
		self::ollie();
		$section = self::section( 4 );
		for ( $i = 0; $i < 6; $i++ ) {
			$section['items'][] = array(
				'question' => 'Question number ' . $i . '?',
				'answer'   => 'Answer number ' . $i . '.',
			);
		}
		$this->assertCount( 10, $section['items'] );

		$built = Layouts::render( $section, 0, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_field', $built->get_error_code() );
	}

	public function test_an_auto_matched_hero_and_cta_count_as_the_pages_hero_and_cta(): void {
		self::spectra();
		$entries = array();
		foreach ( ( new Vocabulary() )->themeDerived() as $entry ) {
			$entries[ (string) $entry['name'] ] = $entry;
		}

		$this->assertTrue( $entries['spectra-one/hero-banner-2']['is_hero'] );
		$this->assertTrue( $entries['spectra-one/call-to-action-2']['is_cta'] );
		$this->assertFalse( $entries['spectra-one/faq-2']['is_hero'] );

		$vocabulary = new Vocabulary();
		$page       = self::render( self::section( 0 ), $vocabulary ) . "\n\n" . self::render( self::section( 4 ), $vocabulary );
		$this->assertPageClean( $page );
	}

	// --- The validator recognises exactly those three changes ------------

	/**
	 * The adapted Ollie hero (eyebrow and second button dropped).
	 */
	private static function adaptedHero(): string {
		self::ollie();
		$built = self::render( self::section( 0 ) );
		self::assertSame( 'senroflux/ollie/hero-light', self::patternName( $built ) );

		return $built;
	}

	/**
	 * The adapted Ollie services section: a heading inserted, the emoji
	 * paragraphs dropped, four cards trimmed to three.
	 */
	private static function adaptedServices(): string {
		self::ollie();
		$built = self::render( self::section( 2, false ) );
		self::assertSame( 'senroflux/ollie/features-with-emojis', self::patternName( $built ) );

		return $built;
	}

	/**
	 * Run `$edit` over the parsed blocks and serialise the result.
	 *
	 * @param callable(list<array<string,mixed>>):list<array<string,mixed>> $edit The change.
	 */
	private static function mutate( string $markup, callable $edit ): string {
		$blocks = array_values( array_filter( parse_blocks( $markup ), static fn ( array $b ): bool => null !== $b['blockName'] ) );

		return serialize_blocks( $edit( $blocks ) );
	}

	/**
	 * A copy of `$block` with `$child` inserted at child position `$at`.
	 *
	 * @param array<string,mixed> $block Parent.
	 * @param array<string,mixed> $child Block to insert.
	 * @return array<string,mixed>
	 */
	private static function withChildAt( array $block, int $at, array $child ): array {
		$count   = count( $block['innerBlocks'] );
		$content = array();
		$marker  = 0;
		$done    = false;
		foreach ( $block['innerContent'] as $chunk ) {
			if ( null === $chunk ) {
				if ( $marker === $at ) {
					$content[] = null;
					$done      = true;
				}
				++$marker;
			}
			$content[] = $chunk;
		}
		if ( ! $done ) {
			// Appended after the last child: before the closing markup.
			$last = count( $content ) - 1;
			array_splice( $content, $last, 0, array( null ) );
		}
		unset( $count );
		array_splice( $block['innerBlocks'], $at, 0, array( $child ) );
		$block['innerContent'] = $content;

		return $block;
	}

	/**
	 * A copy of `$block` without child `$at`, and that child.
	 *
	 * @param array<string,mixed> $block Parent.
	 * @return array{0:array<string,mixed>,1:array<string,mixed>}
	 */
	private static function withoutChildAt( array $block, int $at ): array {
		$content = array();
		$marker  = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			if ( null === $chunk && $marker++ === $at ) {
				continue;
			}
			$content[] = $chunk;
		}
		$removed               = array_splice( $block['innerBlocks'], $at, 1 );
		$block['innerContent'] = $content;

		return array( $block, $removed[0] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function block( string $markup ): array {
		return parse_blocks( $markup )[0];
	}

	public function test_the_validator_recognises_a_pattern_with_its_three_adaptations(): void {
		$this->assertClean( self::adaptedHero(), true );
		$this->assertClean( self::adaptedServices() );
	}

	public function test_the_validator_refuses_an_added_block_of_any_other_kind(): void {
		$hero = self::adaptedHero();
		$this->assertClean( $hero, true );

		$extra = self::block( '<!-- wp:quote --><blockquote class="wp-block-quote"><p>An extra quote</p></blockquote><!-- /wp:quote -->' );
		foreach ( array(
			'quote' => $extra,
			'list'  => self::block( '<!-- wp:list --><ul class="wp-block-list"><li>One</li></ul><!-- /wp:list -->' ),
		) as $kind => $block ) {
			$added = self::mutate(
				$hero,
				static function ( array $blocks ) use ( $block ): array {
					// Into the pattern's outermost container, after everything it has.
					$cover                   = $blocks[0];
					$cover['innerBlocks'][0] = self::withChildAt( $cover['innerBlocks'][0], count( $cover['innerBlocks'][0]['innerBlocks'] ), $block );
					$blocks[0]               = $cover;

					return $blocks;
				}
			);
			$this->assertRefused( $added, 'an added ' . $kind . ' block', true );
		}

		// A second heading (a kind the pattern ships, but not another place it ships one) is no different.
		$heading = self::block( '<!-- wp:heading --><h2 class="wp-block-heading">Extra</h2><!-- /wp:heading -->' );
		$added   = self::mutate(
			$hero,
			static function ( array $blocks ) use ( $heading ): array {
				$blocks[0]['innerBlocks'][0] = self::withChildAt( $blocks[0]['innerBlocks'][0], 1, $heading );

				return $blocks;
			}
		);
		$this->assertRefused( $added, 'an added heading inside a pattern that has its own', true );
	}

	public function test_the_validator_refuses_a_reordered_block(): void {
		$services = self::adaptedServices();
		$this->assertClean( $services );

		// The inserted heading and the card grid swap places.
		$swapped = self::mutate(
			$services,
			static function ( array $blocks ): array {
				[$top, $heading] = self::withoutChildAt( $blocks[0], 0 );
				$blocks[0]       = self::withChildAt( $top, 1, $heading );

				return $blocks;
			}
		);
		$this->assertRefused( $swapped, 'the heading moved after the card grid' );

		// Inside a card, title and text swap.
		$hero    = self::adaptedHero();
		$swapped = self::mutate(
			$hero,
			static function ( array $blocks ): array {
				$wrap            = $blocks[0]['innerBlocks'][0]['innerBlocks'][0];
				[$wrap, $titles] = self::withoutChildAt( $wrap, 0 );
				$wrap            = self::withChildAt( $wrap, 1, $titles );
				$blocks[0]['innerBlocks'][0]['innerBlocks'][0] = $wrap;

				return $blocks;
			}
		);
		$this->assertRefused( $swapped, 'the titles group moved after the paragraph', true );
	}

	public function test_the_validator_refuses_a_block_moved_between_containers(): void {
		$hero = self::adaptedHero();
		$this->assertClean( $hero, true );

		// The buttons leave their "Text and Buttons" group for the group around it.
		$moved = self::mutate(
			$hero,
			static function ( array $blocks ): array {
				$outer                       = $blocks[0]['innerBlocks'][0];
				[$inner, $buttons]           = self::withoutChildAt( $outer['innerBlocks'][0], 2 );
				$outer['innerBlocks'][0]     = $inner;
				$outer                       = self::withChildAt( $outer, 1, $buttons );
				$blocks[0]['innerBlocks'][0] = $outer;

				return $blocks;
			}
		);
		$this->assertRefused( $moved, 'the buttons moved up a level', true );
	}

	public function test_the_validator_refuses_a_colour_attribute_not_in_the_shipped_markup(): void {
		$hero = self::adaptedHero();
		$this->assertClean( $hero, true );

		// A preset the theme defines, on a block that ships no colour.
		$painted = str_replace(
			'<!-- wp:group {"metadata":{"name":"Titles"}',
			'<!-- wp:group {"backgroundColor":"main","metadata":{"name":"Titles"}',
			$hero
		);
		$this->assertNotSame( $hero, $painted );
		$this->assertRefused( $painted, 'a backgroundColor the pattern does not ship', true );

		// The inserted heading may not carry one either.
		$services = self::adaptedServices();
		$this->assertClean( $services );
		$painted = (string) preg_replace( '/<!-- wp:heading (\{[^}]*\} )?-->/', '<!-- wp:heading {"textColor":"primary"} -->', $services, 1 );
		$this->assertNotSame( $services, $painted );
		$this->assertRefused( $painted, 'a textColor on the inserted heading' );
	}

	public function test_the_validator_refuses_a_heading_inserted_anywhere_but_the_top(): void {
		$services = self::adaptedServices();
		$this->assertClean( $services );

		$heading = self::block( '<!-- wp:heading --><h2 class="wp-block-heading"></h2><!-- /wp:heading -->' );
		$inner   = self::block( $services );

		// A second heading at the top: the pattern now has a heading outside its cards... of its own making.
		$twice = self::mutate(
			$services,
			static function ( array $blocks ) use ( $heading ): array {
				$blocks[0] = self::withChildAt( $blocks[0], 0, $heading );

				return $blocks;
			}
		);
		$this->assertRefused( $twice, 'two inserted headings' );

		// Moved from the top to the middle of the section's own children.
		$middle = self::mutate(
			$services,
			static function ( array $blocks ): array {
				[$top, $heading]       = self::withoutChildAt( $blocks[0], 0 );
				$grid                  = $top['innerBlocks'][0];
				$top['innerBlocks'][0] = self::withChildAt( $grid, 1, $heading );
				$blocks[0]             = $top;

				return $blocks;
			}
		);
		$this->assertRefused( $middle, 'the heading inside the card grid' );

		// Never as the last block either.
		$last = self::mutate(
			$services,
			static function ( array $blocks ): array {
				[$top, $heading] = self::withoutChildAt( $blocks[0], 0 );
				$blocks[0]       = self::withChildAt( $top, count( $top['innerBlocks'] ), $heading );

				return $blocks;
			}
		);
		$this->assertRefused( $last, 'the heading after the card grid' );
		unset( $inner );
	}
}
