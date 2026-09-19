<?php
/**
 * Thin wrapper over the WordPress AI Client for image/alt-text generation.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The ONE place `generate-image` and `generate-alt-text` reach the model,
 * mirroring {@see AiClientGateway}'s role for the run loop's own turns.
 *
 * UPSTREAM NAMES (S5, for the withdrawal path): a real WordPress/ai install
 * exposes this same generative surface as `ai/image-generation` and
 * `ai/alt-text-generation`; this class calls core's own `wp_ai_client_prompt()`
 * (WP 7.0+) and depends on neither the `ai` plugin nor its ability names.
 *
 * NOT wired into the harness's own gateway seam ({@see ModelGatewayInterface}):
 * a run's own model turns and an ability's one-shot generative call are
 * different lifecycles (one counts against the run's step/token budget via
 * the tick loop; the other is a single ability execution that spends the
 * `images` budget key instead), so they get separate interfaces.
 */
final class AiClientMediaGateway implements MediaGatewayInterface {

	/** {@inheritDoc} */
	public function generateImage( string $prompt ): array|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'The WordPress AI Client is not available. WordPress 7.0+ is required.', 'senroflux' )
			);
		}

		try {
			$result = wp_ai_client_prompt( $prompt )->generate_image_result();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gateway_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_object( $result ) || ! method_exists( $result, 'saveToDisk' ) ) {
			return new WP_Error( 'gateway_failed', __( 'The image provider returned an unexpected result.', 'senroflux' ) );
		}

		try {
			/** @var array{path:string, filename:string} $saved */
			$saved = $result->saveToDisk();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gateway_failed', $e->getMessage() );
		}

		return $saved;
	}

	/** {@inheritDoc} */
	public function generateAltText( string $image_url ): string|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'The WordPress AI Client is not available. WordPress 7.0+ is required.', 'senroflux' )
			);
		}

		try {
			$prompt = sprintf(
				/* translators: %s: image URL. */
				__( 'Write concise, descriptive alt text (under 125 characters) for the image at %s. Return only the alt text.', 'senroflux' ),
				$image_url
			);
			$result = wp_ai_client_prompt( $prompt )->generate_text_result();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gateway_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_object( $result ) || ! method_exists( $result, 'toMessage' ) ) {
			return new WP_Error( 'gateway_failed', __( 'The provider returned an unexpected result.', 'senroflux' ) );
		}

		$message = $result->toMessage();
		if ( ! $message instanceof \WordPress\AiClient\Messages\DTO\Message ) {
			return new WP_Error( 'gateway_failed', __( 'The provider returned an unexpected result.', 'senroflux' ) );
		}

		$text = '';
		foreach ( $message->getParts() as $part ) {
			$text .= (string) $part->getText();
		}

		return trim( $text );
	}
}
