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

	/** {@inheritDoc} */
	public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools ): ModelTurn|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'gateway_unavailable',
				__( 'The WordPress AI Client is not available. WordPress 7.0+ is required.', 'senroflux' )
			);
		}

		try {
			$builder = wp_ai_client_prompt( $history )
				->using_system_instruction( $system_instruction );

			$declarations = array();
			foreach ( $tools->declarations() as $declaration ) {
				if ( $declaration instanceof FunctionDeclaration ) {
					$declarations[] = $declaration;
				}
			}
			if ( array() !== $declarations ) {
				$builder = $builder->using_function_declarations( ...$declarations );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gateway_failed', $e->getMessage() );
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
}
