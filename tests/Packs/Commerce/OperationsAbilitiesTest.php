<?php
/**
 * Commerce\Abilities operations tests (S19, stage 13): `orders-refund`,
 * `shipping-zone-save`, `tax-rate-save`, `store-report`, `save-store-report`.
 *
 * TARGET REPO PATH: tests/Packs/Commerce/OperationsAbilitiesTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\Abilities;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\WpdbRunStore;
use WP_Error;
use wpdb;

final class OperationsAbilitiesTest extends TestCase {

	private WpdbRunStore $store;

	private int $runId;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/commerce.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']                = array();
		$GLOBALS['senroflux_test_posts']                    = array();
		$GLOBALS['senroflux_test_postmeta']                 = array();
		$GLOBALS['senroflux_test_ability_categories']       = array();
		$GLOBALS['senroflux_test_orders']                   = array();
		$GLOBALS['senroflux_test_shipping_zones']           = array();
		$GLOBALS['senroflux_test_next_zone_id']             = 1;
		$GLOBALS['senroflux_test_tax_rates']                = array();
		$GLOBALS['senroflux_test_next_tax_rate_id']         = 1;
		$GLOBALS['senroflux_test_products']                 = array();
		$GLOBALS['senroflux_test_gateway_supports_refunds'] = true;
		$GLOBALS['senroflux_test_next_post_id']             = 100;

		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();

		$this->store = new WpdbRunStore( new wpdb() );
		$this->runId = $this->store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Abilities::useRunContext( $this->runId, $this->store );
	}

	protected function tearDown(): void {
		Abilities::forgetRunContext();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function callAbility( string $name, array $input ): mixed {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "ability $name was not registered" );

		return $ability->execute( $input );
	}

	private function makeOrder( int $id, float $total, float $refunded = 0.0, string $payment_method = 'bacs', string $status = 'processing', ?int $date_created = null ): void {
		$GLOBALS['senroflux_test_orders'][ $id ] = (object) array(
			'total'          => $total,
			'total_refunded' => $refunded,
			'payment_method' => $payment_method,
			'status'         => $status,
			'date_created'   => $date_created ?? time(),
		);
	}

	/** Records one successful `ok` tool-result step, the shape Budget::spentCount() reads. */
	private function recordSuccessfulStep( string $tool_name ): void {
		$this->store->appendStep(
			$this->runId,
			\Specflux\SenroFlux\Run\StepKind::ToolResult,
			array( 'ok' => true ),
			$tool_name,
			null,
			'ok'
		);
	}

	// ------------------------------------------------------------------
	// orders-refund
	// ------------------------------------------------------------------

	public function test_refund_within_the_remaining_amount_succeeds(): void {
		$this->makeOrder( 1, 100.00 );

		$result = $this->callAbility(
			'senroflux/orders-refund',
			array(
				'order_id' => 1,
				'amount'   => 40.00,
				'reason'   => 'Customer request',
				'restock'  => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['order_id'] );
		$this->assertSame( 40.00, $result['amount'] );
		$this->assertTrue( $result['money_moved'] );
		$this->assertSame( 60.00, $result['remaining_refundable'] );
	}

	public function test_a_refund_over_the_remaining_amount_is_refused(): void {
		$this->makeOrder( 1, 100.00, 80.00 ); // Only 20 remains refundable.

		$result = $this->callAbility(
			'senroflux/orders-refund',
			array(
				'order_id' => 1,
				'amount'   => 25.00,
				'reason'   => 'Too much',
				'restock'  => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'refund_too_large', $result->get_error_code() );
	}

	public function test_a_second_refund_in_the_same_run_is_refused_at_the_default_budget(): void {
		$this->makeOrder( 1, 100.00 );
		$this->makeOrder( 2, 100.00 );

		$first = $this->callAbility(
			'senroflux/orders-refund',
			array(
				'order_id' => 1,
				'amount'   => 10.00,
				'reason'   => 'First',
				'restock'  => false,
			)
		);
		$this->assertIsArray( $first );

		// Budget::spentCount() reads the run's OWN step history: record the
		// successful call the way Runner::executeCall() would.
		$this->recordSuccessfulStep( 'senroflux/orders-refund' );

		$second = $this->callAbility(
			'senroflux/orders-refund',
			array(
				'order_id' => 2,
				'amount'   => 10.00,
				'reason'   => 'Second',
				'restock'  => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'budget_exhausted', $second->get_error_code() );
	}

	public function test_refund_is_record_only_when_the_gateway_does_not_support_refunds(): void {
		$GLOBALS['senroflux_test_gateway_supports_refunds'] = false;
		$this->makeOrder( 1, 100.00 );

		$result = $this->callAbility(
			'senroflux/orders-refund',
			array(
				'order_id' => 1,
				'amount'   => 10.00,
				'reason'   => 'No gateway support',
				'restock'  => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['money_moved'] );
	}

	public function test_refund_of_an_unknown_order_is_refused(): void {
		$result = $this->callAbility(
			'senroflux/orders-refund',
			array(
				'order_id' => 999,
				'amount'   => 10.00,
				'reason'   => 'x',
				'restock'  => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// shipping-zone-save
	// ------------------------------------------------------------------

	public function test_creating_a_shipping_zone_reports_no_previous_value(): void {
		$result = $this->callAbility(
			'senroflux/shipping-zone-save',
			array(
				'name'      => 'United States',
				'locations' => array(
					array(
						'code' => 'US',
						'type' => 'country',
					),
				),
				'methods'   => array( 'flat_rate' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertNull( $result['previous'] );
		$this->assertIsInt( $result['zone_id'] );
	}

	public function test_updating_a_shipping_zone_records_the_previous_values(): void {
		$created = $this->callAbility(
			'senroflux/shipping-zone-save',
			array(
				'name'      => 'United States',
				'locations' => array(
					array(
						'code' => 'US',
						'type' => 'country',
					),
				),
				'methods'   => array( 'flat_rate' ),
			)
		);

		$updated = $this->callAbility(
			'senroflux/shipping-zone-save',
			array(
				'zone_id'   => $created['zone_id'],
				'name'      => 'United States and Canada',
				'locations' => array(
					array(
						'code' => 'US',
						'type' => 'country',
					),
					array(
						'code' => 'CA',
						'type' => 'country',
					),
				),
				'methods'   => array( 'flat_rate', 'free_shipping' ),
			)
		);

		$this->assertIsArray( $updated );
		$this->assertSame( $created['zone_id'], $updated['zone_id'] );
		$this->assertNotNull( $updated['previous'] );
		$this->assertSame( 'United States', $updated['previous']['name'] );
	}

	public function test_updating_an_unknown_shipping_zone_is_refused(): void {
		$result = $this->callAbility(
			'senroflux/shipping-zone-save',
			array(
				'zone_id'   => 999,
				'name'      => 'Ghost',
				'locations' => array(),
				'methods'   => array(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// tax-rate-save
	// ------------------------------------------------------------------

	public function test_creating_a_tax_rate_reports_no_previous_value(): void {
		$result = $this->callAbility(
			'senroflux/tax-rate-save',
			array(
				'country'  => 'us',
				'rate'     => '7.25',
				'name'     => 'CA State Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertNull( $result['previous'] );
		$this->assertIsInt( $result['tax_rate_id'] );
	}

	public function test_updating_a_tax_rate_records_the_previous_values(): void {
		$created = $this->callAbility(
			'senroflux/tax-rate-save',
			array(
				'country'  => 'US',
				'rate'     => '7.25',
				'name'     => 'CA State Tax',
				'priority' => 1,
				'compound' => false,
				'shipping' => true,
			)
		);

		$updated = $this->callAbility(
			'senroflux/tax-rate-save',
			array(
				'tax_rate_id' => $created['tax_rate_id'],
				'country'     => 'US',
				'rate'        => '8.00',
				'name'        => 'CA State Tax',
				'priority'    => 1,
				'compound'    => false,
				'shipping'    => true,
			)
		);

		$this->assertIsArray( $updated );
		$this->assertNotNull( $updated['previous'] );
		$this->assertSame( '7.25', $updated['previous']['tax_rate'] );
	}

	public function test_updating_an_unknown_tax_rate_is_refused(): void {
		$result = $this->callAbility(
			'senroflux/tax-rate-save',
			array(
				'tax_rate_id' => 999,
				'country'     => 'US',
				'rate'        => '1.00',
				'name'        => 'Ghost',
				'priority'    => 1,
				'compound'    => false,
				'shipping'    => false,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// store-report — read-only
	// ------------------------------------------------------------------

	public function test_store_report_never_writes_anything(): void {
		$this->makeOrder( 1, 100.00, 10.00, 'bacs', 'processing', strtotime( '2026-01-10' ) );
		$this->makeOrder( 2, 50.00, 0.00, 'bacs', 'completed', strtotime( '2026-01-15' ) );
		$GLOBALS['senroflux_test_products'] = array(
			501 => 1, // At/below the default low-stock amount (2).
			502 => 10, // Well above it.
		);

		$posts_before    = $GLOBALS['senroflux_test_posts'];
		$orders_before   = $GLOBALS['senroflux_test_orders'];
		$products_before = $GLOBALS['senroflux_test_products'];

		$result = $this->callAbility(
			'senroflux/store-report',
			array(
				'from' => '2026-01-01',
				'to'   => '2026-01-31',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['order_count'] );
		$this->assertSame( 150.0, $result['gross_sales'] );
		$this->assertSame( 140.0, $result['net_sales'] );
		$this->assertSame( 10.0, $result['refunds_total'] );
		$this->assertSame( array( 501 ), $result['low_stock_products'] );

		// A write-recording assertion: nothing this call could have written
		// to (orders, posts, products) changed.
		$this->assertEquals( $posts_before, $GLOBALS['senroflux_test_posts'] );
		$this->assertEquals( $orders_before, $GLOBALS['senroflux_test_orders'] );
		$this->assertEquals( $products_before, $GLOBALS['senroflux_test_products'] );
	}

	public function test_a_window_over_92_days_is_refused(): void {
		$result = $this->callAbility(
			'senroflux/store-report',
			array(
				'from' => '2026-01-01',
				'to'   => '2026-05-01',
			) // 120 days.
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'window_too_long', $result->get_error_code() );
	}

	public function test_a_window_of_exactly_92_days_is_accepted(): void {
		$result = $this->callAbility(
			'senroflux/store-report',
			array(
				'from' => '2026-01-01',
				'to'   => '2026-04-02',
			) // 92 days.
		);

		$this->assertIsArray( $result );
	}

	// ------------------------------------------------------------------
	// save-store-report — tag refusal
	// ------------------------------------------------------------------

	public function test_save_store_report_accepts_allowed_tags(): void {
		$result = $this->callAbility(
			'senroflux/save-store-report',
			array(
				'title'  => 'January report',
				'markup' => '<h2>January</h2><table><thead><tr><th>Metric</th></tr></thead><tbody><tr><td>Sales</td></tr></tbody></table>',
			)
		);

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['page_id'] );
		$this->assertSame( 'private', $GLOBALS['senroflux_test_posts'][ $result['page_id'] ]->post_status );
	}

	public function test_save_store_report_refuses_a_disallowed_tag_rather_than_stripping_it(): void {
		$result = $this->callAbility(
			'senroflux/save-store-report',
			array(
				'title'  => 'January report',
				'markup' => '<h2>January</h2><script>alert(1)</script>',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'disallowed_tag', $result->get_error_code() );
	}

	public function test_save_store_report_refuses_a_link_tag(): void {
		// No `a` in the allowed set at all (see ReportMarkupValidator's docblock).
		$result = $this->callAbility(
			'senroflux/save-store-report',
			array(
				'title'  => 'January report',
				'markup' => '<p><a href="https://example.com">link</a></p>',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'disallowed_tag', $result->get_error_code() );
	}
}
