<?php
/**
 * D1 step 3 and D4 (S5): every known layout renders a curated section when the
 * theme provides no pattern for it, and that section's spacing and overlay
 * slugs are rewritten to ones the active theme defines. Also the built-in
 * Ollie layout profile, filled from real Ollie pattern files.
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

final class CuratedFallbackTest extends TestCase {

	/** Ollie's real palette (`theme.json`, 1.6.4): slug => colour. */
	private const OLLIE_PALETTE = array(
		'primary'            => '#5344F4',
		'primary-accent'     => '#e9e7ff',
		'primary-alt'        => '#DEC9FF',
		'primary-alt-accent' => '#3d386b',
		'main'               => '#1E1E26',
		'main-accent'        => '#d4d4ec',
		'base'               => '#fff',
		'secondary'          => '#545473',
		'tertiary'           => '#f8f7fc',
		'border-light'       => '#E3E3F0',
		'border-dark'        => '#4E4E60',
	);

	/** Ollie's seven named spacing sizes (`theme.json`, 1.6.4), smallest first. */
	private const OLLIE_SPACING = array( 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large', 'xxxx-large' );

	protected function setUp(): void {
		require_once __DIR__ . '/LayoutsTest.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$GLOBALS['senroflux_test_theme_patterns']  = array();
		$GLOBALS['senroflux_test_stylesheet']      = 'ollie';
		$GLOBALS['senroflux_test_global_settings'] = self::settings( self::OLLIE_PALETTE, self::OLLIE_SPACING );
		ThemePatterns::resetCache();
	}

	protected function tearDown(): void {
		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'], $GLOBALS['senroflux_test_stylesheet'], $GLOBALS['senroflux_test_template'], $GLOBALS['senroflux_test_global_settings'], $GLOBALS['senroflux_test_declared_patterns'] );
	}

	/**
	 * The shape `wp_get_global_settings()` returns. By default the live one:
	 * each preset list keyed by origin, with Core's own `default` list present
	 * but switched off (`defaultPalette`, `defaultSpacingSizes` false), as on
	 * Ollie. `$flat` gives a plain list instead.
	 *
	 * @param array<string,string> $palette Slug => colour.
	 * @param list<string>         $spacing Spacing size slugs, smallest first.
	 * @return array<string,mixed>
	 */
	private static function settings( array $palette, array $spacing, bool $flat = false ): array {
		$colours = array();
		foreach ( $palette as $slug => $color ) {
			$colours[] = array(
				'slug'  => $slug,
				'color' => $color,
			);
		}
		$sizes = array_map( static fn ( string $slug ): array => array( 'slug' => $slug ), $spacing );

		if ( $flat ) {
			return array(
				'color'   => array(
					'palette'   => $colours,
					'gradients' => array(),
				),
				'spacing' => array( 'spacingSizes' => $sizes ),
			);
		}

		return array(
			'color'   => array(
				'defaultPalette'   => false,
				'defaultGradients' => false,
				'palette'          => array(
					'default' => array(
						array(
							'slug'  => 'black',
							'color' => '#000000',
						),
					),
					'theme'   => $colours,
				),
				'gradients'        => array(),
			),
			'spacing' => array(
				'defaultSpacingSizes' => false,
				'spacingSizes'        => array(
					'default' => array_map( static fn ( string $slug ): array => array( 'slug' => $slug ), array( '20', '30', '40', '50', '60', '70', '80' ) ),
					'theme'   => $sizes,
				),
			),
		);
	}

	/**
	 * Every known layout and the curated pattern it falls back to.
	 *
	 * @return array<string,array{int,string}>
	 */
	public static function layouts(): array {
		return array(
			'hero with image' => array( 0, 'senroflux/cover-hero' ),
			'text'            => array( 1, 'senroflux/text-section' ),
			'services'        => array( 2, 'senroflux/feature-grid' ),
			'text-with-image' => array( 3, 'senroflux/media-text' ),
			'faq'             => array( 4, 'senroflux/faq' ),
			'cta'             => array( 5, 'senroflux/cta' ),
		);
	}

	/**
	 * @dataProvider layouts
	 */
	public function test_every_layout_renders_its_curated_section_with_no_theme_patterns( int $which, string $name ): void {
		$built = Layouts::render( LayoutsTest::outline()[ $which ], $which, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_code() . ': ' . $built->get_error_message() : '' );
		$this->assertSame( $name, parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
	}

	public function test_a_hero_without_an_image_falls_back_to_the_plain_hero(): void {
		$section = LayoutsTest::outline()[0];
		unset( $section['image'] );

		$built = Layouts::render( $section, 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/hero', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
		$this->assertStringContainsString( 'Back to running, properly', $built );
	}

	public function test_a_page_of_every_layout_passes_validator_clean_with_ollies_slugs(): void {
		$vocabulary = new Vocabulary();
		$parts      = array();
		foreach ( LayoutsTest::outline() as $index => $section ) {
			$built = Layouts::render( $section, $index, $vocabulary );
			$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
			$parts[] = $built;
		}
		$page = implode( "\n\n", $parts );

		$clean = ( new Validator( $vocabulary ) )->clean( $page );

		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
		// D4: the curated 50/60 slugs are Core's, Ollie has x-large / xx-large.
		$this->assertDoesNotMatchRegularExpression( '/spacing(\||--)(50|60)\b/', $clean['content'] );
		$this->assertStringContainsString( 'var:preset|spacing|x-large', $clean['content'] );
		$this->assertStringContainsString( 'var:preset|spacing|xx-large', $clean['content'] );
		$this->assertStringContainsString( 'var(--wp--preset--spacing--x-large)', $clean['content'] );
		// D4: the cover overlay is the palette's darkest colour (Ollie's `main`).
		$this->assertStringContainsString( '"overlayColor":"main"', $clean['content'] );
		$this->assertStringContainsString( 'has-main-background-color', $clean['content'] );
		$this->assertStringNotContainsString( '"overlayColor":"contrast"', $clean['content'] );
	}

	public function test_an_unknown_layout_is_still_refused(): void {
		$built = Layouts::render( array( 'layout' => 'gallery' ), 0, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertSame( 'layout_unknown', $built->get_error_code() );
	}

	public function test_a_fallback_section_still_enforces_the_layouts_field_rules(): void {
		$section = LayoutsTest::outline()[2];
		array_pop( $section['items'] );
		$too_few = Layouts::render( $section, 2, new Vocabulary() );

		$section                      = LayoutsTest::outline()[2];
		$section['items'][1]['title'] = 'Rehabilitation after knee and hip joint replacement surgery';
		$too_long                     = Layouts::render( $section, 2, new Vocabulary() );

		$no_url = LayoutsTest::outline()[5];
		unset( $no_url['button']['url'] );
		$unlinked = Layouts::render( $no_url, 5, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $too_few );
		$this->assertStringContainsString( 'takes exactly 3 items; it has 2', $too_few->get_error_message() );
		$this->assertInstanceOf( WP_Error::class, $too_long );
		$this->assertStringContainsString( '`items[1].title` has 8 words; the limit is 6', $too_long->get_error_message() );
		$this->assertInstanceOf( WP_Error::class, $unlinked );
		$this->assertStringContainsString( '`button.url` needs a real destination', $unlinked->get_error_message() );
	}

	public function test_spacing_slugs_the_theme_defines_are_kept(): void {
		$GLOBALS['senroflux_test_global_settings'] = self::settings( array( 'contrast' => '#111111' ), array( '20', '30', '40', '50', '60', '70', '80' ), true );

		$built = Layouts::render( LayoutsTest::outline()[1], 1, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( 'var:preset|spacing|50', $built );
		$this->assertStringNotContainsString( 'x-large', $built );
	}

	public function test_spacing_is_mapped_by_rank_on_a_shorter_scale(): void {
		// Five sizes: 50 is rank 3 of 7 (index 3 of 0..6), so index round(3/6 * 4) = 2 of 5; 60 is index round(4/6 * 4) = 3.
		$GLOBALS['senroflux_test_global_settings'] = self::settings( array( 'dark' => '#000000' ), array( 'xs', 's', 'm', 'l', 'xl' ) );

		$built = Layouts::render( LayoutsTest::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( 'var:preset|spacing|l', $built );
		$this->assertStringNotContainsString( 'spacing|60', $built );
	}

	public function test_core_default_presets_the_theme_switched_off_are_not_counted(): void {
		// As live on Ollie: Core's `default` origin lists 20..80 and `black`, but the theme has turned both off.
		$built = Layouts::render( LayoutsTest::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( 'var:preset|spacing|xx-large', $built );
		$this->assertStringContainsString( '"overlayColor":"main"', $built );
		$this->assertStringNotContainsString( 'black', $built );
	}

	public function test_core_default_presets_the_theme_leaves_on_are_counted(): void {
		$settings                                   = self::settings( self::OLLIE_PALETTE, self::OLLIE_SPACING );
		$settings['spacing']['defaultSpacingSizes'] = true;
		$settings['color']['defaultPalette']        = true;
		$GLOBALS['senroflux_test_global_settings']  = $settings;

		$built = Layouts::render( LayoutsTest::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( 'var:preset|spacing|60', $built );
		$this->assertStringContainsString( '"overlayColor":"black"', $built );
	}

	public function test_a_theme_with_no_spacing_list_leaves_the_curated_slugs_alone(): void {
		unset( $GLOBALS['senroflux_test_global_settings'] );

		$built = Layouts::render( LayoutsTest::outline()[1], 1, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( 'var:preset|spacing|50', $built );
	}

	public function test_the_overlay_is_the_darkest_palette_colour_by_relative_luminance(): void {
		// Pure blue is darker than mid grey and pure green by relative luminance, though its channel sum is not the smallest.
		$GLOBALS['senroflux_test_global_settings'] = self::settings(
			array(
				'grey'  => '#777777',
				'green' => '#00ff00',
				'blue'  => '#0000ff',
				'cream' => '#fff8e8',
				'mixed' => 'color-mix(in srgb, currentColor 20%, transparent)',
			),
			self::OLLIE_SPACING
		);

		$built = Layouts::render( LayoutsTest::outline()[0], 0, new Vocabulary() );

		$this->assertIsString( $built );
		$this->assertStringContainsString( '"overlayColor":"blue"', $built );
		$this->assertStringContainsString( 'has-blue-background-color', $built );
	}

	// --- The Ollie profile, filled from real Ollie pattern files ---------

	/**
	 * Register real Ollie pattern files (tests/ThemePatterns/ollie) as the
	 * active theme's patterns, the way `WP_Theme::get_block_patterns()` does.
	 *
	 * @param list<string> $slugs File names without `.php`.
	 */
	private static function registerOllie( array $slugs ): void {
		$dir      = dirname( __DIR__, 2 ) . '/ThemePatterns/ollie';
		$patterns = array();
		foreach ( $slugs as $slug ) {
			$path = $dir . '/' . $slug . '.php';
			$raw  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture.
			preg_match( '/^\s*\*\s*Slug:\s*(.+)$/mi', $raw, $name );
			preg_match( '/^\s*\*\s*Categories:\s*(.+)$/mi', $raw, $categories );
			ob_start();
			include $path;
			$content = trim( (string) ob_get_clean() );

			$patterns[] = array(
				'name'       => trim( $name[1] ),
				'title'      => $slug,
				'content'    => $content,
				'filePath'   => $path,
				'categories' => array_map( 'trim', explode( ',', $categories[1] ) ),
			);
		}

		$GLOBALS['senroflux_test_theme_patterns'] = $patterns;
		$GLOBALS['senroflux_test_stylesheet_dir'] = $dir;
		$GLOBALS['senroflux_test_template_dir']   = $dir;
		ThemePatterns::resetCache();
	}

	/** @return array<string,mixed> */
	private static function ollieHero(): array {
		return array(
			'layout'  => 'hero',
			'eyebrow' => 'Evening physiotherapy',
			'heading' => 'Back to running, properly',
			'text'    => 'Sports and injury physiotherapy in Northside, with evening appointments for people who train after work.',
			'button'  => array(
				'label' => 'Book an assessment',
				'url'   => 'tel:+441234567890',
			),
			'button2' => array(
				'label' => 'See our services',
				'url'   => 'https://example.test/services',
			),
			'image'   => array(
				'url' => 'http://localhost:8897/wp-content/uploads/2026/09/runner-knee.webp',
				'alt' => 'A physiotherapist checks a runner\'s knee on the treatment table',
			),
		);
	}

	public function test_the_ollie_profile_builds_the_hero_from_ollie_hero_light(): void {
		self::registerOllie( array( 'hero-light', 'text-call-to-action-buttons' ) );

		$built = Layouts::render( self::ollieHero(), 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_code() . ': ' . $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/ollie/hero-light', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
		$this->assertStringContainsString( 'Evening physiotherapy', $built );
		$this->assertStringContainsString( '>Back to running, properly</h1>', $built );
		$this->assertStringContainsString( 'href="tel:+441234567890"', $built );
		$this->assertStringContainsString( 'href="https://example.test/services"', $built );
		$this->assertStringContainsString( 'runner-knee.webp', $built );
		$this->assertStringNotContainsString( 'WordPress Reimagined', $built );
		$this->assertStringNotContainsString( 'Download Ollie', $built );
		// Ollie's own hero cover has no background photo; the model's image fills the pattern's image block, not the cover.
		$this->assertArrayNotHasKey( 'url', parse_blocks( $built )[0]['attrs'] );
	}

	public function test_the_ollie_hero_without_an_eyebrow_or_second_button_drops_both_and_still_validates(): void {
		self::registerOllie( array( 'hero-light', 'text-call-to-action-buttons' ) );
		$section = self::ollieHero();
		unset( $section['eyebrow'], $section['button2'] );

		$vocabulary = new Vocabulary();
		$built      = Layouts::render( $section, 0, $vocabulary );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_code() . ': ' . $built->get_error_message() : '' );
		$this->assertStringNotContainsString( 'WordPress Reimagined', $built );
		$this->assertStringNotContainsString( 'Ollie Features', $built );
		$this->assertSame( 1, substr_count( $built, 'wp-block-button__link' ) );

		$second = Layouts::render( LayoutsTest::outline()[2], 1, $vocabulary );
		$clean  = ( new Validator( $vocabulary ) )->clean( $built . "\n\n" . $second );
		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
	}

	/**
	 * Core removes `filePath` from a registered pattern once its content has
	 * been read (live on Ollie), so a theme's own pattern must still be found by
	 * the slug its file declares.
	 */
	public function test_theme_patterns_are_found_when_core_has_dropped_their_file_path(): void {
		self::registerOllie( array( 'hero-light', 'text-call-to-action-buttons' ) );
		$GLOBALS['senroflux_test_theme_patterns']    = array_map(
			static function ( array $pattern ): array {
				unset( $pattern['filePath'] );

				return $pattern;
			},
			$GLOBALS['senroflux_test_theme_patterns']
		);
		$GLOBALS['senroflux_test_declared_patterns'] = array(
			array( 'slug' => 'ollie/hero-light' ),
			array( 'slug' => 'ollie/text-call-to-action-buttons' ),
		);
		ThemePatterns::resetCache();

		$built = Layouts::render( self::ollieHero(), 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/ollie/hero-light', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
	}

	public function test_a_half_given_second_button_is_refused_by_name(): void {
		self::registerOllie( array( 'hero-light', 'text-call-to-action-buttons' ) );
		$section = self::ollieHero();
		unset( $section['button2']['url'] );

		$built = Layouts::render( $section, 0, new Vocabulary() );

		$this->assertInstanceOf( WP_Error::class, $built );
		$this->assertStringContainsString( '`button2.url` needs a real destination', $built->get_error_message() );
	}

	public function test_the_ollie_profile_resolves_through_a_child_theme(): void {
		self::registerOllie( array( 'hero-light', 'text-call-to-action-buttons' ) );
		$GLOBALS['senroflux_test_stylesheet'] = 'ollie-child';
		$GLOBALS['senroflux_test_template']   = 'ollie';

		$built = Layouts::render( self::ollieHero(), 0, new Vocabulary() );

		$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
		$this->assertSame( 'senroflux/ollie/hero-light', parse_blocks( $built )[0]['attrs']['metadata']['name'] ?? '' );
	}

	public function test_a_page_mixing_ollie_patterns_and_curated_fallbacks_passes_validator_clean(): void {
		self::registerOllie( array( 'hero-light', 'text-call-to-action-buttons' ) );

		$vocabulary = new Vocabulary();
		$outline    = LayoutsTest::outline();
		$sections   = array( self::ollieHero(), $outline[1], $outline[2], $outline[3], $outline[4], $outline[5] );
		$parts      = array();
		foreach ( $sections as $index => $section ) {
			$built = Layouts::render( $section, $index, $vocabulary );
			$this->assertIsString( $built, $built instanceof WP_Error ? $built->get_error_message() : '' );
			$parts[] = $built;
		}

		$clean = ( new Validator( $vocabulary ) )->clean( implode( "\n\n", $parts ) );

		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
		$names = array();
		foreach ( parse_blocks( $clean['content'] ) as $block ) {
			if ( null !== $block['blockName'] ) {
				$names[] = $block['attrs']['metadata']['name'] ?? '';
			}
		}
		$this->assertSame(
			array(
				'senroflux/ollie/hero-light',
				'senroflux/text-section',
				'senroflux/feature-grid',
				'senroflux/media-text',
				'senroflux/faq',
				'senroflux/ollie/text-call-to-action-buttons',
			),
			$names
		);
	}
}
