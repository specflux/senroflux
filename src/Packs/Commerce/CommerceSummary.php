<?php
/**
 * The commerce pack's approval-summary builder (S19 build grade, AS-11's
 * filter reused for a third pack).
 *
 * TARGET REPO PATH: src/Packs/Commerce/CommerceSummary.php
 *
 * Hooks `agent_safety_approval_summary` (same seam as
 * {@see \Specflux\SenroFlux\Packs\Pages\PublishSummary}) for the commerce
 * pack's eight Tier-2 verbs and renders SERVER-READ state BESIDE the call's
 * own arguments, per SPEC-SENROFLUX-1.0.md S19's approval-cards list:
 *
 *   - price change: current regular/sale price (read from the product) vs proposed
 *   - product publish: title, preview link, price, stock
 *   - refund: order total, already refunded, remaining refundable (all read
 *     from the order), then amount/restock/reason and whether money moves
 *   - customer-visible note: the exact text and the recipient's billing email
 *   - coupon enable: code, discount, expiry, usage limit (read from the coupon)
 *   - shipping/tax: previous values beside proposed
 *   - report-save: title + "this page will be private"
 *
 * PROVENANCE RULE (same discipline as PublishSummary): every "current" value
 * is read SERVER-SIDE from the live WooCommerce object, never trusted from
 * the call's own arguments — an argument can claim anything. Agent-written
 * free text (a refund reason, a customer note) is rendered but LABELLED as
 * the agent's words and escaped; it is never treated as a fact about the
 * store.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the commerce approval summaries.
 */
final class CommerceSummary {

	/**
	 * The Agent Safety approval-summary filter (AS-11), shared with
	 * {@see \Specflux\SenroFlux\Packs\Pages\PublishSummary}.
	 */
	public const HOOK = 'agent_safety_approval_summary';

	/**
	 * The commerce pack verbs this builder enriches — every Tier-2 row of
	 * {@see CommercePack::verbMap()}.
	 *
	 * @var list<string>
	 */
	private const TIER2_VERBS = array(
		'commerce/price-change',
		'commerce/product-publish',
		'commerce/refund',
		'commerce/order-note-customer',
		'commerce/coupon-enable',
		'commerce/shipping-write',
		'commerce/tax-write',
		'commerce/report-save',
	);

	/**
	 * Wire the hook (call once, from the composition root).
	 */
	public static function boot(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( self::HOOK, array( self::class, 'filter' ), 10, 3 );
	}

	/**
	 * The hook callback. Non-Tier-2 commerce calls, and anything from
	 * another pack entirely, pass the summary through unchanged.
	 *
	 * Agent Safety hands the filter the ABILITY ID, so the Tier-2 test runs
	 * through the pack's own predicate first — a plain `'commerce/...' ===
	 * $verb` comparison here would never match a real approval row (see
	 * PublishSummary's identical reasoning).
	 *
	 * @param mixed               $summary The summary so far.
	 * @param string              $verb    The Agent Safety verb (an ability id).
	 * @param array<string,mixed> $input   The call input.
	 */
	public static function filter( mixed $summary, string $verb, array $input ): string {
		$summary  = is_string( $summary ) ? $summary : '';
		$resolved = ( new CommercePack() )->verbFor( $verb, $input );

		if ( ! in_array( $resolved, self::TIER2_VERBS, true ) ) {
			return $summary;
		}

		return self::build( $summary, $resolved, self::baseName( $verb ), $input );
	}

	/**
	 * Dispatch to the per-verb card builder.
	 *
	 * @param string              $summary Fallback summary.
	 * @param string              $verb    The resolved Tier-2 pack verb.
	 * @param string              $base    The ability id's final name segment
	 *                                     (distinguishes `product-create` from
	 *                                     `product-update`, which can both
	 *                                     resolve to `commerce/product-publish`).
	 * @param array<string,mixed> $input   Call input.
	 */
	private static function build( string $summary, string $verb, string $base, array $input ): string {
		return match ( $verb ) {
			'commerce/price-change' => self::priceChangeCard( $summary, $input ),
			'commerce/product-publish' => 'product-create' === $base
				? self::productCreatePublishCard( $input )
				: self::productUpdatePublishCard( $summary, $input ),
			'commerce/refund' => self::refundCard( $summary, $input ),
			'commerce/order-note-customer' => self::customerNoteCard( $summary, $input ),
			'commerce/coupon-enable' => self::couponEnableCard( $summary, $input ),
			'commerce/shipping-write' => self::shippingCard( $input ),
			'commerce/tax-write' => self::taxCard( $input ),
			'commerce/report-save' => self::reportSaveCard( $input ),
			default => $summary,
		};
	}

	// ------------------------------------------------------------------
	// Cards
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function priceChangeCard( string $summary, array $input ): string {
		$product = self::product( $input );
		if ( null === $product ) {
			return $summary;
		}

		$current_regular  = self::money( self::call( $product, 'get_regular_price' ) );
		$current_sale     = self::money( self::call( $product, 'get_sale_price' ) );
		$proposed_regular = array_key_exists( 'regular_price', $input ) ? self::money( $input['regular_price'] ) : null;
		$proposed_sale    = array_key_exists( 'sale_price', $input ) ? self::money( $input['sale_price'] ) : null;

		$current = array( sprintf( 'regular %s', esc_html( $current_regular ) ) );
		if ( '' !== $current_sale ) {
			$current[] = sprintf( 'sale %s', esc_html( $current_sale ) );
		}

		$proposed = array();
		if ( null !== $proposed_regular ) {
			$proposed[] = sprintf( 'regular %s', esc_html( $proposed_regular ) );
		}
		if ( null !== $proposed_sale ) {
			$proposed[] = sprintf( 'sale %s', esc_html( $proposed_sale ) );
		}

		$row  = sprintf( 'Change price for &quot;%s&quot;', esc_html( self::productTitle( $input, $product ) ) );
		$row .= sprintf( ' — current %s', implode( ', ', $current ) );
		if ( array() !== $proposed ) {
			$row .= sprintf( ' — proposed %s', implode( ', ', $proposed ) );
		}

		return $row;
	}

	/**
	 * `product-update` transitioning to publish/future: the object already
	 * exists, so title/price/stock are all read server-side.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function productUpdatePublishCard( string $summary, array $input ): string {
		$product = self::product( $input );
		if ( null === $product ) {
			return $summary;
		}

		$id      = (int) $input['id'];
		$title   = self::productTitle( $input, $product );
		$preview = function_exists( 'get_preview_post_link' ) ? (string) get_preview_post_link( $id ) : '';
		$edit    = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $id, 'raw' ) : '';
		$price   = self::money( self::call( $product, 'get_regular_price' ) );
		$stock   = self::call( $product, 'get_stock_quantity' );

		$row = sprintf( 'Publish product &quot;%s&quot;', esc_html( $title ) );
		if ( '' !== $preview ) {
			$row .= sprintf( ' — <a href="%s">preview</a>', esc_url( $preview ) );
		}
		if ( '' !== $edit ) {
			$row .= sprintf( ' · <a href="%s">edit</a>', esc_url( $edit ) );
		}
		if ( '' !== $price ) {
			$row .= sprintf( ' — price %s', esc_html( $price ) );
		}
		if ( null !== $stock ) {
			$row .= sprintf( ' — stock %s', esc_html( (string) $stock ) );
		}

		return $row;
	}

	/**
	 * `product-create` with a publish/future status: nothing exists on the
	 * server yet, so every value shown is the proposed one — there is no
	 * "current" to read and no preview link for a product that has not been
	 * created.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function productCreatePublishCard( array $input ): string {
		$title = is_string( $input['name'] ?? null ) ? $input['name'] : '';
		$price = array_key_exists( 'regular_price', $input ) ? self::money( $input['regular_price'] ) : '';

		$row = sprintf( 'Publish new product &quot;%s&quot;', esc_html( $title ) );
		if ( '' !== $price ) {
			$row .= sprintf( ' — price %s', esc_html( $price ) );
		}

		return $row;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function refundCard( string $summary, array $input ): string {
		if ( ! isset( $input['order_id'] ) || ! is_numeric( $input['order_id'] ) ) {
			return $summary;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $input['order_id'] ) : false;
		if ( ! is_object( $order ) ) {
			return $summary;
		}

		$total     = self::call( $order, 'get_total' );
		$refunded  = self::call( $order, 'get_total_refunded' );
		$total     = is_numeric( $total ) ? (float) $total : 0.0;
		$refunded  = is_numeric( $refunded ) ? (float) $refunded : 0.0;
		$remaining = max( 0.0, $total - $refunded );

		$amount  = is_numeric( $input['amount'] ?? null ) ? (float) $input['amount'] : 0.0;
		$restock = true === ( $input['restock'] ?? false );
		$reason  = is_string( $input['reason'] ?? null ) ? $input['reason'] : '';
		$moves   = self::gatewaySupportsRefunds( $order );

		$row  = sprintf( 'Refund order #%d', (int) $input['order_id'] );
		$row .= sprintf( ' — order total %s, already refunded %s, remaining refundable %s', esc_html( self::money( $total ) ), esc_html( self::money( $refunded ) ), esc_html( self::money( $remaining ) ) );
		$row .= sprintf( ' — refund amount %s', esc_html( self::money( $amount ) ) );
		$row .= $restock ? ' — restocks items' : ' — does not restock items';
		$row .= $moves
			? ' — money moves through the payment gateway'
			: ' — recorded on the order only; no money moves through the gateway';
		if ( '' !== $reason ) {
			$row .= sprintf( ' — reason (the agent\'s words): &quot;%s&quot;', esc_html( $reason ) );
		}

		return $row;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function customerNoteCard( string $summary, array $input ): string {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) ) {
			return $summary;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $input['id'] ) : false;
		if ( ! is_object( $order ) ) {
			return $summary;
		}

		$email = self::call( $order, 'get_billing_email' );
		$note  = is_string( $input['note'] ?? null ) ? $input['note'] : '';

		$row = sprintf( 'Send a customer-visible note on order #%d', (int) $input['id'] );
		if ( is_string( $email ) && '' !== $email ) {
			$row .= sprintf( ' to %s', esc_html( $email ) );
		}
		$row .= sprintf( ' — the agent\'s words: &quot;%s&quot;', esc_html( $note ) );

		return $row;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function couponEnableCard( string $summary, array $input ): string {
		if ( ! isset( $input['coupon_id'] ) || ! is_numeric( $input['coupon_id'] ) || ! class_exists( '\WC_Coupon' ) ) {
			return $summary;
		}

		$coupon = new \WC_Coupon( (int) $input['coupon_id'] );
		if ( 0 === $coupon->get_id() ) {
			return $summary;
		}

		$code        = $coupon->get_code();
		$discount    = sprintf( '%s %s', $coupon->get_amount(), $coupon->get_discount_type() );
		$expiry      = $coupon->get_date_expires();
		$usage_limit = $coupon->get_usage_limit();

		$row  = sprintf( 'Enable coupon &quot;%s&quot;', esc_html( $code ) );
		$row .= sprintf( ' — discount %s', esc_html( $discount ) );
		$row .= sprintf( ' — expiry %s', esc_html( null !== $expiry ? $expiry : 'none' ) );
		$row .= sprintf( ' — usage limit %s', esc_html( null !== $usage_limit ? (string) $usage_limit : 'unlimited' ) );

		return $row;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function shippingCard( array $input ): string {
		$name     = is_string( $input['name'] ?? null ) ? $input['name'] : '';
		$zone_id  = isset( $input['zone_id'] ) && is_numeric( $input['zone_id'] ) ? (int) $input['zone_id'] : 0;
		$previous = null;

		if ( $zone_id > 0 && class_exists( '\WC_Shipping_Zone' ) ) {
			$existing = new \WC_Shipping_Zone( $zone_id );
			if ( $existing->get_id() > 0 ) {
				$previous = $existing->get_zone_name();
			}
		}

		$row = null !== $previous
			? sprintf( 'Save shipping zone — current name &quot;%s&quot;', esc_html( $previous ) )
			: 'Save new shipping zone';

		return $row . sprintf( ' — proposed name &quot;%s&quot;', esc_html( $name ) );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function taxCard( array $input ): string {
		$rate        = is_scalar( $input['rate'] ?? null ) ? (string) $input['rate'] : '';
		$tax_rate_id = isset( $input['tax_rate_id'] ) && is_numeric( $input['tax_rate_id'] ) ? (int) $input['tax_rate_id'] : 0;
		$previous    = null;

		if ( $tax_rate_id > 0 && class_exists( '\WC_Tax' ) ) {
			$existing = \WC_Tax::_get_tax_rate( $tax_rate_id ); // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- mirrors WooCommerce's own method name.
			if ( array() !== $existing && isset( $existing['tax_rate'] ) ) {
				$previous = (string) $existing['tax_rate'];
			}
		}

		$row = null !== $previous
			? sprintf( 'Save tax rate — current rate %s', esc_html( $previous ) )
			: 'Save new tax rate';

		return $row . sprintf( ' — proposed rate %s', esc_html( $rate ) );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private static function reportSaveCard( array $input ): string {
		$title = is_string( $input['title'] ?? null ) ? $input['title'] : '';

		return sprintf( 'Save store report as a page — &quot;%s&quot; — this page will be private.', esc_html( $title ) );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * The product a `product-update`-rooted call targets, read fresh from
	 * WooCommerce, or null when unavailable — the caller falls back to the
	 * plain summary rather than guessing.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function product( array $input ): ?object {
		if ( ! isset( $input['id'] ) || ! is_numeric( $input['id'] ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( (int) $input['id'] );

		return is_object( $product ) ? $product : null;
	}

	/**
	 * The product's title, read from the post store (matches the pages/
	 * posts cards) with a fall back to the product's own `get_name()`.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function productTitle( array $input, object $product ): string {
		$id    = (int) ( $input['id'] ?? 0 );
		$title = ( 0 !== $id && function_exists( 'get_the_title' ) ) ? (string) get_the_title( $id ) : '';
		if ( '' !== $title ) {
			return $title;
		}

		$name = self::call( $product, 'get_name' );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * A guarded method call on a duck-typed WooCommerce object — mirrors the
	 * `method_exists()` discipline `Commerce\Abilities` already uses (this
	 * codebase carries no WooCommerce class stubs to type against statically).
	 */
	private static function call( object $target, string $method ): mixed {
		return method_exists( $target, $method ) ? $target->{$method}() : null;
	}

	/**
	 * Format a price-like value for the card. `''` for anything empty/unset
	 * so callers can test for "no value" with a plain string comparison.
	 */
	private static function money( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * Whether the order's own payment gateway supports refunds — same
	 * reasoning as `Commerce\Abilities::gatewaySupportsRefunds()`, duplicated
	 * here rather than shared because that method is `private static` on a
	 * class this builder should not otherwise depend on.
	 */
	private static function gatewaySupportsRefunds( object $order ): bool {
		if ( ! method_exists( $order, 'get_payment_method' ) ) {
			return false;
		}
		$method = (string) $order->get_payment_method();
		if ( '' === $method || ! function_exists( 'wc_get_payment_gateway_by_order' ) ) {
			return false;
		}
		$gateway = wc_get_payment_gateway_by_order( $order );

		return is_object( $gateway ) && method_exists( $gateway, 'supports' ) && true === $gateway->supports( 'refunds' );
	}

	/**
	 * The ability id's final name segment (mirrors `Pack::baseName()`,
	 * duplicated here because that helper is `protected` on the abstract
	 * base and this class is not a `Pack`).
	 */
	private static function baseName( string $ability ): string {
		$pos = strrpos( $ability, '/' );

		return false === $pos ? $ability : substr( $ability, $pos + 1 );
	}
}
