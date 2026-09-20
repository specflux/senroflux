<?php
/**
 * Function declaration for the harness-owned brief-suggestion tool (0.3 S20).
 *
 * A third harness tool alongside ask-user (S6) and propose-plan (S7): it is
 * NOT an ability, never appears on the run's allow-list, is never governed
 * by Agent Safety (Tier 0), and is never routed through {@see ToolExecutor}.
 * Unlike the other two harness tools it PARKS NOTHING — the Runner answers
 * it synchronously, in the same tick, because the model never writes the
 * site brief: only a human clicking Save on the endpoint does that.
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
 * Declarations + validation for `senroflux/suggest-brief-addition`.
 */
final class SuggestBriefTool {

	/**
	 * The harness tool's identity, in the two forms the repo uses.
	 */
	public const TOOL_NAME     = 'senroflux/suggest-brief-addition';
	public const FUNCTION_NAME = 'senroflux__suggest-brief-addition';

	/** S20 `[assumed]`: the suggestion text cap. */
	public const MAX_TEXT_CHARS = 200;

	/** S20: at most 3 accepted suggestions per run. */
	public const MAX_PER_RUN = 3;

	/** S20 refusal codes (tool_result errors, never HTTP errors). */
	public const ERROR_INVALID              = 'invalid_suggestion';
	public const ERROR_SUGGESTION_LIMIT     = 'suggestion_limit';
	public const ERROR_SUGGESTION_DISMISSED = 'suggestion_dismissed';

	/** The function name exposed to the model (no `wpab__` prefix). */
	public static function functionName(): string {
		return self::FUNCTION_NAME;
	}

	/** The `tool_name` column value (S4 step convention). */
	public static function toolName(): string {
		return self::TOOL_NAME;
	}

	/**
	 * Always declared: unlike ask-user/propose-plan, being at the per-run
	 * limit does not withdraw the tool — a further call is refused with
	 * `suggestion_limit`, not hidden.
	 *
	 * @return array<string, FunctionDeclaration|array<string,mixed>>
	 */
	public static function declarations(): array {
		return array( self::TOOL_NAME => self::declaration() );
	}

	/**
	 * @return FunctionDeclaration|array<string,mixed>
	 */
	public static function declaration(): FunctionDeclaration|array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'text' => array(
					'type'        => 'string',
					'maxLength'   => self::MAX_TEXT_CHARS,
					'description' => __( 'A short addition to suggest for the site brief.', 'senroflux' ),
				),
			),
			'required'             => array( 'text' ),
			// Fail closed: the model may not smuggle extra fields in.
			'additionalProperties' => false,
		);

		$description = __(
			'Suggest one short addition to the site owner\'s standing brief. The site owner reviews it and decides whether to save it — you never write the brief yourself.',
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
	 * Validate a suggest-brief-addition payload.
	 *
	 * @param mixed $args The function call's args (arbitrary, from the model).
	 * @return array{text:string}|WP_Error
	 */
	public static function validate( mixed $args ): array|WP_Error {
		$invalid = static fn ( string $what ): WP_Error => new WP_Error(
			self::ERROR_INVALID,
			/* translators: %s names the offending field. */
			sprintf( __( 'Invalid suggest-brief-addition call: %s', 'senroflux' ), $what )
		);

		if ( ! is_array( $args ) ) {
			return $invalid( __( 'the payload must be an object.', 'senroflux' ) );
		}

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

		return array( 'text' => trim( $text ) );
	}

	/** Normalise text for the dismissed-repeat comparison: trim + lower-case. */
	public static function normalize( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $text ) ) : strtolower( trim( $text ) );
	}
}
