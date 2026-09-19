<?php
/**
 * Content\Abilities tests (0.3 S4: moved + split from Pages\Abilities).
 *
 * TARGET REPO PATH: tests/Packs/Content/AbilitiesTest.php
 *
 * Four things are under test here:
 *   1. the CAPABILITY contract — every `permission_callback`, and the same
 *      gates re-applied inside each execute callback (a write must never rely
 *      on an earlier gate having run);
 *   2. the WRITE contract — create is draft-only, the update/publish split's
 *      status allow-lists and routing, and that EVERY refusal persists
 *      nothing;
 *   3. the SLUG-COLLISION refusal (S4), across every collision status and
 *      ignoring trash;
 *   4. the PACK SEAM (S4 point 4) — vocabulary-bearing calls resolve the
 *      running pack's vocabulary/validator, never a model-supplied `pack`.
 *
 * The `current_user_can()` shim is keyed by capability name only, so a test
 * that withholds `edit_post` is asserting the per-object check EXISTS and
 * refuses; it cannot distinguish two different post ids.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Content;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Content\Abilities;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use WP_Error;

final class AbilitiesTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_inserted_posts']     = array();
		$GLOBALS['senroflux_test_next_post_id']       = 100;
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_users']              = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_user_caps']          = array();

		Abilities::reset();
		Abilities::resetSources();
		Abilities::registerCategory();
		Abilities::register();

		$vocabulary = new Vocabulary();
		Abilities::registerSource( 'pages', new Validator( $vocabulary ), $vocabulary, 'edit_pages' );
		Abilities::useRunPack( 'pages' );
	}

	protected function tearDown(): void {
		Abilities::forgetRunPack();
		Abilities::resetSources();
	}

	// --- Helpers -----------------------------------------------------------

	private function grant( string ...$caps ): void {
		$GLOBALS['senroflux_test_user_caps'] = array();
		foreach ( $caps as $cap ) {
			$GLOBALS['senroflux_test_user_caps'][ $cap ] = true;
		}
	}

	private function ability( string $name ): object {
		$ability = wp_get_ability( $name );
		$this->assertIsObject( $ability, $name . ' must be registered' );

		return $ability;
	}

	private function validContent(): string {
		$vocabulary = new Vocabulary();

		// Pattern index 0 is hero; index 1 is text-section.
		return $vocabulary->all()[0]['markup'] . "\n\n" . $vocabulary->all()[1]['markup'];
	}

	private function seedPost( int $id = 100, string $post_type = 'page', string $status = 'draft', string $title = 'Existing', string $slug = 'existing' ): \stdClass {
		$post                = new \stdClass();
		$post->ID            = $id;
		$post->post_type     = $post_type;
		$post->post_title    = $title;
		$post->post_content  = '<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->';
		$post->post_status   = $status;
		$post->post_name     = $slug;
		$post->post_parent   = 0;
		$post->post_excerpt  = '';
		$post->post_author   = 7;
		$post->post_date     = '2026-01-01 00:00:00';
		$post->post_modified = '2026-01-01 00:00:00';

		$GLOBALS['senroflux_test_posts'][ $id ] = $post;

		return $post;
	}

	// --- permission_callback ----------------------------------------------

	public function test_all_six_abilities_are_registered(): void {
		foreach (
			array(
				'senroflux/read-content',
				'senroflux/create-post',
				'senroflux/update-post',
				'senroflux/publish-post',
				'senroflux/get-preview-url',
				'senroflux/list-patterns',
			) as $name
		) {
			$this->assertNotNull( wp_get_ability( $name ), $name );
		}
	}

	public function test_read_content_permission_requires_the_post_type_cap(): void {
		$ability = $this->ability( 'senroflux/read-content' );

		$this->assertFalse( (bool) $ability->check_permissions( array( 'post_type' => 'page' ) ) );

		$this->grant( 'edit_pages' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'post_type' => 'page' ) ) );

		// `post` uses its own cap, and `edit_pages` does not stand in for it.
		$this->assertFalse( (bool) $ability->check_permissions( array( 'post_type' => 'post' ) ) );
	}

	public function test_read_content_permission_refuses_a_post_type_off_the_allowlist(): void {
		$this->grant( 'edit_pages', 'edit_posts', 'read_post' );

		$this->assertFalse(
			(bool) $this->ability( 'senroflux/read-content' )->check_permissions( array( 'post_type' => 'attachment' ) )
		);
	}

	public function test_read_content_permission_requires_read_post_on_the_id_branch(): void {
		$this->seedPost();
		$ability = $this->ability( 'senroflux/read-content' );

		$this->grant( 'edit_pages' );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );

		$this->grant( 'edit_pages', 'read_post' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );
	}

	public function test_create_permission_requires_the_create_cap(): void {
		$ability = $this->ability( 'senroflux/create-post' );

		$this->assertFalse( (bool) $ability->check_permissions( array( 'post_type' => 'page' ) ) );

		$this->grant( 'edit_pages' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'post_type' => 'page' ) ) );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'post_type' => 'post' ) ) );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'post_type' => 'attachment' ) ) );
	}

	public function test_update_permission_requires_per_post_edit_post(): void {
		$this->seedPost();
		$ability = $this->ability( 'senroflux/update-post' );

		// The primitive type cap alone is NOT enough.
		$this->grant( 'edit_pages' );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );

		$this->grant( 'edit_pages', 'edit_post' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );
	}

	public function test_update_permission_refuses_an_unknown_id(): void {
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$this->assertFalse(
			(bool) $this->ability( 'senroflux/update-post' )->check_permissions( array( 'id' => 999 ) )
		);
	}

	/** update-post is draft-state edits ONLY now (0.3 S4): it never accepts a publish transition, cap or no cap. */
	public function test_update_permission_refuses_a_publish_transition_even_with_the_publish_cap(): void {
		$this->seedPost();
		$ability = $this->ability( 'senroflux/update-post' );

		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );
		$this->assertFalse(
			(bool) $ability->check_permissions(
				array(
					'id'     => 100,
					'status' => 'publish',
				)
			)
		);
	}

	/** …and refuses a target that is already public, even for a plain edit. */
	public function test_update_permission_refuses_an_already_public_target(): void {
		$this->seedPost( 100, 'page', 'publish' );
		$ability = $this->ability( 'senroflux/update-post' );

		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );
	}

	public function test_publish_permission_requires_publish_cap_for_a_transition(): void {
		$this->seedPost();
		$ability = $this->ability( 'senroflux/publish-post' );

		$this->grant( 'edit_pages', 'edit_post' );
		$this->assertFalse(
			(bool) $ability->check_permissions(
				array(
					'id'     => 100,
					'status' => 'publish',
				)
			)
		);

		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );
		$this->assertTrue(
			(bool) $ability->check_permissions(
				array(
					'id'     => 100,
					'status' => 'publish',
				)
			)
		);
	}

	/** Editing an already-public post through publish-post needs no publish cap: it isn't a transition. */
	public function test_publish_permission_allows_editing_an_already_public_post_without_the_publish_cap(): void {
		$this->seedPost( 100, 'page', 'publish' );
		$ability = $this->ability( 'senroflux/publish-post' );

		$this->grant( 'edit_pages', 'edit_post' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );
	}

	/** publish-post refuses a draft-state target that isn't transitioning: that call belongs to update-post. */
	public function test_publish_permission_refuses_a_draft_target_with_no_transition(): void {
		$this->seedPost();
		$ability = $this->ability( 'senroflux/publish-post' );

		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );
		$this->assertFalse( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );
	}

	public function test_get_preview_url_permission_requires_edit_post(): void {
		$this->seedPost();
		$ability = $this->ability( 'senroflux/get-preview-url' );

		$this->assertFalse( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );

		$this->grant( 'edit_post' );
		$this->assertTrue( (bool) $ability->check_permissions( array( 'id' => 100 ) ) );
	}

	public function test_list_patterns_permission_requires_edit_pages(): void {
		$ability = $this->ability( 'senroflux/list-patterns' );

		$this->assertFalse( (bool) $ability->check_permissions( array() ) );

		$this->grant( 'edit_pages' );
		$this->assertTrue( (bool) $ability->check_permissions( array() ) );
	}

	/** No pack resolved (S4 point 4): list-patterns' permission fails closed, never a default pack. */
	public function test_list_patterns_permission_fails_closed_with_no_pack_resolved(): void {
		Abilities::forgetRunPack();
		$this->grant( 'edit_pages' );

		$this->assertFalse( (bool) $this->ability( 'senroflux/list-patterns' )->check_permissions( array() ) );
	}

	// --- get-preview-url execute ------------------------------------------

	public function test_get_preview_url_returns_the_preview_link(): void {
		$this->seedPost();
		$this->grant( 'edit_post' );

		$result = $this->ability( 'senroflux/get-preview-url' )->execute( array( 'id' => 100 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.test/?p=100&preview=true', $result['preview_url'] );
	}

	public function test_get_preview_url_refuses_an_unknown_id(): void {
		$this->grant( 'edit_post' );

		$result = $this->ability( 'senroflux/get-preview-url' )->execute( array( 'id' => 999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	// --- create-post execute ----------------------------------------------

	public function test_create_post_refuses_without_the_create_cap(): void {
		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'], 'a refusal must persist nothing' );
	}

	public function test_create_post_status_not_allowed_refuses_publish(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => $this->validContent(),
				'status'    => 'publish',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'], 'a refusal must persist nothing' );
	}

	/** S4: create-post refuses `future` outright too, same as any non-draft status. */
	public function test_create_post_status_not_allowed_refuses_future(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => $this->validContent(),
				'status'    => 'future',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	public function test_create_post_returns_id_and_forces_draft(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $this->validContent(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertCount( 1, $GLOBALS['senroflux_test_inserted_posts'] );
		$this->assertSame( 'draft', $GLOBALS['senroflux_test_inserted_posts'][0]['post_status'] );
	}

	public function test_create_post_refuses_invalid_markup(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => '<!-- wp:image --><figure></figure><!-- /wp:image -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_block', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	public function test_create_post_refuses_a_script_payload_and_persists_nothing(): void {
		$this->grant( 'edit_pages' );

		$content = str_replace(
			'<p>One short paragraph that makes a single concrete point.</p>',
			'<p>Hi<script>alert(1)</script></p>',
			$this->validContent()
		);

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => $content,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'disallowed_markup', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	public function test_create_post_refuses_a_model_supplied_pack_argument(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => $this->validContent(),
				'pack'      => 'posts',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_arg_refused', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	public function test_create_post_fails_closed_with_no_pack_resolved(): void {
		Abilities::forgetRunPack();
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Unrelated Title',
				'content'   => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_unresolved', $result->get_error_code() );
	}

	// --- create-post slug collision (S4) -----------------------------------

	/**
	 * @dataProvider collisionStatuses
	 */
	public function test_create_post_refuses_a_slug_collision_across_every_status( string $status ): void {
		$this->seedPost( 100, 'page', $status, 'Existing', 'pricing' );
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Different title',
				'slug'      => 'pricing',
				'content'   => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, $status );
		$this->assertSame( 'slug_collision', $result->get_error_code(), $status );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null, $status );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'], $status );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function collisionStatuses(): array {
		return array(
			'publish' => array( 'publish' ),
			'draft'   => array( 'draft' ),
			'pending' => array( 'pending' ),
			'private' => array( 'private' ),
			'future'  => array( 'future' ),
		);
	}

	public function test_create_post_refuses_a_case_insensitive_title_match(): void {
		$this->seedPost( 100, 'page', 'draft', 'Pricing Page', 'unrelated-slug' );
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'PRICING PAGE',
				'slug'      => 'a-new-slug',
				'content'   => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'slug_collision', $result->get_error_code() );
	}

	public function test_create_post_ignores_a_trashed_holder(): void {
		$this->seedPost( 100, 'page', 'trash', 'Existing', 'pricing' );
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'A brand new title',
				'slug'      => 'pricing',
				'content'   => $this->validContent(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_create_post_does_not_collide_across_post_types(): void {
		$this->seedPost( 100, 'post', 'publish', 'Existing', 'pricing' );
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'A different title',
				'slug'      => 'pricing',
				'content'   => $this->validContent(),
			)
		);

		$this->assertIsArray( $result );
	}

	// --- update-post execute (Tier 1: draft-state edits only) --------------

	public function test_update_post_draft_to_draft_writes_the_cleaned_content(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'title'   => 'Renamed',
				'content' => $this->validContent(),
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertStringContainsString( '"name":"senroflux/hero"', $post->post_content );
	}

	public function test_update_post_refuses_a_publish_status_and_persists_nothing(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
				'status'  => 'publish',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code() );
		$this->assertSame( 'draft', $post->post_status );
	}

	public function test_update_post_refuses_a_future_status(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'     => 100,
				'status' => 'future',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code() );
		$this->assertSame( 'draft', $post->post_status );
	}

	/** The whole point of the split: a published post must go through publish-post. */
	public function test_update_post_refuses_an_already_public_target_and_must_use_publish_post(): void {
		$post = $this->seedPost( 100, 'page', 'publish' );
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'Sneaky edit',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 'Existing', $post->post_title, 'a refusal must persist nothing' );
	}

	public function test_update_post_refuses_without_per_post_edit_post(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertStringContainsString( 'original', $post->post_content );
	}

	/**
	 * @dataProvider refusedStatuses
	 */
	public function test_update_post_status_not_allowed( string $status ): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'     => 100,
				'status' => $status,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code(), $status );
		$this->assertSame( 'draft', $post->post_status, 'a refusal must persist nothing' );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function refusedStatuses(): array {
		return array(
			'private'  => array( 'private' ),
			'trash'    => array( 'trash' ),
			'inherit'  => array( 'inherit' ),
			'auto'     => array( 'auto-draft' ),
			'nonsense' => array( 'anything-else' ),
		);
	}

	public function test_update_post_pending_is_allowed(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'     => 100,
				'status' => 'pending',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'pending', $post->post_status );
	}

	public function test_update_post_refuses_an_unknown_id(): void {
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute( array( 'id' => 999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_update_post_refuses_a_post_type_off_the_allowlist(): void {
		$this->seedPost( 100, 'attachment' );
		$this->grant( 'edit_pages', 'edit_posts', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/update-post' )->execute( array( 'id' => 100 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	/** An empty content on a NON-publish update touches nothing and passes. */
	public function test_update_post_empty_content_on_a_draft_update_leaves_the_stored_markup(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'title'   => 'Renamed',
				'content' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertStringContainsString( 'original', $post->post_content );
	}

	public function test_update_post_refuses_invalid_content_and_persists_nothing(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'title'   => 'Renamed',
				'content' => '<!-- wp:image --><figure></figure><!-- /wp:image -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_block', $result->get_error_code() );
		$this->assertSame( 'Existing', $post->post_title, 'the whole write is refused, title included' );
		$this->assertStringContainsString( 'original', $post->post_content );
	}

	public function test_update_post_refuses_a_model_supplied_pack_argument(): void {
		$this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'   => 100,
				'pack' => 'posts',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_arg_refused', $result->get_error_code() );
	}

	// --- publish-post execute (Tier 2) --------------------------------------

	public function test_publish_post_publishes_when_the_publish_cap_is_held(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
				'status'  => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', $post->post_status );
	}

	public function test_publish_post_refuses_a_publish_transition_without_the_publish_cap(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
				'status'  => 'publish',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 'draft', $post->post_status, 'a refused publish must persist nothing' );
		$this->assertStringContainsString( 'original', $post->post_content );
	}

	/** publish-post refuses a call that is neither a transition nor a public-target edit: use update-post. */
	public function test_publish_post_refuses_a_draft_edit_with_no_transition(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'Should use update-post',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 'Existing', $post->post_title );
	}

	/** Editing an already-public post (no status change) is allowed — "any edit with public effect". */
	public function test_publish_post_edits_an_already_public_post_without_a_status_change(): void {
		$post               = $this->seedPost( 100, 'page', 'publish' );
		$post->post_content = $this->validContent();
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'Refreshed copy',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Refreshed copy', $post->post_title );
		$this->assertSame( 'publish', $post->post_status );
	}

	public function test_publish_post_status_not_allowed_refuses_private(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'     => 100,
				'status' => 'private',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'status_not_allowed', $result->get_error_code() );
		$this->assertSame( 'draft', $post->post_status );
	}

	/**
	 * Run 51: the model published with `{id, status:"publish", content:""}`
	 * and was refused "A page needs 2 to 8 patterns; 0 given". An empty
	 * `content` is "content unchanged": status changes, markup does not.
	 */
	public function test_publish_post_publishes_a_valid_draft_with_empty_content_and_leaves_the_markup(): void {
		$post               = $this->seedPost();
		$post->post_content = $this->validContent();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'      => 100,
				'status'  => 'publish',
				'content' => '',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( $this->validContent(), $post->post_content, 'empty content must not touch post_content' );
	}

	/** Omitted entirely is the same contract as an empty string. */
	public function test_publish_post_publishes_a_valid_draft_with_content_omitted(): void {
		$post               = $this->seedPost();
		$post->post_content = $this->validContent();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'     => 100,
				'title'  => 'Renamed',
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertSame( $this->validContent(), $post->post_content );
	}

	/**
	 * Fail closed: "content unchanged" is not a way past the validator. The
	 * seeded draft's stored markup is a bare paragraph (no patterns), so the
	 * publish is refused on the STORED content.
	 */
	public function test_publish_post_refuses_publishing_a_draft_whose_stored_content_is_invalid(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'      => 100,
				'status'  => 'publish',
				'content' => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_pattern', $result->get_error_code() );
		$this->assertSame( 'draft', $post->post_status, 'a refused publish must persist nothing' );
		$this->assertStringContainsString( 'original', $post->post_content );
	}

	/** A transition to `future` is validated the same way as `publish`. */
	public function test_publish_post_schedules_with_future_and_validates_stored_content(): void {
		$post               = $this->seedPost();
		$post->post_content = $this->validContent();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'     => 100,
				'status' => 'future',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( 'future', $post->post_status );
	}

	public function test_publish_post_refuses_a_model_supplied_pack_argument(): void {
		$this->seedPost();
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'     => 100,
				'status' => 'publish',
				'pack'   => 'posts',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_arg_refused', $result->get_error_code() );
	}

	// --- read-content execute ---------------------------------------------

	public function test_read_content_by_id_returns_the_post(): void {
		$this->seedPost();
		$this->grant( 'edit_pages', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute( array( 'id' => 100 ) );

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( 'page', $result['post_type'] );
		$this->assertStringContainsString( 'original', $result['content_raw'] );
	}

	public function test_read_content_by_id_refuses_without_read_post(): void {
		$this->seedPost();
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/read-content' )->execute( array( 'id' => 100 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_read_content_by_id_refuses_a_post_type_off_the_allowlist(): void {
		$this->seedPost( 100, 'attachment' );
		$this->grant( 'edit_pages', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute( array( 'id' => 100 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_read_content_by_id_refuses_a_post_type_mismatch(): void {
		$this->seedPost( 100, 'page' );
		$this->grant( 'edit_pages', 'edit_posts', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute(
			array(
				'id'        => 100,
				'post_type' => 'post',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_read_content_narrow_fields_emit_only_what_was_asked_for(): void {
		// The single-post output schema requires ONLY `id`; a narrow `fields`
		// list must not produce an output the ability then rejects.
		$this->seedPost();
		$this->grant( 'edit_pages', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute(
			array(
				'id'     => 100,
				'fields' => array( 'id' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertArrayNotHasKey( 'content_raw', $result );
		$this->assertArrayNotHasKey( 'author', $result );
	}

	public function test_read_content_emits_the_author_when_requested(): void {
		$this->seedPost();
		$user               = new \stdClass();
		$user->display_name = 'Ada';

		$GLOBALS['senroflux_test_users'][7] = $user;
		$this->grant( 'edit_pages', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute(
			array(
				'id'     => 100,
				'fields' => array( 'author' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array(
				'id'   => 7,
				'name' => 'Ada',
			),
			$result['author']
		);
	}

	public function test_read_content_query_mode_lists_matching_posts(): void {
		$this->seedPost( 100, 'page', 'publish', 'A' );
		$this->seedPost( 101, 'page', 'publish', 'B' );
		$this->seedPost( 102, 'page', 'draft', 'C' );
		$this->grant( 'edit_pages', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute(
			array(
				'post_type' => 'page',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
	}

	// --- list-patterns execute --------------------------------------------

	public function test_list_patterns_returns_seven_patterns(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertCount( 7, $result['patterns'] );
		$this->assertArrayHasKey( 'name', $result['patterns'][0] );
		$this->assertArrayHasKey( 'constraints', $result['patterns'][0] );
	}

	public function test_list_patterns_refuses_a_model_supplied_pack_argument(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute( array( 'pack' => 'posts' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_arg_refused', $result->get_error_code() );
	}

	public function test_list_patterns_fails_closed_with_no_pack_resolved(): void {
		Abilities::forgetRunPack();
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'pack_unresolved', $result->get_error_code() );
	}

	// --- annotation hints (SF-BUG-2 / 0.3 S4) -------------------------------

	/**
	 * The `destructive` annotation is safety-critical, not documentation:
	 * Agent Safety reads it as `true === ($annotations['destructive'] ?? null)`
	 * (plugin/src/Verdict/Hints.php) and
	 * `VerdictPipeline::elevateForDestructiveHint()` then treats the call as
	 * irreversible — which parked every Tier-1 draft creation for a human
	 * approval on live run 43. Creating a draft destroys nothing.
	 */
	public function test_create_post_carries_no_destructive_hint(): void {
		$annotations = $this->ability( 'senroflux/create-post' )->get_meta()['annotations'] ?? array();

		$this->assertIsArray( $annotations );
		$this->assertNotSame(
			true,
			$annotations['destructive'] ?? null,
			'create-post is draft-only; a destructive hint elevates it to Tier 2 in Agent Safety'
		);
	}

	/**
	 * 0.3 S4: update-post can no longer publish anything, so it must lose the
	 * destructive hint too — carrying it would elevate every Tier-1 draft edit
	 * to Agent Safety's irreversible classification, the same SF-BUG-2 bug
	 * `create-post` above already guards against.
	 */
	public function test_update_post_carries_no_destructive_hint(): void {
		$annotations = $this->ability( 'senroflux/update-post' )->get_meta()['annotations'] ?? array();

		$this->assertIsArray( $annotations );
		$this->assertNotSame( true, $annotations['destructive'] ?? null );
	}

	/** …and the hint moved to where it now IS true: publish-post. */
	public function test_publish_post_keeps_the_destructive_hint(): void {
		$annotations = $this->ability( 'senroflux/publish-post' )->get_meta()['annotations'] ?? array();

		$this->assertIsArray( $annotations );
		$this->assertTrue( $annotations['destructive'] ?? null );
	}
}
