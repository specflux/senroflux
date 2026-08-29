<?php
/**
 * Function declarations for the harness-owned plan tool (0.2 S7).
 *
 * The plan tool is a second harness tool alongside ask-user (S6): it is NOT
 * an ability, never appears on the run's allow-list, is never governed by
 * Agent Safety, and is never routed through {@see ToolExecutor}. The Runner
 * declares it to the model and intercepts it by name BEFORE the executor and
 * BEFORE the plan fence. This factory owns the declaration shape and the
 * payload validation so the Runner neither knows about the JSON schema nor
 * invents its own rules.
 *
 * S7 names the tool `senroflux/propose-plan`. Its function name in a
 * declaration is `senroflux__propose-plan` (the 0.1 namespace mapping), and
 * its `tool_name` column value is `senroflux/propose-plan`.
 *
 * A plan is validated then annotated: each step carries the highest Agent
 * Safety tier among its verbs (S7 — "tier from Agent Safety's classifier,
 * never from the model"). Stage 4 resolves that tier through the site-wide
 * `senroflux_verb_map` filter (verb => int 0/1/2) via {@see VerbTier}; stage 6
 * replaces it with the pack's real verb map (the RUNNER re-resolves each call
 * through the same seam, so plan annotation and the fence agree).
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
 * Declarations + validation for the harness propose-plan tool.
 */
final class PlanTools {

	/**
	 * The harness tool's identity, in the two forms the repo uses.
	 */
	public const TOOL_NAME     = 'senroflux/propose-plan';
	public const FUNCTION_NAME = 'senroflux__propose-plan';

	/** Field caps/limits per S7. */
	public const MAX_GOAL_CHARS      = 200;
	public const MAX_STEPS           = 10;
	public const MAX_STEP_TEXT_CHARS = 200;
	public const MAX_ASSUMPTIONS     = 10;

	/** S7 invalid-payload code (a tool_result error, never an HTTP error). */
	public const ERROR_INVALID_PLAN = 'invalid_plan';

	/**
	 * 0.2 S7 says nothing about what happens if the model calls propose-plan at
	 * zero remaining (the tool is withdrawn). Fail closed: it is refused with
	 * this code and still counts as a tool call. (See NOTES.md.)
	 */
	public const ERROR_PLANS_EXHAUSTED = 'plans_exhausted';

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
	 * The plan tool is withdrawn once `remaining_plans` hits zero (S7), so this
	 * returns an empty map then — the Runner passes whatever it yields into the
	 * registry handed to the model.
	 *
	 * @param int $remaining_plans Live count of remaining plans.
	 * @return array<string, FunctionDeclaration|array<string,mixed>>
	 */
	public static function declarations( int $remaining_plans ): array {
		if ( $remaining_plans <= 0 ) {
			return array();
		}

		return array( self::TOOL_NAME => self::proposePlanDeclaration() );
	}

	/**
	 * One FunctionDeclaration for `senroflux__propose-plan`, built directly
	 * (the harness tool is not an ability, so it cannot come from an ability's
	 * get_input_schema()).
	 *
	 * Mirrors {@see ToolRegistry::declarationFor()} and
	 * {@see HarnessTools::askUserDeclaration()}: when the AI Client SDK is
	 * present we build the real DTO; otherwise we hand back the array shape so
	 * SDK-less contexts (tests) still see the same contract.
	 *
	 * @return FunctionDeclaration|array<string,mixed>
	 */
	public static function proposePlanDeclaration(): FunctionDeclaration|array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'goal'        => array(
					'type'        => 'string',
					'maxLength'   => self::MAX_GOAL_CHARS,
					'description' => __( 'The goal this plan proposes to achieve.', 'senroflux' ),
				),
				'steps'       => array(
					'type'        => 'array',
					'maxItems'    => self::MAX_STEPS,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'text'  => array(
								'type'        => 'string',
								'maxLength'   => self::MAX_STEP_TEXT_CHARS,
								'description' => __( 'One ordered step of the plan.', 'senroflux' ),
							),
							'verbs' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => __( 'The Agent Safety verbs this step uses.', 'senroflux' ),
							),
						),
						'required'             => array( 'text', 'verbs' ),
						// Fail closed: a step may not smuggle extra fields in.
						'additionalProperties' => false,
					),
					'description' => __( 'The ordered steps the plan will carry out.', 'senroflux' ),
				),
				'assumptions' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => self::MAX_ASSUMPTIONS,
					'description' => __( 'Assumptions the plan rests on, if any.', 'senroflux' ),
				),
			),
			'required'             => array( 'goal', 'steps' ),
			// Fail closed: the model may not smuggle extra fields in.
			'additionalProperties' => false,
		);

		$description = __(
			'Propose a step-by-step plan for the current goal and stop to wait for the user to review it before performing any Tier-1 or Tier-2 work. Widening scope after acceptance means proposing a new plan.',
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
	 * Validate and normalize a propose-plan payload (S7), annotating every step
	 * with the highest Agent Safety tier among its verbs.
	 *
	 * On success returns the VALIDATED payload (defaults applied: assumptions
	 * defaults to []; each step gains an int `tier`). On failure returns a
	 * WP_Error with code {@see self::ERROR_INVALID_PLAN} — the Runner turns
	 * that into a tool_result error the model sees.
	 *
	 * The tier annotation uses {@see VerbTier::tierFor()} against the
	 * `senroflux_verb_map` filter; when `$run_id` is given it is threaded to the
	 * filter so stage 6 can resolve the pack's per-run verb map (stage 4 relies
	 * on the site-wide default and the Runner resolves call tiers the same way).
	 *
	 * @param mixed    $args   The function call's args (arbitrary, from the model).
	 * @param int|null $run_id Optional run id, threaded to the verb-map filter.
	 * @return array<string,mixed>|WP_Error Validated payload or an error.
	 */
	public static function validateProposePlan( mixed $args, ?int $run_id = null ): array|WP_Error {
		$invalid = static fn ( string $what ): WP_Error => new WP_Error(
			self::ERROR_INVALID_PLAN,
			/* translators: %s names the offending field. */
			sprintf( __( 'Invalid propose-plan call: %s', 'senroflux' ), $what )
		);

		if ( ! is_array( $args ) ) {
			return $invalid( __( 'the payload must be an object.', 'senroflux' ) );
		}

		// goal: required, ≤ 200 chars.
		$goal = $args['goal'] ?? null;
		if ( ! is_string( $goal ) || '' === trim( $goal ) ) {
			return $invalid( __( 'a non-empty "goal" is required.', 'senroflux' ) );
		}
		if ( mb_strlen( $goal ) > self::MAX_GOAL_CHARS ) {
			return $invalid(
				sprintf(
					/* translators: %d is the character cap. */
					__( '"goal" may be at most %d characters.', 'senroflux' ),
					self::MAX_GOAL_CHARS
				)
			);
		}

		// steps: required, non-empty, ≤ 10, each { text ≤ 200, verbs non-empty }.
		$steps = $args['steps'] ?? null;
		if ( ! is_array( $steps ) ) {
			return $invalid( __( '"steps" must be an array of steps.', 'senroflux' ) );
		}

		$normalized_steps = array();
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				return $invalid( __( 'every "steps" entry must be an object.', 'senroflux' ) );
			}

			$text = $step['text'] ?? null;
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				return $invalid( __( 'every step needs a non-empty "text".', 'senroflux' ) );
			}
			if ( mb_strlen( $text ) > self::MAX_STEP_TEXT_CHARS ) {
				return $invalid(
					sprintf(
						/* translators: %d is the character cap. */
						__( 'a step "text" may be at most %d characters.', 'senroflux' ),
						self::MAX_STEP_TEXT_CHARS
					)
				);
			}

			$verbs = $step['verbs'] ?? null;
			if ( ! is_array( $verbs ) ) {
				return $invalid( __( 'every step needs a "verbs" array.', 'senroflux' ) );
			}

			$normalized_verbs = array();
			foreach ( $verbs as $verb ) {
				if ( ! is_string( $verb ) || '' === trim( $verb ) ) {
					return $invalid( __( 'every "verbs" entry must be a non-empty string.', 'senroflux' ) );
				}
				$normalized_verbs[] = $verb;
			}
			if ( array() === $normalized_verbs ) {
				return $invalid( __( 'every step needs at least one verb.', 'senroflux' ) );
			}

			// S7: annotate the step with the highest tier among its verbs.
			$tier = 0;
			foreach ( $normalized_verbs as $verb ) {
				$tier = max( $tier, VerbTier::tierFor( $verb, null, $run_id ) );
			}

			$normalized_steps[] = array(
				'text'  => $text,
				'verbs' => $normalized_verbs,
				'tier'  => $tier,
			);
		}

		if ( count( $normalized_steps ) > self::MAX_STEPS ) {
			return $invalid(
				sprintf(
					/* translators: %d is the maximum number of steps. */
					__( '"steps" may contain at most %d entries.', 'senroflux' ),
					self::MAX_STEPS
				)
			);
		}

		// assumptions: optional, array of strings, ≤ 10. Null coalescing
		// already mapped an absent or null field onto the empty array.
		$assumptions = $args['assumptions'] ?? array();
		if ( ! is_array( $assumptions ) ) {
			return $invalid( __( '"assumptions" must be an array of strings.', 'senroflux' ) );
		}
		$normalized_assumptions = array();
		foreach ( $assumptions as $assumption ) {
			if ( ! is_string( $assumption ) ) {
				return $invalid( __( 'every "assumptions" entry must be a string.', 'senroflux' ) );
			}
			$normalized_assumptions[] = $assumption;
		}
		if ( count( $normalized_assumptions ) > self::MAX_ASSUMPTIONS ) {
			return $invalid(
				sprintf(
					/* translators: %d is the maximum number of assumptions. */
					__( '"assumptions" may contain at most %d entries.', 'senroflux' ),
					self::MAX_ASSUMPTIONS
				)
			);
		}

		return array(
			'goal'        => $goal,
			'steps'       => $normalized_steps,
			'assumptions' => $normalized_assumptions,
		);
	}

	/**
	 * remaining_plans = max_plans − count(plan steps), floored at 0.
	 *
	 * @param int $max_plans The run's max_plans ceiling.
	 * @param int $used      The number of plan steps persisted so far.
	 */
	public static function remaining( int $max_plans, int $used ): int {
		return max( 0, $max_plans - $used );
	}
}
