<?php
/**
 * Thin wrapper over the WordPress AI Client prompt builder.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The ONE place a run's model calls go through, so the Runner is unit-tested
 * with this gateway mocked and never touches the AI Client API surface
 * directly. One call = exactly one model turn (S9: a tick never makes more
 * than one).
 */
final class AiClientGateway implements ModelGatewayInterface {

	/**
	 * Live run evidence: reasoning models on a large run context routinely
	 * exceed WordPress core's own AI Client default of 30s ("cURL error 28:
	 * Operation timed out"). Mirrors {@see AiClientMediaGateway}'s fix for
	 * the same core default, but filterable here (S9: unlike a one-shot
	 * media call, a run's own step/token budget already bounds how much a
	 * slow turn can cost, so a site can tune it without a code change).
	 */
	private const DEFAULT_TIMEOUT_SECONDS = 120.0;

	/** {@inheritDoc} */
	public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'The WordPress AI Client is not available. WordPress 7.0+ is required.', 'senroflux' )
			);
		}

		try {
			$request_options = new RequestOptions();
			$request_options->setTimeout( self::requestTimeout() );

			$builder = wp_ai_client_prompt( $history )
				->using_system_instruction( $system_instruction )
				->using_request_options( $request_options );

			$declarations = array();
			foreach ( $tools->declarations() as $declaration ) {
				if ( $declaration instanceof FunctionDeclaration ) {
					$declarations[] = $declaration;
				}
			}
			if ( array() !== $declarations ) {
				$builder = $builder->using_function_declarations( ...$declarations );
			}

			// A preference, not a mandate: an unavailable model falls back to auto-selection.
			if ( null !== $model_preference ) {
				$builder = $builder->using_model_preference( $model_preference );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			// The AI Client's own HTTP exceptions (ServerException,
			// ClientException) carry the real HTTP status as the exception
			// code; a NetworkException (timeouts, DNS, connection resets)
			// carries 0. The Runner reads this to tell a transient failure
			// (worth retrying) from a fatal one, without parsing prose.
			return new WP_Error( 'gateway_failed', $e->getMessage(), array( 'status' => $e->getCode() ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tokens_in  = 0;
		$tokens_out = 0;
		try {
			$usage      = $result->getTokenUsage();
			$tokens_in  = $usage->getPromptTokens();
			$tokens_out = $usage->getCompletionTokens();
		} catch ( \Throwable $e ) { // Usage is best-effort; never fail a turn over it.
			unset( $e );
		}

		return new ModelTurn( $result->toMessage(), $tokens_in, $tokens_out );
	}

	/**
	 * Per-request timeout in seconds, filterable per site. A non-numeric or
	 * non-positive override is a misconfiguration, not a request for "no
	 * timeout" — {@see RequestOptions::setTimeout()} itself rejects negative
	 * values, but a filter returning e.g. `0` or a string would otherwise
	 * reach it uncaught.
	 *
	 * @since 0.4.0
	 */
	private static function requestTimeout(): float {
		/**
		 * Filters the per-request timeout (seconds) for a run's own model
		 * turns. Does not affect other AI Client callers — set
		 * `wp_ai_client_default_request_timeout` for that. `@internal`.
		 *
		 * @param float $timeout Seconds. Default 120.0.
		 */
		$timeout = apply_filters( 'senroflux_model_request_timeout', self::DEFAULT_TIMEOUT_SECONDS );

		return is_numeric( $timeout ) && (float) $timeout > 0
			? (float) $timeout
			: self::DEFAULT_TIMEOUT_SECONDS;
	}
}
