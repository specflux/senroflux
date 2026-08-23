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
			if ( is_callable( $this->execute_result ) ) {
				return ( $this->execute_result )( $input );
			}

			return $this->execute_result;
		}
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
