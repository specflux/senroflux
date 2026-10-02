<?php
/**
 * Every Tier >= 1 pack verb names the object it writes: which output key
 * carries its id (or the id itself, for a nested or singleton output), and
 * the prefix that keeps it apart from another object kind sharing the same
 * number. The proof run's report dropped the attachment and the category
 * because three verbs here declared nothing.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Packs\Site\SitePack;

final class WrittenObjectIdsTest extends TestCase {

	/**
	 * The id a verb's write tracks, as the harness computes it: the pack's
	 * own answer, else output[objectIdKey], with the prefix in front.
	 *
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $output
	 */
	private static function trackedId( Pack $pack, string $verb, array $args, array $output ): ?string {
		$id = $pack->objectIdForWrite( $verb, $args, $output );
		if ( null === $id ) {
			$raw = $output[ $pack->objectIdKey( $verb ) ] ?? null;
			$id  = ( is_int( $raw ) || ( is_string( $raw ) && '' !== $raw ) ) ? (string) $raw : null;
		}

		return null === $id ? null : $pack->objectIdPrefix( $verb ) . $id;
	}

	/** @return array<string,array{0:Pack,1:string,2:array<string,mixed>,3:array<string,mixed>,4:string}> */
	public static function writeVerbs(): array {
		$posts = new PostsPack();
		$pages = new PagesPack();
		$site  = new SitePack();
		$shop  = new CommercePack();

		$rows = array(
			'posts media-upload'       => array( $posts, 'posts/media-upload', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'posts media-generate'     => array( $posts, 'posts/media-generate', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'posts media-stock-import' => array( $posts, 'posts/media-stock-import', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'posts update-alt'         => array( $posts, 'posts/update-alt', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'posts create-term'        => array( $posts, 'posts/create-term', array(), array( 'term_id' => 5 ), 'term:5' ),
			'posts set-terms'          => array( $posts, 'posts/set-terms', array(), array( 'post_id' => 4 ), '4' ),
			'posts set-featured-image' => array( $posts, 'posts/set-featured-image', array(), array( 'post_id' => 4 ), '4' ),
			'posts create-draft'       => array( $posts, 'posts/create-draft', array(), array( 'id' => 4 ), '4' ),
			'posts publish'            => array( $posts, 'posts/publish', array(), array( 'id' => 4 ), '4' ),
			'pages media-upload'       => array( $pages, 'pages/media-upload', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'pages media-generate'     => array( $pages, 'pages/media-generate', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'pages media-stock-import' => array( $pages, 'pages/media-stock-import', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'pages set-featured-image' => array( $pages, 'pages/set-featured-image', array(), array( 'post_id' => 6 ), '6' ),
			'pages update-draft'       => array( $pages, 'pages/update-draft', array(), array( 'id' => 6 ), '6' ),
			'site media-upload'        => array( $site, 'site/media-upload', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'site media-generate'      => array( $site, 'site/media-generate', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'site media-stock-import'  => array( $site, 'site/media-stock-import', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'site set-featured-image'  => array( $site, 'site/set-featured-image', array(), array( 'post_id' => 8 ), '8' ),
			'site update-navigation'   => array( $site, 'site/update-navigation', array(), array(), 'site-navigation' ),
			'commerce product-create'  => array( $shop, 'commerce/product-create-draft', array(), array( 'product' => array( 'id' => 31 ) ), '31' ),
			'commerce product-update'  => array( $shop, 'commerce/product-update', array( 'id' => 31 ), array( 'product' => array( 'id' => 31 ) ), '31' ),
			'commerce price-change'    => array( $shop, 'commerce/price-change', array( 'id' => 31 ), array( 'product' => array( 'id' => 31 ) ), '31' ),
			'commerce product-publish' => array( $shop, 'commerce/product-publish', array( 'id' => 31 ), array( 'product' => array( 'id' => 31 ) ), '31' ),
			'commerce product-image'   => array( $shop, 'commerce/product-image', array(), array( 'product_id' => 31 ), '31' ),
			'commerce image-generate'  => array( $shop, 'commerce/image-generate', array(), array( 'attachment_id' => 9 ), 'attachment:9' ),
			'commerce coupon-draft'    => array( $shop, 'commerce/coupon-draft', array(), array( 'coupon_id' => 12 ), 'coupon:12' ),
			'commerce coupon-enable'   => array( $shop, 'commerce/coupon-enable', array(), array( 'coupon_id' => 12 ), 'coupon:12' ),
			'commerce order-note'      => array( $shop, 'commerce/order-note-private', array( 'id' => 77 ), array( 'note_id' => 3 ), 'order:77' ),
			'commerce order-note-cust' => array( $shop, 'commerce/order-note-customer', array( 'id' => 77 ), array( 'note_id' => 3 ), 'order:77' ),
			'commerce refund'          => array( $shop, 'commerce/refund', array(), array( 'order_id' => 77 ), 'order:77' ),
			'commerce shipping-write'  => array( $shop, 'commerce/shipping-write', array(), array( 'zone_id' => 2 ), 'zone:2' ),
			'commerce tax-write'       => array( $shop, 'commerce/tax-write', array(), array( 'tax_rate_id' => 4 ), 'taxrate:4' ),
			'commerce report-save'     => array( $shop, 'commerce/report-save', array(), array( 'page_id' => 55 ), 'page:55' ),
		);

		return $rows;
	}

	/**
	 * @param array<string,mixed> $args
	 * @param array<string,mixed> $output
	 */
	#[DataProvider( 'writeVerbs' )]
	public function test_a_write_verb_tracks_the_object_it_wrote( Pack $pack, string $verb, array $args, array $output, string $expected ): void {
		$this->assertSame( $expected, self::trackedId( $pack, $verb, $args, $output ) );
	}

	public function test_the_commerce_order_read_verifies_an_order_write(): void {
		$shop = new CommercePack();

		$this->assertSame( $shop->objectIdPrefix( 'commerce/refund' ), $shop->objectIdPrefix( 'commerce/order-read' ) );
		$this->assertSame( 'id', $shop->objectIdKey( 'commerce/order-read' ) );
	}

	public function test_every_tier_one_or_two_verb_of_every_pack_is_covered_above(): void {
		$covered = array();
		foreach ( self::writeVerbs() as $row ) {
			$covered[ $row[1] ] = true;
		}

		// A verb either appears in the table above or is one of its
		// siblings that share an ability and an id shape (update-live,
		// schedule, update-draft on posts; set-front-page / set-style
		// singletons are covered by the Site pack's own tests).
		$siblings = array(
			'posts/update-draft',
			'posts/update-live',
			'posts/schedule',
			'pages/create-draft',
			'pages/update-live',
			'pages/publish',
			'site/create-draft',
			'site/update-draft',
			'site/update-live',
			'site/publish',
			'site/set-front-page',
			'site/set-style',
			'pages/update-alt',
			'site/update-alt',
		);

		foreach ( array( new PostsPack(), new PagesPack(), new SitePack(), new CommercePack() ) as $pack ) {
			foreach ( $pack->verbMap() as $verb => $tier ) {
				if ( $tier < 1 ) {
					continue;
				}
				$this->assertTrue(
					isset( $covered[ $verb ] ) || in_array( $verb, $siblings, true ),
					$verb . ' writes an object and must be in the table'
				);
			}
		}
	}
}
