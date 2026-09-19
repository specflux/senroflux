<?php
/**
 * Media-generation gateway contract (S5's `generate-image` / `generate-alt-text`).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Implemented by {@see AiClientMediaGateway}; mocked in ability tests so
 * `Packs\Content\Media` is verifiable without a real provider (S5: "mock it
 * in tests"). Both methods are thin — the ability itself owns the WordPress
 * side (inserting the attachment, writing the meta); this gateway only talks
 * to the model.
 */
interface MediaGatewayInterface {

	/**
	 * Generate one image for `$prompt`, saved to a real file on disk.
	 *
	 * @param string $prompt The image prompt.
	 * @return array{path:string, filename:string}|WP_Error Absolute file path
	 *         and a suggested filename, or a refusal.
	 */
	public function generateImage( string $prompt ): array|WP_Error;

	/**
	 * Draft alt text for an already-uploaded attachment.
	 *
	 * @param string $image_url The attachment's public URL.
	 * @return string|WP_Error The drafted alt text, or a refusal.
	 */
	public function generateAltText( string $image_url ): string|WP_Error;
}
