<?php
/**
 * Rewrites the preset slugs in SenroFlux's curated markup to ones the active
 * theme defines (0.3 quality, D4), so a curated section keeps its padding and
 * its overlay on a theme that does not ship Core's numeric spacing scale.
 *
 * Runs on the markup a layout builds, before {@see Validator::clean()}; the
 * validator and {@see BlockShells} accept any preset slug, so neither changes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Pages;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class PresetAdapter {

	/**
	 * Core's default spacing scale. The curated patterns use `50` and `60`,
	 * positions 4 and 5 of these seven.
	 */
	private const CORE_SPACING_SCALE = array( '20', '30', '40', '50', '60', '70', '80' );

	/**
	 * Adapt curated markup to the active theme's presets.
	 */
	public static function adapt( string $markup ): string {
		return self::adaptOverlay( self::adaptSpacing( $markup ) );
	}

	/**
	 * A Core scale slug the theme defines stays. One it does not is replaced by
	 * the theme's size at the same relative position in its own list: with
	 * seven sizes, `50` and `60` become the fourth and fifth.
	 */
	private static function adaptSpacing( string $markup ): string {
		$sizes = self::presets( 'spacing', 'spacingSizes', 'defaultSpacingSizes' );
		if ( array() === $sizes ) {
			return $markup;
		}

		$defined = array_column( $sizes['all'], 'slug' );
		$own     = array_values( array_column( $sizes['own'], 'slug' ) );
		$last    = count( self::CORE_SPACING_SCALE ) - 1;

		return (string) preg_replace_callback(
			'/(var:preset\|spacing\||--wp--preset--spacing--)([0-9]+)\b/',
			static function ( array $found ) use ( $defined, $own, $last ): string {
				$rank = array_search( $found[2], self::CORE_SPACING_SCALE, true );
				if ( false === $rank || in_array( $found[2], $defined, true ) || array() === $own ) {
					return $found[0];
				}

				return $found[1] . $own[ (int) round( $rank / $last * ( count( $own ) - 1 ) ) ];
			},
			$markup
		);
	}

	/**
	 * `core/cover`'s overlay becomes the palette's darkest colour (D3b's rule).
	 * A palette with no parseable hex colour leaves the markup alone.
	 */
	private static function adaptOverlay( string $markup ): string {
		$slug = self::darkestPaletteSlug();
		if ( null === $slug ) {
			return $markup;
		}

		$markup = (string) preg_replace( '/"overlayColor":"[a-z0-9-]+"/', '"overlayColor":"' . $slug . '"', $markup );

		return (string) preg_replace( '/(wp-block-cover__background )has-[a-z0-9-]+-background-color/', '${1}has-' . $slug . '-background-color', $markup );
	}

	/**
	 * The palette slug with the lowest WCAG relative luminance, among entries
	 * whose colour is a hex value (a `color-mix()` or `var()` entry can't be
	 * measured here).
	 */
	private static function darkestPaletteSlug(): ?string {
		$darkest = null;
		$lowest  = INF;
		foreach ( self::presets( 'color', 'palette', 'defaultPalette' )['all'] ?? array() as $entry ) {
			$luminance = self::luminance( (string) ( $entry['color'] ?? '' ) );
			if ( null !== $luminance && $luminance < $lowest ) {
				$lowest  = $luminance;
				$darkest = (string) $entry['slug'];
			}
		}

		return $darkest;
	}

	private static function luminance( string $color ): ?float {
		if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $color ), $match ) ) {
			return null;
		}

		$hex = 3 === strlen( $match[1] ) ? (string) preg_replace( '/(.)/', '$1$1', $match[1] ) : $match[1];
		$rgb = array_map(
			static function ( string $channel ): float {
				$value = hexdec( $channel ) / 255;

				return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( $hex, 2 )
		);

		return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
	}

	/**
	 * The preset entries the active theme can actually use, from
	 * `wp_get_global_settings()`. Core returns either a flat list of
	 * `{slug, ...}` entries or one list per origin (`default`, `theme`,
	 * `custom`); both are read. The `default` origin is Core's own list, which
	 * the theme has switched off when `$default_flag` (`defaultPalette`,
	 * `defaultSpacingSizes`, `defaultGradients`) is false: Core then doesn't
	 * print its CSS variables, so those slugs are not defined. `all` is every
	 * usable entry; `own` is the list a rank is measured in: the site's, else
	 * the theme's, else Core's.
	 *
	 * @param string $group        Settings group, e.g. `color`.
	 * @param string $key          Preset list, e.g. `palette`.
	 * @param string $default_flag The group's switch for Core's own list.
	 * @return array{all:list<array<string,mixed>>, own:list<array<string,mixed>>}|array{}
	 */
	public static function presets( string $group, string $key, string $default_flag ): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$value = (array) wp_get_global_settings( array( $group, $key ) );
		if ( array_is_list( $value ) ) {
			$lists = array( $value );
		} else {
			$core_on = false !== wp_get_global_settings( array( $group, $default_flag ) );
			$lists   = array_filter(
				array( $value['custom'] ?? null, $value['theme'] ?? null, $core_on ? ( $value['default'] ?? null ) : null ),
				'is_array'
			);
		}

		$all = array();
		$own = array();
		foreach ( $lists as $list ) {
			$entries = array_values(
				array_filter(
					$list,
					static fn ( $entry ): bool => is_array( $entry ) && '' !== (string) ( $entry['slug'] ?? '' )
				)
			);
			$all     = array_merge( $all, $entries );
			if ( array() === $own ) {
				$own = $entries;
			}
		}

		return array() === $all ? array() : array(
			'all' => $all,
			'own' => $own,
		);
	}
}
