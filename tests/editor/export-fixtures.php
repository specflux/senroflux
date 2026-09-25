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

use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;

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

echo wp_json_encode(
	array(
		'patterns' => $senroflux_patterns,
		'page'     => $senroflux_clean['content'],
	)
);
