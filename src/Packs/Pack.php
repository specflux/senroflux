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
 * — the allow-list (`allowList()`), the skills (via `SkillSet::collect($pack_skills)`)
 * and the verb map (`verbMap()`). No Run class references a Pack; the Runner
 * never reads `Packs\*` classes. The verb→tier map is consulted by the stage-8
 * fence/approval-summary hook, which lives outside the run loop too.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs;

use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
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

		return 'senroflux/' . $template;
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
	 * Resolve the S10 VERB for one call. The verb is what Agent Safety tiers,
	 * grants and audits — it is NOT the ability name. At stage-6 integration the
	 * RESOLVED verb keys the plan fence and the approval-summary hook; the map
	 * here mirrors the S10 table so the fence and the hook can key on it.
	 *
	 * @param string               $ability The concrete ability id the model called (e.g. 'senroflux/update-post').
	 * @param array<string,mixed>  $input   The call input (id/status fields drive the predicate).
	 */
	public function verbFor( string $ability, array $input ): string {
		return $this->verbForBase( $this->baseName( $ability ), $input );
	}

	/**
	 * Dispatch by the ability name segment.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	protected function verbForBase( string $base, array $input ): string {
		return match ( $base ) {
			'read-content'    => 'pages/read',
			'list-patterns'   => 'pages/list-patterns',
			'get-preview-url' => 'pages/preview',
			'create-post'     => 'pages/create-draft',
			'update-post'     => $this->updateVerb( $input ),
			default           => $base, // Non-verb ability (S9: verbs fall back to ability names).
		};
	}

	/**
	 * The update-post predicate (S10): transition to publish is `pages/publish`
	 * (tier 2); publish target with unchanged status is `pages/update-live`
	 * (tier 2); draft|pending target (or no status) is `pages/update-draft`
	 * (tier 1).
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	protected function updateVerb( array $input ): string {
		$desired = $input['status'] ?? null;
		if ( ! is_string( $desired ) || '' === $desired ) {
			return 'pages/update-draft';
		}

		$current = $this->currentStatus( $input );

		if ( 'publish' === $desired && $desired !== $current ) {
			return 'pages/publish';
		}

		if ( 'publish' === $desired ) {
			return 'pages/update-live';
		}

		return 'pages/update-draft';
	}

	/**
	 * The target object's CURRENT status, for the update predicate. Base cannot
	 * know it from `$input` alone (only `id`), so it returns '' (no transition
	 * detected); the pages pack overrides to read the post. Thus the base
	 * treats any publish `status` as a transition → `pages/publish`.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	protected function currentStatus( array $input ): string {
		unset( $input );

		return '';
	}

	/**
	 * The ability name's final segment (namespace stripped).
	 */
	private function baseName( string $ability ): string {
		$pos = strrpos( $ability, '/' );

		return false === $pos ? $ability : substr( $ability, $pos + 1 );
	}

	/**
	 * verb => tier per S10. The tier is what Agent Safety gates on; the fence
	 * and the approval-summary hook key on the verb, not the ability.
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'pages/read'          => 0,
			'pages/list-patterns' => 0,
			'pages/preview'       => 0,
			'pages/create-draft'  => 1,
			'pages/update-draft'  => 1,
			'pages/update-live'   => 2,
			'pages/publish'       => 2,
		);
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
	 * ceiling holds (S8) and (b) the user is bound to the Agent Safety pack
	 * (Capability Packs) with every verb in `verbMap()` allowed (tier 0/1) or
	 * approval-gated (tier 2). Returns true, or a WP_Error (`skills_too_large`
	 * from the ceiling, or `pack_unbound`).
	 *
	 * OPEN SEAM (documented): the Agent Safety binding resolution —
	 * `agent_safety()` → pack registry → identity tokens (`user:N`,
	 * `role:administrator`) → "every verb allowed or approval-gated" — is the
	 * stage-8/9 integration. Until it lands the binding check below is a
	 * guarded no-op; the skills-ceiling check is real and authoritative NOW.
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

		if ( function_exists( 'agent_safety' ) ) {
			$unbound = $this->agentSafetyBindingError( $user_id );
			if ( null !== $unbound ) {
				return $unbound;
			}
		}

		return true;
	}

	/**
	 * S13 seam: resolve the Agent Safety pack for the user's identity tokens and
	 * require every verb in `verbMap()` to be allowed (tier 0/1) or
	 * approval-gated (tier 2). Returns null when bound, else `pack_unbound`.
	 *
	 * Real integration arrives with stage 8 (pack registry resolution) and
	 * stage 9 (Capability Packs binding). Defensive `function_exists` /
	 * `class_exists` guards only; callers must not treat this as authoritative
	 * before then — SenroFlux never auto-binds (S13).
	 *
	 * @return WP_Error|null null when no binding defect is detectable yet.
	 */
	protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
		unset( $user_id );

		// Defensive guard: the Agent Safety surface (agent_safety()->packs()…)
		// is not consulted until stage 8/9. Keeping the seam type-correct and
		// returning null means preflight stays permissive for direct-allow runs
		// and for packs whose binding is not yet wired.
		return null;
	}
}
