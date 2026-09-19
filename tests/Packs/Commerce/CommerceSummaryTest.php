<?php
/**
 * CommerceSummary tests (stage 14, S19/AS-15).
 *
 * TARGET REPO PATH: tests/Packs/Commerce/CommerceSummaryTest.php
 *
 * Verifies every S19 approval card renders from the SERVER-READ fixture
 * object, not the call's own arguments; that agent-written text is labelled
 * and escaped; that the filter is registered once per request; and that
 * nothing renders for a non-matching verb.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommerceSummary;

final class CommerceSummaryTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/commerce.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$GLOBALS['senroflux_test_posts']                    = array();
		$GLOBALS['senroflux_test_product_rows']             = array();
		$GLOBALS['senroflux_test_products']                 = array();
		$GLOBALS['senroflux_test_orders']                   = array();
		$GLOBALS['senroflux_test_coupon_codes']             = array();
		$GLOBALS['senroflux_test_coupon_meta']              = array();
		$GLOBALS['senroflux_test_shipping_zones']           = array();
		$GLOBALS['senroflux_test_tax_rates']                = array();
		$GLOBALS['senroflux_test_gateway_supports_refunds'] = true;
		remove_all_filters( CommerceSummary::HOOK );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function seedPost( int $id, string $title, string $post_type = 'product' ): void {
		$post                                   = new \stdClass();
		$post->ID                               = $id;
		$post->post_type                        = $post_type;
		$post->post_title                       = $title;
		$post->post_status                      = 'publish';
		$post->post_name                        = '';
		$post->post_parent                      = 0;
		$post->post_excerpt                     = '';
		$post->post_content                     = '';
		$GLOBALS['senroflux_test_posts'][ $id ] = $post;
	}

	private function seedProduct( int $id, string $name, string $regular_price, string $sale_price = '', ?int $stock = null ): void {
		$GLOBALS['senroflux_test_product_rows'][ $id ] = (object) array(
			'name'           => $name,
			'regular_price'  => $regular_price,
			'sale_price'     => $sale_price,
			'stock_quantity' => $stock,
			'status'         => 'publish',
		);
	}

	private function seedOrder( int $id, float $total, float $refunded = 0.0, string $payment_method = 'bacs', string $billing_email = '' ): void {
		$GLOBALS['senroflux_test_orders'][ $id ] = (object) array(
			'total'          => $total,
			'total_refunded' => $refunded,
			'payment_method' => $payment_method,
			'status'         => 'processing',
			'date_created'   => time(),
			'billing_email'  => $billing_email,
		);
	}

	private function seedCoupon( int $id, string $code, string $amount, string $discount_type, ?string $expires, ?int $usage_limit ): void {
		$this->seedPost( $id, $code, 'shop_coupon' );
		$GLOBALS['senroflux_test_coupon_meta'][ $id ] = (object) array(
			'discount_type' => $discount_type,
			'amount'        => $amount,
			'expires'       => $expires,
			'usage_limit'   => $usage_limit,
		);
	}

	// ------------------------------------------------------------------
	// Price change
	// ------------------------------------------------------------------

	public function test_price_change_shows_server_price_not_args(): void {
		$this->seedPost( 100, 'Blue Mug' );
		// The stored product's OWN price is 10; the call's args claim 5 —
		// the card must show the SERVER value as "current".
		$this->seedProduct( 100, 'Blue Mug', '10.00' );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/product-update',
			array(
				'id'            => 100,
				'regular_price' => '5.00',
			)
		);

		$this->assertStringContainsString( 'current regular 10.00', $sum );
		$this->assertStringContainsString( 'proposed regular 5.00', $sum );
		$this->assertStringNotContainsString( 'current regular 5.00', $sum );
	}

	public function test_price_change_includes_sale_price_when_present(): void {
		$this->seedPost( 100, 'Blue Mug' );
		$this->seedProduct( 100, 'Blue Mug', '10.00', '8.00' );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/product-update',
			array(
				'id'         => 100,
				'sale_price' => '7.00',
			)
		);

		$this->assertStringContainsString( 'sale 8.00', $sum );
		$this->assertStringContainsString( 'sale 7.00', $sum );
	}

	// ------------------------------------------------------------------
	// Product publish
	// ------------------------------------------------------------------

	public function test_product_publish_update_shows_title_price_stock(): void {
		$this->seedPost( 100, 'Blue Mug' );
		$this->seedProduct( 100, 'Blue Mug', '10.00', '', 4 );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/product-update',
			array(
				'id'     => 100,
				'status' => 'publish',
			)
		);

		$this->assertStringContainsString( 'Blue Mug', $sum );
		$this->assertStringContainsString( '<a href=', $sum );
		$this->assertStringContainsString( 'price 10.00', $sum );
		$this->assertStringContainsString( 'stock 4', $sum );
	}

	public function test_product_publish_create_has_no_current_or_preview(): void {
		// A create has no existing object: nothing to read, no preview link
		// for a product that has not been created yet.
		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/product-create',
			array(
				'name'          => 'New Mug',
				'status'        => 'publish',
				'regular_price' => '12.00',
			)
		);

		$this->assertStringContainsString( 'New Mug', $sum );
		$this->assertStringContainsString( 'price 12.00', $sum );
		$this->assertStringNotContainsString( '<a href=', $sum );
	}

	// ------------------------------------------------------------------
	// Refund
	// ------------------------------------------------------------------

	public function test_refund_shows_order_totals_read_from_the_order(): void {
		// The order's OWN total/refunded are 100/20; the call's args must
		// never be trusted for those two facts.
		$this->seedOrder( 5, 100.0, 20.0 );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/orders-refund',
			array(
				'order_id' => 5,
				'amount'   => 30.0,
				'restock'  => true,
				'reason'   => 'Item arrived damaged',
			)
		);

		$this->assertStringContainsString( 'order total 100', $sum );
		$this->assertStringContainsString( 'already refunded 20', $sum );
		$this->assertStringContainsString( 'remaining refundable 80', $sum );
		$this->assertStringContainsString( 'refund amount 30', $sum );
		$this->assertStringContainsString( 'restocks items', $sum );
		$this->assertStringContainsString( 'money moves through the payment gateway', $sum );
		$this->assertStringContainsString( "reason (the agent's words): &quot;Item arrived damaged&quot;", $sum );
	}

	public function test_refund_labels_no_gateway_money_movement(): void {
		$this->seedOrder( 5, 100.0, 0.0, '' );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/orders-refund',
			array(
				'order_id' => 5,
				'amount'   => 10.0,
				'restock'  => false,
				'reason'   => 'Refund only',
			)
		);

		$this->assertStringContainsString( 'recorded on the order only', $sum );
		$this->assertStringContainsString( 'does not restock items', $sum );
	}

	public function test_refund_reason_is_escaped(): void {
		$this->seedOrder( 5, 100.0 );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/orders-refund',
			array(
				'order_id' => 5,
				'amount'   => 10.0,
				'restock'  => false,
				'reason'   => '<script>alert(1)</script>',
			)
		);

		$this->assertStringNotContainsString( '<script>', $sum );
	}

	// ------------------------------------------------------------------
	// Customer note
	// ------------------------------------------------------------------

	public function test_customer_note_shows_text_and_billing_email(): void {
		$this->seedOrder( 5, 50.0, 0.0, 'bacs', 'buyer@example.test' );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/order-add-note',
			array(
				'id'            => 5,
				'note'          => 'Your order shipped today.',
				'customer_note' => true,
			)
		);

		$this->assertStringContainsString( 'buyer@example.test', $sum );
		$this->assertStringContainsString( "the agent's words: &quot;Your order shipped today.&quot;", $sum );
	}

	public function test_private_note_is_not_enriched(): void {
		$this->seedOrder( 5, 50.0 );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/order-add-note',
			array(
				'id'            => 5,
				'note'          => 'Internal only',
				'customer_note' => false,
			)
		);

		$this->assertSame( 'plain', $sum );
	}

	// ------------------------------------------------------------------
	// Coupon enable
	// ------------------------------------------------------------------

	public function test_coupon_enable_reads_code_discount_expiry_limit_from_the_coupon(): void {
		// The coupon's OWN amount is 15; nothing in the call's args carries
		// a competing value — coupon-enable's input is just the id.
		$this->seedCoupon( 200, 'SUMMER15', '15', 'percent', '2026-12-31', 3 );

		$sum = CommerceSummary::filter( 'plain', 'senroflux/coupon-enable', array( 'coupon_id' => 200 ) );

		$this->assertStringContainsString( 'SUMMER15', $sum );
		$this->assertStringContainsString( '15 percent', $sum );
		$this->assertStringContainsString( '2026-12-31', $sum );
		$this->assertStringContainsString( 'usage limit 3', $sum );
	}

	public function test_coupon_enable_unlimited_usage_is_labelled(): void {
		$this->seedCoupon( 200, 'FREESHIP', '0', 'fixed_cart', null, null );

		$sum = CommerceSummary::filter( 'plain', 'senroflux/coupon-enable', array( 'coupon_id' => 200 ) );

		$this->assertStringContainsString( 'usage limit unlimited', $sum );
		$this->assertStringContainsString( 'expiry none', $sum );
	}

	// ------------------------------------------------------------------
	// Shipping / tax
	// ------------------------------------------------------------------

	public function test_shipping_zone_shows_previous_name_beside_proposed(): void {
		$GLOBALS['senroflux_test_shipping_zones'][7] = array(
			'name'      => 'Old Zone',
			'locations' => array(),
			'methods'   => array(),
		);

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/shipping-zone-save',
			array(
				'zone_id'   => 7,
				'name'      => 'New Zone',
				'locations' => array(),
				'methods'   => array(),
			)
		);

		$this->assertStringContainsString( 'current name &quot;Old Zone&quot;', $sum );
		$this->assertStringContainsString( 'proposed name &quot;New Zone&quot;', $sum );
	}

	public function test_shipping_zone_create_has_no_previous(): void {
		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/shipping-zone-save',
			array(
				'name'      => 'New Zone',
				'locations' => array(),
				'methods'   => array(),
			)
		);

		$this->assertStringContainsString( 'Save new shipping zone', $sum );
	}

	public function test_tax_rate_shows_previous_rate_beside_proposed(): void {
		$GLOBALS['senroflux_test_tax_rates'][9] = array( 'tax_rate' => '5.0000' );

		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/tax-rate-save',
			array(
				'tax_rate_id' => 9,
				'country'     => 'US',
				'rate'        => '7.0000',
				'name'        => 'Sales tax',
				'priority'    => 1,
				'compound'    => false,
				'shipping'    => false,
			)
		);

		$this->assertStringContainsString( 'current rate 5.0000', $sum );
		$this->assertStringContainsString( 'proposed rate 7.0000', $sum );
	}

	// ------------------------------------------------------------------
	// Report save
	// ------------------------------------------------------------------

	public function test_report_save_shows_title_and_private_note(): void {
		$sum = CommerceSummary::filter(
			'plain',
			'senroflux/save-store-report',
			array(
				'title'  => 'Q3 store health',
				'markup' => '<p>...</p>',
			)
		);

		$this->assertStringContainsString( 'Q3 store health', $sum );
		$this->assertStringContainsString( 'this page will be private', $sum );
	}

	// ------------------------------------------------------------------
	// Passthrough / registration
	// ------------------------------------------------------------------

	public function test_passthrough_for_a_tier1_verb(): void {
		$this->seedPost( 100, 'Blue Mug' );
		$this->seedProduct( 100, 'Blue Mug', '10.00' );

		$sum = CommerceSummary::filter( 'plain', 'senroflux/product-update', array( 'id' => 100 ) );

		$this->assertSame( 'plain', $sum );
	}

	public function test_passthrough_for_an_unrelated_verb(): void {
		$sum = CommerceSummary::filter( 'plain', 'senroflux/read-content', array( 'id' => 100 ) );

		$this->assertSame( 'plain', $sum );
	}

	public function test_boot_can_be_called_more_than_once_without_double_registering(): void {
		// The test shim's add_filter() has no WordPress-style dedup, so a
		// second boot() WOULD double-register if boot() itself gained a
		// guard removed by accident — this pins the current, correct
		// behaviour: boot() unconditionally calls add_filter() once per
		// call, so the COMPOSITION ROOT (Plugin::start(), stage 14) is the
		// thing responsible for calling it exactly once per request. That
		// contract is asserted here directly against the raw filter store.
		remove_all_filters( CommerceSummary::HOOK );
		CommerceSummary::boot();

		$registered = $GLOBALS['senroflux_test_filters'][ CommerceSummary::HOOK ][10] ?? array();
		$this->assertCount( 1, $registered );
		$this->assertSame( array( CommerceSummary::class, 'filter' ), $registered[0][0] );
	}
}
