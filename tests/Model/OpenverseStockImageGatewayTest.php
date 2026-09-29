<?php
/**
 * OpenverseStockImageGateway tests.
 *
 * Covers: the search request's license/source query params, mapping a
 * successful search/detail response, the disabled-by-filter seam living in
 * `Packs\Content\Media` rather than here (this class always talks to the
 * network when called), and the common failure modes — transport error,
 * non-2xx status, malformed body — all normalizing to one
 * `stock_images_unavailable` refusal.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Model;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\OpenverseStockImageGateway;
use WP_Error;

final class OpenverseStockImageGatewayTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/stubs/media.php';
		require_once dirname( __DIR__ ) . '/stubs/stock-image.php';

		$GLOBALS['senroflux_test_remote_get_calls']      = array();
		$GLOBALS['senroflux_test_remote_get_responses']  = array();
		$GLOBALS['senroflux_test_download_url_calls']    = array();
		$GLOBALS['senroflux_test_download_url_response'] = null;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['senroflux_test_remote_get_calls'],
			$GLOBALS['senroflux_test_remote_get_responses'],
			$GLOBALS['senroflux_test_download_url_calls'],
			$GLOBALS['senroflux_test_download_url_response']
		);
	}

	private function queueResponse( int $code, array $body ): void {
		$GLOBALS['senroflux_test_remote_get_responses'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	public function test_search_sends_the_default_license_and_source_filter(): void {
		$this->queueResponse( 200, array( 'results' => array( array( 'id' => 'a-uuid' ) ) ) );

		( new OpenverseStockImageGateway() )->search( 'a red bicycle' );

		$this->assertCount( 1, $GLOBALS['senroflux_test_remote_get_calls'] );
		$url = $GLOBALS['senroflux_test_remote_get_calls'][0]['url'];

		$this->assertStringContainsString( 'license=cc0%2Cpdm', $url );
		$this->assertStringContainsString( 'source=wordpress%2Crawpixel', $url ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Openverse's own lowercase source slug, not prose.
		$this->assertStringContainsString( 'mature=false', $url );
	}

	public function test_search_honours_the_sources_filter(): void {
		add_filter(
			'senroflux_stock_image_sources',
			static fn () => array( 'wordpress' )
		);
		$this->queueResponse( 200, array( 'results' => array() ) );

		( new OpenverseStockImageGateway() )->search( 'x' );

		$url = $GLOBALS['senroflux_test_remote_get_calls'][0]['url'];
		$this->assertStringContainsString( 'source=wordpress', $url ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Openverse's own lowercase source slug, not prose.
		$this->assertStringNotContainsString( 'rawpixel', $url );

		remove_all_filters( 'senroflux_stock_image_sources' );
	}

	public function test_search_returns_the_results_array(): void {
		$this->queueResponse(
			200,
			array(
				'results' => array(
					array(
						'id'    => 'a-uuid',
						'title' => 'A red bicycle',
					),
				),
			)
		);

		$results = ( new OpenverseStockImageGateway() )->search( 'a red bicycle' );

		$this->assertIsArray( $results );
		$this->assertSame( 'a-uuid', $results[0]['id'] );
	}

	public function test_an_empty_multi_word_search_retries_with_fewer_words(): void {
		$this->queueResponse( 200, array( 'results' => array() ) );
		$this->queueResponse( 200, array( 'results' => array() ) );
		$this->queueResponse( 200, array( 'results' => array( array( 'id' => 'a-uuid' ) ) ) );

		$results = ( new OpenverseStockImageGateway() )->search( 'physiotherapy clinic stretching' );

		$this->assertIsArray( $results );
		$this->assertSame( 'a-uuid', $results[0]['id'] );
		$queries = array_map(
			static function ( array $call ): string {
				parse_str( (string) parse_url( $call['url'], PHP_URL_QUERY ), $args ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test helper.
				return (string) $args['q'];
			},
			$GLOBALS['senroflux_test_remote_get_calls']
		);
		$this->assertSame( array( 'physiotherapy clinic stretching', 'physiotherapy clinic', 'physiotherapy' ), $queries );
	}

	public function test_a_single_word_search_that_finds_nothing_is_asked_once(): void {
		$this->queueResponse( 200, array( 'results' => array() ) );

		$this->assertSame( array(), ( new OpenverseStockImageGateway() )->search( 'zzzz' ) );
		$this->assertCount( 1, $GLOBALS['senroflux_test_remote_get_calls'] );
	}

	public function test_search_refuses_on_a_transport_error(): void {
		$GLOBALS['senroflux_test_remote_get_responses'] = array(
			new WP_Error( 'http_request_failed', 'Could not resolve host.' ),
		);

		$result = ( new OpenverseStockImageGateway() )->search( 'x' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
	}

	public function test_search_refuses_on_a_non_2xx_status(): void {
		$this->queueResponse( 503, array() );

		$result = ( new OpenverseStockImageGateway() )->search( 'x' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
	}

	public function test_search_refuses_on_a_malformed_body(): void {
		$GLOBALS['senroflux_test_remote_get_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not json',
		);

		$result = ( new OpenverseStockImageGateway() )->search( 'x' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
	}

	public function test_fetch_requests_the_detail_url_and_returns_it(): void {
		$this->queueResponse(
			200,
			array(
				'id'      => 'a-uuid',
				'url'     => 'https://stock.example/photo.jpg',
				'license' => 'cc0',
			)
		);

		$detail = ( new OpenverseStockImageGateway() )->fetch( 'a-uuid' );

		$this->assertIsArray( $detail );
		$this->assertSame( 'https://stock.example/photo.jpg', $detail['url'] );
		$this->assertStringContainsString( '/images/a-uuid/', $GLOBALS['senroflux_test_remote_get_calls'][0]['url'] );
	}

	public function test_download_persists_the_file_to_uploads(): void {
		// A 1x1 PNG served from a URL ending .jpg: the saved extension follows the bytes.
		$GLOBALS['senroflux_test_download_url_response'] = (string) hex2bin( '89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da6364600000000600023081d02f0000000049454e44ae426082' );

		$result = ( new OpenverseStockImageGateway() )->download( array( 'url' => 'https://stock.example/photo.jpg' ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFileExists( $result['path'] );
		$this->assertSame( 'png', pathinfo( $result['path'], PATHINFO_EXTENSION ) );
	}

	public function test_download_refuses_a_file_that_is_not_an_image(): void {
		$GLOBALS['senroflux_test_download_url_response'] = '<html><body>Photo unavailable</body></html>';

		$result = ( new OpenverseStockImageGateway() )->download( array( 'url' => 'https://stock.example/photo.jpg' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
	}

	public function test_a_refused_download_tells_the_model_to_pick_another_photo(): void {
		// Live run 2026-09-28: a CDN answered 403 and the model saw only "Forbidden".
		$GLOBALS['senroflux_test_download_url_response'] = new WP_Error( 'http_404', 'Forbidden' );

		$result = ( new OpenverseStockImageGateway() )->download( array( 'url' => 'https://stock.example/photo.jpg' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( 'Forbidden', $result->get_error_message() );
		$this->assertStringContainsString( 'different result', $result->get_error_message() );
	}

	public function test_download_refuses_without_a_url(): void {
		$result = ( new OpenverseStockImageGateway() )->download( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stock_images_unavailable', $result->get_error_code() );
	}
}
