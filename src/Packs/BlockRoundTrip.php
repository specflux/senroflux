<?php
/**
 * Comparison helpers for the parse → serialise round-trip the content validators run.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Normalises block markup for the round-trip compare and locates where it diverges.
 *
 * @internal
 */
final class BlockRoundTrip {

	private const EXCERPT_LENGTH = 120;

	/**
	 * Collapse whitespace and drop the explicit `core/` namespace from block
	 * comment delimiters: `serialize_blocks()` writes core blocks without it, so
	 * `<!-- wp:core/paragraph -->` and `<!-- wp:paragraph -->` are the same block.
	 * Other namespaces are left alone.
	 */
	public static function normalize( string $text ): string {
		$text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );

		return (string) preg_replace( '#(<!-- /?wp:)core/#', '$1', $text );
	}

	/**
	 * Whether the model's markup and its re-serialised form are the same block tree.
	 */
	public static function matches( string $content, string $reserialized ): bool {
		return self::normalize( $content ) === self::normalize( $reserialized );
	}

	/**
	 * A short excerpt of the model's markup around the first position where it
	 * differs from the re-serialised form.
	 */
	public static function divergence( string $content, string $reserialized ): string {
		$a   = self::normalize( $content );
		$b   = self::normalize( $reserialized );
		$max = min( strlen( $a ), strlen( $b ) );
		$pos = 0;
		while ( $pos < $max && $a[ $pos ] === $b[ $pos ] ) {
			++$pos;
		}

		$start = max( 0, $pos - 40 );

		return ( $start > 0 ? '…' : '' )
			. mb_strcut( $a, $start, self::EXCERPT_LENGTH, 'UTF-8' )
			. ( $start + self::EXCERPT_LENGTH < strlen( $a ) ? '…' : '' );
	}

	/**
	 * The sentence appended to an `invalid_markup` refusal.
	 */
	public static function hint( string $content, string $reserialized ): string {
		return sprintf(
			/* translators: %s: an excerpt of the submitted block markup. */
			__( 'The markup first differs from its round-trip near: "%s"', 'senroflux' ),
			self::divergence( $content, $reserialized )
		);
	}
}
