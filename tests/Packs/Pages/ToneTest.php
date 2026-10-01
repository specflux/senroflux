<?php
/**
 * D3b (S6): section tone. A layout item's `tone` becomes a `backgroundColor` +
 * `textColor` preset pair that SenroFlux writes on a curated section's
 * top-level group, resolved from the active palette by luminance (never by
 * slug), dropped when no pair reaches WCAG AA, and bounded per page.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Pages;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Pages\Tone;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use WP_Error;

final class ToneTest extends TestCase {

	/** Twenty Twenty-Five's real palette (`theme.json`): slug => colour. */
	private const TT25_PALETTE = array(
		'base'     => '#FFFFFF',
		'contrast' => '#111111',
		'accent-1' => '#FFEE58',
		'accent-2' => '#F6CFF4',
		'accent-3' => '#503AA8',
		'accent-4' => '#686868',
		'accent-5' => '#FBFAF3',
		'accent-6' => 'color-mix(in srgb, currentColor 20%, transparent)',
	);

	/** Ollie's real palette (`theme.json`, 1.6.3): slug => colour. */
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

	protected function setUp(): void {
		require_once __DIR__ . '/LayoutsTest.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';

		$GLOBALS['senroflux_test_theme_patterns'] = array();
		self::palette( self::TT25_PALETTE );
		ThemePatterns::resetCache();
	}

	protected function tearDown(): void {
		ThemePatterns::resetCache();
		unset( $GLOBALS['senroflux_test_theme_patterns'], $GLOBALS['senroflux_test_stylesheet_dir'], $GLOBALS['senroflux_test_template_dir'], $GLOBALS['senroflux_test_stylesheet'], $GLOBALS['senroflux_test_template'], $GLOBALS['senroflux_test_global_settings'], $GLOBALS['senroflux_test_declared_patterns'] );
	}

	/**
	 * Set the active palette, in the origin-keyed shape the live function
	 * returns (Core's own list present but switched off).
	 *
	 * @param array<string,string> $palette Slug => colour.
	 */
	private static function palette( array $palette ): void {
		$colours = array();
		foreach ( $palette as $slug => $color ) {
			$colours[] = array(
				'slug'  => $slug,
				'color' => $color,
			);
		}

		$GLOBALS['senroflux_test_global_settings'] = array(
			'color' => array(
				'defaultPalette' => false,
				'palette'        => array(
					'default' => array(
						array(
							'slug'  => 'black',
							'color' => '#000000',
						),
					),
					'theme'   => $colours,
				),
			),
		);
	}

	/** WCAG relative luminance, written out independently of the code under test. */
	private static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$channels = array_map(
			static function ( string $pair ): float {
				$value = hexdec( $pair ) / 255;

				return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( $hex, 2 )
		);

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	private static function ratio( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** @return array<string,mixed> */
	private static function textSection( string $tone = '' ): array {
		$section = LayoutsTest::outline()[1];
		if ( '' !== $tone ) {
			$section['tone'] = $tone;
		}

		return $section;
	}

	/** @return array<string,mixed> A hero with no image: the curated `hero`. */
	private static function plainHero( string $tone = '' ): array {
		$section = LayoutsTest::outline()[0];
		unset( $section['image'] );
		if ( '' !== $tone ) {
			$section['tone'] = $tone;
		}

		return $section;
	}

	private static function render( array $section ): string {
		$built = Layouts::render( $section, 0, new Vocabulary() );
		self::assertIsString( $built, $built instanceof WP_Error ? $built->get_error_code() . ': ' . $built->get_error_message() : '' );

		return $built;
	}

	/** @return array<string,mixed> The first block's attributes. */
	private static function topAttrs( string $markup ): array {
		return parse_blocks( $markup )[0]['attrs'] ?? array();
	}

	// --- Resolution by luminance -----------------------------------------

	public function test_tone_resolves_by_luminance_on_the_twenty_twenty_five_palette(): void {
		$contrast = Tone::pair( 'contrast' );
		$accent   = Tone::pair( 'accent' );

		$this->assertSame(
			array(
				'background' => 'contrast',
				'text'       => 'base',
			),
			$contrast
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		// The most saturated mid-luminance colour: purple, not the lighter yellow and pink.
		$this->assertSame(
			array(
				'background' => 'accent-3',
				'text'       => 'base',
			),
			$accent
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertGreaterThanOrEqual( 4.5, self::ratio( self::TT25_PALETTE[ $contrast['background'] ], self::TT25_PALETTE[ $contrast['text'] ] ) );
		$this->assertGreaterThanOrEqual( 4.5, self::ratio( self::TT25_PALETTE[ $accent['background'] ], self::TT25_PALETTE[ $accent['text'] ] ) );
	}

	public function test_tone_resolves_by_luminance_on_the_ollie_palette(): void {
		self::palette( self::OLLIE_PALETTE );

		$contrast = Tone::pair( 'contrast' );
		$accent   = Tone::pair( 'accent' );

		$this->assertSame(
			array(
				'background' => 'main',
				'text'       => 'base',
			),
			$contrast
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame(
			array(
				'background' => 'primary',
				'text'       => 'base',
			),
			$accent
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertGreaterThanOrEqual( 4.5, self::ratio( self::OLLIE_PALETTE['main'], self::OLLIE_PALETTE['base'] ) );
		$this->assertGreaterThanOrEqual( 4.5, self::ratio( self::OLLIE_PALETTE['primary'], self::OLLIE_PALETTE['base'] ) );
	}

	public function test_tone_is_chosen_by_luminance_not_by_slug_name(): void {
		// A theme that names its light colour `contrast` and its dark one `base`.
		self::palette(
			array(
				'contrast' => '#fafafa',
				'base'     => '#101010',
				'brand'    => '#b00020',
			)
		);

		$this->assertSame(
			array(
				'background' => 'base',
				'text'       => 'contrast',
			),
			Tone::pair( 'contrast' )
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertSame(
			array(
				'background' => 'brand',
				'text'       => 'contrast',
			),
			Tone::pair( 'accent' )
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
	}

	public function test_accent_takes_whichever_end_reaches_aa(): void {
		// A saturated light-mid red reads on the dark end, not on the white.
		self::palette(
			array(
				'ink'   => '#101010',
				'paper' => '#ffffff',
				'coral' => '#ff6f61',
			)
		);

		$accent = Tone::pair( 'accent' );

		$this->assertSame(
			array(
				'background' => 'coral',
				'text'       => 'ink',
			),
			$accent
		); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		$this->assertGreaterThanOrEqual( 4.5, self::ratio( '#ff6f61', '#101010' ) );
		$this->assertLessThan( 4.5, self::ratio( '#ff6f61', '#ffffff' ) );
	}

	public function test_tone_is_dropped_when_no_pair_reaches_aa(): void {
		// Dark/light are 2.07:1 apart; the mid purple is under 2.5:1 on either end.
		self::palette(
			array(
				'dark'  => '#6a6a6a',
				'light' => '#a0a0a0',
				'mid'   => '#7a4a9a',
			)
		);
		$this->assertLessThan( 4.5, self::ratio( '#6a6a6a', '#a0a0a0' ) );
		$this->assertLessThan( 4.5, self::ratio( '#7a4a9a', '#a0a0a0' ) );
		$this->assertLessThan( 4.5, self::ratio( '#7a4a9a', '#6a6a6a' ) );

		$this->assertNull( Tone::pair( 'contrast' ) );
		$this->assertNull( Tone::pair( 'accent' ) );

		$built = self::render( self::textSection( 'contrast' ) );
		$this->assertArrayNotHasKey( 'backgroundColor', self::topAttrs( $built ) );
		$this->assertSame( self::render( self::textSection() ), $built, 'a dropped tone renders exactly the default section' );
	}

	public function test_a_palette_with_nothing_measurable_has_no_tone(): void {
		self::palette( array( 'only' => 'color-mix(in srgb, currentColor 20%, transparent)' ) );

		$this->assertNull( Tone::pair( 'contrast' ) );
		$this->assertNull( Tone::pair( 'accent' ) );
	}

	// --- Applying a tone ---------------------------------------------------

	public function test_a_curated_section_gets_a_preset_pair_and_its_classes(): void {
		$built = self::render( self::textSection( 'contrast' ) );
		$attrs = self::topAttrs( $built );

		$this->assertSame( 'contrast', $attrs['backgroundColor'] );
		$this->assertSame( 'base', $attrs['textColor'] );
		$this->assertSame( 'senroflux/text-section', $attrs['metadata']['name'] );
		$this->assertMatchesRegularExpression( '/<div class="wp-block-group [^"]*\bhas-base-color\b[^"]*"/', $built );
		foreach ( array( 'has-base-color', 'has-contrast-background-color', 'has-text-color', 'has-background' ) as $class ) {
			$this->assertMatchesRegularExpression( '/<div class="[^"]*\b' . $class . '\b[^"]*"/', $built, $class );
		}
		$this->assertStringNotContainsString( '"style":{"color"', $built, 'preset slugs only, never a raw colour' );

		$accent = self::topAttrs( self::render( self::textSection( 'accent' ) ) );
		$this->assertSame( 'accent-3', $accent['backgroundColor'] );
		$this->assertSame( 'base', $accent['textColor'] );
	}

	public function test_default_tone_and_no_tone_add_nothing(): void {
		$plain = self::render( self::textSection() );

		$this->assertArrayNotHasKey( 'backgroundColor', self::topAttrs( $plain ) );
		$this->assertSame( $plain, self::render( self::textSection( 'default' ) ) );
	}

	public function test_a_bad_tone_is_refused_like_any_other_bad_field(): void {
		foreach ( array( 'loud', '#ff0000', 'Contrast', '' ) as $tone ) {
			$section         = self::textSection();
			$section['tone'] = $tone;
			$built           = Layouts::render( $section, 1, new Vocabulary() );

			$this->assertInstanceOf( WP_Error::class, $built, $tone );
			$this->assertSame( 'layout_field', $built->get_error_code() );
			$this->assertStringContainsString( 'Section 2', $built->get_error_message() );
			$this->assertStringContainsString( '`tone`', $built->get_error_message() );
			$this->assertStringContainsString( 'default, contrast or accent', $built->get_error_message() );
		}

		$section         = self::textSection();
		$section['tone'] = array( 'contrast' );
		$this->assertInstanceOf( WP_Error::class, Layouts::render( $section, 1, new Vocabulary() ) );
	}

	public function test_a_toned_section_is_accepted_by_the_validator_on_every_curated_group_layout(): void {
		$outline = LayoutsTest::outline();
		$vocab   = new Vocabulary();
		$parts   = array();
		foreach ( array( 0, 1, 2, 4, 5 ) as $which ) {
			$section = $outline[ $which ];
			unset( $section['image'] );
			$section['tone'] = 0 === $which || 4 === $which ? 'contrast' : 'accent';
			if ( 2 === $which ) {
				foreach ( $section['items'] as &$item ) {
					unset( $item['image'] );
				}
				unset( $item );
			}
			$parts[] = self::render( $section );
		}

		$clean = ( new Validator( $vocab ) )->clean( implode( "\n\n", $parts ) );

		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
		// Five bands plus the inverse pair on the two buttons (hero, cta).
		$this->assertSame( 7, substr_count( $clean['content'], '"textColor":"' ) );
	}

	public function test_a_hero_with_an_image_is_a_cover_and_takes_no_tone(): void {
		$section         = LayoutsTest::outline()[0];
		$section['tone'] = 'accent';

		$built = self::render( $section );

		$this->assertSame( 'senroflux/cover-hero', self::topAttrs( $built )['metadata']['name'] );
		$this->assertArrayNotHasKey( 'backgroundColor', self::topAttrs( $built ) );
		$this->assertArrayNotHasKey( 'textColor', self::topAttrs( $built ) );
	}

	public function test_a_theme_pattern_section_keeps_its_shipped_colours_and_ignores_tone(): void {
		self::palette( self::OLLIE_PALETTE );
		$GLOBALS['senroflux_test_stylesheet'] = 'ollie';
		self::registerOllie( array( 'hero-light' ) );

		$plain = self::render( self::ollieHero() );
		$toned = self::render( self::ollieHero() + array( 'tone' => 'accent' ) );

		$this->assertSame( 'senroflux/ollie/hero-light', self::topAttrs( $toned )['metadata']['name'] );
		$this->assertSame( $plain, $toned );
	}

	// --- Image-less hero default -------------------------------------------

	public function test_an_image_less_hero_gets_contrast(): void {
		$attrs = self::topAttrs( self::render( self::plainHero() ) );

		$this->assertSame( 'senroflux/hero', $attrs['metadata']['name'] );
		$this->assertSame( 'contrast', $attrs['backgroundColor'] );
		$this->assertSame( 'base', $attrs['textColor'] );
	}

	public function test_an_image_less_hero_keeps_the_tone_the_model_gave(): void {
		$accent  = self::topAttrs( self::render( self::plainHero( 'accent' ) ) );
		$default = self::topAttrs( self::render( self::plainHero( 'default' ) ) );

		$this->assertSame( 'accent-3', $accent['backgroundColor'] );
		$this->assertArrayNotHasKey( 'backgroundColor', $default );
	}

	// --- The model cannot write colour through this path -------------------

	/**
	 * Each case: the text a model might splice into the top-level group's
	 * comment JSON, and whether it goes inside `style` (else it is a new key).
	 *
	 * @return array<string,array{string,bool}>
	 */
	public static function smuggled(): array {
		return array(
			'raw background hex on a group'     => array( '"color":{"background":"#ff0000"},', true ),
			'raw text hex on a group'           => array( '"color":{"text":"#ff0000"},', true ),
			'a palette slug that is not a pair' => array( '"backgroundColor":"accent-1","textColor":"contrast",', false ),
			'only half of a pair'               => array( '"backgroundColor":"contrast",', false ),
			'the pair reversed'                 => array( '"backgroundColor":"base","textColor":"contrast",', false ),
			'a gradient'                        => array( '"gradient":"vivid",', false ),
		);
	}

	/**
	 * @dataProvider smuggled
	 */
	public function test_a_colour_the_model_wrote_in_a_group_is_still_refused( string $injected, bool $in_style ): void {
		$markup = self::render( self::textSection() );
		$marked = $in_style
			? str_replace( '"style":{"spacing"', '"style":{' . $injected . '"spacing"', $markup )
			: str_replace( '{"metadata"', '{' . $injected . '"metadata"', $markup );
		$this->assertNotSame( $markup, $marked );

		$clean = ( new Validator( new Vocabulary() ) )->clean( $marked );

		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );
	}

	public function test_a_button_on_a_toned_band_takes_the_inverse_pair(): void {
		$built   = self::render( self::plainHero() );
		$buttons = array_values( array_filter( parse_blocks( $built )[0]['innerBlocks'], static fn ( array $b ): bool => 'core/buttons' === $b['blockName'] ) );
		$button  = $buttons[0]['innerBlocks'][0];

		$this->assertSame( 'base', $button['attrs']['backgroundColor'] );
		$this->assertSame( 'contrast', $button['attrs']['textColor'] );
		$this->assertMatchesRegularExpression( '/<a class="wp-block-button__link[^"]*\bhas-contrast-color\b[^"]*\bhas-base-background-color\b/', $built );
		$clean = ( new Validator( new Vocabulary() ) )->clean( $built . "\n\n" . self::render( self::textSection() ) );
		$this->assertTrue( $clean['ok'], $clean['wp_error'] instanceof WP_Error ? $clean['wp_error']->get_error_message() : '' );
	}

	public function test_a_button_colour_the_model_wrote_is_refused_on_a_toned_band_and_a_plain_one(): void {
		$toned  = self::render( self::plainHero() );
		$custom = str_replace( '"backgroundColor":"base","textColor":"contrast"', '"backgroundColor":"accent-1","textColor":"contrast"', $toned );
		$this->assertNotSame( $toned, $custom );
		$clean = ( new Validator( new Vocabulary() ) )->clean( $custom );
		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );

		$plain  = self::render( self::plainHero( 'default' ) );
		$marked = str_replace( '<!-- wp:button -->', '<!-- wp:button {"backgroundColor":"base","textColor":"contrast"} -->', $plain );
		$this->assertNotSame( $plain, $marked );
		$clean = ( new Validator( new Vocabulary() ) )->clean( $marked );
		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );
	}

	public function test_the_tone_pair_does_not_excuse_a_raw_colour_beside_it(): void {
		$toned  = self::render( self::textSection( 'contrast' ) );
		$marked = str_replace( '"style":{"spacing"', '"style":{"color":{"text":"#ff0000"},"spacing"', $toned );
		$this->assertNotSame( $toned, $marked );

		$clean = ( new Validator( new Vocabulary() ) )->clean( $marked );

		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );
	}

	public function test_the_tone_pair_is_refused_without_its_classes_and_on_a_nested_block(): void {
		$toned = self::render( self::textSection( 'contrast' ) );

		// The editor would reject a pair whose classes are missing, so the validator does not wave it through.
		$no_classes = str_replace( ' has-text-color has-background', '', $toned );
		$this->assertNotSame( $toned, $no_classes );
		$clean = ( new Validator( new Vocabulary() ) )->clean( $no_classes );
		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );

		// The same pair on the heading inside the group is a model-written colour.
		$nested = str_replace( '<!-- wp:heading -->', '<!-- wp:heading {"backgroundColor":"contrast","textColor":"base"} -->', $toned );
		$this->assertNotSame( $toned, $nested );
		$clean = ( new Validator( new Vocabulary() ) )->clean( $nested );
		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );
	}

	public function test_the_tone_pair_is_refused_on_a_top_level_block_that_is_not_a_curated_group(): void {
		$cover  = self::render( LayoutsTest::outline()[0] );
		$marked = str_replace( '{"metadata"', '{"backgroundColor":"contrast","textColor":"base","metadata"', $cover );
		$this->assertNotSame( $cover, $marked );

		$clean = ( new Validator( new Vocabulary() ) )->clean( $marked );

		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );
	}

	public function test_a_pair_that_is_not_the_active_palettes_is_refused(): void {
		$toned = self::render( self::textSection( 'contrast' ) );
		self::palette( self::OLLIE_PALETTE );

		// `contrast`/`base` is TT25's pair; on Ollie the resolved one is `main`/`base`.
		$clean = ( new Validator( new Vocabulary() ) )->clean( $toned );

		$this->assertFalse( $clean['ok'] );
		$this->assertSame( 'decorative_color', $clean['wp_error']->get_error_code() );
	}

	// --- Page shape ---------------------------------------------------------

	public function test_two_adjacent_sections_with_the_same_tone_are_refused(): void {
		$error = Tone::pageCheck( array( 'contrast', 'contrast', 'default' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'layout_tone_adjacent', $error->get_error_code() );
		$this->assertStringContainsString( 'Sections 1 and 2', $error->get_error_message() );
		$this->assertStringContainsString( 'contrast', $error->get_error_message() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	public function test_different_or_separated_tones_are_fine(): void {
		$this->assertNull( Tone::pageCheck( array( 'contrast', 'accent', 'default' ) ) );
		$this->assertNull( Tone::pageCheck( array( 'contrast', 'default', 'contrast' ) ) );
		$this->assertNull( Tone::pageCheck( array( 'default', 'default', 'default', 'default' ) ) );
	}

	public function test_more_than_two_toned_sections_are_refused(): void {
		$error = Tone::pageCheck( array( 'contrast', 'default', 'accent', 'default', 'contrast' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'layout_tone_count', $error->get_error_code() );
		$this->assertStringContainsString( 'Sections 1, 3 and 5', $error->get_error_message() );
	}

	public function test_the_tone_an_applied_section_carries_is_read_back_from_its_markup(): void {
		$this->assertSame( 'contrast', Tone::of( self::render( self::textSection( 'contrast' ) ) ) );
		$this->assertSame( 'accent', Tone::of( self::render( self::textSection( 'accent' ) ) ) );
		$this->assertSame( 'default', Tone::of( self::render( self::textSection() ) ) );
		$this->assertSame( 'contrast', Tone::of( self::render( self::plainHero() ) ), 'the hero default counts' );
		$this->assertSame( 'default', Tone::of( self::render( LayoutsTest::outline()[0] ) ), 'a cover hero has none' );
	}

	// --- Fixtures -------------------------------------------------------------

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
			'image'   => array(
				'url' => 'http://localhost:8897/wp-content/uploads/2026/09/runner-knee.webp',
				'alt' => 'A physiotherapist checks a runner\'s knee on the treatment table',
			),
		);
	}

	/**
	 * Register real Ollie pattern files (tests/ThemePatterns/ollie) as the
	 * active theme's patterns.
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

	public function test_hero_default_yields_only_where_it_would_break_the_page_rule(): void {
		$hero = array( 'layout' => 'hero' );
		$text = array( 'layout' => 'text' );

		$beside = Tone::withHeroDefaultYielding( array( $hero, array( 'tone' => 'contrast' ) + $text ) );
		$this->assertSame( 'default', $beside[0]['tone'] );

		$two_asked = Tone::withHeroDefaultYielding( array( $hero, $text, array( 'tone' => 'accent' ) + $text, $text, array( 'tone' => 'contrast' ) + $text ) );
		$this->assertSame( 'default', $two_asked[0]['tone'] );

		$free = Tone::withHeroDefaultYielding( array( $hero, array( 'tone' => 'accent' ) + $text ) );
		$this->assertArrayNotHasKey( 'tone', $free[0] );

		$asked = Tone::withHeroDefaultYielding( array( array( 'tone' => 'contrast' ) + $hero, array( 'tone' => 'contrast' ) + $text ) );
		$this->assertSame( 'contrast', $asked[0]['tone'] );
	}
}
