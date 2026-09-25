<?php
/**
 * Test double for WP's `wp_ai_client_prompt()` global (WP_AI_Client_Prompt_Builder,
 * defined in wp-includes/ai-client) — just enough fluent surface for
 * AiClientMediaGateway: `using_request_options()` (recorded) and the two
 * generating methods it calls, scripted one result per call.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

if ( ! class_exists( 'SenroFlux_Test_Fake_Prompt_Builder', false ) ) {
	final class SenroFlux_Test_Fake_Prompt_Builder {

		private string $prompt;

		private ?RequestOptions $requestOptions = null;

		private ?File $file = null;

		public function __construct( string $prompt ) {
			$this->prompt = $prompt;
		}

		public function using_request_options( RequestOptions $options ): self {
			$this->requestOptions = $options;

			return $this;
		}

		/**
		 * Mirrors PromptBuilder::withFile(): a File object or a string
		 * (URL/base64/data-URI/local path) that File itself detects.
		 *
		 * @param File|string $file The file (real vendor DTO detects the shape).
		 */
		public function with_file( File|string $file ): self {
			$this->file = $file instanceof File ? $file : new File( $file );

			return $this;
		}

		/** @return mixed */
		private function nextResult() {
			$GLOBALS['senroflux_test_prompt_builder_calls'][] = array(
				'prompt'          => $this->prompt,
				'request_options' => $this->requestOptions,
				'file'            => $this->file,
			);

			$script = $GLOBALS['senroflux_test_prompt_builder_script'] ?? array();
			$next   = array_shift( $script );
			$GLOBALS['senroflux_test_prompt_builder_script'] = $script;

			return null !== $next ? $next() : null;
		}

		/** @return mixed */
		public function generate_image_result() {
			return $this->nextResult();
		}

		/** @return mixed */
		public function generate_text_result() {
			return $this->nextResult();
		}
	}
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	function wp_ai_client_prompt( $prompt = null ): SenroFlux_Test_Fake_Prompt_Builder {
		return new SenroFlux_Test_Fake_Prompt_Builder( (string) $prompt );
	}
}
