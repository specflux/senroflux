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
	 * The note {@see \Specflux\SenroFlux\Run\Runner} leaves in a resent call's
	 * history in place of a bulky old argument (`sprintf()`'d with the left-out
	 * length) — shared here, not duplicated, so the note text and the refusal
	 * that recognises it ({@see refuseHistoryPlaceholder()}) can never drift
	 * apart. Live evidence 2026-09-29-cards1/scenario-1-1 step 115: the model
	 * resent this exact placeholder as its `sections` value on a `create-post`
	 * call, after two unrelated tool calls pushed its previous attempt out of
	 * the full-args window — it read as a value, not as a note ABOUT history.
	 */
	public const HISTORY_PLACEHOLDER_FORMAT = '[%d characters left out of the history; read the saved object if you need them]';

	/**
	 * Run one tool call to its terminal outcome.
	 *
	 * 0.3 S3: in {@see \Specflux\SenroFlux\Run\GateMode::BuiltIn}, `$gate`
	 * carries the Runner's classification of this call. When it is active
	 * (tier above 0, or unmapped) and not yet approved, the call parks HERE —
	 * BEFORE `check_permissions`, but AFTER argument repair, the placeholder
	 * refusal and `$validate`, so a call that could never succeed is refused
	 * without asking a person to approve it. SenroFlux never registers
	 * permission filters of its own (that would change the ability for every
	 * consumer on the site, not just this run). On the re-run of an approved
	 * park, `$gate->approved` is true and the park is skipped (the repair and
	 * refusals run again), so the ability's OWN `check_permissions`/`execute()`
	 * run exactly as they would in AS mode.
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
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return ToolOutcome::unknownTool( $ability_name );
		}

		$ability = wp_get_ability( $ability_name );

		if ( null === $ability || ! is_object( $ability ) ) {
			return ToolOutcome::unknownTool( $ability_name );
		}

		$args = self::repairModelSlips( $args, (array) $ability->get_input_schema() );

		$placeholder_refusal = self::refuseHistoryPlaceholder( $args );
		if ( null !== $placeholder_refusal ) {
			return ToolOutcome::denied( 'history_placeholder', $placeholder_refusal );
		}

		if ( is_callable( $validate ) ) {
			$violation = $validate( $ability_name, is_array( $args ) ? $args : array() );
			if ( $violation instanceof WP_Error ) {
				return ToolOutcome::denied( (string) $violation->get_error_code(), (string) $violation->get_error_message() );
			}
		}

		if ( null !== $gate && $gate->active && ! $gate->approved ) {
			return ToolOutcome::approvalRequired(
				$gate->approvalId,
				'' !== $gate->verb ? $gate->verb : $ability_name,
				(string) $gate->tier
			);
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

			return ToolOutcome::denied( 'not_allowed', __( 'Refused: this account may not make this call on this item.', 'senroflux' ) );
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
		/** Filters the max byte size of an encoded tool result. `@internal`. */
		$max = (int) apply_filters( 'senroflux_tool_result_max_bytes', self::DEFAULT_MAX_BYTES );
		if ( $max > 0 && strlen( $encoded ) > $max ) {
			return array(
				'truncated' => true,
				'prefix'    => substr( $encoded, 0, $max ),
			);
		}

		return $output;
	}

	/**
	 * Recognise {@see HISTORY_PLACEHOLDER_FORMAT} resent as a real argument
	 * value (live evidence 2026-09-29-cards1/scenario-1-1 step 115) and refuse
	 * BEFORE the ability ever sees it — that string is a note the history
	 * compaction left behind, not content the model actually meant to send.
	 *
	 * @param mixed $args Decoded call arguments (or a nested part of them).
	 * @return string|null A refusal message naming the offending key, or null
	 *                      when nothing in `$args` matches the placeholder.
	 */
	private static function refuseHistoryPlaceholder( mixed $args ): ?string {
		if ( is_string( $args ) && 1 === preg_match( '/^\[\d+ characters left out of the history; read the saved object if you need them\]$/', $args ) ) {
			return __( 'That value is a note left behind by history compaction, not content — it was never something you wrote. Resend the full value for this argument.', 'senroflux' );
		}

		if ( is_array( $args ) ) {
			foreach ( $args as $item ) {
				$refusal = self::refuseHistoryPlaceholder( $item );
				if ( null !== $refusal ) {
					return $refusal;
				}
			}
		}

		return null;
	}

	/**
	 * Repair two narrow model slips against the input schema; the ability's
	 * own validation (patterns, enums) still runs afterwards.
	 *
	 * Where the schema expects an object or array and the model sent a JSON
	 * string of one, use the decoded value. Models double-encode nested
	 * arguments (live batches 2026-09-29-final/final3: "input[sections][0] is
	 * not of type object"), and every schema refusal costs a full turn.
	 *
	 * Where the schema allows a string but no number and the model sent an int
	 * or float, use its string form. Live journey J9: regular_price 18 was
	 * refused nine times for a {"type":"string"} property.
	 *
	 * Where the schema expects an array and the model wrapped it
	 * (`{"item": [...]}`, live J7: create-post tags refused nine times), unwrap
	 * it; a bare string for an array of strings becomes a one-name list.
	 * Schemas that admit an object are never touched.
	 *
	 * Composite schemas (`allOf`/`anyOf`/`oneOf`, at any level) are repaired
	 * against {@see self::compositeView()}, which only ever narrows: WooCommerce's
	 * product-create/update inputs are a top-level `oneOf` of object branches
	 * with no `properties` of their own, and regular_price 18 was refused six
	 * times in a row because nothing looked inside the branches.
	 *
	 * @param mixed               $value  An argument value.
	 * @param array<string,mixed> $schema Its JSON schema.
	 */
	private static function repairModelSlips( mixed $value, array $schema ): mixed {
		$schema = self::compositeView( $schema );
		$types  = (array) ( $schema['type'] ?? array() );

		if ( is_string( $value )
			&& ! in_array( 'string', $types, true )
			&& ( in_array( 'object', $types, true ) || in_array( 'array', $types, true ) )
			&& in_array( substr( ltrim( $value ), 0, 1 ), array( '{', '[' ), true )
		) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		// A list the model wrapped, only where the schema admits an array and
		// no object: {"item": [...]} / {"items": "x"} become the list, and a
		// bare string for an array of strings becomes a one-name list.
		if ( in_array( 'array', $types, true ) && ! in_array( 'object', $types, true ) ) {
			if ( is_array( $value ) && 1 === count( $value ) && ! array_is_list( $value ) ) {
				$key = (string) array_key_first( $value );
				if ( in_array( $key, array( 'item', 'items' ), true ) ) {
					$inner = $value[ $key ];
					if ( is_array( $inner ) && array_is_list( $inner ) ) {
						$value = $inner;
					} elseif ( is_scalar( $inner ) ) {
						$value = array( $inner );
					}
				}
			} elseif ( is_string( $value )
				&& ! in_array( 'string', $types, true )
				&& in_array( 'string', (array) ( is_array( $schema['items'] ?? null ) ? ( $schema['items']['type'] ?? array() ) : array() ), true )
			) {
				$value = array( $value );
			}
		}

		// A boolean sent as text ("true"/"false"), only where the schema
		// admits a boolean and not a string.
		if ( is_string( $value ) && in_array( strtolower( $value ), array( 'true', 'false' ), true )
			&& in_array( 'boolean', $types, true )
			&& ! in_array( 'string', $types, true )
		) {
			return 'true' === strtolower( $value );
		}

		if ( ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) )
			&& in_array( 'string', $types, true )
			&& ! in_array( 'number', $types, true )
			&& ! in_array( 'integer', $types, true )
		) {
			// Floats as a plain decimal, never an exponent (18.0 => "18").
			return is_int( $value ) ? (string) $value : rtrim( rtrim( sprintf( '%.15F', $value ), '0' ), '.' );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( is_array( $schema['properties'] ?? null ) ) {
			foreach ( $schema['properties'] as $key => $property ) {
				if ( array_key_exists( $key, $value ) && is_array( $property ) ) {
					$value[ $key ] = self::repairModelSlips( $value[ $key ], $property );
				}
			}
		}

		if ( is_array( $schema['items'] ?? null ) && array_is_list( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = self::repairModelSlips( $item, $schema['items'] );
			}
		}

		return $value;
	}

	/**
	 * Flatten a schema's `allOf`/`anyOf`/`oneOf` branches into one plain view
	 * (`type`, `properties`, `items`) for {@see self::repairModelSlips()}. A
	 * schema with no composite keyword is returned untouched.
	 *
	 * Fail closed: the view must never let a value be converted that some
	 * alternative could accept as it is. So a type is the union over every
	 * voter, and ANY voter with no `type` leaves the view typeless (no decoding,
	 * no conversion); a property or `items` schema is kept as written only when
	 * every voter that declares it declares it identically, else it shrinks to
	 * the union of the declarers' types (or nothing, if one has none). A branch
	 * that does not declare a property does not vote for it.
	 *
	 * @param array<string,mixed> $schema A JSON schema.
	 * @return array<string,mixed>
	 */
	private static function compositeView( array $schema ): array {
		$views = array();
		foreach ( array( 'allOf', 'anyOf', 'oneOf' ) as $keyword ) {
			if ( ! is_array( $schema[ $keyword ] ?? null ) ) {
				continue;
			}
			foreach ( $schema[ $keyword ] as $branch ) {
				if ( is_array( $branch ) ) {
					$views[] = self::compositeView( $branch );
				}
			}
		}

		if ( array() === $views ) {
			return $schema;
		}

		// The schema itself votes only for what it declares: a bare
		// {"oneOf":[...]} has no type of its own to contradict its branches.
		$own = array_intersect_key( $schema, array_flip( array( 'type', 'properties', 'items' ) ) );
		if ( array() !== $own ) {
			$views[] = $own;
		}

		$types = array();
		foreach ( $views as $view ) {
			if ( ! isset( $view['type'] ) ) {
				$types = null;
				break;
			}
			$types = array_merge( $types, (array) $view['type'] );
		}

		$merged = array();
		if ( null !== $types ) {
			$merged['type'] = array_values( array_unique( $types ) );
		}

		$names = array();
		foreach ( $views as $view ) {
			if ( is_array( $view['properties'] ?? null ) ) {
				$names = array_merge( $names, array_keys( $view['properties'] ) );
			}
		}
		foreach ( array_unique( $names ) as $name ) {
			$declared = array();
			foreach ( $views as $view ) {
				if ( is_array( $view['properties'][ $name ] ?? null ) ) {
					$declared[] = $view['properties'][ $name ];
				}
			}
			if ( array() !== $declared ) {
				$merged['properties'][ $name ] = self::agreedSchema( $declared );
			}
		}

		$declared = array();
		foreach ( $views as $view ) {
			if ( is_array( $view['items'] ?? null ) ) {
				$declared[] = $view['items'];
			}
		}
		if ( array() !== $declared ) {
			$merged['items'] = self::agreedSchema( $declared );
		}

		return $merged;
	}

	/**
	 * The one schema a set of declarations of the same property agree on.
	 *
	 * @param non-empty-list<array<mixed>> $declared Every voter's schema for it.
	 * @return array<string,mixed> The shared schema; else only the union of their types; else empty.
	 */
	private static function agreedSchema( array $declared ): array {
		if ( array() === array_filter( $declared, static fn ( array $schema ): bool => $schema !== $declared[0] ) ) {
			return $declared[0];
		}

		$types = array();
		foreach ( $declared as $schema ) {
			$type = self::compositeView( $schema )['type'] ?? null;
			if ( null === $type ) {
				return array();
			}
			$types = array_merge( $types, (array) $type );
		}

		return array( 'type' => array_values( array_unique( $types ) ) );
	}
}
