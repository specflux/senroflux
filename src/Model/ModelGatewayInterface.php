<?php
/**
 * Model-gateway contract (the Runner's single seam to the AI Client).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Model;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Implemented by {@see AiClientGateway}; mocked in Runner tests so the whole
 * S4 tick algorithm is verifiable without a provider.
 */
interface ModelGatewayInterface {

	/**
	 * Generate ONE model turn (S9: a tick never makes more than one).
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $history           History messages.
	 * @param string                                          $system_instruction System instruction.
	 * @param \Specflux\SenroFlux\Tools\ToolRegistry          $tools             Admitted tools.
	 * @return \Specflux\SenroFlux\Model\ModelTurn|\WP_Error
	 */
	public function generateTurn( array $history, string $system_instruction, \Specflux\SenroFlux\Tools\ToolRegistry $tools ): ModelTurn|WP_Error;
}
