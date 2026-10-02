<?php
/**
 * Report rows for the WooCommerce objects a commerce run writes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use Specflux\SenroFlux\Run\Report;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Resolves the commerce pack's qualified ids ({@see CommercePack::objectIdPrefix()})
 * to the shape {@see Report} needs. Every WooCommerce call is guarded, so a
 * site where Woo went away mid-run gets an `unknown` row rather than a fatal.
 * Products need no entry here: their id is a bare post id.
 */
final class ReportLookup {

	public const COUPON_PREFIX   = 'coupon:';
	public const ORDER_PREFIX    = 'order:';
	public const ZONE_PREFIX     = 'zone:';
	public const TAX_RATE_PREFIX = 'taxrate:';
	public const PAGE_PREFIX     = 'page:';

	/**
	 * The lookup for `$object_id` when it carries one of this pack's
	 * prefixes, else null (the id is not ours).
	 *
	 * @param string $object_id The tracked object id.
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}|null
	 */
	public static function resolve( string $object_id ): ?array {
		foreach ( array( self::COUPON_PREFIX, self::PAGE_PREFIX ) as $post_prefix ) {
			if ( str_starts_with( $object_id, $post_prefix ) ) {
				// A coupon and a saved report page are plain posts under a prefix.
				return ( Report::wpPostLookup() )( substr( $object_id, strlen( $post_prefix ) ) );
			}
		}

		if ( str_starts_with( $object_id, self::ORDER_PREFIX ) ) {
			return self::order( (int) substr( $object_id, strlen( self::ORDER_PREFIX ) ) );
		}

		if ( str_starts_with( $object_id, self::ZONE_PREFIX ) ) {
			return self::zone( (int) substr( $object_id, strlen( self::ZONE_PREFIX ) ) );
		}

		if ( str_starts_with( $object_id, self::TAX_RATE_PREFIX ) ) {
			return self::taxRate( (int) substr( $object_id, strlen( self::TAX_RATE_PREFIX ) ) );
		}

		return null;
	}

	/**
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	private static function order( int $order_id ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! is_object( $order ) ) {
			return self::unknown();
		}

		$edit = method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : '';

		return array(
			'object_type' => 'shop_order',
			/* translators: %s: the order's number. */
			'title'       => sprintf( __( 'Order #%s', 'senroflux' ), method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order_id ),
			'status'      => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
			'edit_url'    => ( is_string( $edit ) && '' !== $edit ) ? $edit : null,
			'preview_url' => null,
		);
	}

	/**
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	private static function zone( int $zone_id ): array {
		if ( ! class_exists( '\WC_Shipping_Zone' ) ) {
			return self::unknown();
		}

		$zone = new \WC_Shipping_Zone( $zone_id );
		if ( $zone_id <= 0 || (int) $zone->get_id() !== $zone_id ) {
			return self::unknown();
		}

		return array(
			'object_type' => 'shipping_zone',
			'title'       => (string) $zone->get_zone_name(),
			'status'      => '',
			'edit_url'    => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wc-settings&tab=shipping&zone_id=' . $zone_id ) : null,
			'preview_url' => null,
		);
	}

	/**
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	private static function taxRate( int $rate_id ): array {
		// phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- WooCommerce's real WC_Tax method name.
		$rate = ( class_exists( '\WC_Tax' ) && $rate_id > 0 ) ? \WC_Tax::_get_tax_rate( $rate_id ) : array();
		if ( ! is_array( $rate ) || array() === $rate ) {
			return self::unknown();
		}

		return array(
			'object_type' => 'tax_rate',
			'title'       => (string) ( $rate['tax_rate_name'] ?? '' ),
			'status'      => '',
			'edit_url'    => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=wc-settings&tab=tax&section=standard' ) : null,
			'preview_url' => null,
		);
	}

	/**
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	private static function unknown(): array {
		return array(
			'object_type' => 'unknown',
			'title'       => '',
			'status'      => '',
			'edit_url'    => null,
			'preview_url' => null,
		);
	}
}
