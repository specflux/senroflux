<?php
/**
 * Stock-photo gateway contract (S5's `stock-image-search` / `stock-image-import`).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Implemented by {@see OpenverseStockImageGateway}; mocked in ability tests so
 * `Packs\Content\Media` is verifiable without a real network call, mirroring
 * {@see MediaGatewayInterface}'s role for `generate-image`/`generate-alt-text`.
 *
 * Both methods talk ONLY to the provider — inserting the attachment, checking
 * the license/source/mature/scheme allow-list, and downloading the file all
 * stay in `Packs\Content\Media` (the security-sensitive re-fetch-by-id
 * decision documented there).
 */
interface StockImageGatewayInterface {

	/**
	 * Search the provider for candidate images.
	 *
	 * @param string $query Free-text search query.
	 * @return array<int,array<string,mixed>>|WP_Error A list of raw provider
	 *         result rows, or a refusal (network/HTTP failure).
	 */
	public function search( string $query ): array|WP_Error;

	/**
	 * Fetch one image's full detail record by the provider's own id.
	 *
	 * SECURITY: the caller re-fetches by id — never trusts a URL supplied by
	 * the model — so this is the ONLY place a stock image's license/source/
	 * mature/url fields are established from the provider itself.
	 *
	 * @param string $id The provider's own image id.
	 * @return array<string,mixed>|WP_Error The detail record, or a refusal.
	 */
	public function fetch( string $id ): array|WP_Error;

	/**
	 * Download an already-fetched detail record's file to a real file on
	 * disk (mirrors {@see MediaGatewayInterface::generateImage()}'s contract).
	 *
	 * @param array<string,mixed> $detail A record previously returned by {@see fetch()}.
	 * @return array{path:string, filename:string}|WP_Error
	 */
	public function download( array $detail ): array|WP_Error;
}
