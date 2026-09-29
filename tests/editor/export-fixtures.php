<?php
/**
 * Prints the pages-pack markup the editor-parity check validates, as JSON:
 * every shipped pattern on its own, and a full page after Validator::clean()
 * (the exact content a create/update write persists).
 *
 * Run by `tests/editor/editor-parity.test.cjs`; not part of the PHPUnit suite.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/stubs/blocks.php';

use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use Specflux\SenroFlux\Tests\Packs\Pages\LayoutsTest;

$senroflux_vocabulary = new Vocabulary();
$senroflux_patterns   = array();
foreach ( $senroflux_vocabulary->all() as $senroflux_pattern ) {
	$senroflux_patterns[ (string) $senroflux_pattern['slug'] ] = (string) $senroflux_pattern['markup'];
}

$senroflux_page  = implode(
	"\n\n",
	array_map(
		static fn ( string $slug ): string => $senroflux_patterns[ $slug ],
		array( 'hero', 'text-section', 'feature-grid', 'pricing-table', 'faq', 'testimonials', 'cta' )
	)
);
$senroflux_clean = ( new Validator( $senroflux_vocabulary ) )->clean( $senroflux_page );
if ( ! $senroflux_clean['ok'] ) {
	throw new RuntimeException( 'The full shipped page was refused: ' . esc_html( (string) $senroflux_clean['wp_error']?->get_error_code() ) );
}

require_once dirname( __DIR__ ) . '/Packs/Pages/LayoutsTest.php';
LayoutsTest::registerThemeFixtures();
$senroflux_layout_parts = array();
foreach ( LayoutsTest::outline() as $senroflux_index => $senroflux_section ) {
	$senroflux_built = Layouts::render( $senroflux_section, $senroflux_index, $senroflux_vocabulary );
	if ( ! is_string( $senroflux_built ) ) {
		throw new RuntimeException( 'A layout was refused: ' . esc_html( $senroflux_built->get_error_message() ) );
	}
	$senroflux_layout_parts[] = $senroflux_built;
}
$senroflux_layouts = ( new Validator( $senroflux_vocabulary ) )->clean( implode( "\n\n", $senroflux_layout_parts ) );
if ( ! $senroflux_layouts['ok'] ) {
	throw new RuntimeException( 'The layouts page was refused: ' . esc_html( (string) $senroflux_layouts['wp_error']?->get_error_message() ) );
}

// D3a (S4): a real Ollie pattern (`tests/ThemePatterns/ollie/numbers-stacked.php`,
// GPL, same licence as this plugin), filled through the plugin's own fill
// path and joined to the curated hero so `checkPageShape()`'s `hero_first`
// passes, must open in the editor with its shipped `backgroundColor`/
// `textColor` still exactly as the theme shipped them.
require_once dirname( __DIR__ ) . '/stubs/theme-patterns.php';

$senroflux_ollie_path = dirname( __DIR__ ) . '/ThemePatterns/ollie/numbers-stacked.php';
ob_start();
require $senroflux_ollie_path;
$senroflux_ollie_content = trim( (string) ob_get_clean() );

$GLOBALS['senroflux_test_theme_patterns']  = array(
	array(
		'name'        => 'ollie/numbers-stacked',
		'title'       => 'Numbers Stacked',
		'description' => 'Display impressive numbers with a short description',
		'content'     => $senroflux_ollie_content,
		'filePath'    => $senroflux_ollie_path,
		'categories'  => array( 'ollie/features' ),
	),
);
$GLOBALS['senroflux_test_stylesheet_dir']  = dirname( __DIR__ ) . '/ThemePatterns/ollie';
$GLOBALS['senroflux_test_template_dir']    = dirname( __DIR__ ) . '/ThemePatterns/ollie';
$GLOBALS['senroflux_test_global_settings'] = array(
	'color' => array(
		'palette'   => array(
			array( 'slug' => 'primary' ),
			array( 'slug' => 'primary-accent' ),
			array( 'slug' => 'primary-alt' ),
			array( 'slug' => 'primary-alt-accent' ),
			array( 'slug' => 'main' ),
			array( 'slug' => 'main-accent' ),
			array( 'slug' => 'base' ),
			array( 'slug' => 'secondary' ),
			array( 'slug' => 'tertiary' ),
			array( 'slug' => 'border-light' ),
			array( 'slug' => 'border-dark' ),
		),
		'gradients' => array(),
	),
);
ThemePatterns::resetCache();

$senroflux_ollie_vocabulary = new Vocabulary();
$senroflux_ollie_slots      = ThemePatterns::textSlots( $senroflux_ollie_content );
$senroflux_ollie_values     = array();
foreach ( $senroflux_ollie_slots as $senroflux_ollie_slot ) {
	$senroflux_ollie_values[ $senroflux_ollie_slot['index'] ] = 'New short copy';
}

$senroflux_ollie_filled = ThemePatterns::fill( $senroflux_ollie_content, $senroflux_ollie_values );
if ( ! $senroflux_ollie_filled['ok'] ) {
	throw new RuntimeException( 'The Ollie pattern fill was refused: ' . esc_html( (string) $senroflux_ollie_filled['wp_error']?->get_error_code() ) );
}

$senroflux_ollie_page = ( new Validator( $senroflux_ollie_vocabulary ) )->clean(
	$senroflux_patterns['hero'] . "\n\n" . $senroflux_ollie_filled['content']
);
if ( ! $senroflux_ollie_page['ok'] ) {
	throw new RuntimeException( 'The Ollie pattern page was refused: ' . esc_html( (string) $senroflux_ollie_page['wp_error']?->get_error_code() ) );
}

echo wp_json_encode(
	array(
		'patterns' => $senroflux_patterns,
		'page'     => $senroflux_clean['content'],
		'layouts'  => $senroflux_layouts['content'],
		'ollie'    => $senroflux_ollie_page['content'],
	)
);
