<?php
/**
 * The commerce capability pack — catalogue (S19, stage 12: D1 add product,
 * D2 bulk price, D3 descriptions, D5 coupons) AND operations (S19, stage 13:
 * D4 refund + customer note, D6 store health report, D7 shipping/tax).
 *
 * TARGET REPO PATH: src/Packs/Commerce/CommercePack.php
 *
 * `abilityNamespaces()` = `['woocommerce/', 'senroflux/']` (S19): a role
 * resolves to Woo's own session ability when it is registered and
 * shape-compatible, else this pack's own polyfill — the pattern S9
 * generalised, applied here to a THIRD-PARTY namespace for the first time.
 *
 * `requiresAgentSafety()` is true (S19): the commerce pack is USELESS
 * without Agent Safety, because it governs only `senroflux/*`
 * ({@see governedNamespaces()}, unchanged base default) — Woo's own session
 * abilities are governed by Agent Safety's Woo integration module, which
 * does not exist in built-in-gate mode at all. Registered whenever
 * WooCommerce is active (composition root), independent of Agent Safety, so
 * its blocking setup check can still show and explain why Start is dead.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Setup\SetupCheck;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The commerce pack.
 */
final class CommercePack extends Pack {

	public function __construct() {
		parent::__construct(
			array(
				'read'           => 'products-query',
				'create'         => 'product-create',
				'update'         => 'product-update',
				'image'          => 'set-product-image',
				'image-generate' => 'generate-image',
				'coupon-create'  => 'coupon-create',
				'coupon-enable'  => 'coupon-enable',
				// Stage 13 (S19 operations rows). 'order-note' resolves to
				// ONE ability (Woo's own `order-add-note`) that spans TWO
				// pack verbs, same shape as 'update' above (see roleVerbs()).
				'order-read'     => 'orders-query',
				'order-note'     => 'order-add-note',
				'refund'         => 'orders-refund',
				'shipping-write' => 'shipping-zone-save',
				'tax-write'      => 'tax-rate-save',
				'store-report'   => 'store-report',
				'report-save'    => 'save-store-report',
			)
		);
	}

	public function name(): string {
		return 'commerce';
	}

	/** @return string 'manage_woocommerce' (S19: the commerce pack's run capability). */
	public function runCapability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * @return list<string> `['woocommerce/', 'senroflux/']` (S19) — Woo's
	 *                       own session ability first, this pack's polyfill
	 *                       last (required by {@see \Specflux\SenroFlux\Packs\PackRegistry::register()}).
	 */
	public function abilityNamespaces(): array {
		return array( 'woocommerce/', self::POLYFILL_NAMESPACE );
	}

	/** @return bool true — the commerce pack refuses to start without Agent Safety (S19). */
	public function requiresAgentSafety(): bool {
		return true;
	}

	/**
	 * The input-property keys this pack's client actually sends, per ability
	 * template (S9 shape-compat seam) — checked only against Woo's OWN
	 * abilities (`products-query`, `product-create`, `product-update`); the
	 * three commerce polyfills are always the final fallback and never
	 * checked against themselves.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	protected function inputProperties( string $template ): array {
		return match ( $template ) {
			'products-query'  => array( 'id' ),
			'product-create'  => array( 'name', 'sku', 'description', 'short_description', 'status', 'regular_price', 'sale_price' ),
			'product-update'  => array( 'id', 'name', 'sku', 'description', 'short_description', 'status', 'regular_price', 'sale_price' ),
			'orders-query'    => array( 'id' ),
			'order-add-note'  => array( 'id', 'note', 'customer_note' ),
			default           => array(),
		};
	}

	/**
	 * The S19 verb predicate. Dispatch is on the ability's name SEGMENT, so
	 * it holds whether a role resolved to Woo's `woocommerce/product-update`
	 * or (Woo absent/incompatible) this pack's own fallback name.
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   Call input.
	 */
	public function verbFor( string $ability, array $input ): string {
		return match ( $this->baseName( $ability ) ) {
			'products-query'     => 'commerce/product-read',
			'product-create'     => $this->createVerb( $input ),
			'product-update'     => $this->updateVerb( $input ),
			'set-product-image'  => 'commerce/product-image',
			'generate-image'     => 'commerce/image-generate',
			'coupon-create'      => 'commerce/coupon-draft',
			'coupon-enable'      => 'commerce/coupon-enable',
			'orders-query'       => 'commerce/order-read',
			'order-add-note'     => $this->noteVerb( $input ),
			'orders-refund'      => 'commerce/refund',
			'shipping-zone-save' => 'commerce/shipping-write',
			'tax-rate-save'      => 'commerce/tax-write',
			'store-report'       => 'commerce/store-report',
			'save-store-report'  => 'commerce/report-save',
			default              => $ability,
		};
	}

	/**
	 * `order-add-note`'s predicate (S19 table): `customer_note` true is the
	 * customer-visible note, Tier 2 and ungrantable; false or absent is the
	 * private, internal-only note, Tier 1. Only a strict `true` counts —
	 * a truthy-but-not-boolean value (e.g. the string `"1"` a looser client
	 * might send) is read as absent/false, the SAME fail-closed-on-the-SAFER-
	 * reading choice `isPublishStatus()` makes for `status`: understating
	 * which note is customer-visible would be the dangerous direction, but
	 * Woo's own ability schema types `customer_note` as a boolean, so a
	 * non-boolean value here is a malformed call, not a legitimate "true".
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function noteVerb( array $input ): string {
		return true === ( $input['customer_note'] ?? false )
			? 'commerce/order-note-customer'
			: 'commerce/order-note-private';
	}

	/**
	 * `product-create`'s predicate (S19 table): a status other than `draft`
	 * (or absent, which Woo treats as draft) that is `publish`/`future` is
	 * `commerce/product-publish`; anything else stays
	 * `commerce/product-create-draft`. Only `publish`/`future` count as
	 * "other than draft" for TIER purposes — a create with `status: pending`
	 * or `private` is still a draft-shaped Tier-1 create in this pack's
	 * reading, matching the pages pack's identical publish/future-only test
	 * (0.3 S4) rather than treating every non-`draft` string as a publish.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function createVerb( array $input ): string {
		return $this->isPublishStatus( $input ) ? 'commerce/product-publish' : 'commerce/product-create-draft';
	}

	/**
	 * `product-update`'s predicate (S19 table). DECISION (explicitly asked
	 * for by the build plan): when a single update call carries BOTH a
	 * price field AND a publish/future status, both readings are Tier 2, so
	 * there is no tier ambiguity to break — but the verb name still has to
	 * pick one for the plan/approval card. Fail-closed reading: PUBLISH WINS.
	 * A card that only said "price change" on a call that also takes the
	 * product live would understate what is about to happen; "publish"
	 * names the more consequential, harder-to-undo action, and the current
	 * vs proposed price still renders on the publish card (S19: "product
	 * title, preview link, price, stock").
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function updateVerb( array $input ): string {
		if ( $this->isPublishStatus( $input ) ) {
			return 'commerce/product-publish';
		}
		if ( $this->isPriceChange( $input ) ) {
			return 'commerce/price-change';
		}

		return 'commerce/product-update';
	}

	/**
	 * S19: "when `regular_price` or `sale_price` is present" — present AND
	 * non-null, so a call that merely echoes other fields with these keys
	 * explicitly nulled (Woo's "clear this price" shape) is not mistaken for
	 * having priced anything.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function isPriceChange( array $input ): bool {
		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private function isPublishStatus( array $input ): bool {
		$status = $input['status'] ?? null;
		if ( ! is_string( $status ) ) {
			return false;
		}

		return in_array( strtolower( trim( $status ) ), array( 'publish', 'future' ), true );
	}

	/**
	 * The full S19 verb => tier table (catalogue, stage 12, plus operations,
	 * stage 13).
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return array(
			'commerce/product-read'         => 0,
			'commerce/product-create-draft' => 1,
			'commerce/product-update'       => 1,
			'commerce/price-change'         => 2,
			'commerce/product-publish'      => 2,
			'commerce/product-image'        => 1,
			'commerce/image-generate'       => 1,
			'commerce/coupon-draft'         => 1,
			'commerce/coupon-enable'        => 2,
			'commerce/order-read'           => 0,
			'commerce/order-note-private'   => 1,
			'commerce/order-note-customer'  => 2,
			'commerce/refund'               => 2,
			'commerce/shipping-write'       => 2,
			'commerce/tax-write'            => 2,
			'commerce/store-report'         => 0,
			'commerce/report-save'          => 2,
		);
	}

	/**
	 * The full S19 role => pack-verb split.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		return array(
			'read'           => array( 'commerce/product-read' ),
			'create'         => array( 'commerce/product-create-draft', 'commerce/product-publish' ),
			'update'         => array( 'commerce/product-update', 'commerce/price-change', 'commerce/product-publish' ),
			'image'          => array( 'commerce/product-image' ),
			'image-generate' => array( 'commerce/image-generate' ),
			'coupon-create'  => array( 'commerce/coupon-draft' ),
			'coupon-enable'  => array( 'commerce/coupon-enable' ),
			'order-read'     => array( 'commerce/order-read' ),
			'order-note'     => array( 'commerce/order-note-private', 'commerce/order-note-customer' ),
			'refund'         => array( 'commerce/refund' ),
			'shipping-write' => array( 'commerce/shipping-write' ),
			'tax-write'      => array( 'commerce/tax-write' ),
			'store-report'   => array( 'commerce/store-report' ),
			'report-save'    => array( 'commerce/report-save' ),
		);
	}

	/**
	 * S19 stage 13: `commerce/order-note-customer` and `commerce/refund` are
	 * NEVER pre-approval-granted, however many times an accepted plan lists
	 * them — they ask a human every single time (the operations skill says
	 * so). Both share Woo-owned/polyfill abilities with a grantable sibling
	 * (`order-add-note` also carries the private-note verb; `orders-refund`
	 * has no grantable sibling but is still named here so the rule reads as
	 * one list rather than one special case), and
	 * {@see \Specflux\SenroFlux\Run\Runner::poisonedGateVerbs()} (S19 stage
	 * 12 guard) already refuses to let a grant issued for the private-note
	 * verb be spent on a customer-visible note instead — see that method's
	 * docblock for why "no grant at all" is the only safe reading for a
	 * shared ability.
	 *
	 * @return list<string>
	 */
	public function ungrantableVerbs(): array {
		return array( 'commerce/order-note-customer', 'commerce/refund' );
	}

	/**
	 * S19: BEFORE a call to a WOO-OWNED ability (`product-create`,
	 * `product-update`) reaches that ability's own
	 * `check_permissions()`/`execute()`, refuse a description/
	 * short_description that carries a disallowed tag, an unsafe link
	 * scheme, or more than {@see DescriptionValidator::MAX_WORDS} words
	 * (D3). This pack's OWN polyfills (`set-product-image`, `coupon-create`,
	 * `coupon-enable`) validate their own input directly inside their
	 * `execute_callback` and are never routed through here.
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   The call input.
	 */
	public function validateCall( string $ability, array $input ): ?WP_Error {
		if ( ! in_array( $this->baseName( $ability ), array( 'product-create', 'product-update' ), true ) ) {
			return null;
		}

		$validator = new DescriptionValidator();
		foreach ( array( 'description', 'short_description' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) ) {
				continue;
			}
			$violation = $validator->validate( $input[ $field ] );
			if ( null !== $violation ) {
				return $violation;
			}
		}

		return null;
	}

	/**
	 * The two pack skills (S19). `commerce/operations-rules` now DESCRIBES
	 * ENFORCED stage-13 behaviour: refunds/customer notes ask every time
	 * (`ungrantableVerbs()`), the `refunds` budget defaults to one per run,
	 * and the store report never writes.
	 *
	 * @return list<Skill>
	 */
	public function skills(): array {
		return array(
			new Skill(
				'commerce/catalogue-rules',
				'Catalogue rules',
				$this->catalogueRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
			new Skill(
				'commerce/operations-rules',
				'Operations rules',
				$this->operationsRulesBody(),
				false,
				SkillSource::Pack,
				'1'
			),
		);
	}

	private function catalogueRulesBody(): string {
		return implode(
			"\n",
			array(
				'Products, prices and descriptions: a new product is created as a draft unless you are explicitly asked to publish it. Publishing a product, or changing its regular or sale price, always asks a human first.',
				'State every price in the store\'s own currency, with its own number of decimal places — never invent a currency or round differently from what the store already shows.',
				'Product descriptions and short descriptions may use ONLY these HTML tags: p, ul, ol, li, strong, em, a, h3. A description is at most 1,500 words. Anything outside that tag list, or an unsafe link scheme, is refused whole — it is never trimmed or rewritten for you, so write within the rule the first time.',
				'To add or replace a product\'s image, use an existing media library attachment that already has alt text — an attachment with no alt text, or one that is not an image, is refused.',
				'Coupons are created as drafts and enabled as a separate step. A coupon code that already exists is refused — check first if you are not sure it is free. Enabling a coupon you created earlier in this same run can fail if the coupon changed since you created it; re-read it before enabling in that case.',
			)
		);
	}

	private function operationsRulesBody(): string {
		return implode(
			"\n",
			array(
				'Refunds and customer-visible order notes ask a human every single time, however a plan was approved — they are never pre-approved in bulk like an ordinary Tier-2 action.',
				'At most one refund per run by default.',
				'A store report is read-only: producing one never writes anything, and saving it as a page is a separate, explicit step.',
			)
		);
	}

	/**
	 * The S19 setup checks: in built-in-gate mode (Agent Safety absent) the
	 * WHOLE test is the blocking `commerce/agent-safety` check, worded and
	 * linked for someone who can install plugins; in Agent Safety mode the
	 * pack falls back to the base's own capability + binding checks
	 * unchanged.
	 *
	 * @param int $user_id The user the run would be started for.
	 * @return list<\Specflux\SenroFlux\Setup\SetupCheck>
	 */
	public function setupChecks( int $user_id ): array {
		if ( GateMode::BuiltIn === Plugin::currentGateMode() ) {
			return array( $this->agentSafetyCheck() );
		}

		return parent::setupChecks( $user_id );
	}

	/** The `commerce/agent-safety` setup check (S19). */
	private function agentSafetyCheck(): SetupCheck {
		$message = __( 'The commerce pack needs the Agent Safety plugin active — without it, WooCommerce writes have no governance to run under.', 'senroflux' );

		return new SetupCheck(
			'commerce/agent-safety',
			SetupCheck::BLOCKING,
			false,
			$message,
			function_exists( 'admin_url' ) ? admin_url( 'plugin-install.php?s=agent-safety&tab=search&type=term' ) : null,
			'install_plugins',
			$message,
			'pack_requires_agent_safety'
		);
	}

	/**
	 * The Agent Safety pack descriptor (S19), registered on
	 * `agent_safety_pack_registry`. Same reading as the pages pack's own
	 * (see {@see \Specflux\SenroFlux\Packs\Pages\PagesPack::agentSafetyPack()}
	 * for the full rationale): `allow` is the RESOLVED ability list, and
	 * every Tier-2 ability is approval-gated.
	 */
	public function agentSafetyPack(): ?object {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return null;
		}

		return new \Specflux\AgentSafety\Packs\Pack(
			name: 'commerce',
			allow: $this->allowList(),
			approvalByClass: array( 'tier2' => true ),
		);
	}

	/**
	 * S13 real binding check, same shape as the pages pack's (duplicated
	 * rather than shared — the base leaves this abstract per-pack, and no
	 * shared helper exists yet across packs).
	 *
	 * @return WP_Error|null null when bound, else pack_unbound.
	 */
	protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
		if ( ! function_exists( 'agent_safety' ) || null === agent_safety() ) {
			return $this->packUnboundError();
		}

		if ( ! class_exists( \Specflux\AgentSafety\Plugin\Support\PackResolver::class )
			|| ! class_exists( \Specflux\AgentSafety\Packs\Pack::class )
			|| ! class_exists( \Specflux\AgentSafety\Policy\Tier::class )
		) {
			return $this->packUnboundError();
		}

		$resolved = $this->resolveAsPackForUser( $user_id );
		if ( null === $resolved ) {
			return $this->packUnboundError();
		}

		foreach ( $this->allowList() as $ability ) {
			if ( ! $resolved->allows( $ability ) ) {
				return $this->packUnboundError();
			}
		}

		if ( ! $resolved->requiresApproval( \Specflux\AgentSafety\Policy\Tier::Irreversible ) ) {
			return $this->packUnboundError();
		}

		return null;
	}

	/**
	 * Resolve the AS pack for a SPECIFIC user: the first bound token
	 * (`user:N` then `role:<slug>`) resolves to a pack, else the registry
	 * default (fail-closed `default-agent`).
	 *
	 * @return \Specflux\AgentSafety\Packs\Pack|null
	 */
	private function resolveAsPackForUser( int $user_id ): ?\Specflux\AgentSafety\Packs\Pack {
		$resolver = new \Specflux\AgentSafety\Plugin\Support\PackResolver();
		$registry = $resolver->registry();
		$bindings = $registry->bindings();

		foreach ( $this->userTokens( $user_id ) as $token ) {
			if ( isset( $bindings[ $token ] ) ) {
				$pack = $registry->get( $bindings[ $token ] );
				if ( null !== $pack ) {
					return $pack;
				}
			}
		}

		return $registry->resolve( null );
	}

	/**
	 * Identity tokens for a user id, in binding priority order.
	 *
	 * @return list<string>
	 */
	private function userTokens( int $user_id ): array {
		$tokens = array( 'user:' . $user_id );

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && is_array( $user->roles ) ) {
				foreach ( $user->roles as $role ) {
					if ( is_string( $role ) && '' !== $role ) {
						$tokens[] = 'role:' . $role;
					}
				}
			}
		}

		return $tokens;
	}

	/** @return WP_Error pack_unbound (400). */
	private function packUnboundError(): WP_Error {
		return new WP_Error(
			'pack_unbound',
			__( 'This pack is not bound to your user. Ask an administrator to bind `user:N` or `role:administrator` to the commerce pack.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}
}
