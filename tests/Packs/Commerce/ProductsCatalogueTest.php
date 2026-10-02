<?php
/**
 * `senroflux/products-catalogue` tests: the Tier-0 read polyfill that shows
 * the model product categories and which products lack a description (live
 * journeys J10/J11: WooCommerce's `products-query` has no category filter and
 * returns neither categories nor description text).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\Abilities;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use WP_Error;

final class ProductsCatalogueTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/commerce.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_user_caps']          = array( 'manage_woocommerce' => true );
		$GLOBALS['senroflux_test_products']           = array();
		$GLOBALS['senroflux_test_product_rows']       = array();
		$GLOBALS['senroflux_test_product_cats']       = array(
			7 => array(
				'name'  => 'Accessories',
				'slug'  => 'accessories',
				'count' => 3,
			),
			8 => array(
				'name'  => 'Hats & Caps',
				'slug'  => 'hats-caps',
				'count' => 1,
			),
		);

		$this->product( 1, 'Leather belt', array( 7 ), 'A sturdy belt made from full-grain leather.' );
		$this->product( 2, 'Wool scarf', array( 7 ), '' );
		$this->product( 3, 'Canvas tote', array( 7, 8 ), "<p>  </p>\n" );
		$this->product( 4, 'Beanie', array( 8 ), '<p>Warm <strong>knit</strong> beanie.</p>' );
		$this->product( 5, 'Uncategorised mug', array(), '' );

		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();
	}

	protected function tearDown(): void {
		$GLOBALS['senroflux_test_user_caps']    = array();
		$GLOBALS['senroflux_test_products']     = array();
		$GLOBALS['senroflux_test_product_rows'] = array();
		$GLOBALS['senroflux_test_product_cats'] = array();
	}

	/**
	 * @param list<int> $category_ids Term ids.
	 */
	private function product( int $id, string $name, array $category_ids, string $description, string $short = '' ): void {
		$GLOBALS['senroflux_test_products'][ $id ]     = 5;
		$GLOBALS['senroflux_test_product_rows'][ $id ] = (object) array(
			'name'              => $name,
			'sku'               => 'SKU-' . $id,
			'status'            => 'publish',
			'type'              => 'simple',
			'regular_price'     => '10.00',
			'sale_price'        => '',
			'stock_status'      => 'instock',
			'stock_quantity'    => 5,
			'category_ids'      => $category_ids,
			'description'       => $description,
			'short_description' => $short,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private function call( array $input = array() ): mixed {
		$ability = wp_get_ability( 'senroflux/products-catalogue' );
		$this->assertNotNull( $ability, 'senroflux/products-catalogue was not registered' );

		return $ability->execute( $input );
	}

	/**
	 * @param array<string,mixed> $result Ability result.
	 * @return list<int>
	 */
	private function ids( array $result ): array {
		return array_map( static fn ( array $p ): int => $p['id'], $result['products'] );
	}

	public function test_it_is_registered_read_only(): void {
		$meta = wp_get_ability( 'senroflux/products-catalogue' )->get_meta();

		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
	}

	public function test_filters_by_category_slug(): void {
		$result = $this->call( array( 'category' => 'accessories' ) );

		$this->assertSame( array( 1, 2, 3 ), $this->ids( $result ) );
		$this->assertSame( 3, $result['total'] );
	}

	public function test_filters_by_category_name_case_insensitively(): void {
		$result = $this->call( array( 'category' => 'hats & CAPS' ) );

		$this->assertSame( array( 3, 4 ), $this->ids( $result ) );
	}

	public function test_an_unknown_category_is_refused_with_the_known_slugs(): void {
		$result = $this->call( array( 'category' => 'shoes' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_category', $result->get_error_code() );
		$this->assertStringContainsString( 'accessories', $result->get_error_message() );
	}

	public function test_missing_description_keeps_only_products_without_description_text(): void {
		$result = $this->call( array( 'missing_description' => true ) );

		$this->assertSame( array( 2, 3, 5 ), $this->ids( $result ) );
		$this->assertSame( 3, $result['total'] );
	}

	public function test_missing_description_combines_with_category_and_pages_after_filtering(): void {
		$result = $this->call(
			array(
				'category'            => 'accessories',
				'missing_description' => true,
				'per_page'            => 1,
				'page'                => 2,
			)
		);

		$this->assertSame( array( 3 ), $this->ids( $result ) );
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 2, $result['total_pages'] );
	}

	public function test_each_product_carries_its_categories_and_description_facts(): void {
		$GLOBALS['senroflux_test_product_rows'][4]->short_description = 'Warm.';
		$result = $this->call( array( 'search' => 'tote' ) );

		$this->assertSame( array( 3 ), $this->ids( $result ) );
		$tote = $result['products'][0];
		$this->assertSame(
			array(
				array(
					'id'   => 7,
					'name' => 'Accessories',
					'slug' => 'accessories',
				),
				array(
					'id'   => 8,
					'name' => 'Hats & Caps',
					'slug' => 'hats-caps',
				),
			),
			$tote['categories']
		);
		$this->assertFalse( $tote['has_description'], 'markup with no text is not a description' );
		$this->assertSame( '', $tote['description_excerpt'] );
		$this->assertSame( 'SKU-3', $tote['sku'] );
		$this->assertSame( 'publish', $tote['status'] );
		$this->assertSame( 'simple', $tote['type'] );
		$this->assertSame( '10.00', $tote['regular_price'] );
		$this->assertSame( 'instock', $tote['stock_status'] );

		$beanie = $this->call( array( 'search' => 'beanie' ) )['products'][0];
		$this->assertTrue( $beanie['has_description'] );
		$this->assertSame( 'Warm knit beanie.', $beanie['description_excerpt'] );
		$this->assertTrue( $beanie['has_short_description'] );
	}

	public function test_the_excerpt_is_plain_text_cut_at_160_characters(): void {
		$this->product( 6, 'Long one', array(), '<p>' . str_repeat( 'word ', 80 ) . '</p>' );

		$excerpt = $this->call( array( 'search' => 'Long one' ) )['products'][0]['description_excerpt'];

		$this->assertLessThanOrEqual( 161, mb_strlen( $excerpt ) );
		$this->assertStringNotContainsString( '<', $excerpt );
		$this->assertStringStartsWith( 'word word', $excerpt );
	}

	public function test_the_category_list_with_counts_comes_back_when_no_product_filter_is_given(): void {
		$result = $this->call();

		$this->assertSame(
			array(
				array(
					'id'    => 7,
					'name'  => 'Accessories',
					'slug'  => 'accessories',
					'count' => 3,
				),
				array(
					'id'    => 8,
					'name'  => 'Hats & Caps',
					'slug'  => 'hats-caps',
					'count' => 1,
				),
			),
			$result['categories']
		);
	}

	public function test_the_category_list_is_left_out_once_a_filter_is_given(): void {
		$this->assertArrayNotHasKey( 'categories', $this->call( array( 'category' => 'accessories' ) ) );
		$this->assertArrayNotHasKey( 'categories', $this->call( array( 'missing_description' => true ) ) );
		$this->assertArrayNotHasKey( 'categories', $this->call( array( 'search' => 'belt' ) ) );
	}

	public function test_per_page_is_capped_at_100_and_defaults_to_20(): void {
		for ( $id = 10; $id < 160; ++$id ) {
			$this->product( $id, 'Bulk ' . $id, array(), 'Described.' );
		}

		$default = $this->call();
		$this->assertSame( 20, $default['per_page'] );
		$this->assertCount( 20, $default['products'] );

		$capped = $this->call( array( 'per_page' => 5000 ) );
		$this->assertSame( 100, $capped['per_page'] );
		$this->assertCount( 100, $capped['products'] );
		$this->assertSame( 155, $capped['total'] );
		$this->assertSame( 2, $capped['total_pages'] );
	}

	public function test_a_caller_without_manage_woocommerce_is_refused(): void {
		$GLOBALS['senroflux_test_user_caps'] = array();

		$this->assertFalse( wp_get_ability( 'senroflux/products-catalogue' )->check_permissions( array() ) );

		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_woocommerce' => true );
		$this->assertTrue( wp_get_ability( 'senroflux/products-catalogue' )->check_permissions( array() ) );
	}

	public function test_it_never_writes(): void {
		$products = $GLOBALS['senroflux_test_products'];
		$rows     = $GLOBALS['senroflux_test_product_rows'];

		$this->call( array( 'missing_description' => true ) );

		$this->assertEquals( $products, $GLOBALS['senroflux_test_products'] );
		$this->assertEquals( $rows, $GLOBALS['senroflux_test_product_rows'] );
	}

	public function test_pack_registers_it_as_tier_zero_in_the_verb_map_and_roles(): void {
		$pack = new CommercePack();

		$this->assertSame( 0, $pack->verbMap()['commerce/catalogue-read'] );
		$this->assertSame( 'commerce/catalogue-read', $pack->verbFor( 'senroflux/products-catalogue', array( 'category' => 'x' ) ) );
		$this->assertSame( array( 'commerce/catalogue-read' ), $pack->roleVerbs()['catalogue'] );
		$this->assertSame( 'products-catalogue', $pack->roles()['catalogue'] );
	}

	public function test_it_is_in_the_commerce_run_tool_set_and_governed_as_tier_zero(): void {
		$pack = new CommercePack();

		$this->assertContains( 'senroflux/products-catalogue', $pack->allowList() );
		$this->assertSame( 0, $pack->agentSafetyVerbMap()['senroflux/products-catalogue'] );
		$this->assertSame( 'senroflux/products-catalogue', $pack->gateVerbFor( 'commerce/catalogue-read' ) );
		$this->assertNull( $pack->objectIdForWrite( 'commerce/catalogue-read', array(), array( 'id' => 5 ) ) );
	}
}
