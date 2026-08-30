<?php
/**
 * Object binding for Agent Safety pre-approval grants (S14 AS-12).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * S14: `agent_safety_grant_eligible` defaults to FALSE, and this is the only
 * thing that ever answers it true.
 *
 * A grant is per-verb and per-count, never per-object — deliberately, because
 * it is issued before the objects exist. Binding it to the objects the human
 * actually accepted is therefore the HOST's job, and this class is where the
 * harness does it. The pack stays grant-ignorant.
 *
 * Two independent conditions, both required:
 *
 *  1. the grant's correlation is the run currently being ticked — a stale
 *     context, another run's grant, or no run in flight at all is a refusal;
 *  2. the object id carried by the call's arguments is one the run itself
 *     wrote (present in `objects_json`).
 *
 * A call whose arguments carry NO object id (a create) is allowed on (1)
 * alone and fenced by `remaining_count`; S14 settles that explicitly, so it is
 * stated here rather than left to be re-litigated as a gap. "The object id in
 * the arguments" means exactly the key the pack names for that verb (S12) —
 * the same key `objects_json` is built from — never a guess at what else in
 * the payload might be an id.
 *
 * Everything is static per-request context, mirroring
 * {@see \Specflux\SenroFlux\Packs\Pages\PublishSummary}: the filter fires deep
 * inside Agent Safety's pipeline, several frames below anything the harness
 * hands an object to. The Runner sets the context for the length of one tick
 * and clears it in a `finally`, so a filter fired outside a tick — by another
 * plugin, in the same request — finds no run and answers false.
 */
final class GrantEligibility {

	/** The correlation id of the run being ticked, or null when none is. */
	private static ?string $correlation = null;

	/**
	 * Current written-object map provider (S12 `objects_json`), read at filter
	 * time because a tick's own writes extend it mid-tick.
	 *
	 * @var callable():array<string,mixed>|null
	 */
	private static $objects = null;

	/**
	 * Object-id key resolver: (ability id, call args) => the args key carrying
	 * this call's object id.
	 *
	 * @var callable(string,array<string,mixed>):string|null
	 */
	private static $object_id_key = null;

	/**
	 * Register the filter. Called once from the composition root; the callback
	 * is inert (answers false) until a tick opens a run context.
	 */
	public static function boot(): void {
		add_filter( 'agent_safety_grant_eligible', array( self::class, 'eligible' ), 10, 4 );
	}

	/**
	 * Open the run context for one tick.
	 *
	 * @param string                                   $correlation_id  The run's correlation id.
	 * @param callable():array<string,mixed>           $objects         Written-object map provider.
	 * @param callable(string,array<string,mixed>):string $object_id_key Object-id key resolver.
	 */
	public static function useRun( string $correlation_id, callable $objects, callable $object_id_key ): void {
		self::$correlation   = '' !== $correlation_id ? $correlation_id : null;
		self::$objects       = $objects;
		self::$object_id_key = $object_id_key;
	}

	/** Close the run context (a tick's `finally`). */
	public static function forgetRun(): void {
		self::$correlation   = null;
		self::$objects       = null;
		self::$object_id_key = null;
	}

	/**
	 * Answer `agent_safety_grant_eligible`.
	 *
	 * Returns a literal bool, never the incoming value: Agent Safety accepts
	 * only `true`, and a filter may narrow but never widen — so a `true` that
	 * arrived from somewhere else is dropped when this harness does not
	 * recognise the grant.
	 *
	 * @param mixed $eligible The incoming decision (false by default).
	 * @param mixed $grant    Agent Safety's Grant value object.
	 * @param mixed $verb     The verb being gated (the ability id).
	 * @param mixed $args     The real call arguments.
	 */
	public static function eligible( $eligible, $grant = null, $verb = null, $args = null ): bool {
		unset( $eligible );

		if ( null === self::$correlation || ! is_object( $grant ) || ! is_string( $verb ) || '' === $verb ) {
			return false;
		}

		// (1) This grant must belong to the run being ticked right now. Agent
		// Safety matched the correlation already; re-checking it here is what
		// makes a stale or foreign context a refusal rather than a silent pass.
		$correlation = property_exists( $grant, 'correlationId' ) ? $grant->correlationId : null;
		if ( ! is_string( $correlation ) || $correlation !== self::$correlation ) {
			return false;
		}

		$granted_verb = property_exists( $grant, 'verb' ) ? $grant->verb : null;
		if ( ! is_string( $granted_verb ) || $granted_verb !== $verb ) {
			return false;
		}

		// (2) The object, when the call names one, must be one this run wrote.
		/** @var array<string,mixed> $call_args */
		$call_args = is_array( $args ) ? $args : array();
		$object_id = self::objectIdIn( $verb, $call_args );
		if ( null === $object_id ) {
			return true;
		}

		return array_key_exists( $object_id, self::runObjects() );
	}

	/**
	 * The object id this call names, or null when it names none.
	 *
	 * @param string              $verb The ability id.
	 * @param array<string,mixed> $args The call arguments.
	 */
	private static function objectIdIn( string $verb, array $args ): ?string {
		$key = 'id';
		if ( is_callable( self::$object_id_key ) ) {
			$resolved = ( self::$object_id_key )( $verb, $args );
			$key      = ( is_string( $resolved ) && '' !== $resolved ) ? $resolved : 'id';
		}

		$value = $args[ $key ] ?? null;
		if ( is_int( $value ) ) {
			return (string) $value;
		}

		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}

	/**
	 * The run's written-object map, as it stands at filter time.
	 *
	 * @return array<string,mixed>
	 */
	private static function runObjects(): array {
		if ( ! is_callable( self::$objects ) ) {
			return array();
		}

		$objects = ( self::$objects )();

		return is_array( $objects ) ? $objects : array();
	}
}
