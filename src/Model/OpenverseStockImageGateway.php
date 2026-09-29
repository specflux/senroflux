<?php
/**
 * Thin wrapper over the Openverse API for stock-photo search/import.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The ONE place `stock-image-search` and `stock-image-import` reach
 * Openverse, mirroring {@see AiClientMediaGateway}'s role for the generative
 * media abilities.
 *
 * SOURCES: verified live (2026-09-28) to return real stock photos rather than
 * logos/identifiable people/other businesses' storefronts, AND to serve the
 * file to WordPress's HTTP client: `wordpress` (the WordPress Photo
 * Directory, CC0) and `rawpixel`. Not `stocksnap` (its CDN answers 403 to
 * any non-browser client), not `nappy` (no results on Openverse), not
 * flickr/wikimedia. Filterable via `senroflux_stock_image_sources`.
 */
final class OpenverseStockImageGateway implements StockImageGatewayInterface {

	private const SEARCH_URL = 'https://api.openverse.org/v1/images/';

	private const TIMEOUT_SECONDS = 10;

	private const LICENSES = 'cc0,pdm';

	private const IMAGE_EXTENSIONS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	);

	/**
	 * @return list<string>
	 */
	public static function defaultSources(): array {
		$sources = array( 'wordpress', 'rawpixel' );

		/**
		 * Filters the Openverse source slugs `stock-image-search` queries.
		 *
		 * @param list<string> $sources Default source slugs.
		 */
		$filtered = apply_filters( 'senroflux_stock_image_sources', $sources );

		return is_array( $filtered ) ? array_values( array_map( 'strval', $filtered ) ) : $sources;
	}

	/** {@inheritDoc} */
	public function search( string $query ): array|WP_Error {
		// Openverse matches every word, so "physiotherapy clinic stretching"
		// finds nothing where "physiotherapy" finds photos: drop words from the end.
		$words = preg_split( '/\s+/', trim( $query ) );
		$words = is_array( $words ) ? $words : array( $query );
		do {
			$results = $this->searchOnce( implode( ' ', $words ) );
			array_pop( $words );
		} while ( array() === $results && array() !== $words );

		return $results;
	}

	/**
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private function searchOnce( string $query ): array|WP_Error {
		$url = add_query_arg(
			array(
				'q'         => $query,
				'license'   => self::LICENSES,
				'source'    => implode( ',', self::defaultSources() ),
				'page_size' => 8,
				'mature'    => 'false',
			),
			self::SEARCH_URL
		);

		$response = $this->get( $url );
		if ( $response instanceof WP_Error ) {
			return $response;
		}

		$results = $response['results'] ?? null;

		return is_array( $results ) ? array_values( $results ) : array();
	}

	/** {@inheritDoc} */
	public function fetch( string $id ): array|WP_Error {
		$url = self::SEARCH_URL . rawurlencode( $id ) . '/';

		return $this->get( $url );
	}

	/** {@inheritDoc} */
	public function download( array $detail ): array|WP_Error {
		$url = is_string( $detail['url'] ?? null ) ? $detail['url'] : '';
		if ( '' === $url ) {
			return new WP_Error( 'stock_images_unavailable', __( 'That stock image has no downloadable file.', 'senroflux' ) );
		}

		if ( ! function_exists( 'download_url' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'download_url' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'File downloads are not available.', 'senroflux' ) );
		}

		$tmp_file = download_url( $url, self::TIMEOUT_SECONDS );
		if ( is_wp_error( $tmp_file ) ) {
			return new WP_Error(
				'stock_images_unavailable',
				sprintf(
					/* translators: %s: the download error, e.g. "Forbidden". */
					__( 'The photo host refused the download (%s). Import a different result from the same search.', 'senroflux' ),
					$tmp_file->get_error_message()
				)
			);
		}

		// The bytes decide the type: a source can answer 200 with an HTML error page.
		$extension = self::IMAGE_EXTENSIONS[ (string) self::imageMime( $tmp_file ) ] ?? null;
		$binary    = null === $extension ? false : file_get_contents( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local tmp file `download_url()` just wrote, not a remote URL.
		wp_delete_file( $tmp_file );

		if ( null === $extension ) {
			return new WP_Error( 'stock_images_unavailable', __( 'The stock image download was not an image file. Import a different result from the same search.', 'senroflux' ) );
		}
		if ( false === $binary ) {
			return new WP_Error( 'stock_images_unavailable', __( 'The stock image could not be downloaded.', 'senroflux' ) );
		}

		if ( ! function_exists( 'wp_upload_bits' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Uploads are not available.', 'senroflux' ) );
		}

		$filename = sprintf( 'senroflux-stock-%s.%s', wp_generate_password( 12, false, false ), $extension );

		$uploaded = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $uploaded['error'] ) ) {
			return new WP_Error( 'stock_images_unavailable', (string) $uploaded['error'] );
		}

		return array(
			'path'     => $uploaded['file'],
			'filename' => basename( $uploaded['file'] ),
		);
	}

	private static function imageMime( string $file ): string|false {
		if ( function_exists( 'wp_get_image_mime' ) ) {
			return wp_get_image_mime( $file );
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a non-image file is an expected answer here, not an error.

		return is_array( $info ) ? (string) $info['mime'] : false;
	}

	/**
	 * GET `$url`, decode the JSON body, and normalize the common failure
	 * modes (transport error, non-2xx status, malformed body) into one
	 * `stock_images_unavailable` refusal — the caller never has to branch on
	 * WHICH kind of network failure occurred, only that one did.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function get( string $url ): array|WP_Error {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Outbound HTTP requests are not available.', 'senroflux' ) );
		}

		$response = wp_remote_get( // phpcs:ignore WordPress.WP.AlternativeFunctions.wp_remote_get -- the Openverse gateway's own request, not a generic file fetch.
			$url,
			array( 'timeout' => self::TIMEOUT_SECONDS )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stock_images_unavailable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'stock_images_unavailable',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The stock photo provider returned an error (%d).', 'senroflux' ),
					$code
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( (string) $body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'stock_images_unavailable', __( 'The stock photo provider returned an unexpected response.', 'senroflux' ) );
		}

		return $decoded;
	}
}
