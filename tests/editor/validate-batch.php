<?php
/**
 * Reads a JSON array of markup strings on STDIN and prints, for each, the
 * pages-pack verdict: `true` when Validator::clean() accepts it, else the
 * refusal code. Used by the editor-parity differential test.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

require dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/stubs/blocks.php';

use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- STDIN of a dev-only CLI script.
$senroflux_inputs    = json_decode( (string) file_get_contents( 'php://stdin' ), true );
$senroflux_validator = new Validator( new Vocabulary() );
$senroflux_verdicts  = array();
foreach ( (array) $senroflux_inputs as $senroflux_markup ) {
	$senroflux_result     = $senroflux_validator->clean( (string) $senroflux_markup );
	$senroflux_verdicts[] = $senroflux_result['ok']
		? array(
			'ok'      => true,
			'content' => $senroflux_result['content'],
		)
		: array(
			'ok'   => false,
			'code' => $senroflux_result['wp_error']?->get_error_code(),
		);
}

echo wp_json_encode( $senroflux_verdicts );
