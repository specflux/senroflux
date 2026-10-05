<?php
/**
 * Test double for WP's `wp_ai_client_prompt()` global (WP_AI_Client_Prompt_Builder,
 * defined in wp-includes/ai-client) — just enough fluent surface for
 * AiClientMediaGateway (`using_request_options()`, `with_file()`) and
 * AiClientGateway (`using_system_instruction()`, `using_function_declarations()`,
 * `using_model_preference()`), all recorded, plus the two generating methods,
 * scripted one result per call.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

if ( ! class_exists( 'SenroFlux_Test_Fake_Prompt_Builder', false ) ) {
	final class SenroFlux_Test_Fake_Prompt_Builder {

		/** @var mixed A string prompt (media calls) or a history array (AiClientGateway). */
		private mixed $prompt;

		private ?RequestOptions $requestOptions = null;

		private ?File $file = null;

		private string $systemInstruction = '';

		/** @var list<FunctionDeclaration> */
		private array $functionDeclarations = array();

		/** @var array{0:string,1:string}|null */
		private ?array $modelPreference = null;

		public function __construct( mixed $prompt ) {
			$this->prompt = $prompt;
		}

		public function using_request_options( RequestOptions $options ): self {
			$this->requestOptions = $options;

			return $this;
		}

		public function using_system_instruction( string $system_instruction ): self {
			$this->systemInstruction = $system_instruction;

			return $this;
		}

		public function using_function_declarations( FunctionDeclaration ...$declarations ): self {
			$this->functionDeclarations = $declarations;

			return $this;
		}

		/** @param array{0:string,1:string}|null $model_preference */
		public function using_model_preference( ?array $model_preference ): self {
			$this->modelPreference = $model_preference;

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
				'prompt'                => $this->prompt,
				'request_options'       => $this->requestOptions,
				'file'                  => $this->file,
				'system_instruction'    => $this->systemInstruction,
				'function_declarations' => $this->functionDeclarations,
				'model_preference'      => $this->modelPreference,
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
		return new SenroFlux_Test_Fake_Prompt_Builder( $prompt );
	}
}
