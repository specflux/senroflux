<?php
/**
 * The report's object lookup resolves each pack-qualified id to its own
 * type, title, status and edit link — a term, a coupon, an order, a
 * shipping zone, a tax rate — never "unknown".
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\ObjectLookup;

final class ObjectLookupTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/../stubs/blocks.php';
		require_once __DIR__ . '/../stubs/media.php';
		require_once __DIR__ . '/../stubs/commerce.php';

		$GLOBALS['senroflux_test_posts']          = array();
		$GLOBALS['senroflux_test_terms']          = array( 'post_tag:desk setup' => 5 );
		$GLOBALS['senroflux_test_orders']         = array(
			77 => (object) array( 'status' => 'processing' ),
		);
		$GLOBALS['senroflux_test_shipping_zones'] = array(
			2 => array(
				'name'      => 'UK mainland',
				'locations' => array(),
				'methods'   => array(),
			),
		);
		$GLOBALS['senroflux_test_tax_rates']      = array(
			4 => array(
				'tax_rate_name'    => 'VAT',
				'tax_rate_country' => 'GB',
			),
		);

		foreach ( array(
			12 => array( 'shop_coupon', 'SAVE10', 'draft' ),
			55 => array( 'page', 'Store report', 'draft' ),
		) as $id => $row ) {
			$post                                   = new \stdClass();
			$post->ID                               = $id;
			$post->post_type                        = $row[0];
			$post->post_title                       = $row[1];
			$post->post_status                      = $row[2];
			$GLOBALS['senroflux_test_posts'][ $id ] = $post;
		}
	}

	public function test_a_term_resolves_to_its_taxonomy_name_and_term_edit_screen(): void {
		$row = ObjectLookup::resolve( 'term:5' );

		$this->assertSame( 'post_tag', $row['object_type'] );
		$this->assertSame( 'Desk Setup', $row['title'] );
		$this->assertStringContainsString( 'term.php?taxonomy=post_tag&tag_ID=5', (string) $row['edit_url'] );
	}

	public function test_a_missing_term_is_unknown_not_an_error(): void {
		$this->assertSame( 'unknown', ObjectLookup::resolve( 'term:999' )['object_type'] );
	}

	public function test_a_coupon_resolves_to_its_code_and_post_status(): void {
		$row = ObjectLookup::resolve( 'coupon:12' );

		$this->assertSame( 'shop_coupon', $row['object_type'] );
		$this->assertSame( 'SAVE10', $row['title'] );
		$this->assertSame( 'draft', $row['status'] );
		$this->assertNotNull( $row['edit_url'] );
	}

	public function test_an_order_resolves_to_its_number_status_and_edit_screen(): void {
		$row = ObjectLookup::resolve( 'order:77' );

		$this->assertSame( 'shop_order', $row['object_type'] );
		$this->assertSame( 'Order #77', $row['title'] );
		$this->assertSame( 'processing', $row['status'] );
		$this->assertStringContainsString( 'id=77', (string) $row['edit_url'] );
	}

	public function test_a_shipping_zone_resolves_to_its_name_and_shipping_settings(): void {
		$row = ObjectLookup::resolve( 'zone:2' );

		$this->assertSame( 'shipping_zone', $row['object_type'] );
		$this->assertSame( 'UK mainland', $row['title'] );
		$this->assertStringContainsString( 'zone_id=2', (string) $row['edit_url'] );
	}

	public function test_a_tax_rate_resolves_to_its_name_and_tax_settings(): void {
		$row = ObjectLookup::resolve( 'taxrate:4' );

		$this->assertSame( 'tax_rate', $row['object_type'] );
		$this->assertSame( 'VAT', $row['title'] );
		$this->assertStringContainsString( 'tab=tax', (string) $row['edit_url'] );
	}

	public function test_a_saved_report_page_resolves_like_a_page(): void {
		$row = ObjectLookup::resolve( 'page:55' );

		$this->assertSame( 'page', $row['object_type'] );
		$this->assertSame( 'Store report', $row['title'] );
	}

	public function test_a_bare_id_is_still_a_post(): void {
		$this->assertSame( 'shop_coupon', ObjectLookup::resolve( '12' )['object_type'] );
	}
}
