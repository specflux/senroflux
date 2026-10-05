<?php
/**
 * The run-model picker's UI-agnostic surface: which (provider, model) pairs
 * are choosable right now, and validation of a candidate pair against that
 * list.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\DTO\RequiredOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Per-run model choice is picked from whatever {@see self::availableChoices()}
 * reports RIGHT NOW: only configured providers, and only their models that
 * meet the same requirements {@see AiClientGateway} actually asks for (text
 * generation plus function calling — this plugin always sends function
 * declarations when it has tools admitted, mirroring
 * `ModelRequirements::fromPromptData()`'s own derivation for that case:
 * vendor/wordpress/php-ai-client/src/Providers/Models/DTO/ModelRequirements.php).
 * This class owns no storage — persistence/wiring into a run belongs to
 * whatever calls it.
 */
final class ModelChoice {

	/** Refusal code: the (provider, model) pair is not currently choosable. */
	public const ERROR_UNAVAILABLE = 'senroflux_model_unavailable';

	/**
	 * Test seam: force the available-choices list. Null restores reality.
	 *
	 * @var array<string, array{name: string, models: list<array{id: string, name: string}>}>|null
	 */
	private static ?array $available_probe = null;

	/**
	 * Test seam: inject a fixed available-choices list (tests only).
	 *
	 * @param array<string, array{name: string, models: list<array{id: string, name: string}>}>|null $choices Forced result, or null to restore the real registry lookup.
	 */
	public static function setAvailableChoicesProbe( ?array $choices ): void {
		self::$available_probe = $choices;
	}

	/**
	 * The choosable (provider, model) pairs right now, grouped by provider:
	 * only providers the AI Client reports as configured, and only their
	 * models meeting {@see self::requirements()}.
	 *
	 * @return array<string, array{name: string, models: list<array{id: string, name: string}>}> Provider id => {name, models: list<{id, name}>}.
	 */
	public static function availableChoices(): array {
		if ( null !== self::$available_probe ) {
			return self::$available_probe;
		}

		if ( ! self::wordPressSupportsAi() ) {
			return array();
		}

		$registry     = AiClient::defaultRegistry();
		$requirements = self::requirements();
		$choices      = array();

		foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
			if ( ! $registry->isProviderConfigured( $provider_id ) ) {
				continue;
			}

			$models = array();
			foreach ( $registry->findProviderModelsMetadataForSupport( $provider_id, $requirements ) as $model_metadata ) {
				$models[] = array(
					'id'   => $model_metadata->getId(),
					'name' => $model_metadata->getName(),
				);
			}

			if ( array() === $models ) {
				continue;
			}

			$class_name              = $registry->getProviderClassName( $provider_id );
			$choices[ $provider_id ] = array(
				'name'   => $class_name::metadata()->getName(),
				'models' => $models,
			);
		}

		return $choices;
	}

	/**
	 * Validate a candidate (provider, model) pair against
	 * {@see self::availableChoices()}. Refuses (rather than silently
	 * substituting automatic) so a caller never persists a pair that quietly
	 * stopped being usable.
	 *
	 * @return bool|WP_Error
	 */
	public static function validate( string $provider, string $model ): bool|WP_Error {
		$choices = self::availableChoices();

		if ( isset( $choices[ $provider ] ) ) {
			foreach ( $choices[ $provider ]['models'] as $available_model ) {
				if ( $available_model['id'] === $model ) {
					return true;
				}
			}
		}

		return new WP_Error(
			self::ERROR_UNAVAILABLE,
			__( 'That model is not currently available. Choose one from the list, or leave it automatic.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * The requirements every run-model candidate must meet: text generation,
	 * plus function calling — the same pair {@see AiClientGateway} implies by
	 * always calling `using_function_declarations()` once it has any tools
	 * admitted (`ModelRequirements::fromPromptData()` adds the same
	 * `functionDeclarations` required option whenever a prompt carries
	 * function-call parts or, as here, the caller sets it directly via
	 * `ModelConfig::setFunctionDeclarations()`).
	 */
	private static function requirements(): ModelRequirements {
		return new ModelRequirements(
			array( CapabilityEnum::textGeneration() ),
			array( new RequiredOption( OptionEnum::functionDeclarations(), true ) )
		);
	}

	/** Whether the AI Client classes this lookup depends on are even present. */
	private static function wordPressSupportsAi(): bool {
		return class_exists( AiClient::class )
			&& class_exists( \WordPress\AiClient\Providers\ProviderRegistry::class );
	}
}
