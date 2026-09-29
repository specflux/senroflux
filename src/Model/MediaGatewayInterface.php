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
	 * 0.3 quality fix 3: `$image_path` is a LOCAL, on-disk file path, never a
	 * public URL — the caller sends it as inline (base64) data, which is the
	 * only form that works from a site the model's provider cannot reach
	 * itself (localhost, staging, password-protected, intranet).
	 *
	 * @param string $image_path Absolute path to the image file on disk.
	 * @return string|WP_Error The drafted alt text, or a refusal.
	 */
	public function generateAltText( string $image_path ): string|WP_Error;
}
