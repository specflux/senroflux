<?php
/**
 * Section tone (0.3 quality, D3b): a layout item's `tone` becomes a
 * `backgroundColor` + `textColor` preset pair that SenroFlux writes on a
 * curated section's top-level group. The model never writes the attributes.
 *
 * A palette's slugs differ per theme, so a tone is resolved by luminance, not
 * by name: `contrast` is the palette's darkest colour under its lightest, and
 * `accent` is its most saturated mid-luminance colour under whichever of the
 * darkest or lightest reads on it. A pair under WCAG AA (4.5:1) is not used;
 * the section then renders in the default colours.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class Tone {

	public const DEFAULT  = 'default';
	public const CONTRAST = 'contrast';
	public const ACCENT   = 'accent';

	/** The values a layout's `tone` takes. */
	public const NAMES = array( self::DEFAULT, self::CONTRAST, self::ACCENT );

	/** The lowest contrast ratio WCAG 2 accepts for body text (AA). */
	private const MIN_RATIO = 4.5;

	/** An accent is "mid" luminance: not near-black, not near-white. */
	private const ACCENT_LUMINANCE = array( 0.05, 0.5 );

	/** Most sections a page may tone. */
	public const MAX_PER_PAGE = 2;

	/**
	 * The preset slugs a tone resolves to on the active palette, or null for
	 * `default`, an unknown tone, or a palette with no pair at AA.
	 *
	 * @return array{background:string,text:string}|null
	 */
	public static function pair( string $tone ): ?array {
		if ( self::CONTRAST !== $tone && self::ACCENT !== $tone ) {
			return null;
		}

		$colours = self::measuredPalette();
		if ( count( $colours ) < 2 ) {
			return null;
		}

		$darkest  = $colours[0];
		$lightest = $colours[0];
		foreach ( $colours as $colour ) {
			$darkest  = $colour['luminance'] < $darkest['luminance'] ? $colour : $darkest;
			$lightest = $colour['luminance'] > $lightest['luminance'] ? $colour : $lightest;
		}
		if ( $darkest['slug'] === $lightest['slug'] ) {
			return null;
		}

		if ( self::CONTRAST === $tone ) {
			return self::ratio( $darkest, $lightest ) >= self::MIN_RATIO
				? array(
					'background' => $darkest['slug'],
					'text'       => $lightest['slug'],
				)
				: null;
		}

		// Most saturated first; the first one a text colour reaches AA on wins.
		$candidates = array_filter(
			$colours,
			static fn ( array $colour ): bool => $colour['luminance'] >= self::ACCENT_LUMINANCE[0] && $colour['luminance'] <= self::ACCENT_LUMINANCE[1]
		);
		usort( $candidates, static fn ( array $a, array $b ): int => $b['chroma'] <=> $a['chroma'] );
		foreach ( $candidates as $candidate ) {
			$ends = array( $darkest, $lightest );
			usort( $ends, static fn ( array $a, array $b ): int => self::ratio( $candidate, $b ) <=> self::ratio( $candidate, $a ) );
			if ( self::ratio( $candidate, $ends[0] ) >= self::MIN_RATIO ) {
				return array(
					'background' => $candidate['slug'],
					'text'       => $ends[0]['slug'],
				);
			}
		}

		return null;
	}

	/**
	 * Write `$tone` on the top-level group of `$markup`: the attribute pair and
	 * the classes the editor's own save() adds for it. Anything else (a cover,
	 * a media-text, `default`, a tone that has no pair) comes back unchanged.
	 */
	public static function apply( string $markup, string $tone ): string {
		$pair = self::pair( $tone );
		if ( null === $pair ) {
			return $markup;
		}

		$blocks = parse_blocks( $markup );
		foreach ( $blocks as $offset => $block ) {
			if ( null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			if ( 'core/group' !== $block['blockName'] ) {
				return $markup;
			}

			$blocks[ $offset ]['attrs']['backgroundColor'] = $pair['background'];
			$blocks[ $offset ]['attrs']['textColor']       = $pair['text'];

			$classes = ' has-' . $pair['text'] . '-color has-' . $pair['background'] . '-background-color has-text-color has-background';
			$add     = static fn ( $chunk ) => is_string( $chunk ) ? (string) preg_replace( '/^(\s*<div class="[^"]*)"/', '$1' . $classes . '"', $chunk, 1 ) : $chunk;

			$blocks[ $offset ]['innerHTML']    = $add( $block['innerHTML'] ?? '' );
			$blocks[ $offset ]['innerContent'] = array_map( $add, $block['innerContent'] ?? array() );
			if ( 'full' !== ( $block['attrs']['align'] ?? null ) ) {
				$blocks[ $offset ] = self::withSidePadding( $blocks[ $offset ] );
			}
			$blocks[ $offset ]['innerBlocks'] = array_map( static fn ( array $child ): array => self::invertButtons( $child, $pair ), $blocks[ $offset ]['innerBlocks'] ?? array() );

			/** @var list<array{blockName: string|null, attrs: array<string,mixed>, innerBlocks: list<array<string,mixed>>, innerHTML: string, innerContent: array<string,mixed>}> $blocks */
			return serialize_blocks( $blocks );
		}

		return $markup;
	}

	/**
	 * A band that is not full width needs side padding as deep as its top, or
	 * its text sits against the coloured edge. Skipped when the group has no
	 * top padding to copy.
	 *
	 * @param array<string,mixed> $block A parsed group.
	 * @return array<string,mixed>
	 */
	private static function withSidePadding( array $block ): array {
		$size = $block['attrs']['style']['spacing']['padding']['top'] ?? null;
		if ( ! is_string( $size ) || ! preg_match( '/^var:preset\|spacing\|([a-z0-9-]+)$/', $size, $slug ) ) {
			return $block;
		}

		$block['attrs']['style']['spacing']['padding']['left']  = $size;
		$block['attrs']['style']['spacing']['padding']['right'] = $size;

		$var = 'var(--wp--preset--spacing--' . $slug[1] . ')';
		$add = static fn ( $chunk ) => is_string( $chunk ) ? (string) preg_replace( '/^(\s*<div class="[^"]*" style=")([^"]*)"/', '$1$2;padding-left:' . $var . ';padding-right:' . $var . '"', $chunk, 1 ) : $chunk;

		$block['innerHTML']    = $add( $block['innerHTML'] ?? '' );
		$block['innerContent'] = array_map( $add, $block['innerContent'] ?? array() );

		return $block;
	}

	/**
	 * A button on a toned band takes the pair the other way round (the band's
	 * text colour as its fill), because the theme's own button colour is
	 * usually the band's background and would vanish into it.
	 *
	 * @param array<string,mixed>            $block A parsed block.
	 * @param array{background:string,text:string} $pair  The group's pair.
	 * @return array<string,mixed>
	 */
	private static function invertButtons( array $block, array $pair ): array {
		if ( 'core/button' === ( $block['blockName'] ?? null ) ) {
			$block['attrs']['backgroundColor'] = $pair['text'];
			$block['attrs']['textColor']       = $pair['background'];

			$classes = ' has-' . $pair['background'] . '-color has-' . $pair['text'] . '-background-color has-text-color has-background';
			$add     = static fn ( $chunk ) => is_string( $chunk ) ? (string) preg_replace( '/(<a class="wp-block-button__link)/', '$1' . $classes, $chunk, 1 ) : $chunk;

			$block['innerHTML']    = $add( $block['innerHTML'] ?? '' );
			$block['innerContent'] = array_map( $add, $block['innerContent'] ?? array() );
		}

		$block['innerBlocks'] = array_map( static fn ( array $child ): array => self::invertButtons( $child, $pair ), $block['innerBlocks'] ?? array() );

		return $block;
	}

	/**
	 * `$block` without the colour attributes {@see apply()} wrote: its own
	 * pair and, on each button, the inverse. Only what matches exactly (both
	 * attributes and their classes) is removed; any other colour stays for the
	 * validator to refuse. A block that carries no tone comes back unchanged.
	 *
	 * @param array<string,mixed> $block A parsed top-level block.
	 * @return array<string,mixed>
	 */
	public static function withoutAppliedColors( array $block ): array {
		$tone = self::appliedTone( $block );
		$pair = null === $tone ? null : self::pair( $tone );
		if ( null === $pair ) {
			return $block;
		}

		unset( $block['attrs']['backgroundColor'], $block['attrs']['textColor'] );
		// The side padding {@see withSidePadding()} adds is the same size as the top's.
		$padding = $block['attrs']['style']['spacing']['padding'] ?? array();
		$var     = is_string( $padding['top'] ?? null ) ? 'var(--wp--preset--spacing--' . substr( (string) strrchr( $padding['top'], '|' ), 1 ) . ')' : '';
		$html    = (string) ( $block['innerHTML'] ?? '' );
		if ( is_array( $padding ) && '' !== $var && ( $padding['left'] ?? null ) === $padding['top'] && ( $padding['right'] ?? null ) === $padding['top']
			&& str_contains( $html, 'padding-left:' . $var ) && str_contains( $html, 'padding-right:' . $var )
		) {
			unset( $block['attrs']['style']['spacing']['padding']['left'], $block['attrs']['style']['spacing']['padding']['right'] );
			$drop                  = static fn ( $chunk ) => is_string( $chunk ) ? (string) preg_replace( '/;?padding-(?:left|right):[^;"]*/', '', $chunk ) : $chunk;
			$block['innerHTML']    = $drop( $block['innerHTML'] ?? '' );
			$block['innerContent'] = array_map( $drop, $block['innerContent'] ?? array() );
		}
		$block['innerBlocks'] = array_map( static fn ( array $child ): array => self::withoutButtonColors( $child, $pair ), $block['innerBlocks'] ?? array() );

		return $block;
	}

	/**
	 * @param array<string,mixed>                  $block A parsed block.
	 * @param array{background:string,text:string} $pair  The band's pair.
	 * @return array<string,mixed>
	 */
	private static function withoutButtonColors( array $block, array $pair ): array {
		if ( 'core/button' === ( $block['blockName'] ?? null )
			&& ( $block['attrs']['backgroundColor'] ?? null ) === $pair['text']
			&& ( $block['attrs']['textColor'] ?? null ) === $pair['background']
		) {
			$wanted = array( 'has-' . $pair['background'] . '-color', 'has-' . $pair['text'] . '-background-color', 'has-text-color', 'has-background' );
			$class  = preg_match( '/<a class="([^"]*)"/', (string) ( $block['innerHTML'] ?? '' ), $found ) ? explode( ' ', $found[1] ) : array();
			if ( array() === array_diff( $wanted, $class ) ) {
				unset( $block['attrs']['backgroundColor'], $block['attrs']['textColor'] );
			}
		}

		$block['innerBlocks'] = array_map( static fn ( array $child ): array => self::withoutButtonColors( $child, $pair ), $block['innerBlocks'] ?? array() );

		return $block;
	}

	/**
	 * The tone a section's markup carries: `contrast` or `accent` when its
	 * first block is a curated group wearing that tone's pair on the active
	 * palette, else `default`. A theme pattern's shipped colour is not a tone.
	 */
	public static function of( string $markup ): string {
		foreach ( parse_blocks( $markup ) as $block ) {
			if ( null === ( $block['blockName'] ?? null ) ) {
				continue;
			}

			// A curated section is named `senroflux/<slug>`; a theme pattern's is
			// `senroflux/<theme>/<slug>`, and its colours are the theme's own.
			$curated = 1 === preg_match( '#^senroflux/[^/]+$#', (string) ( $block['attrs']['metadata']['name'] ?? '' ) );

			return $curated ? ( self::appliedTone( $block ) ?? self::DEFAULT ) : self::DEFAULT;
		}

		return self::DEFAULT;
	}

	/**
	 * Whether `$block` is a top-level group carrying a tone's whole pair, as
	 * {@see apply()} writes it: both attributes and all four classes. The
	 * validator uses this to tell SenroFlux's colour from one a model wrote.
	 *
	 * @param array<string,mixed> $block A parsed top-level block.
	 */
	public static function isApplied( array $block ): bool {
		return null !== self::appliedTone( $block );
	}

	/**
	 * @param array<string,mixed> $block A parsed top-level block.
	 */
	private static function appliedTone( array $block ): ?string {
		if ( 'core/group' !== ( $block['blockName'] ?? null ) || ! is_array( $block['attrs'] ?? null ) ) {
			return null;
		}

		$attrs = $block['attrs'];
		foreach ( array( self::CONTRAST, self::ACCENT ) as $tone ) {
			$pair = self::pair( $tone );
			if ( null === $pair || ( $attrs['backgroundColor'] ?? null ) !== $pair['background'] || ( $attrs['textColor'] ?? null ) !== $pair['text'] ) {
				continue;
			}

			$wanted = array( 'has-' . $pair['text'] . '-color', 'has-' . $pair['background'] . '-background-color', 'has-text-color', 'has-background' );
			$class  = preg_match( '/^\s*<div class="([^"]*)"/', (string) ( $block['innerHTML'] ?? '' ), $found ) ? explode( ' ', $found[1] ) : array();

			return array() === array_diff( $wanted, $class ) ? $tone : null;
		}

		return null;
	}

	/**
	 * `$sections` with an untoned hero set to `default` wherever its
	 * `contrast` default would break {@see pageCheck()}: beside a section the
	 * model toned `contrast`, or on a page the model already toned
	 * {@see MAX_PER_PAGE} times. The default is SenroFlux's choice, so it
	 * must never be the reason a page is refused.
	 *
	 * @param list<mixed> $sections The call's `sections` input.
	 * @return list<mixed>
	 */
	public static function withHeroDefaultYielding( array $sections ): array {
		$sections = array_values( $sections );
		$asked    = array_map(
			static fn ( $section ): ?string => is_array( $section ) && is_string( $section['tone'] ?? null ) ? $section['tone'] : null,
			$sections
		);
		$toned    = count( array_intersect( $asked, array( self::CONTRAST, self::ACCENT ) ) );

		foreach ( $sections as $i => $section ) {
			if ( ! is_array( $section ) || 'hero' !== ( $section['layout'] ?? null ) || array_key_exists( 'tone', $section ) ) {
				continue;
			}
			if ( $toned >= self::MAX_PER_PAGE || self::CONTRAST === ( $asked[ $i - 1 ] ?? null ) || self::CONTRAST === ( $asked[ $i + 1 ] ?? null ) ) {
				$sections[ $i ]['tone'] = self::DEFAULT;
			}
		}

		return array_values( $sections );
	}

	/**
	 * The page rule over each section's effective tone, in page order
	 * (`default` for a section with none): no two ADJACENT sections share a
	 * non-default tone, and at most {@see MAX_PER_PAGE} sections are toned.
	 * Both are refusals the model can act on, like the `text` wall check; the
	 * tone itself is cosmetic, but a rule that quietly rewrote what the model
	 * asked for would leave it believing the page is as it described it.
	 *
	 * @param list<string> $tones Tone per section.
	 */
	public static function pageCheck( array $tones ): ?WP_Error {
		$tones = array_values( $tones );
		foreach ( $tones as $i => $tone ) {
			if ( self::DEFAULT !== $tone && ( $tones[ $i + 1 ] ?? null ) === $tone ) {
				return new WP_Error(
					'layout_tone_adjacent',
					sprintf(
						/* translators: 1: first section number, 2: second section number, 3: tone name. */
						__( 'Sections %1$d and %2$d are side by side and both `%3$s`. Give one of them another `tone`, or `default`.', 'senroflux' ),
						$i + 1,
						$i + 2,
						$tone
					),
					array( 'status' => 400 )
				);
			}
		}

		$toned = array_keys( array_filter( $tones, static fn ( string $tone ): bool => self::DEFAULT !== $tone ) );
		if ( count( $toned ) > self::MAX_PER_PAGE ) {
			$numbers = array_map( static fn ( int $i ): string => (string) ( $i + 1 ), $toned );
			$last    = array_pop( $numbers );

			return new WP_Error(
				'layout_tone_count',
				sprintf(
					/* translators: 1: section numbers, 2: how many sections a page may tone. */
					__( 'Sections %1$s all have a `tone`; a page takes at most %2$d. Set `tone` to `default` on the others.', 'senroflux' ),
					implode( ', ', $numbers ) . ' and ' . $last,
					self::MAX_PER_PAGE
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * The active palette's measurable colours: hex entries only (a `var()` or
	 * `color-mix()` entry can't be measured here), with slugs safe to use in a
	 * class name.
	 *
	 * @return list<array{slug:string,luminance:float,chroma:float}>
	 */
	private static function measuredPalette(): array {
		$out = array();
		foreach ( PresetAdapter::presets( 'color', 'palette', 'defaultPalette' )['all'] ?? array() as $entry ) {
			$slug      = (string) ( $entry['slug'] ?? '' );
			$color     = (string) ( $entry['color'] ?? '' );
			$luminance = PresetAdapter::luminance( $color );
			$channels  = PresetAdapter::channels( $color );
			if ( null === $luminance || null === $channels || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
				continue;
			}

			$out[] = array(
				'slug'      => $slug,
				'luminance' => $luminance,
				'chroma'    => max( $channels ) - min( $channels ),
			);
		}

		return $out;
	}

	/**
	 * WCAG contrast ratio of two measured colours.
	 *
	 * @param array{luminance:float} $a A colour.
	 * @param array{luminance:float} $b Another.
	 */
	private static function ratio( array $a, array $b ): float {
		return ( max( $a['luminance'], $b['luminance'] ) + 0.05 ) / ( min( $a['luminance'], $b['luminance'] ) + 0.05 );
	}
}
