<?php
/**
 * Executes one admitted tool call, permission-first.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

use WP_Error;

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
	 * 0.3 S3: in {@see \Specflux\SenroFlux\Run\GateMode::BuiltIn}, `$gate`
	 * carries the Runner's classification of this call. When it is active
	 * (tier above 0, or unmapped) and not yet approved, the call parks HERE —
	 * BEFORE `check_permissions` — because SenroFlux never registers
	 * permission filters of its own (that would change the ability for every
	 * consumer on the site, not just this run). On the re-run of an approved
	 * park, `$gate->approved` is true and this check is skipped entirely, so
	 * the ability's OWN `check_permissions`/`execute()` run exactly as they
	 * would in AS mode.
	 *
	 * @param string           $ability_name Ability id (ns/name form).
	 * @param mixed|null       $args         Call arguments; null when argument-less.
	 * @param BuiltinGate|null $gate         Built-in gate classification, or null in AS mode.
	 * @param callable(string,array<string,mixed>):(WP_Error|null)|null $validate
	 *   S19 (stage 12): the run's pack's pre-execution validator
	 *   ({@see \Specflux\SenroFlux\Packs\Pack::validateCall()}), run BEFORE
	 *   the ability's own `check_permissions()`/`execute()` — the seam for a
	 *   pack rule over an ability ANOTHER plugin registers and this class
	 *   never owns (e.g. WooCommerce's `product-update`). Null = no opinion
	 *   (a direct-allow run, or a pack that declares none).
	 */
	public function call( string $ability_name, mixed $args = null, ?BuiltinGate $gate = null, mixed $validate = null ): ToolOutcome {
		if ( null !== $gate && $gate->active && ! $gate->approved ) {
			return ToolOutcome::approvalRequired(
				$gate->approvalId,
				'' !== $gate->verb ? $gate->verb : $ability_name,
				(string) $gate->tier
			);
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return ToolOutcome::unknownTool( $ability_name );
		}

		$ability = wp_get_ability( $ability_name );

		if ( null === $ability || ! is_object( $ability ) ) {
			return ToolOutcome::unknownTool( $ability_name );
		}

		if ( is_callable( $validate ) ) {
			$violation = $validate( $ability_name, is_array( $args ) ? $args : array() );
			if ( $violation instanceof WP_Error ) {
				return ToolOutcome::denied( (string) $violation->get_error_code(), (string) $violation->get_error_message() );
			}
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
