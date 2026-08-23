<?php
/**
 * Executes one admitted tool call, permission-first.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * S5's execution seam. The order IS the safety property:
 *   check_permissions() FIRST — so Agent Safety's rich verdict (approval id,
 *   tier, denial reason) is visible — and execute() ONLY on true. Calling
 *   WP_Ability::execute() blind would mask the gate's error behind core's
 *   generic ability_invalid_permissions doing-it-wrong and lose the approval
 *   id the resume flow depends on.
 *
 * A denied call never reaches execute(); an unknown tool likewise.
 */
final class ToolExecutor {

	/**
	 * Default payload cap for what is handed back to the model (S5: 32 KB,
	 * filterable via senroflux_tool_result_max_bytes).
	 */
	public const DEFAULT_MAX_BYTES = 32768;

	/**
	 * Run one tool call to its terminal outcome.
	 *
	 * @param string      $ability_name Ability id (ns/name form).
	 * @param mixed|null  $args         Call arguments; null when argument-less.
	 */
	public function call( string $ability_name, mixed $args = null ): ToolOutcome {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return ToolOutcome::unknownTool( $ability_name );
		}

		$ability = wp_get_ability( $ability_name );

		if ( null === $ability || ! is_object( $ability ) ) {
			return ToolOutcome::unknownTool( $ability_name );
		}

		$permission = $ability->check_permissions( $args );

		if ( true !== $permission ) {
			if ( is_wp_error( $permission ) && 'approval_required' === $permission->get_error_code() ) {
				$data = $permission->get_error_data();
				$data = is_array( $data ) ? $data : array();

				return ToolOutcome::approvalRequired(
					(string) ( $data['approval_id'] ?? '' ),
					(string) ( $data['verb'] ?? $ability_name ),
					isset( $data['tier'] ) ? (string) $data['tier'] : null
				);
			}

			if ( is_wp_error( $permission ) ) {
				return ToolOutcome::denied(
					(string) $permission->get_error_code(),
					(string) $permission->get_error_message()
				);
			}

			return ToolOutcome::denied( 'not_allowed', (string) $permission );
		}

		$result = $ability->execute( $args );

		if ( is_wp_error( $result ) ) {
			return ToolOutcome::executionError( (string) $result->get_error_message() );
		}

		return ToolOutcome::result( $this->normalizeOutput( $result ) );
	}

	/**
	 * Shape an ability result for a FunctionResponse: arrays pass through;
	 * scalars wrap as {"text": ...}; anything over the byte cap is truncated
	 * with a {"truncated": true} marker so the model knows it saw a prefix.
	 *
	 * @param mixed $result Raw ability output.
	 * @return array<string,mixed>
	 */
	private function normalizeOutput( mixed $result ): array {
		$output = is_array( $result )
			? $result
			: array( 'text' => is_string( $result ) ? $result : wp_json_encode( $result ) );

		$encoded = (string) wp_json_encode( $output );
		$max     = (int) apply_filters( 'senroflux_tool_result_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( $max > 0 && strlen( $encoded ) > $max ) {
			return array(
				'truncated' => true,
				'prefix'    => substr( $encoded, 0, $max ),
			);
		}

		return $output;
	}
}
