<?php
/**
 * Function declarations for the harness-owned tools (0.2 S6).
 *
 * The harness tools are NOT abilities: they never appear on the run's
 * allow-list, are never governed by Agent Safety, and are never routed
 * through {@see ToolExecutor}. They are declared to the model by the Runner
 * and intercepted by name BEFORE the executor. This factory owns the
 * declaration shape and the payload validation so the Runner neither knows
 * about the JSON schema nor invents its own rules.
 *
 * S6 names the only harness tool today: `senroflux/ask-user`. Its function
 * name in a declaration is `senroflux__ask-user` (the 0.1 namespace mapping),
 * and its `tool_name` column value is `senroflux/ask-user`.
 *
 * The difference from an ability is deliberate: a harness tool has no Agent
 * Safety tier, no allow-list entry and no `wpab__` prefix, so
 * `functionNameFor()`/`abilityName()` MUST NOT round-trip it — the answer path
 * uses {@see self::functionName()} directly instead of the ability mapping.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

use WP_Error;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Declarations + validation for the harness ask-the-user tool.
 */
final class HarnessTools {

	/**
	 * The harness tool's identity, in the two forms the repo uses.
	 */
	public const TOOL_NAME     = 'senroflux/ask-user';
	public const FUNCTION_NAME = 'senroflux__ask-user';

	/** Field caps/limits per S6. */
	public const MAX_TEXT_CHARS = 300;
	public const MAX_CHOICES    = 6;

	/** S6 invalid-payload code (a tool_result error, never an HTTP error). */
	public const ERROR_INVALID_QUESTION = 'invalid_question';

	/**
	 * 0.2 S6 says nothing about what happens if the model calls ask-user at
	 * zero remaining (the tool is withdrawn). Fail closed: it is refused with
	 * this code and still counts as a tool call.
	 */
	public const ERROR_QUESTIONS_EXHAUSTED = 'questions_exhausted';

	/**
	 * The function name exposed to the model (no `wpab__` prefix).
	 */
	public static function functionName(): string {
		return self::FUNCTION_NAME;
	}

	/**
	 * The `tool_name` column value (S4 step convention).
	 */
	public static function toolName(): string {
		return self::TOOL_NAME;
	}

	/**
	 * Zero or more harness declarations for the run's tool surface, keyed by
	 * the harness tool name so they merge onto the registry's declaration map.
	 *
	 * The harness tool is withdrawn once `remaining_questions` hits zero
	 * (S6), so this returns an empty map then — the Runner passes whatever
	 * it yields into the registry handed to the model.
	 *
	 * @param int $remaining_questions Live count of remaining questions.
	 * @return array<string, FunctionDeclaration|array<string,mixed>>
	 */
	public static function declarations( int $remaining_questions ): array {
		if ( $remaining_questions <= 0 ) {
			return array();
		}

		return array( self::TOOL_NAME => self::askUserDeclaration() );
	}

	/**
	 * One FunctionDeclaration for `senroflux__ask-user`, built directly (the
	 * harness tool is not an ability, so it cannot come from an ability's
	 * get_input_schema()).
	 *
	 * Mirrors {@see ToolRegistry::declarationFor()}: when the AI Client SDK is
	 * present we build the real DTO; otherwise we hand back the array shape so
	 * SDK-less contexts (tests) still see the same contract.
	 *
	 * @return FunctionDeclaration|array<string,mixed>
	 */
	public static function askUserDeclaration(): FunctionDeclaration|array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'text'        => array(
					'type'        => 'string',
					'maxLength'   => self::MAX_TEXT_CHARS,
					'description' => __( 'The clarifying question to the user.', 'senroflux' ),
				),
				'choices'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => self::MAX_CHOICES,
					'description' => __( 'Optional fixed choices for the user to pick from.', 'senroflux' ),
				),
				'allow_other' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Whether the user may type a free-text answer instead of a choice.', 'senroflux' ),
				),
				'default'     => array(
					'type'        => 'string',
					'description' => __( 'Optional default/placeholder answer.', 'senroflux' ),
				),
				'rationale'   => array(
					'type'        => 'string',
					'description' => __( 'One line explaining why you are asking — the user sees it.', 'senroflux' ),
				),
			),
			'required'             => array( 'text', 'rationale' ),
			// Fail closed: the model may not smuggle extra fields in.
			'additionalProperties' => false,
		);

		$description = __(
			'Ask the user a clarifying question and stop to wait for their answer before continuing. Ask one question per call.',
			'senroflux'
		);

		if ( class_exists( FunctionDeclaration::class ) ) {
			return new FunctionDeclaration( self::FUNCTION_NAME, $description, $schema );
		}

		return array(
			'name'        => self::FUNCTION_NAME,
			'description' => $description,
			'inputSchema' => $schema,
		);
	}

	/**
	 * Validate and normalize an ask-user payload (S6).
	 *
	 * On success returns the VALIDATED payload with defaults applied
	 * (allow_other defaults to true; choices default to []; default to '';
	 * both required fields checked). On failure returns a WP_Error with code
	 * {@see self::ERROR_INVALID_QUESTION} — the Runner turns that into a
	 * tool_result error the model sees.
	 *
	 * @param mixed $args The function call's args (arbitrary, from the model).
	 * @return array<string,mixed>|WP_Error Validated payload or an error.
	 */
	public static function validateAskUser( mixed $args ): array|WP_Error {
		$invalid = static fn ( string $what ): WP_Error => new WP_Error(
			self::ERROR_INVALID_QUESTION,
			/* translators: %s names the offending field. */
			sprintf( __( 'Invalid ask-user call: %s', 'senroflux' ), $what )
		);

		if ( ! is_array( $args ) ) {
			return $invalid( __( 'the payload must be an object.', 'senroflux' ) );
		}

		// text: required, ≤ 300 chars.
		$text = $args['text'] ?? null;
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $invalid( __( 'a non-empty "text" is required.', 'senroflux' ) );
		}
		if ( mb_strlen( $text ) > self::MAX_TEXT_CHARS ) {
			return $invalid(
				sprintf(
				/* translators: %d is the character cap. */
					__( '"text" may be at most %d characters.', 'senroflux' ),
					self::MAX_TEXT_CHARS
				)
			);
		}

		// rationale: required, one line.
		$rationale = $args['rationale'] ?? null;
		if ( ! is_string( $rationale ) || '' === trim( $rationale ) ) {
			return $invalid( __( 'a non-empty "rationale" is required.', 'senroflux' ) );
		}
		if ( str_contains( $rationale, "\n" ) || str_contains( $rationale, "\r" ) ) {
			return $invalid( __( '"rationale" must be a single line.', 'senroflux' ) );
		}

		// choices: optional, array of strings, ≤ 6. Null coalescing already
		// mapped an absent or null field onto the empty array.
		$choices = $args['choices'] ?? array();
		if ( ! is_array( $choices ) ) {
			return $invalid( __( '"choices" must be an array of strings.', 'senroflux' ) );
		}
		$normalized_choices = array();
		foreach ( $choices as $choice ) {
			if ( ! is_string( $choice ) ) {
				return $invalid( __( 'every "choices" entry must be a string.', 'senroflux' ) );
			}
			$normalized_choices[] = $choice;
		}
		if ( count( $normalized_choices ) > self::MAX_CHOICES ) {
			return $invalid(
				sprintf(
				/* translators: %d is the maximum number of choices. */
					__( '"choices" may contain at most %d entries.', 'senroflux' ),
					self::MAX_CHOICES
				)
			);
		}

		// allow_other: optional, boolean, default true.
		$allow_other = $args['allow_other'] ?? true;
		if ( ! is_bool( $allow_other ) ) {
			return $invalid( __( '"allow_other" must be a boolean.', 'senroflux' ) );
		}

		// default: optional, string, default ''. Null coalescing already
		// mapped an absent or null field onto the default.
		$default = $args['default'] ?? '';
		if ( ! is_string( $default ) ) {
			return $invalid( __( '"default" must be a string.', 'senroflux' ) );
		}

		return array(
			'text'        => $text,
			'choices'     => $normalized_choices,
			'allow_other' => $allow_other,
			'default'     => $default,
			'rationale'   => $rationale,
		);
	}

	/**
	 * remaining_questions = max_questions − count(question steps), floored at 0.
	 *
	 * @param int $max_questions The run's max_questions ceiling.
	 * @param int $used          The number of question steps persisted so far.
	 */
	public static function remaining( int $max_questions, int $used ): int {
		return max( 0, $max_questions - $used );
	}
}
