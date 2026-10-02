<?php
/**
 * Thin wrapper over the WordPress AI Client for image/alt-text generation.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
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

	/**
	 * Defect 2 (live run evidence): image generation routinely takes 30-90s;
	 * with no per-request timeout set, the AI Client's HTTP call inherited
	 * WordPress's own HTTP API default (~30s) and failed outright —
	 * "cURL error 28: Operation timed out after 30002 milliseconds". Set
	 * generously above the observed worst case.
	 */
	private const IMAGE_TIMEOUT_SECONDS = 120.0;

	/**
	 * Alt text is a short text completion, not a media render — a smaller
	 * but still-explicit timeout so it never silently inherits the same
	 * default the image path was found to be missing.
	 */
	private const ALT_TEXT_TIMEOUT_SECONDS = 60.0;

	/**
	 * The longest either call above may legitimately run, plus headroom —
	 * used only to defensively raise PHP's OWN execution-time cap, which
	 * would otherwise kill the request before the HTTP timeout does.
	 */
	private const TIME_LIMIT_SECONDS = 150;

	/**
	 * Defect A (live run: "The image provider returned an unexpected
	 * result."): `generate_image_result()` was checked for an invented
	 * `saveToDisk()` method that doesn't exist anywhere in the AI Client.
	 * The real terminal accessor is {@see \WordPress\AiClient\Results\DTO\GenerativeAiResult::toImageFile()},
	 * which returns a {@see File} — inline (base64) or remote (URL) — that
	 * this gateway must itself persist into the uploads directory, since
	 * that is the contract {@see MediaGatewayInterface::generateImage()}
	 * promises its caller (an absolute on-disk path + filename).
	 */
	private const ALLOWED_IMAGE_EXTENSIONS = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	);

	/** {@inheritDoc} */
	public function generateImage( string $prompt ): array|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'The WordPress AI Client is not available. WordPress 7.0+ is required.', 'senroflux' )
			);
		}

		$this->raiseExecutionTimeLimit();

		$request_options = new RequestOptions();
		$request_options->setTimeout( self::IMAGE_TIMEOUT_SECONDS );

		try {
			$result = wp_ai_client_prompt( $prompt )->using_request_options( $request_options )->generate_image_result();
		} catch ( \Throwable $e ) {
			return self::isNoModelFailure( $e ) ? self::noModelError( $e->getMessage() ) : new WP_Error( 'gateway_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return self::isNoModelFailure( $result ) ? self::noModelError( $result->get_error_message() ) : $result;
		}

		if ( ! is_object( $result ) || ! method_exists( $result, 'toImageFile' ) ) {
			return new WP_Error( 'gateway_failed', __( 'The image provider returned an unexpected result.', 'senroflux' ) );
		}

		try {
			$file = $result->toImageFile();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gateway_failed', $e->getMessage() );
		}

		if ( ! $file instanceof File ) {
			return new WP_Error( 'gateway_failed', __( 'The image provider returned an unexpected result.', 'senroflux' ) );
		}

		return $this->persistImageFile( $file );
	}

	/**
	 * Whether a failure is the AI Client's "no model can serve this request":
	 * `ModelResolver::resolve()` throws an `InvalidArgumentException` reading
	 * "No models found that support image_generation[ for this prompt]." (with
	 * a `for provider "x"` variant), which `WP_AI_Client_Prompt_Builder`
	 * turns into a `prompt_invalid_argument` WP_Error. Timeouts, rate limits
	 * and 5xx are different classes and never match.
	 *
	 * @param \Throwable|WP_Error $failure The thrown exception or returned error.
	 */
	private static function isNoModelFailure( \Throwable|WP_Error $failure ): bool {
		if ( $failure instanceof WP_Error ) {
			if ( 'prompt_invalid_argument' !== $failure->get_error_code() ) {
				return false;
			}
			$message = $failure->get_error_message();
		} elseif ( $failure instanceof \InvalidArgumentException ) {
			$message = $failure->getMessage();
		} else {
			return false;
		}

		return 1 === preg_match( '/^No models found (?:for provider "[^"]*" )?that support image_generation\b/', $message );
	}

	/**
	 * The distinct refusal Media acts on (it records the site-wide verdict).
	 */
	private static function noModelError( string $message ): WP_Error {
		return new WP_Error( 'image_generation_unavailable', $message );
	}

	/**
	 * Persist a {@see File} returned by the AI Client into the uploads
	 * directory, refusing anything that isn't a supported image MIME type.
	 *
	 * @return array{path:string, filename:string}|WP_Error
	 */
	private function persistImageFile( File $file ): array|WP_Error {
		$extension = self::ALLOWED_IMAGE_EXTENSIONS[ $file->getMimeType() ] ?? null;
		if ( null === $extension ) {
			return new WP_Error(
				'unsupported_mime_type',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'The image provider returned an unsupported file type: %s', 'senroflux' ),
					$file->getMimeType()
				)
			);
		}

		if ( $file->isInline() ) {
			$binary = base64_decode( (string) $file->getBase64Data(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding a real inline image File, not obfuscating code.
			if ( false === $binary ) {
				return new WP_Error( 'gateway_failed', __( 'The image provider returned malformed image data.', 'senroflux' ) );
			}
		} elseif ( $file->isRemote() ) {
			$binary = $this->downloadRemoteFile( (string) $file->getUrl() );
			if ( $binary instanceof WP_Error ) {
				return $binary;
			}
		} else {
			return new WP_Error( 'gateway_failed', __( 'The image provider returned an unexpected result.', 'senroflux' ) );
		}

		return $this->writeToUploads( $binary, $extension );
	}

	/**
	 * Download a remote file to a string, using the same generous timeout
	 * as inline image generation. `download_url()` always writes to a
	 * temporary file; this reads it and cleans up.
	 *
	 * @return string|WP_Error
	 */
	private function downloadRemoteFile( string $url ) {
		if ( ! function_exists( 'download_url' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'download_url' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'File downloads are not available.', 'senroflux' ) );
		}

		$tmp_file = download_url( $url, (int) self::IMAGE_TIMEOUT_SECONDS );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$binary = file_get_contents( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local tmp file `download_url()` just wrote, not a remote URL.
		wp_delete_file( $tmp_file );

		if ( false === $binary ) {
			return new WP_Error( 'gateway_failed', __( 'The generated image could not be downloaded.', 'senroflux' ) );
		}

		return $binary;
	}

	/**
	 * @return array{path:string, filename:string}|WP_Error
	 */
	private function writeToUploads( string $binary, string $extension ) {
		if ( ! function_exists( 'wp_upload_bits' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'Uploads are not available.', 'senroflux' ) );
		}

		$filename = sprintf( 'senroflux-%s.%s', wp_generate_password( 12, false, false ), $extension );

		$uploaded = wp_upload_bits( $filename, null, $binary );
		if ( ! empty( $uploaded['error'] ) ) {
			return new WP_Error( 'gateway_failed', (string) $uploaded['error'] );
		}

		return array(
			'path'     => $uploaded['file'],
			'filename' => basename( $uploaded['file'] ),
		);
	}

	/** {@inheritDoc} */
	public function generateAltText( string $image_path ): string|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'The WordPress AI Client is not available. WordPress 7.0+ is required.', 'senroflux' )
			);
		}

		if ( ! is_file( $image_path ) ) {
			return new WP_Error(
				'attachment_file_missing',
				__( 'The attachment file could not be found on disk.', 'senroflux' ),
				array( 'status' => 404 )
			);
		}

		$request_options = new RequestOptions();
		$request_options->setTimeout( self::ALT_TEXT_TIMEOUT_SECONDS );

		try {
			// Defect A (alt-text half): the prompt used to embed the image
			// URL as TEXT, so the model never actually saw the image — it
			// was asked to describe a URL string.
			//
			// 0.3 quality fix 3 (live run): fixing THAT surfaced a second bug
			// — `with_file( $image_url )` attached the image as a REMOTE
			// File (a URL the provider must itself download), which fails
			// outright from localhost/staging/password-protected/intranet
			// sites: "Bad Request (400) - Error while downloading file.
			// Upstream status code: 407." `File`'s own constructor (see
			// vendor/wordpress/php-ai-client/src/Files/DTO/File.php)
			// detects a LOCAL file path and inlines it as base64 instead of
			// treating it as a URL — passing the on-disk path here, never
			// the public URL, is what actually attaches image DATA rather
			// than a link the provider must fetch.
			$prompt = __( 'Write concise, descriptive alt text (under 125 characters) for this image. Return only the alt text.', 'senroflux' );
			$result = wp_ai_client_prompt( $prompt )->with_file( $image_path )->using_request_options( $request_options )->generate_text_result();
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

	/**
	 * Best-effort: raise PHP's own execution-time cap so it does not kill
	 * a slow image generation before the HTTP client's own (longer) timeout
	 * gets the chance to. `set_time_limit()` is commonly disabled on shared
	 * hosts (removed via `disable_functions`, which makes `function_exists()`
	 * return false rather than fatal) — a no-op there, by design.
	 */
	private function raiseExecutionTimeLimit(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::TIME_LIMIT_SECONDS ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- see the docblock: image generation outlasts the default limit.
		}
	}
}
