<?php
/**
 * Shipping-zone location parsing shared by `shipping-zone-save` and its
 * approval card, so both read a location code the same way.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Normalises one `{code,type?}` location (or a bare code string) into the
 * `code`/`type` pair `WC_Shipping_Zone::add_location()` stores.
 */
final class ShippingZoneInput {

	/** The location types WooCommerce zones hold. */
	public const LOCATION_TYPES = array( 'country', 'state', 'continent', 'postcode' );

	/**
	 * Resolve a location to its code and type. Syntax only: whether a country
	 * or state actually exists is checked against WooCommerce by the caller.
	 *
	 * A `type:` prefix on the code (`country:CA`, `state:CA:ON`) is accepted
	 * and stripped; an explicit type that disagrees with it is refused.
	 *
	 * @param mixed $location `{code,type?}` array or bare code string.
	 * @return array{code:string,type:string}|WP_Error
	 */
	public static function location( mixed $location ): array|WP_Error {
		$code = is_array( $location ) ? ( $location['code'] ?? null ) : $location;
		$type = is_array( $location ) ? ( $location['type'] ?? null ) : null;

		if ( ! is_string( $code ) || '' === trim( $code ) ) {
			return self::refuse( __( 'Every location needs a code, such as "CA", "CA:ON", "NA" or "90210".', 'senroflux' ) );
		}
		if ( null !== $type && ( ! is_string( $type ) || ! in_array( $type, self::LOCATION_TYPES, true ) ) ) {
			return self::refuse( __( 'A location type must be one of country, state, continent or postcode.', 'senroflux' ) );
		}

		$code = trim( $code );
		if ( 1 === preg_match( '/^(country|state|continent|postcode):(.+)$/i', $code, $m ) ) {
			$prefix = strtolower( $m[1] );
			if ( null !== $type && $type !== $prefix ) {
				return self::refuse(
					sprintf(
						/* translators: 1: location code as given, 2: explicit type. */
						__( 'Location "%1$s" conflicts with its type "%2$s".', 'senroflux' ),
						$code,
						$type
					)
				);
			}
			$type = $prefix;
			$code = trim( $m[2] );
		}

		$type ??= 'country';
		$code   = strtoupper( $code );

		if ( 'state' === $type && 1 !== preg_match( '/^[A-Z0-9]+:[A-Z0-9-]+$/', $code ) ) {
			return self::refuse(
				sprintf(
					/* translators: %s: state location code as given. */
					__( 'State location "%s" must look like "CA:ON" (country, colon, state).', 'senroflux' ),
					$code
				)
			);
		}

		return array(
			'code' => $code,
			'type' => $type,
		);
	}

	private static function refuse( string $message ): WP_Error {
		return new WP_Error( 'invalid_input', $message, array( 'status' => 400 ) );
	}
}
