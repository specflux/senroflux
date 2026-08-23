<?php
/**
 * Test-only stand-in for WordPress's WP_Error.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error: code + message + mixed data, real accessor names.
	 */
	class WP_Error {

		/** @var list<array{code:string,message:string}> */
		private array $errors = array();

		/** @var mixed */
		private mixed $error_data = null;

		public function __construct( string $code = '', string $message = '', mixed $data = array() ) {
			if ( '' !== $code ) {
				$this->errors[]   = array(
					'code'    => $code,
					'message' => $message,
				);
				$this->error_data = $data;
			}
		}

		public function get_error_code(): string {
			return $this->errors[0]['code'] ?? '';
		}

		public function get_error_message(): string {
			return $this->errors[0]['message'] ?? '';
		}

		/** @return mixed */
		public function get_error_data() {
			return $this->error_data;
		}
	}
}
