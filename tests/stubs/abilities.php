<?php
/**
 * Test-only stand-ins for the Abilities API surface: a duck-typed WP_Ability
 * whose permission/execute behaviour is injected per-test, plus the global
 * wp_get_ability()/wp_get_abilities() shims backed by a fixture map.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'SenroFlux_Test_Fake_Ability' ) ) {
	/**
	 * Duck-typed ability: exercises the exact accessor shape ToolRegistry and
	 * ToolExecutor call (get_name/get_description/get_input_schema/get_meta/
	 * check_permissions/execute) without WordPress.
	 */
	class SenroFlux_Test_Fake_Ability {

		/** @var callable|null */
		public $on_execute = null;

		public function __construct(
			private string $name,
			private mixed $permission_result = true,
			private mixed $execute_result = array( 'ok' => true ),
			private string $description = '',
			private ?array $input_schema = null,
			private array $meta = array(),
			private ?array $output_schema = null,
		) {
		}

		public function get_name(): string {
			return $this->name;
		}

		public function get_description(): string {
			return $this->description;
		}

		public function get_input_schema(): ?array {
			return $this->input_schema;
		}

		public function get_output_schema(): ?array {
			return $this->output_schema;
		}

		public function get_meta(): array {
			return $this->meta;
		}

		public function check_permissions( mixed $input = null ): mixed {
			if ( is_callable( $this->permission_result ) ) {
				return ( $this->permission_result )( $input );
			}

			return $this->permission_result;
		}

		public function execute( mixed $input = null ): mixed {
			if ( is_callable( $this->on_execute ) ) {
				( $this->on_execute )( $input );
			}
			$result = is_callable( $this->execute_result ) ? ( $this->execute_result )( $input ) : $this->execute_result;

			// Real WP_Ability::execute() validates the result against the
			// output schema and turns a mismatch into an error the model sees.
			if ( null !== $this->output_schema && ! $result instanceof \WP_Error ) {
				$reason = senroflux_test_schema_violation( $result, $this->output_schema );
				if ( null !== $reason ) {
					return new \WP_Error( 'ability_invalid_output', sprintf( 'Ability "%s" has invalid output. Reason: %s', $this->name, $reason ) );
				}
			}

			return $result;
		}
	}
}

if ( ! function_exists( 'senroflux_test_schema_violation' ) ) {
	/**
	 * The subset of JSON Schema that WordPress's rest_validate_value_from_schema()
	 * enforces and SenroFlux's schemas use: type, properties, required,
	 * additionalProperties:false, items, enum. Null when the value conforms.
	 *
	 * @param mixed               $value  Value to check.
	 * @param array<string,mixed> $schema Schema.
	 * @param string              $path   Path for the message.
	 */
	function senroflux_test_schema_violation( mixed $value, array $schema, string $path = 'output' ): ?string {
		$types = isset( $schema['type'] ) ? (array) $schema['type'] : array();
		if ( array() !== $types ) {
			$matches = false;
			foreach ( $types as $type ) {
				$matches = $matches || match ( $type ) {
					'object'  => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ),
					'array'   => is_array( $value ) && array_is_list( $value ),
					'string'  => is_string( $value ),
					'integer' => is_int( $value ),
					'number'  => is_int( $value ) || is_float( $value ),
					'boolean' => is_bool( $value ),
					'null'    => null === $value,
					default   => true,
				};
			}
			if ( ! $matches ) {
				return sprintf( '%s is not of type %s.', $path, implode( ',', $types ) );
			}
		}
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return sprintf( '%s is not one of %s.', $path, implode( ', ', array_map( 'strval', $schema['enum'] ) ) );
		}
		if ( is_array( $value ) && ! array_is_list( $value ) ) {
			$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				if ( ! array_key_exists( $required, $value ) ) {
					return sprintf( '%s is a required property of %s.', $required, $path );
				}
			}
			foreach ( $value as $key => $item ) {
				if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
					$reason = senroflux_test_schema_violation( $item, $properties[ $key ], $path . '[' . $key . ']' );
					if ( null !== $reason ) {
						return $reason;
					}
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					return sprintf( '%s is not a valid property of Object.', $key );
				}
			}
		}
		if ( is_array( $value ) && array_is_list( $value ) && is_array( $schema['items'] ?? null ) ) {
			foreach ( $value as $index => $item ) {
				$reason = senroflux_test_schema_violation( $item, $schema['items'], $path . '[' . $index . ']' );
				if ( null !== $reason ) {
					return $reason;
				}
			}
		}

		return null;
	}
}

// Global Abilities-API shims — only when real WordPress isn't already loaded.
if ( ! function_exists( 'wp_get_ability' ) ) {
	/**
	 * Fixture-backed lookup.
	 *
	 * @param string $name Ability name.
	 */
	function wp_get_ability( string $name ): ?object {
		return $GLOBALS['senroflux_test_abilities'][ $name ] ?? null;
	}
}

if ( ! function_exists( 'wp_has_ability' ) ) {
	/**
	 * Fixture-backed probe — mirrors wp_get_ability()'s store so Pack's
	 * silent step-aside probe stays in sync with the unit fixtures.
	 *
	 * @param string $name Ability name.
	 */
	function wp_has_ability( string $name ): bool {
		return isset( $GLOBALS['senroflux_test_abilities'][ $name ] );
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * Fixture-backed list.
	 *
	 * @return array<string, object>
	 */
	function wp_get_abilities(): array {
		return $GLOBALS['senroflux_test_abilities'] ?? array();
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Standard shim.
	 *
	 * @param mixed $thing Thing to check.
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Recording shim: appends every call to a global list instead of
	 * triggering a real E_USER_NOTICE (which PHPUnit's `failOnWarning` would
	 * turn into a test failure) so a test can assert one fired without the
	 * suite dying on it (S19 — `PackRegistry::register()`'s refusal notice).
	 *
	 * @param string $function_name The offending function/method.
	 * @param string $message       The notice message.
	 * @param string $version       The version the notice was added in.
	 */
	function _doing_it_wrong( string $function_name, string $message, string $version ): void {
		$GLOBALS['senroflux_test_doing_it_wrong'][] = array(
			'function' => $function_name,
			'message'  => $message,
			'version'  => $version,
		);
	}
}
