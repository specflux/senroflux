<?php
/**
 * Whether this site's AI Client can produce an image at all.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * `generate-image` reaches the model through {@see AiClientMediaGateway},
 * which lets the AI Client pick ANY configured provider's image-capable
 * model — never the run's own text model. So "can this run generate an
 * image" is a question about the site's configured models, asked with the
 * same registry metadata {@see ModelChoice} uses for the model picker: is
 * there at least one model on a configured provider that supports the image
 * generation capability. A site whose only configured models are text-only
 * (the proof run's OpenRouter text model) answers no, and a run started
 * there is never offered the tool nor asked to approve a call that can only
 * fail.
 */
final class ImageCapability {

	/**
	 * Test seam: force the answer. Null restores the real registry lookup.
	 */
	private static ?bool $probe = null;

	/**
	 * Test seam: force {@see available()} (tests only).
	 *
	 * @param bool|null $available Forced answer, or null to restore the registry lookup.
	 */
	public static function setProbe( ?bool $available ): void {
		self::$probe = $available;
	}

	/**
	 * Whether any configured provider offers an image-generation model right
	 * now. Fails closed: no AI Client, or a registry that throws, means no.
	 */
	public static function available(): bool {
		if ( null !== self::$probe ) {
			return self::$probe;
		}

		if ( ! class_exists( AiClient::class ) || ! class_exists( \WordPress\AiClient\Providers\ProviderRegistry::class ) ) {
			return false;
		}

		try {
			$registry = AiClient::defaultRegistry();

			return array() !== $registry->findModelsMetadataForSupport(
				new ModelRequirements( array( CapabilityEnum::imageGeneration() ), array() )
			);
		} catch ( \Throwable $e ) {
			unset( $e );

			return false;
		}
	}
}
