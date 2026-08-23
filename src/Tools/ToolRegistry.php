<?php
/**
 * The run's tool surface: allow-list ∩ registered abilities.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

use Specflux\SenroFlux\Run\Run;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Per S5: a model may only call what the CONSUMER allow-listed at start() AND
 * what exists as a registered ability, minus anything hidden from the harness.
 * Abilities excluded by the allow-list are not logged — they were never part
 * of the deal; abilities the MODEL asks for that are absent produce an
 * unknown_tool error result and are never executed.
 */
final class ToolRegistry {

	/**
	 * Abilities hidden via meta.senroflux.hidden never become tools.
	 */
	public const HIDDEN_META = 'senroflux';

	/**
	 * Build the registry for one run.
	 *
	 * @param Run $run The run (allow-list + consumer scope).
	 * @return self
	 */
	public static function forRun( Run $run ): self {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();

		$names        = array();
		$declarations = array();

		foreach ( $abilities as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}

			$name = (string) $ability->get_name();
			if ( '' === $name ) {
				continue;
			}

			// Opt-out meta wins before the allow-list is even consulted.
			if ( self::isHidden( $ability ) ) {
				continue;
			}

			$admitted = false;
			foreach ( $run->allow as $pattern ) {
				if ( AbilityGlob::matches( $pattern, $name ) ) {
					$admitted = true;
					break;
				}
			}
			if ( ! $admitted ) {
				continue; // Not logged, per S5: outside the deal.
			}

			$names[ $name ]        = true;
			$declarations[ $name ] = self::declarationFor( $ability, $name );
		}

		return new self( array_keys( $names ), $declarations );
	}

	/**
	 * @param list<string>                                        $names Admitted ability names.
	 * @param array<string, FunctionDeclaration|array<string,mixed>> $declarations Ability name => declaration payload.
	 */
	private function __construct(
		private readonly array $names,
		private readonly array $declarations
	) {
	}

	/**
	 * Is this ability admitted to the run's tool surface?
	 */
	public function admits( string $ability_name ): bool {
		return in_array( $ability_name, $this->names, true );
	}

	/**
	 * All admitted ability names.
	 *
	 * @return list<string>
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * Function declarations for the prompt builder, keyed by ability name.
	 *
	 * @return array<string, FunctionDeclaration|array<string,mixed>>
	 */
	public function declarations(): array {
		return $this->declarations;
	}

	/**
	 * Hidden via meta.senroflux.hidden === true?
	 *
	 * @param object $ability Ability-like object.
	 */
	private static function isHidden( object $ability ): bool {
		if ( ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}

		$meta = $ability->get_meta();
		if ( ! is_array( $meta ) ) {
			return false;
		}

		$ours = $meta[ self::HIDDEN_META ] ?? null;

		return is_array( $ours ) && true === ( $ours['hidden'] ?? false );
	}

	/**
	 * One FunctionDeclaration for an ability: name mapped ns/name →
	 * wpab__ns__name (the core AI Client convention), description verbatim,
	 * input_schema normalized so empty properties encode as an object.
	 *
	 * @param object $ability Ability-like object.
	 * @param string $name    Ability name.
	 * @return FunctionDeclaration|array<string,mixed>
	 */
	private static function declarationFor( object $ability, string $name ): FunctionDeclaration|array {
		$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;
		if ( ! is_array( $schema ) ) {
			$schema = null;
		} else {
			if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && array() === $schema['properties'] ) {
				$schema['properties'] = new \stdClass(); // JSON Schema demands an object.
			}
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) && array() === $schema['required'] ) {
				unset( $schema['required'] ); // An empty required list is noise providers reject.
			}
		}

		$description = method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';

		// When the SDK is present build the real declaration; otherwise hand
		// back the shape (tests / SDK-less contexts).
		if ( class_exists( FunctionDeclaration::class ) ) {
			return new FunctionDeclaration(
				self::functionName( $name ),
				$description,
				$schema
			);
		}

		return array(
			'name'        => self::functionName( $name ),
			'description' => $description,
			'inputSchema' => $schema,
		);
	}

	/**
	 * Map an ability id onto a provider-safe function name (S4 mapping).
	 */
	public static function functionName( string $ability_name ): string {
		if ( str_starts_with( $ability_name, 'wpab__' ) ) {
			return $ability_name;
		}

		return 'wpab__' . str_replace( '/', '__', $ability_name );
	}

	/**
	 * Reverse of {@see functionName()}.
	 */
	public static function abilityName( string $function_name ): string {
		if ( str_starts_with( $function_name, 'wpab__' ) ) {
			$function_name = substr( $function_name, strlen( 'wpab__' ) );
		}

		return str_replace( '__', '/', $function_name );
	}
}
