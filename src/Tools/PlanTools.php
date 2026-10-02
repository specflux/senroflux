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
	public const MAX_OBJECT_LIST     = 20;

	/**
	 * The character caps above are what the model is told (schema maxLength,
	 * refusals); enforcement sits this far above them. A model cannot count
	 * characters, and live runs resubmitted near misses (207, 203, 203 against
	 * 200) for a full turn each.
	 */
	public const LENGTH_TOLERANCE_PERCENT = 25;

	/** S7 invalid-payload code (a tool_result error, never an HTTP error). */
	public const ERROR_INVALID_PLAN = 'invalid_plan';

	/**
	 * A plan naming a verb the run cannot produce. Distinct from the generic
	 * invalid_plan on purpose: the model can only fix a hallucinated verb if it
	 * is told THAT is what was wrong, and a plan whose verbs mean nothing to
	 * the fence would otherwise be accepted by a human and then refuse every
	 * call it authorises.
	 */
	public const ERROR_UNKNOWN_VERB = 'unknown_verb';

	/**
	 * 0.2 S7 says nothing about what happens if the model calls propose-plan at
	 * zero remaining (the tool is withdrawn). Fail closed: it is refused with
	 * this code and still counts as a tool call.
	 */
	public const ERROR_PLANS_EXHAUSTED = 'plans_exhausted';

	/**
	 * 0.3 quality fix (live run 2026-09-27, scenario-2-1): a plan that creates a
	 * page but names no way to get an image ran the SAME failure loop
	 * `ERROR_UNKNOWN_VERB` exists to prevent — the page write was refused
	 * `page_needs_image` (see {@see \Specflux\SenroFlux\Packs\Content\Abilities::executeCreatePost()}),
	 * the follow-up `generate-image` call was then refused `not_in_plan`
	 * (its verb was never in the accepted plan), and the run burned its
	 * max_plans re-planning around a mistake the FENCE could see up front.
	 * Deliberately reuses the ability-layer's own `page_needs_image` code —
	 * one vocabulary for "this page write has no image", whichever layer
	 * catches it first.
	 */
	public const ERROR_PAGE_NEEDS_IMAGE = 'page_needs_image';

	/**
	 * PACK VERBS, not ability ids (see {@see \Specflux\SenroFlux\Packs\Pack}'s
	 * isolation rule — this class may not depend on src/Packs, so the handful
	 * of verb strings the image check needs are duplicated here as bare
	 * strings, sourced from PagesPack::verbMap()/SitePack::verbMap()).
	 *
	 * Posts pack's own `posts/create-draft` is deliberately absent: a POST's
	 * image rule is enforced on its own terms elsewhere (see the "never
	 * enforced on a post" note in `executeCreatePost()`) and was never part of
	 * this defect.
	 */
	private const PAGE_CREATE_VERBS  = array( 'pages/create-draft', 'site/create-draft' );
	private const MEDIA_SEARCH_VERBS = array( 'pages/media-search', 'site/media-search' );
	/**
	 * Either an AI-generated image or an uploaded one counts as "a way to get
	 * an image" — requiring generate specifically would refuse an otherwise
	 * complete plan that searches, comes up empty, and uploads instead.
	 */
	private const MEDIA_ACQUIRE_VERBS = array( 'pages/media-generate', 'site/media-generate', 'pages/media-upload', 'site/media-upload', 'pages/media-stock-import', 'site/media-stock-import' );

	/**
	 * The verbs an accepted plan covers: its own, plus `<pack>/media-stock-import`
	 * wherever it lists `<pack>/media-generate` — the stock photo is what a
	 * run falls back to once the image budget is spent, so a plan that may
	 * generate an image may import one instead.
	 *
	 * @param list<string> $verbs The plan's own verbs.
	 * @return list<string>
	 */
	public static function coveredVerbs( array $verbs ): array {
		foreach ( $verbs as $verb ) {
			if ( str_ends_with( $verb, '/media-generate' ) ) {
				$verbs[] = substr( $verb, 0, -strlen( 'media-generate' ) ) . 'media-stock-import';
			}
		}

		return array_values( array_unique( $verbs ) );
	}

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
	 * @param int                $remaining_plans Live count of remaining plans.
	 * @param list<string>|null  $known_verbs     0.3 quality fix (instruction
	 *                                            ceiling): the run's OWN verb
	 *                                            list, spelled out in the
	 *                                            declaration itself instead of
	 *                                            a pack skill — the same list
	 *                                            {@see \Specflux\SenroFlux\Run\Runner::knownVerbs()}
	 *                                            already resolves for
	 *                                            `validateProposePlan()`'s
	 *                                            `unknown_verb` check, so this
	 *                                            can never drift from what the
	 *                                            fence actually accepts. Null
	 *                                            omits the list (a direct-allow
	 *                                            run with no pack).
	 * @return array<string, FunctionDeclaration|array<string,mixed>>
	 */
	public static function declarations( int $remaining_plans, ?array $known_verbs = null ): array {
		if ( $remaining_plans <= 0 ) {
			return array();
		}

		return array( self::TOOL_NAME => self::proposePlanDeclaration( $known_verbs ) );
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
	 * @param list<string>|null $known_verbs 0.3 quality fix (instruction ceiling):
	 *                                       see {@see declarations()}.
	 * @return FunctionDeclaration|array<string,mixed>
	 */
	public static function proposePlanDeclaration( ?array $known_verbs = null ): FunctionDeclaration|array {
		$verbs_description = __( 'The Agent Safety verbs this step uses.', 'senroflux' );
		if ( null !== $known_verbs ) {
			$verbs_description .= ' ' . sprintf(
				/* translators: %s is a comma-separated list of verb names. */
				__( 'Spell each one exactly as one of: %s. Any other word is refused as unknown_verb.', 'senroflux' ),
				implode( ', ', $known_verbs )
			);
		}

		// 0.3 quality fix (images budget 0): a media-generate verb is only
		// ever "known" (see Runner::knownVerbs()) when the run's images
		// budget is above zero, so its absence from a non-null list IS the
		// budget-zero signal — the step description must not steer the model
		// toward a verb the tool surface has already withheld, or it repeats
		// the observed live-run loop (media-search -> generate-image refused
		// budget_exhausted -> re-plan -> stock-image-search).
		$media_generate_available = null === $known_verbs || array() !== array_filter(
			$known_verbs,
			static fn ( string $verb ): bool => str_ends_with( $verb, '/media-generate' )
		);

		$step_text_description = $media_generate_available
			? __( 'One ordered step of the plan. A step that writes a page must state: who the page is for, what it must achieve, its sections in order, and the next step for the visitor. Give each post, page, product, price, image or order you change its own step, never one step for several of them: the plan\'s approval count, and any pre-approval, is counted from the steps, so a step covering several under-states what you will ask and its extra writes stop for approval again. Changes to that same object (its featured image, terms, excerpt, alt text) belong in its step. A step that adds or edits an image must ALSO list every media verb it will call in that SAME step\'s verbs — media-search, media-generate/generate-image, media-stock-import, generate-alt-text, update-alt, read-media, whichever apply, spelled exactly as this pack\'s own verb list gives them. A media call whose verb is missing from every step is refused not_in_plan.', 'senroflux' )
			: __( 'One ordered step of the plan. A step that writes a page must state: who the page is for, what it must achieve, its sections in order, and the next step for the visitor. Give each post, page, product, price, image or order you change its own step, never one step for several of them: the plan\'s approval count, and any pre-approval, is counted from the steps, so a step covering several under-states what you will ask and its extra writes stop for approval again. Changes to that same object (its featured image, terms, excerpt, alt text) belong in its step. A step that adds or edits an image must ALSO list every media verb it will call in that SAME step\'s verbs — media-search, media-stock-import, generate-alt-text, update-alt, read-media, whichever apply, spelled exactly as this pack\'s own verb list gives them. This run has no image-generation budget left, so use media-search then media-stock-import for any image. A media call whose verb is missing from every step is refused not_in_plan.', 'senroflux' );

		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'goal'         => array(
					'type'        => 'string',
					'maxLength'   => self::MAX_GOAL_CHARS,
					'description' => __( 'The goal this plan proposes to achieve.', 'senroflux' ),
				),
				'steps'        => array(
					'type'        => 'array',
					'maxItems'    => self::MAX_STEPS,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'text'  => array(
								'type'        => 'string',
								'maxLength'   => self::MAX_STEP_TEXT_CHARS,
								// 0.3 quality fix 4 (page brief): a step that
								// writes a page must set out the brief the
								// write tools expect it to follow — see their
								// own descriptions.
								//
								// 0.3 quality fix (live run: media verbs kept
								// getting left off a step, forcing not_in_plan
								// refusals on generate-image/update-alt, a
								// re-plan to fix it, then a SECOND re-plan
								// when the fix was incomplete — burning
								// max_plans before the page budget did any
								// real work). Naming every media verb the
								// step needs, up front, is the one edit that
								// avoids the whole retry loop.
								'description' => $step_text_description,
							),
							'verbs' => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => $verbs_description,
							),
						),
						'required'             => array( 'text', 'verbs' ),
						// Fail closed: a step may not smuggle extra fields in.
						'additionalProperties' => false,
					),
					'description' => __( 'The ordered steps the plan will carry out.', 'senroflux' ),
				),
				'assumptions'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => self::MAX_ASSUMPTIONS,
					'description' => __( 'Assumptions the plan rests on, if any.', 'senroflux' ),
				),
				'adopted'      => self::objectListSchema( __( 'Existing objects (pages, for example) this plan adopts as they are instead of creating new ones — each with its ID. Leave out when it adopts nothing.', 'senroflux' ) ),
				'left_for_you' => self::objectListSchema( __( 'Existing objects the run will NOT touch but the human may want to remove, such as default-install leftovers — each with its ID. Leave out when there are none.', 'senroflux' ) ),
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
	 * WP_Error with code {@see self::ERROR_INVALID_PLAN} or
	 * {@see self::ERROR_UNKNOWN_VERB} — the Runner turns that into a
	 * tool_result error the model sees.
	 *
	 * The tier annotation uses {@see VerbTier::tierFor()} against the run's OWN
	 * verb map when one is given, which is what keeps the plan card's tiers and
	 * the fence's tiers the same number: a pack run whose plan was annotated
	 * from the site-wide filter would show a human tier 2 for a call the fence
	 * treats as tier 0, or the reverse.
	 *
	 * @param mixed             $args                The function call's args (arbitrary, from the model).
	 * @param int|null          $run_id              Optional run id, threaded to the verb-map filter.
	 * @param array<string,int>|null $verb_map       The run's verb => tier map; null = the site-wide filter.
	 * @param list<string>|null $known_verbs         The verbs this run can produce (pack verbs, or
	 *                                               ability names for a direct-allow run). Null skips
	 *                                               the check; an EMPTY list means nothing is known.
	 * @param int|null          $remaining_questions The run's remaining question budget; at 0 the
	 *                                               plan must state its assumptions (S7). Null = unknown.
	 * @return array<string,mixed>|WP_Error Validated payload or an error.
	 */
	public static function validateProposePlan(
		mixed $args,
		?int $run_id = null,
		?array $verb_map = null,
		?array $known_verbs = null,
		?int $remaining_questions = null
	): array|WP_Error {
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
		if ( self::overCap( $goal, self::MAX_GOAL_CHARS ) ) {
			return $invalid(
				sprintf(
					/* translators: %1$d is the actual character count, %2$d is the character cap. */
					__( '"goal" is %1$d characters; the limit is %2$d. Shorten it and propose the plan again.', 'senroflux' ),
					mb_strlen( $goal ),
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
		foreach ( $steps as $step_index => $step ) {
			if ( ! is_array( $step ) ) {
				return $invalid( __( 'every "steps" entry must be an object.', 'senroflux' ) );
			}

			$text = $step['text'] ?? null;
			if ( ! is_string( $text ) || '' === trim( $text ) ) {
				return $invalid( __( 'every step needs a non-empty "text".', 'senroflux' ) );
			}
			if ( self::overCap( $text, self::MAX_STEP_TEXT_CHARS ) ) {
				return $invalid(
					sprintf(
						/* translators: %1$d is the 1-based step number, %2$d is the actual character count, %3$d is the character cap. */
						__( 'step %1$d "text" is %2$d characters; the limit is %3$d. Shorten it and propose the plan again.', 'senroflux' ),
						$step_index + 1,
						mb_strlen( $text ),
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
				// S7: verbs are validated against the run's verb vocabulary. A
				// plan naming a verb the run cannot produce is not a plan — the
				// fence would refuse every call it claims to authorise.
				if ( null !== $known_verbs && ! in_array( $verb, $known_verbs, true ) ) {
					return new WP_Error(
						self::ERROR_UNKNOWN_VERB,
						sprintf(
							/* translators: %s is the verb the plan named. */
							__( 'Unknown verb in propose-plan: %s', 'senroflux' ),
							$verb
						)
					);
				}
				$normalized_verbs[] = $verb;
			}
			if ( array() === $normalized_verbs ) {
				return $invalid( __( 'every step needs at least one verb.', 'senroflux' ) );
			}

			// S7: annotate the step with the highest tier among its verbs,
			// through the RUN's map so the card and the fence agree.
			$tier = 0;
			foreach ( $normalized_verbs as $verb ) {
				$tier = max( $tier, VerbTier::tierFor( $verb, $verb_map, $run_id ) );
			}

			$normalized_steps[] = array(
				'text'  => $text,
				'verbs' => $normalized_verbs,
				'tier'  => $tier,
			);
		}

		// S7: a plan with no steps authorises nothing and cannot be acted on;
		// accepting one would set accepted_plan_step_id to an empty verb set,
		// which reads as "a plan exists" while refusing every write.
		if ( array() === $normalized_steps ) {
			return $invalid( __( '"steps" must contain at least one step.', 'senroflux' ) );
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

		// S7: with no questions left the model may not simply proceed on
		// unstated guesses — the assumptions ARE the record of what it decided
		// on its own, and the human accepting the plan is accepting them.
		if ( null !== $remaining_questions && 0 >= $remaining_questions && array() === $normalized_assumptions ) {
			return $invalid( __( 'no questions remain, so the plan must state its assumptions.', 'senroflux' ) );
		}

		$image_error = self::missingImageStepError( $normalized_steps, $known_verbs );
		if ( null !== $image_error ) {
			return $image_error;
		}

		// S7 quality fix (2026-09-28): a pack-contributed plan-time check, kept
		// out of this file the same way `missingImageStepError()`'s own
		// PAGE_CREATE_VERBS duplicates pack verb strings rather than reaching
		// into `src/Packs` (this class's own isolation rule, see the class
		// docblock) — a hook, not a hardcoded pack name, so ANY pack may
		// refuse a plan through it. Each registered callback is passed the
		// PRIOR error (null the first time) and must pass an existing WP_Error
		// through unmodified; {@see \Specflux\SenroFlux\Packs\Site\Navigation::filterPlanError()}
		// is the site pack's own contribution (the stock-Sample-Page-left-in-nav
		// check), a no-op for every other pack's plan.
		/** Filters an accepted plan for a pack-specific extra refusal. `@internal`. */
		$pack_error = apply_filters( 'senroflux_plan_error', null, $normalized_steps, $known_verbs, $run_id );
		if ( $pack_error instanceof WP_Error ) {
			return $pack_error;
		}

		$payload = array(
			'goal'        => $goal,
			'steps'       => $normalized_steps,
			'assumptions' => $normalized_assumptions,
		);

		// 0.3 stage 22b (S7/S12): the objects the plan adopts, and the ones it
		// leaves for the human — the report reads them back from the accepted
		// plan. Optional; a plan naming none keeps its old payload shape.
		foreach ( array( 'adopted', 'left_for_you' ) as $key ) {
			if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
				continue;
			}
			$list = self::normaliseObjectList( $key, $args[ $key ] );
			if ( $list instanceof WP_Error ) {
				return $list;
			}
			if ( array() !== $list ) {
				$payload[ $key ] = $list;
			}
		}

		return $payload;
	}

	/**
	 * The schema of one `adopted` / `left_for_you` list.
	 *
	 * @param string $description What the list means, for the model.
	 * @return array<string,mixed>
	 */
	private static function objectListSchema( string $description ): array {
		return array(
			'type'        => 'array',
			'maxItems'    => self::MAX_OBJECT_LIST,
			'description' => $description,
			'items'       => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'    => array(
						'type'        => 'string',
						'description' => __( 'The object\'s ID, as a string.', 'senroflux' ),
					),
					'title' => array(
						'type'        => 'string',
						'maxLength'   => self::MAX_STEP_TEXT_CHARS,
						'description' => __( 'Its title, if you know it.', 'senroflux' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Validate one `adopted` / `left_for_you` list into `{ id, title }` rows.
	 * The model's title is untrusted display text; the report resolves the
	 * real one from the object itself where it can.
	 *
	 * @param string $key   The field name, for the error message.
	 * @param mixed  $value The model's value.
	 * @return list<array{id:string,title:string}>|WP_Error
	 */
	private static function normaliseObjectList( string $key, mixed $value ): array|WP_Error {
		$invalid = static fn ( string $what ): WP_Error => new WP_Error(
			self::ERROR_INVALID_PLAN,
			/* translators: %s names the offending field. */
			sprintf( __( 'Invalid propose-plan call: %s', 'senroflux' ), $what )
		);

		if ( ! is_array( $value ) || count( $value ) > self::MAX_OBJECT_LIST ) {
			return $invalid(
				sprintf(
					/* translators: 1: the field name, 2: the maximum number of entries. */
					__( '"%1$s" must be a list of at most %2$d objects.', 'senroflux' ),
					$key,
					self::MAX_OBJECT_LIST
				)
			);
		}

		$rows = array();
		foreach ( $value as $entry ) {
			$id = is_array( $entry ) ? ( $entry['id'] ?? null ) : null;
			if ( is_int( $id ) ) {
				$id = (string) $id;
			}
			if ( ! is_string( $id ) || '' === trim( $id ) ) {
				return $invalid(
					sprintf(
						/* translators: %s: the field name. */
						__( 'every "%s" entry needs an "id".', 'senroflux' ),
						$key
					)
				);
			}

			$title = is_array( $entry ) && is_string( $entry['title'] ?? null ) ? trim( $entry['title'] ) : '';
			if ( self::overCap( $title, self::MAX_STEP_TEXT_CHARS ) ) {
				$title = mb_substr( $title, 0, self::MAX_STEP_TEXT_CHARS );
			}

			$rows[] = array(
				'id'    => trim( $id ),
				'title' => $title,
			);
		}

		return $rows;
	}

	/**
	 * 0.3 quality fix (S7 plan-time image check): refuse a plan that creates a
	 * page (a `PAGE_CREATE_VERBS` verb on any step) unless SOME step also lists
	 * a media-search verb AND a media-generate/upload verb. Create-only: an
	 * `update-post` step cannot be checked here — the plan names a VERB, never
	 * the `content`/`no_image_reason` arguments the actual call will carry, so
	 * whether that particular update needs an image is undecidable at plan
	 * time (see {@see \Specflux\SenroFlux\Packs\Content\Abilities}'s own
	 * per-call check for that half of the rule).
	 *
	 * `senroflux_require_page_image` off disables this too — same filter, same
	 * meaning ("this site's runs don't need page images").
	 *
	 * @param list<array{text:string,verbs:list<string>,tier:int}> $steps       Normalized steps.
	 * @param list<string>|null                                    $known_verbs The run's own verb vocabulary
	 *                                                                          (narrows which verbs the
	 *                                                                          message suggests); null lists
	 *                                                                          every candidate verb.
	 */
	private static function missingImageStepError( array $steps, ?array $known_verbs ): ?WP_Error {
		/** Filters whether a page write must include an image step. `@internal`. */
		if ( ! apply_filters( 'senroflux_require_page_image', true ) ) {
			return null;
		}

		$verbs        = array();
		$creates_page = false;
		foreach ( $steps as $step ) {
			foreach ( $step['verbs'] as $verb ) {
				$verbs[] = $verb;
				if ( in_array( $verb, self::PAGE_CREATE_VERBS, true ) ) {
					$creates_page = true;
				}
			}
		}

		if ( ! $creates_page ) {
			return null;
		}

		$has_search  = array() !== array_intersect( self::MEDIA_SEARCH_VERBS, $verbs );
		$has_acquire = array() !== array_intersect( self::MEDIA_ACQUIRE_VERBS, $verbs );
		if ( $has_search && $has_acquire ) {
			return null;
		}

		// Suggest only verbs this run can actually produce, so the fix the
		// message names is never itself refused as unknown_verb.
		$narrow = static fn ( array $candidates ): array => null !== $known_verbs
			? array_values( array_intersect( $candidates, $known_verbs ) )
			: $candidates;

		$narrowed_acquire = $narrow( self::MEDIA_ACQUIRE_VERBS );

		// 0.3 quality fix (images budget 0): when narrowing dropped every
		// media-generate verb (the run's images budget is 0, see
		// Runner::knownVerbs()), the message must not tell the model to add
		// one anyway — that is the exact refused-then-re-plan loop this fix
		// removes.
		$has_generate_option = array() !== array_filter(
			$narrowed_acquire,
			static fn ( string $verb ): bool => str_ends_with( $verb, '/media-generate' )
		);

		$message = $has_generate_option
			? sprintf(
				/* translators: 1: media-search verb list, 2: media-generate/upload/stock-import verb list. */
				__( 'This plan creates a page but no step lists a way to get an image. Add a media-search verb (%1$s) AND a media-generate, media-upload or media-stock-import verb (%2$s) to a step, spelled exactly as this pack\'s own verb list gives them, then propose the plan again.', 'senroflux' ),
				implode( ', ', $narrow( self::MEDIA_SEARCH_VERBS ) ),
				implode( ', ', $narrowed_acquire )
			)
			: sprintf(
				/* translators: 1: media-search verb list, 2: media-upload/stock-import verb list. */
				__( 'This plan creates a page but no step lists a way to get an image. Add a media-search verb (%1$s) AND a media-upload or media-stock-import verb (%2$s) to a step, spelled exactly as this pack\'s own verb list gives them, then propose the plan again.', 'senroflux' ),
				implode( ', ', $narrow( self::MEDIA_SEARCH_VERBS ) ),
				implode( ', ', $narrowed_acquire )
			);

		return new WP_Error( self::ERROR_PAGE_NEEDS_IMAGE, $message );
	}

	/**
	 * Is `$text` past `$cap` plus {@see LENGTH_TOLERANCE_PERCENT}?
	 */
	public static function overCap( string $text, int $cap ): bool {
		return mb_strlen( $text ) > intdiv( $cap * ( 100 + self::LENGTH_TOLERANCE_PERCENT ), 100 );
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
