<?php
/**
 * CommercePack build-contract tests (S19): catalogue (stage 12) and
 * operations (stage 13 — order read/note, refund, shipping, tax, report).
 *
 * TARGET REPO PATH: tests/Packs/Commerce/CommercePackTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Skills\SkillSet;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

final class CommercePackTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		remove_all_filters( 'senroflux_skills_max_tokens' );
		remove_all_filters( 'senroflux_run_skills' );
		Plugin::reset();
	}

	protected function tearDown(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( null );
		unset( $GLOBALS['senroflux_test_user_caps_by_id'], $GLOBALS['senroflux_test_posts'] );
	}

	public function test_name_is_commerce(): void {
		$this->assertSame( 'commerce', ( new CommercePack() )->name() );
	}

	public function test_run_capability_is_manage_woocommerce(): void {
		$this->assertSame( 'manage_woocommerce', ( new CommercePack() )->runCapability() );
	}

	public function test_ability_namespaces_prefer_woocommerce_then_senroflux(): void {
		$this->assertSame( array( 'woocommerce/', 'senroflux/' ), ( new CommercePack() )->abilityNamespaces() );
	}

	public function test_requires_agent_safety(): void {
		$this->assertTrue( ( new CommercePack() )->requiresAgentSafety() );
	}

	public function test_governs_only_the_senroflux_namespace(): void {
		// Woo's own module governs woocommerce/*; this pack governs only its
		// own polyfills (S19).
		$this->assertSame( array( 'senroflux/' ), ( new CommercePack() )->governedNamespaces() );
	}

	// ------------------------------------------------------------------
	// verbMap() — catalogue rows only (S19 table)
	// ------------------------------------------------------------------

	public function test_verb_map_matches_the_full_s19_table(): void {
		$this->assertSame(
			array(
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
			),
			( new CommercePack() )->verbMap()
		);
	}

	public function test_ungrantable_verbs_are_order_note_customer_and_refund(): void {
		$this->assertSame(
			array( 'commerce/order-note-customer', 'commerce/refund' ),
			( new CommercePack() )->ungrantableVerbs()
		);
	}

	/**
	 * Confirms the stage-12 grant guard (`Runner::poisonedGateVerbs()`) now
	 * has real teeth over `woocommerce/order-add-note`: the pack's own
	 * `gateVerbFor()` shows BOTH the grantable private-note verb and the
	 * ungrantable customer-note verb resolve to the exact same gate ability,
	 * which is precisely the shape `poisonedGateVerbs()` refuses a grant
	 * for — so no plan can ever buy a pre-approval that a customer-visible
	 * note could spend.
	 */
	public function test_order_note_private_and_customer_share_one_gate_ability_so_no_grant_ever_issues(): void {
		$pack = new CommercePack();

		$private  = $pack->gateVerbFor( 'commerce/order-note-private' );
		$customer = $pack->gateVerbFor( 'commerce/order-note-customer' );

		$this->assertNotNull( $private );
		$this->assertSame( $private, $customer, 'both notes must resolve to the same ability for the guard to poison it' );
		$this->assertContains( 'commerce/order-note-customer', $pack->ungrantableVerbs() );
	}

	// ------------------------------------------------------------------
	// verbFor() — every catalogue row, including the price/publish splits
	// ------------------------------------------------------------------

	public function test_products_query_is_product_read(): void {
		$this->assertSame(
			'commerce/product-read',
			( new CommercePack() )->verbFor( 'woocommerce/products-query', array( 'id' => 5 ) )
		);
	}

	public function test_product_create_with_no_status_is_draft(): void {
		$this->assertSame(
			'commerce/product-create-draft',
			( new CommercePack() )->verbFor( 'woocommerce/product-create', array( 'name' => 'Widget' ) )
		);
	}

	public function test_product_create_with_draft_status_is_draft(): void {
		$this->assertSame(
			'commerce/product-create-draft',
			( new CommercePack() )->verbFor( 'woocommerce/product-create', array( 'status' => 'draft' ) )
		);
	}

	public function test_product_create_with_publish_status_is_publish(): void {
		$this->assertSame(
			'commerce/product-publish',
			( new CommercePack() )->verbFor( 'woocommerce/product-create', array( 'status' => 'publish' ) )
		);
	}

	public function test_product_create_with_future_status_is_publish(): void {
		$this->assertSame(
			'commerce/product-publish',
			( new CommercePack() )->verbFor( 'woocommerce/product-create', array( 'status' => 'future' ) )
		);
	}

	public function test_product_create_with_pending_status_stays_draft_tier(): void {
		// Only publish/future count as "going live" for tier purposes (design
		// decision, stated in CommercePack::createVerb()'s docblock).
		$this->assertSame(
			'commerce/product-create-draft',
			( new CommercePack() )->verbFor( 'woocommerce/product-create', array( 'status' => 'pending' ) )
		);
	}

	public function test_product_update_with_no_price_or_status_is_plain_update(): void {
		$this->assertSame(
			'commerce/product-update',
			( new CommercePack() )->verbFor( 'woocommerce/product-update', array( 'description' => 'New copy' ) )
		);
	}

	public function test_product_update_with_regular_price_is_price_change(): void {
		$this->assertSame(
			'commerce/price-change',
			( new CommercePack() )->verbFor( 'woocommerce/product-update', array( 'regular_price' => '19.99' ) )
		);
	}

	public function test_product_update_with_sale_price_is_price_change(): void {
		$this->assertSame(
			'commerce/price-change',
			( new CommercePack() )->verbFor( 'woocommerce/product-update', array( 'sale_price' => '14.99' ) )
		);
	}

	public function test_product_update_with_null_price_keys_is_not_a_price_change(): void {
		$this->assertSame(
			'commerce/product-update',
			( new CommercePack() )->verbFor(
				'woocommerce/product-update',
				array(
					'regular_price' => null,
					'sale_price'    => null,
				)
			)
		);
	}

	public function test_product_update_with_publish_status_is_publish(): void {
		$this->assertSame(
			'commerce/product-publish',
			( new CommercePack() )->verbFor( 'woocommerce/product-update', array( 'status' => 'publish' ) )
		);
	}

	/**
	 * DECISION (stated in CommercePack::updateVerb()'s docblock): a call
	 * carrying BOTH a price field and a publish/future status resolves to
	 * `commerce/product-publish` — publish wins. Both readings are Tier 2;
	 * this is a fail-closed CHOICE of which name the plan/approval card
	 * shows, not a tier ambiguity.
	 */
	public function test_product_update_with_both_price_and_publish_status_resolves_to_publish(): void {
		$this->assertSame(
			'commerce/product-publish',
			( new CommercePack() )->verbFor(
				'woocommerce/product-update',
				array(
					'regular_price' => '19.99',
					'status'        => 'publish',
				)
			)
		);
	}

	public function test_set_product_image_is_product_image(): void {
		$this->assertSame(
			'commerce/product-image',
			( new CommercePack() )->verbFor( 'senroflux/set-product-image', array( 'product_id' => 1 ) )
		);
	}

	public function test_generate_image_is_image_generate(): void {
		$this->assertSame(
			'commerce/image-generate',
			( new CommercePack() )->verbFor( 'senroflux/generate-image', array( 'prompt' => 'a widget' ) )
		);
	}

	public function test_coupon_create_is_coupon_draft(): void {
		$this->assertSame(
			'commerce/coupon-draft',
			( new CommercePack() )->verbFor( 'senroflux/coupon-create', array( 'code' => 'SAVE10' ) )
		);
	}

	public function test_coupon_enable_is_coupon_enable(): void {
		$this->assertSame(
			'commerce/coupon-enable',
			( new CommercePack() )->verbFor( 'senroflux/coupon-enable', array( 'coupon_id' => 1 ) )
		);
	}

	public function test_orders_query_is_order_read(): void {
		$this->assertSame(
			'commerce/order-read',
			( new CommercePack() )->verbFor( 'woocommerce/orders-query', array( 'id' => 10 ) )
		);
	}

	public function test_order_add_note_without_customer_note_is_private(): void {
		$this->assertSame(
			'commerce/order-note-private',
			( new CommercePack() )->verbFor(
				'woocommerce/order-add-note',
				array(
					'id'   => 10,
					'note' => 'internal',
				)
			)
		);
	}

	public function test_order_add_note_with_customer_note_false_is_private(): void {
		$this->assertSame(
			'commerce/order-note-private',
			( new CommercePack() )->verbFor(
				'woocommerce/order-add-note',
				array(
					'id'            => 10,
					'note'          => 'internal',
					'customer_note' => false,
				)
			)
		);
	}

	public function test_order_add_note_with_customer_note_true_is_customer(): void {
		$this->assertSame(
			'commerce/order-note-customer',
			( new CommercePack() )->verbFor(
				'woocommerce/order-add-note',
				array(
					'id'            => 10,
					'note'          => 'Refunded',
					'customer_note' => true,
				)
			)
		);
	}

	public function test_orders_refund_is_refund(): void {
		$this->assertSame(
			'commerce/refund',
			( new CommercePack() )->verbFor( 'senroflux/orders-refund', array( 'order_id' => 1 ) )
		);
	}

	public function test_shipping_zone_save_is_shipping_write(): void {
		$this->assertSame(
			'commerce/shipping-write',
			( new CommercePack() )->verbFor( 'senroflux/shipping-zone-save', array( 'name' => 'US' ) )
		);
	}

	public function test_tax_rate_save_is_tax_write(): void {
		$this->assertSame(
			'commerce/tax-write',
			( new CommercePack() )->verbFor( 'senroflux/tax-rate-save', array( 'country' => 'US' ) )
		);
	}

	public function test_store_report_is_store_report(): void {
		$this->assertSame(
			'commerce/store-report',
			( new CommercePack() )->verbFor(
				'senroflux/store-report',
				array(
					'from' => '2026-01-01',
					'to'   => '2026-01-31',
				)
			)
		);
	}

	public function test_save_store_report_is_report_save(): void {
		$this->assertSame(
			'commerce/report-save',
			( new CommercePack() )->verbFor( 'senroflux/save-store-report', array( 'title' => 'Q1' ) )
		);
	}

	public function test_unmapped_ability_keeps_its_id_as_the_verb(): void {
		$this->assertSame(
			'woocommerce/product-delete',
			( new CommercePack() )->verbFor( 'woocommerce/product-delete', array( 'id' => 1 ) )
		);
	}

	// ------------------------------------------------------------------
	// roleVerbs() / verbMap() consistency
	// ------------------------------------------------------------------

	public function test_role_verbs_cover_every_role(): void {
		$pack = new CommercePack();

		$this->assertSame( array_keys( $pack->roles() ), array_keys( $pack->roleVerbs() ) );
	}

	public function test_every_declared_role_verb_is_in_the_verb_map(): void {
		$pack = new CommercePack();
		$map  = $pack->verbMap();

		foreach ( $pack->roleVerbs() as $role => $verbs ) {
			foreach ( $verbs as $verb ) {
				$this->assertArrayHasKey( $verb, $map, sprintf( 'role %s declares an unmapped verb %s', $role, $verb ) );
			}
		}
	}

	// ------------------------------------------------------------------
	// validateCall() — D3 description-tag refusal, BEFORE the Woo ability runs
	// ------------------------------------------------------------------

	public function test_validate_call_refuses_a_disallowed_tag_in_a_product_update(): void {
		$error = ( new CommercePack() )->validateCall(
			'woocommerce/product-update',
			array( 'description' => '<script>alert(1)</script>' )
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'disallowed_tag', $error->get_error_code() );
	}

	public function test_validate_call_refuses_a_disallowed_tag_in_a_product_create(): void {
		$error = ( new CommercePack() )->validateCall(
			'woocommerce/product-create',
			array( 'short_description' => '<img src="x">' )
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'disallowed_tag', $error->get_error_code() );
	}

	public function test_validate_call_accepts_allowed_tags(): void {
		$this->assertNull(
			( new CommercePack() )->validateCall(
				'woocommerce/product-update',
				array( 'description' => '<p>Great <strong>widget</strong>.</p>' )
			)
		);
	}

	public function test_validate_call_ignores_abilities_it_does_not_own(): void {
		$this->assertNull(
			( new CommercePack() )->validateCall(
				'woocommerce/products-query',
				array( 'description' => '<script>bad</script>' )
			)
		);
	}

	public function test_validate_call_ignores_its_own_polyfills(): void {
		$this->assertNull(
			( new CommercePack() )->validateCall(
				'senroflux/coupon-create',
				array( 'code' => 'SAVE10' )
			)
		);
	}

	// ------------------------------------------------------------------
	// Skills — 2,000-token ceiling
	// ------------------------------------------------------------------

	public function test_skills_returns_two_pack_skills(): void {
		$skills = ( new CommercePack() )->skills();

		$this->assertCount( 2, $skills );
		$ids = array_map( static fn ( $s ) => $s->id, $skills );
		$this->assertContains( 'commerce/catalogue-rules', $ids );
		$this->assertContains( 'commerce/operations-rules', $ids );

		foreach ( $skills as $skill ) {
			$this->assertSame( '1', $skill->version );
			$this->assertSame( SkillSource::Pack, $skill->source );
		}
	}

	public function test_a_commerce_run_skill_set_stays_under_the_2000_token_ceiling(): void {
		$skills = SkillSet::collect( 'test-consumer', 'List products under $20', new CommercePack(), null, 'en_US' );

		$this->assertNull( SkillSet::ceilingError( $skills ) );
	}

	// ------------------------------------------------------------------
	// Setup checks / preflight — S19: start refused without Agent Safety
	// ------------------------------------------------------------------

	public function test_setup_checks_in_built_in_mode_is_the_blocking_agent_safety_check(): void {
		Plugin::set_dependency_probe( false ); // Agent Safety absent => built-in mode.

		$checks = ( new CommercePack() )->setupChecks( 1 );

		$this->assertCount( 1, $checks );
		$this->assertSame( 'commerce/agent-safety', $checks[0]->id() );
		$this->assertTrue( $checks[0]->isBlocking() );
		$this->assertFalse( $checks[0]->passed() );
		$this->assertSame( 'install_plugins', $checks[0]->fixCapability() );
	}

	public function test_preflight_refuses_to_start_without_agent_safety(): void {
		Plugin::set_dependency_probe( false );

		$result = ( new CommercePack() )->preflight( 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_requires_agent_safety', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_agent_safety_pack_is_null_when_class_absent(): void {
		if ( class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			$this->markTestSkipped( 'Agent Safety core is loaded in this environment.' );
		}

		$this->assertNull( ( new CommercePack() )->agentSafetyPack() );
	}
}
