<?php
/**
 * Abstract capability pack: role → ability resolution, verb map, skills, preflight.
 *
 * S9 (SPEC-SENROFLUX-0.2) — the pack is the ONLY source of a run's ability
 * allow-list when `start()` is given a pack name. It also owns the S10 verb
 * map (what Agent Safety tiers/grants/audits), the pack skills that ride the
 * instruction, and the S13 preflight that gates a start on skills ceiling +
 * Capability-Packs binding.
 *
 * ISOLATION RULE (harness contract): NOTHING under src/Packs may be needed by
 * src/Run for the RUN LOOP. A pack feeds the Runner through EXPLICIT seams only
 * — the allow-list (`allowList()`), the skills (via `SkillSet::collect($consumer, $goal, $pack)`),
 * the verb map (`verbMap()`) and the ability→verb predicate (`verbFor()`). No Run
 * class references a Pack; the Runner reaches both verb seams through callables
 * the composition root injects.
 *
 * TWO VERB VOCABULARIES, deliberately. They are NOT the same string space and
 * conflating them is the bug this class exists to prevent:
 *   - PACK verbs (`pages/publish`) are SenroFlux's own, argument-aware names.
 *     They key the S7 plan fence, the plan's `verbs` list and the approval card.
 *   - AGENT SAFETY verbs are, at the gate seam this plugin is governed by, the
 *     ABILITY ID itself: `AbilityPermissionGate::wrap()` passes the registered
 *     ability name straight into `VerdictPipeline::judge()`, which hands it to
 *     `Gate::evaluate()` as `GateContext::$verb` (agent-safety
 *     plugin/src/Hooks/AbilityPermissionGate.php:113, plugin/src/Verdict/VerdictPipeline.php:70,
 *     src/Gate/Gate.php:31-41). Agent Safety's own core module does exactly
 *     this — `CorePacks` allows `core/read-content` etc. So everything Agent
 *     Safety sees (`agent_safety_governed_namespaces`, `agent_safety_verb_map`,
 *     a `Packs\Pack`'s allow-list, the approval-summary `$verb`) is keyed on
 *     ABILITY IDS, never on `pages/*`.
 * {@see agentSafetyVerbMap()} is the bridge: it collapses the pack verbs a role
 * can produce down to the one tier Agent Safety can carry for that ability.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs;

use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Setup\Checks;
use Specflux\SenroFlux\Setup\SetupCheck;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Base class for every capability pack.
 *
 * The base ships the S9/S10 shape. Concrete packs (the pages pack, stage 8)
 * supply the pack `name()`, the role→ability template map (via the
 * constructor), the input properties their clients actually send (for the
 * shape-compat check), and override `skills()` / `agentSafetyPack()` /
 * `verbMap()` / `preflight()` as needed.
 */
abstract class Pack {

	/**
	 * The namespace every polyfilled pack ability lives in. This is also the
	 * namespace the pack asks Agent Safety to govern — `core/*` is governed by
	 * Agent Safety's own core module and must never be re-declared here (S9).
	 */
	public const POLYFILL_NAMESPACE = 'senroflux/';

	/** @var array<string,string> role => ability-id template (e.g. 'read' => 'read-content'). */
	private array $roles = array();

	/** @var array<string,string> per-request cache of resolveAbilities(): role => concrete id. */
	private array $resolved = array();

	/** @var bool Whether resolveAbilities() has been computed for this request. */
	private bool $resolved_once = false;

	/**
	 * @param array<string,string> $roles role => ability-id template.
	 */
	public function __construct( array $roles = array() ) {
		$this->roles = $roles;
	}

	/**
	 * The pack's short machine name, e.g. 'pages'.
	 */
	abstract public function name(): string;

	/**
	 * role => ability-id template. The template is the name segment WITHOUT the
	 * namespace prefix; resolution decides 'core/<template>' vs 'senroflux/<template>'.
	 *
	 * @return array<string,string>
	 */
	public function roles(): array {
		return $this->roles;
	}

	/**
	 * role => concrete ability id.
	 *
	 * Resolution (generalised, S19): {@see abilityNamespaces()} lists the
	 * candidate namespaces in preference order (default `['core/',
	 * 'senroflux/']`). Each is tried in turn — the first whose
	 * `<namespace><template>` ability is REGISTERED and shape-compatible
	 * (every input property the pack sends for that role exists in the
	 * ability's `get_input_schema()`) is adopted. The LAST namespace in the
	 * list is the pack's own polyfill and is always accepted without a
	 * compat check (it is authored to match the pack's own calls) — this is
	 * the pre-S19 "otherwise senroflux/<template>" fallback, generalised.
	 * Cached per request.
	 *
	 * @return array<string,string>
	 */
	public function resolveAbilities(): array {
		if ( $this->resolved_once ) {
			return $this->resolved;
		}

		$resolved = array();
		foreach ( $this->roles as $role => $template ) {
			$resolved[ $role ] = $this->resolveAbility( $template );
		}

		$this->resolved      = $resolved;
		$this->resolved_once = true;

		return $this->resolved;
	}

	/**
	 * The ability namespaces this pack's roles resolve through, in
	 * preference order (S19, `@api`). The default is the pre-S19 behaviour
	 * unchanged: try `core/`, otherwise `senroflux/`. A pack that prefers a
	 * third-party namespace (e.g. `woocommerce/`) lists it first; the LAST
	 * entry MUST be `self::POLYFILL_NAMESPACE` — {@see PackRegistry::register()}
	 * refuses a pack whose list doesn't end with it, because a pack with no
	 * guaranteed final fallback could resolve a role to nothing.
	 *
	 * @return list<string>
	 */
	public function abilityNamespaces(): array {
		return array( 'core/', self::POLYFILL_NAMESPACE );
	}

	/**
	 * The resolved ability id for one template: the first namespace in
	 * {@see abilityNamespaces()} that is registered and shape-compatible, or
	 * the last (polyfill) namespace when none of the earlier ones qualify.
	 */
	private function resolveAbility( string $template ): string {
		$namespaces = $this->abilityNamespaces();
		$last_index = count( $namespaces ) - 1;

		foreach ( $namespaces as $index => $namespace ) {
			$candidate = $namespace . $template;
			if ( $index === $last_index ) {
				// The final namespace is the pack's own polyfill: always the
				// fallback, never gated on a compat check against itself.
				return $candidate;
			}
			if ( $this->namespaceCompatible( $candidate, $template ) ) {
				return $candidate;
			}
		}

		// Defensive only: abilityNamespaces() always ends with the polyfill
		// namespace (enforced at registration), so an empty list never
		// reaches here in practice.
		return self::POLYFILL_NAMESPACE . $template;
	}

	/**
	 * Shape-compat check against the Abilities API (S9, generalised S19): the
	 * candidate ability must exist, be an object with `get_input_schema()`,
	 * and its schema must accept every input property this pack's client
	 * sends for the role.
	 */
	private function namespaceCompatible( string $candidate_id, string $template ): bool {
		$schema = $this->abilitySchema( $candidate_id );
		if ( null === $schema ) {
			return false;
		}

		$properties = $this->schemaProperties( $schema );
		foreach ( $this->inputProperties( $template ) as $property ) {
			if ( ! array_key_exists( $property, $properties ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The raw input schema from a candidate ability, or null when it is
	 * absent.
	 *
	 * Defensive guards: `wp_get_ability()` may return null or a duck-typed
	 * object across real WordPress versions (the wordpress-stubs type it as a
	 * non-nullable WP_Ability); `schemaFor()` takes a generic `object` so the
	 * `method_exists()`/`is_array()` checks stay genuine runtime guards (same
	 * defensive intent as ToolRegistry/ToolExecutor).
	 *
	 * @return array<string,mixed>|null
	 */
	private function abilitySchema( string $candidate_id ): ?array {
		// Probe with wp_has_ability() (a silent registry read): probing with
		// wp_get_ability() on an unregistered ability raises a
		// _doing_it_wrong notice on every check — noisy for a step-aside probe.
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $candidate_id ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = wp_get_ability( $candidate_id );
		if ( ! is_object( $ability ) ) {
			return null;
		}

		return $this->schemaFor( $ability );
	}

	/**
	 * The raw input schema from a duck-typed ability-like object.
	 *
	 * The `object` parameter (not the narrowed `WP_Ability`) keeps the
	 * `method_exists()` duck-type guard genuinely dynamic — wp_get_ability()
	 * may return an object that is not a full WP_Ability across real WordPress
	 * versions (same defensive intent as ToolRegistry).
	 *
	 * @param object $ability The ability-like object.
	 * @return array<string,mixed>|null
	 */
	private function schemaFor( object $ability ): ?array {
		if ( ! method_exists( $ability, 'get_input_schema' ) ) {
			return null;
		}

		$schema = $ability->get_input_schema();
		if ( ! is_array( $schema ) ) {
			return null;
		}

		return $schema;
	}

	/**
	 * The set of accepted input-property keys for a schema. `oneOf`/`anyOf`
	 * branches are unioned — an input property only needs to be accepted by one
	 * branch (the API validates the actual call against one branch).
	 *
	 * @param array<string,mixed> $schema The input schema.
	 * @return array<string,mixed>
	 */
	private function schemaProperties( array $schema ): array {
		$properties = array();
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			$properties = $schema['properties'];
		}
		foreach ( array( 'oneOf', 'anyOf' ) as $branch_key ) {
			if ( ! isset( $schema[ $branch_key ] ) || ! is_array( $schema[ $branch_key ] ) ) {
				continue;
			}
			foreach ( $schema[ $branch_key ] as $branch ) {
				if ( is_array( $branch ) && isset( $branch['properties'] ) && is_array( $branch['properties'] ) ) {
					$properties = array_merge( $properties, $branch['properties'] );
				}
			}
		}

		return $properties;
	}

	/**
	 * The input property keys this pack's client sends for a given ability
	 * template. Base returns an empty list (nothing required → existence check
	 * only means "any schema at all" for the base); the pages pack overrides
	 * with its real per-role fields so a partial core ability is never adopted
	 * silently (S9 step-aside "core present but incompatible").
	 *
	 * @param string $template Ability template, e.g. 'update-post'.
	 * @return list<string>
	 */
	protected function inputProperties( string $template ): array {
		unset( $template );

		return array();
	}

	/**
	 * The run's ability allow-list: the RESOLVED ability ids (deduped, ordered).
	 * This feeds `Run::$allow` (the existing direct path) when `start()` is
	 * given a pack — S9.
	 *
	 * @return list<string>
	 */
	public function allowList(): array {
		return array_values( array_unique( array_values( $this->resolveAbilities() ) ) );
	}

	/**
	 * Resolve the PACK VERB for one call — the argument-aware name the S7 plan
	 * fence and the plan's `verbs` list are keyed on. It is NOT the Agent
	 * Safety verb (see the class docblock).
	 *
	 * The base is the S9 direct-allow rule: with no argument-aware predicate of
	 * its own, a pack's verb IS the ability id. A pack that tiers one ability
	 * differently per call (the pages pack's update-post → draft / live /
	 * publish) overrides this and declares the same split in {@see roleVerbs()}.
	 *
	 * @param string              $ability The concrete ability id the model called.
	 * @param array<string,mixed> $input   The call input (drives an override's predicate).
	 */
	public function verbFor( string $ability, array $input ): string {
		unset( $input );

		return $ability;
	}

	/**
	 * The ability name's final segment (namespace stripped).
	 *
	 * @param string $ability Concrete ability id.
	 */
	protected function baseName( string $ability ): string {
		$pos = strrpos( $ability, '/' );

		return false === $pos ? $ability : substr( $ability, $pos + 1 );
	}

	/**
	 * S12: the input/output key carrying an object's id for one PACK VERB.
	 *
	 * The harness tracks written objects by an opaque id and verifies them
	 * when a READ call targets that id — but which argument carries the id is
	 * domain knowledge (`id`, `post_id`, `page_id`). The base answers S12's
	 * default; a pack whose read ability names it differently overrides.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdKey( string $verb ): string {
		unset( $verb );

		return 'id';
	}

	/**
	 * S12 (defect fix): a STRING prepended to the id {@see objectIdKey()}
	 * extracts for one pack verb, before the harness tracks/looks it up in
	 * `objects_json`. A pack whose ability space has more than one object
	 * kind sharing one id space (e.g. a post and an attachment can both be
	 * `63`) uses this to keep them from colliding in the same run's written-
	 * object set. The harness treats the qualified id as an OPAQUE string —
	 * it never parses the prefix back out; only the pack's own report lookup
	 * (wired at the composition root) needs to recognise it. The base
	 * declares none (backward compatible: every pre-existing pack keeps its
	 * bare ids).
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdPrefix( string $verb ): string {
		unset( $verb );

		return '';
	}

	/**
	 * role => required WordPress capability (0.3 S6). A role absent from this
	 * map, or mapped to `''`, needs no capability of its own beyond whatever
	 * `runCapability()`/the ability's own permission callback already checks
	 * — the base declares none, so a pack that never calls this out withholds
	 * nothing (backward compatible with every pre-0.3 pack). No 0.3 pack
	 * declares one yet; the posts pack (stage 5) is the first real caller,
	 * naming `upload_files` for `media-upload`/`generate-image`.
	 *
	 * @return array<string,string>
	 */
	public function roleCapabilities(): array {
		return array();
	}

	/**
	 * The system-instruction line for a run that starts with roles withheld
	 * (0.3 S6) — one line per withheld GROUP, in the pack's own words, so the
	 * harness (which never learns what a role's ability actually does) stays
	 * domain-agnostic. Null when the pack has nothing to say (the base
	 * default, and a pack given an empty `$withheld`).
	 *
	 * @param list<string> $withheld The role names withheld from this run's start().
	 */
	public function withheldRoleNotice( array $withheld ): ?string {
		unset( $withheld );

		return null;
	}

	/**
	 * PACK verb => tier (S10). Abstract on purpose: an empty default would let a
	 * pack ship with no map at all and rely on VerbTier's fail-closed tier 2 for
	 * every call, which reads as governance but is really an unfenced accident.
	 * Every pack states its own table.
	 *
	 * @return array<string,int>
	 */
	abstract public function verbMap(): array;

	/**
	 * The PACK verbs each role can produce — role => list<verb>. The base
	 * declares none, which is the S9 direct-allow reading (verb = ability id)
	 * and, for {@see agentSafetyVerbMap()}, the fail-closed one.
	 *
	 * This is the pack DATA that drives Agent Safety governance: it is the only
	 * place that knows one ability can span several pack verbs, so the bridge
	 * below never has to hardcode a pack's shape.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		return array();
	}

	/**
	 * S14: the AGENT SAFETY verb behind one pack verb — the RESOLVED ability id
	 * the gate classifies and a pre-approval grant must name. Null when no role
	 * declares that verb (fail closed: the harness issues no grant, so the call
	 * parks for a human).
	 *
	 * The inverse of {@see roleVerbs()}, and the only place that knows a grant
	 * on `pages/publish` has to be issued against `senroflux/publish-post`.
	 * Several pack verbs legitimately collapse onto one ability — the caller
	 * aggregates their counts.
	 *
	 * @param string $pack_verb The pack verb (as it appears in a plan step).
	 */
	public function gateVerbFor( string $pack_verb ): ?string {
		$resolved = $this->resolveAbilities();

		foreach ( $this->roleVerbs() as $role => $verbs ) {
			if ( in_array( $pack_verb, $verbs, true ) && isset( $resolved[ $role ] ) ) {
				return $resolved[ $role ];
			}
		}

		return null;
	}

	/**
	 * PACK verbs (S19, `@api`) that {@see \Specflux\SenroFlux\Run\Runner::grantCounts()}
	 * must NEVER issue a pre-approval grant for, however many times the
	 * accepted plan lists them. Default empty (every 0.2/0.3-era verb stays
	 * grantable). A verb here still gates and audits normally — it just asks
	 * a human every single time (the plan card marks it "asks every time")
	 * rather than being spendable ahead of the calls, which is the right
	 * shape for an operation like a customer-facing refund or note where
	 * pre-approving N of them is not the same promise as approving each one.
	 *
	 * @return list<string>
	 */
	public function ungrantableVerbs(): array {
		return array();
	}

	/**
	 * The ability namespaces this pack asks Agent Safety to govern, contributed
	 * to `agent_safety_governed_namespaces`. Only the POLYFILL namespace: a
	 * role that resolved to `core/*` is already governed unconditionally by
	 * Agent Safety's own core module (S9), and re-declaring it here would be a
	 * second, weaker opinion on the same ability.
	 *
	 * A pack with no roles governs nothing — an empty contribution leaves the
	 * gate exactly as inert as it is on a site with no integration.
	 *
	 * @return list<string>
	 */
	public function governedNamespaces(): array {
		return array() === $this->roles() ? array() : array( self::POLYFILL_NAMESPACE );
	}

	/**
	 * The pack's contribution to `agent_safety_verb_map`: Agent Safety verb
	 * (= polyfill ability id) => tier. Without this, governing the namespace
	 * would deny every call in it as `unknown_verb` — the gate fails closed on
	 * unclassified verbs by design (agent-safety README, plugin/agent-safety.php:177).
	 *
	 * COLLAPSE RULE — an ability that spans several pack verbs is registered at
	 * the HIGHEST tier any of them reaches. Agent Safety carries exactly one
	 * tier per verb, and its only argument-aware seam (`ElevationRule`) is
	 * constructor-injected by integration modules with no filter a third-party
	 * host can reach (verified against agent-safety 0.3: no `apply_filters` on
	 * the elevation-rule list). Rounding UP is the §0 fail-closed reading. 0.3
	 * S4 splits `update-post` (draft-state edits, Tier 1) from `publish-post`
	 * (Tier 2) into two abilities precisely so this collapse can no longer drag
	 * a draft edit up to Tier 2 the way 0.2's combined ability did — each
	 * ability's own role now spans only the verbs it can actually produce.
	 * Rounding down would still be the only unsafe choice for an ability that
	 * genuinely spans tiers.
	 *
	 * Keyed on the polyfill id, never on the resolved one, so this stays a pure
	 * function of pack data: Agent Safety reads both filters on
	 * `plugins_loaded` priority 0, long before abilities are registered on
	 * `init`, so resolution is not knowable here.
	 *
	 * @return array<string,int>
	 */
	public function agentSafetyVerbMap(): array {
		$map        = array();
		$verb_tiers = $this->verbMap();
		$role_verbs = $this->roleVerbs();

		foreach ( $this->roles() as $role => $template ) {
			$verbs = $role_verbs[ $role ] ?? array();
			// Fail closed: a role that declares no verbs is irreversible.
			$tier = VerbTier::TIER_2;
			if ( array() !== $verbs ) {
				$tier = VerbTier::TIER_0;
				foreach ( $verbs as $verb ) {
					$tier = max( $tier, VerbTier::tierFor( $verb, $verb_tiers ) );
				}
			}

			$map[ self::POLYFILL_NAMESPACE . $template ] = $tier;
		}

		return $map;
	}

	/**
	 * S7: key => value overrides applied over the SHIPPED budget table for
	 * this pack's runs, BEFORE the `senroflux_default_budget` filter runs
	 * (see {@see \Specflux\SenroFlux\Run\Budget::defaults()}). The base
	 * returns an empty table — no override — which is what keeps every
	 * pre-0.3 pack's budget byte-for-byte unchanged; the site pack (stage 7)
	 * is the first to override this with its own flat-and-high table.
	 *
	 * @return array<string,int>
	 */
	public function defaultBudget(): array {
		return array();
	}

	/**
	 * The pack's guidance skills, source Pack, in render order. Base ships none;
	 * the pages pack contributes `pages/copy-rules` (rendered), `pages/layout-rules`
	 * and `pages/content-language` (S11/S15, stage 8).
	 *
	 * @return list<Skill>
	 */
	public function skills(): array {
		return array();
	}

	/**
	 * The Agent Safety pack descriptor (allow/deny/approvalByTier) registered on
	 * `agent_safety_pack_registry`; null when the pack contributes none. Stage 8
	 * fills the pages pack's; the base contributes nothing.
	 */
	public function agentSafetyPack(): ?object {
		return null;
	}

	/**
	 * S19 (`@api`, stage 12): refuse (a WP_Error) or allow (null) one call
	 * BEFORE its ability's own `check_permissions()`/`execute()` run — the
	 * seam for a pack rule that must bind to an ability ANOTHER plugin
	 * registers, which this pack has no other hook into. A pack's OWN
	 * polyfill abilities validate their own input directly inside their
	 * `execute_callback` and never need this; it exists for the case a
	 * polyfill cannot cover — the commerce pack's description-tag rule over
	 * WooCommerce's own `product-create`/`product-update` abilities.
	 *
	 * Called by {@see \Specflux\SenroFlux\Run\Runner::executeCall()} for
	 * every admitted call, in AS and built-in gate modes alike, after the S7
	 * plan fence and the gate's own approval decision but before the
	 * ability's own permission/execute pair — so a refusal here can never be
	 * bypassed by anything downstream. The base returns null (no opinion)
	 * for every call, which is every pre-S19 pack's unchanged behaviour.
	 *
	 * @param string               $ability The concrete ability id the model called.
	 * @param array<string,mixed>  $input   The call input.
	 */
	public function validateCall( string $ability, array $input ): ?WP_Error {
		unset( $ability, $input );

		return null;
	}

	/**
	 * S13 preflight: the run may not start from this pack unless (a) the skills
	 * ceiling holds (S8) and (b) the user is bound to an Agent Safety Capability
	 * Pack that allows every ability this pack resolves and approval-gates
	 * tier 2. Returns true, or a WP_Error (`skills_too_large` from the ceiling,
	 * or `pack_unbound`).
	 *
	 * The binding check is NOT conditional on `function_exists('agent_safety')`:
	 * Agent Safety is a hard runtime dependency (S2) and the Capability Pack IS
	 * the governance seam, so a missing seam is a refusal, never a gated pass.
	 * Answering that question is each pack's own job — see
	 * {@see agentSafetyBindingError()}.
	 *
	 * The skills arguments exist so this check and `start()`'s are the SAME
	 * question: `start()` passes the run's real consumer, goal and disable list,
	 * so the `senroflux_run_skills` filter sees identical inputs in both places
	 * and the two can never disagree about the ceiling. The screen-time caller
	 * (no run yet) keeps the empty defaults, which is the pre-run approximation
	 * and is documented as such.
	 *
	 * @param int               $user_id        The user the run would be started for.
	 * @param string            $consumer       Consumer identifier, when known.
	 * @param string            $goal           The run's goal, when known.
	 * @param list<string>|null $skills_disable Non-required skill ids the start would drop.
	 * @return true|WP_Error
	 */
	public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): true|WP_Error {
		// S8/S13: the ceiling is checked over the COMBINED harness + pack skill
		// set (the same set `start()` will collect), so a pack that pushes the
		// instruction over the ceiling is refused here, never truncated.
		$skills  = SkillSet::collect( $consumer, $goal, $this, $skills_disable );
		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		// 0.3 S11: preflight is now a THIN caller over {@see setupChecks()} —
		// the same governance questions the setup panel renders, asked the
		// same way, so the two can never disagree (never two rule sets).
		$failure = Checks::firstBlockingFailure( $this->setupChecks( $user_id ) );
		if ( null !== $failure ) {
			return new WP_Error(
				$failure->errorCode(),
				$failure->messageFor( $user_id ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * S11: this pack's own contributed setup checks — the SAME questions
	 * {@see preflight()} asks, in the SAME order, so the setup panel and the
	 * preflight refusal can never disagree.
	 *
	 * In built-in mode the run capability (or, when the pack refuses to run
	 * ungoverned, {@see requiresAgentSafety()}) is the whole test. In Agent
	 * Safety mode BOTH the run capability AND {@see agentSafetyBindingError()}
	 * must hold (S11: "the viewer holds the pack's run capability and, in AS
	 * mode only, has a valid pack binding") — a Capability Pack binding is the
	 * governance seam there, but it never substitutes for the WP capability
	 * question; a user with neither, or with only one of the two, is refused.
	 * `firstBlockingFailure()` returns whichever of the two checks fails
	 * first, in the order below.
	 *
	 * @param int $user_id The user the run would be started for.
	 * @return list<SetupCheck>
	 */
	public function setupChecks( int $user_id ): array {
		if ( GateMode::BuiltIn === Plugin::currentGateMode() ) {
			if ( $this->requiresAgentSafety() ) {
				return array( $this->agentSafetyRequiredCheck() );
			}

			return array( $this->capabilityCheck( $user_id ) );
		}

		return array( $this->capabilityCheck( $user_id ), $this->bindingCheck( $user_id ) );
	}

	/** The `<pack>/capability` setup check (built-in mode's whole test). */
	private function capabilityCheck( int $user_id ): SetupCheck {
		$capability = $this->runCapability();
		$passed     = '' === $capability || ( function_exists( 'user_can' ) && user_can( $user_id, $capability ) );
		$message    = __( 'You do not have the capability this pack needs to start a run.', 'senroflux' );

		return new SetupCheck(
			$this->name() . '/capability',
			SetupCheck::BLOCKING,
			$passed,
			$message,
			null,
			null,
			$message,
			'pack_unbound'
		);
	}

	/** The `<pack>/binding` setup check (Agent Safety mode's whole test). */
	private function bindingCheck( int $user_id ): SetupCheck {
		$error   = $this->agentSafetyBindingError( $user_id );
		$message = null !== $error ? (string) $error->get_error_message() : '';

		return new SetupCheck(
			$this->name() . '/binding',
			SetupCheck::BLOCKING,
			null === $error,
			$message,
			null,
			null,
			$message,
			null !== $error ? (string) $error->get_error_code() : ''
		);
	}

	/** The `<pack>/agent-safety-required` setup check (built-in mode, {@see requiresAgentSafety()}). */
	private function agentSafetyRequiredCheck(): SetupCheck {
		$message = __( 'This pack requires the Agent Safety plugin to be active.', 'senroflux' );

		return new SetupCheck(
			$this->name() . '/agent-safety-required',
			SetupCheck::BLOCKING,
			false,
			$message,
			null,
			null,
			$message,
			'pack_requires_agent_safety'
		);
	}

	/**
	 * The WordPress capability that gates STARTING a run with this pack. Tested
	 * only in built-in mode (S3) — in AS mode {@see agentSafetyBindingError()}
	 * is the whole test. Empty string means "nothing to check", which exists
	 * only so pre-0.3 pack test fixtures keep compiling; every shipped pack
	 * overrides this with its real run capability (e.g. `edit_pages`).
	 */
	public function runCapability(): string {
		return '';
	}

	/**
	 * Whether this pack refuses to start at all without Agent Safety (S3). No
	 * 0.3 pack returns true; the hook exists for commerce at 0.5.
	 */
	public function requiresAgentSafety(): bool {
		return false;
	}

	/**
	 * S13 binding check: is this user bound to an Agent Safety Capability Pack
	 * that admits every ability this pack resolves and approval-gates tier 2?
	 * Return null when bound, else the refusal (`pack_unbound`).
	 *
	 * Abstract on purpose. The base used to answer `null` unconditionally,
	 * which made {@see preflight()} unable to fail for any pack but the pages
	 * one — a governance check that cannot fail is not a check. A pack that has
	 * no binding of its own must say so explicitly by returning a refusal.
	 *
	 * @param int $user_id The user the run would be started for.
	 * @return WP_Error|null null when bound, else `pack_unbound`.
	 */
	abstract protected function agentSafetyBindingError( int $user_id ): ?WP_Error;
}
