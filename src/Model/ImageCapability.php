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
	 * Site-wide memory of a real attempt that no configured model could serve.
	 * Registry metadata cannot reveal this: the OpenRouter provider advertises
	 * image generation for models its `createModel()` only ever builds as text
	 * models, so every attempt fails with "No models found that support
	 * image_generation". The value is {@see providerFingerprint()}.
	 */
	private const UNAVAILABLE_TRANSIENT = 'senroflux_image_generation_unavailable';

	private const UNAVAILABLE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Test seam: the configured provider ids. Null restores the registry.
	 *
	 * @var list<string>|null
	 */
	private static ?array $provider_ids = null;

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
	 * Test seam: force the configured provider ids (tests only).
	 *
	 * @param list<string>|null $ids Forced ids, or null to restore the registry lookup.
	 */
	public static function setProviderIds( ?array $ids ): void {
		self::$provider_ids = $ids;
	}

	/**
	 * Remember that an image request found no model able to serve it. Only
	 * the "no model can serve this" failure belongs here, never a timeout,
	 * rate limit or 5xx.
	 */
	public static function markUnavailable(): void {
		set_transient( self::UNAVAILABLE_TRANSIENT, self::providerFingerprint(), self::UNAVAILABLE_TTL );
	}

	/**
	 * Whether a recorded failure still stands: it expires after 12 hours and
	 * is void as soon as the set of configured providers changes.
	 */
	public static function knownUnavailable(): bool {
		$recorded = get_transient( self::UNAVAILABLE_TRANSIENT );

		return is_string( $recorded ) && hash_equals( $recorded, self::providerFingerprint() );
	}

	/**
	 * A hash of the configured provider ids, order-independent.
	 */
	private static function providerFingerprint(): string {
		$ids = self::$provider_ids;
		if ( null === $ids ) {
			$ids = array();
			try {
				if ( class_exists( AiClient::class ) ) {
					$registry = AiClient::defaultRegistry();
					foreach ( $registry->getRegisteredProviderIds() as $id ) {
						if ( $registry->isProviderConfigured( $id ) ) {
							$ids[] = $id;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		sort( $ids );

		return hash( 'sha256', implode( ',', $ids ) );
	}

	/**
	 * Whether any configured provider offers an image-generation model right
	 * now. Fails closed: no AI Client, or a registry that throws, means no.
	 */
	public static function available(): bool {
		if ( self::knownUnavailable() ) {
			return false;
		}

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
