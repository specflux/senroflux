<?php
/**
 * Site\Validator tests (stage 7, S7).
 *
 * TARGET REPO PATH: tests/Packs/Site/ValidatorTest.php
 *
 * Covers only what the site pack ADDS on top of the inherited pages
 * behaviour (fully covered by tests/Packs/Pages/ValidatorTest.php against
 * the same shared engine): the `page-links`/`intro` patterns validate, the
 * `columns` slot on `page-links` is enforced, and `page-links` is capped at
 * one per page exactly like `cta`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Validator;
use Specflux\SenroFlux\Packs\Site\Vocabulary;
use WP_Error;

final class ValidatorTest extends TestCase {

	private Vocabulary $vocabulary;
	private Validator $validator;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		$this->vocabulary = new Vocabulary();
		$this->validator  = new Validator( $this->vocabulary );
	}

	private function markup( string $slug ): string {
		foreach ( $this->vocabulary->all() as $pattern ) {
			if ( $slug === $pattern['slug'] ) {
				return (string) $pattern['markup'];
			}
		}

		return '';
	}

	private function page( string ...$slugs ): string {
		$parts = array();
		foreach ( $slugs as $slug ) {
			$parts[] = $this->markup( $slug );
		}

		return implode( "\n\n", $parts );
	}

	public function test_hero_plus_page_links_and_intro_validate(): void {
		$result = $this->validator->clean( $this->page( 'hero', 'page-links', 'intro' ) );

		$this->assertTrue( $result['ok'] );
	}

	public function test_page_links_refuses_a_single_card(): void {
		// The shipped page-links markup ships two cards; stripping any card
		// markup drops the count below the 2-card minimum.
		$markup   = $this->markup( 'page-links' );
		$one_card = preg_replace(
			'#<!-- wp:column --><div class="wp-block-column">.*?</div><!-- /wp:column -->(?=</div><!-- /wp:columns -->)#s',
			'',
			$markup,
			1
		);
		$this->assertIsString( $one_card );
		$this->assertNotSame( $markup, $one_card );

		$result = $this->validator->clean( $this->page( 'hero' ) . "\n\n" . $one_card );

		$this->assertFalse( $result['ok'] );
		$this->assertInstanceOf( WP_Error::class, $result['wp_error'] );
		$this->assertSame( 'slot_count', $result['wp_error']->get_error_code() );
	}

	public function test_page_links_appears_at_most_once_per_page(): void {
		$page = $this->page( 'hero', 'page-links', 'page-links' );

		$result = $this->validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'page_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'max_once', $result['wp_error']->get_error_data()['rule'] );
	}

	public function test_cta_still_capped_at_one_on_the_site_pack_too(): void {
		$page = $this->page( 'hero', 'cta', 'cta' );

		$result = $this->validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'page_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'max_once', $result['wp_error']->get_error_data()['rule'] );
	}

	public function test_hero_must_still_be_first(): void {
		$page = $this->page( 'intro', 'hero' );

		$result = $this->validator->clean( $page );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'page_shape', $result['wp_error']->get_error_code() );
		$this->assertSame( 'hero_first', $result['wp_error']->get_error_data()['rule'] );
	}
}
