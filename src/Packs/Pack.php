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
 * — the allow-list (`allowList()`), the skills (via `SkillSet::collect($pack_skills)`),
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
	 * Resolution: 'core/<template>' when `wp_get_ability('core/<template>')`
	 * exists AND every input property the pack sends for that role exists in
	 * the core ability's `get_input_schema()` (shape-compat — a core ability
	 * the pack's calls would not satisfy is NOT adopted); otherwise
	 * 'senroflux/<template>'. Cached per request.
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
	 * The resolved ability id for one template.
	 */
	private function resolveAbility( string $template ): string {
		$core = 'core/' . $template;
		if ( $this->coreCompatible( $core, $template ) ) {
			return $core;
		}

		return self::POLYFILL_NAMESPACE . $template;
	}

	/**
	 * Shape-compat check against the Abilities API (S9): the core ability must
	 * exist, be an object with `get_input_schema()`, and its schema must accept
	 * every input property this pack's client sends for the role.
	 */
	private function coreCompatible( string $core_id, string $template ): bool {
		$schema = $this->coreSchema( $core_id );
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
	 * The raw input schema from the core ability, or null when it is absent.
	 *
	 * Defensive guards: `wp_get_ability()` may return null or a duck-typed
	 * object across real WordPress versions (the wordpress-stubs type it as a
	 * non-nullable WP_Ability); `schemaFor()` takes a generic `object` so the
	 * `method_exists()`/`is_array()` checks stay genuine runtime guards (same
	 * defensive intent as ToolRegistry/ToolExecutor).
	 *
	 * @return array<string,mixed>|null
	 */
	private function coreSchema( string $core_id ): ?array {
		// Probe with wp_has_ability() (a silent registry read): probing with
		// wp_get_ability() on an unregistered core ability raises a
		// _doing_it_wrong notice on every check — noisy for a step-aside probe.
		if ( ! function_exists( 'wp_has_ability' ) || ! wp_has_ability( $core_id ) ) {
			return null;
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		$ability = wp_get_ability( $core_id );
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
	 * the elevation-rule list). Rounding UP is the §0 fail-closed reading: the
	 * pages pack's `update-post` therefore parks for approval on a draft edit
	 * too, rather than letting a publish through unparked. Rounding down would
	 * be the only unsafe choice.
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
	 * @param int $user_id The user the run would be started for.
	 * @return true|WP_Error
	 */
	public function preflight( int $user_id ): true|WP_Error {
		// S8/S13: the ceiling is checked over the COMBINED harness + pack skill
		// set (the same set `start()` will collect), so a pack that pushes the
		// instruction over the ceiling is refused here, never truncated.
		$skills  = SkillSet::collect( '', '', $this->skills() );
		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		$unbound = $this->agentSafetyBindingError( $user_id );
		if ( null !== $unbound ) {
			return $unbound;
		}

		return true;
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
