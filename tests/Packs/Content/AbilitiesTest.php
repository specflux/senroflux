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
use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Packs\Pages\Layouts;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Pages\ThemePatterns;
use Specflux\SenroFlux\Packs\Pages\Validator;
use Specflux\SenroFlux\Packs\Pages\Vocabulary;
use Specflux\SenroFlux\Packs\Posts\Validator as PostsValidator;
use Specflux\SenroFlux\Packs\Posts\Vocabulary as PostsVocabulary;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Run\Tracker;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tests\Packs\Pages\LayoutsTest;
use WP_Error;
use wpdb;

final class AbilitiesTest extends TestCase {

	private WpdbRunStore $store;

	private int $runId;

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/theme-patterns.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
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
		// 0.3 S21: no theme patterns unless a test explicitly registers some.
		$GLOBALS['senroflux_test_theme_patterns'] = array();
		$GLOBALS['senroflux_test_stylesheet_dir'] = '/no-such-theme';
		$GLOBALS['senroflux_test_template_dir']   = '/no-such-theme';
		$GLOBALS['senroflux_test_postmeta']       = array();
		ThemePatterns::resetCache();

		Abilities::reset();
		Abilities::resetSources();
		Abilities::registerCategory();
		Abilities::register();

		$vocabulary = new Vocabulary();
		Abilities::registerSource( 'pages', new Validator( $vocabulary ), $vocabulary, 'edit_pages' );
		Abilities::useRunPack( 'pages' );

		// 0.3 S8: every content-ability call runs inside a run context now —
		// a real WpdbRunStore backed by the in-memory wpdb test double, exactly
		// as the composition root wires it around one tick. Tests that need a
		// write to succeed prime the tracker's read first via {@see primeRead()}.
		$this->store = new WpdbRunStore( new wpdb() );
		$this->runId = $this->store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Abilities::useRunContext( $this->runId, $this->store );
		// pageImageCheck()'s escape hatch reads Media::noImageSourceLeft(),
		// which resolves against Media's OWN run context — shares the same
		// store/run id so a test can seed one history both classes see.
		Media::useRunContext( $this->runId, $this->store );
	}

	protected function tearDown(): void {
		Abilities::forgetRunContext();
		Abilities::forgetRunPack();
		Abilities::resetSources();
		Media::forgetRunContext();
		remove_all_filters( 'senroflux_theme_patterns' );
		remove_all_filters( 'senroflux_layout_use_when' );
		unset( $GLOBALS['senroflux_test_options'][ ThemePatterns::OPTION ] );
	}

	/**
	 * S8 test helper: record that this run has already read `$id`, with its
	 * CURRENT `post_modified_gmt` (or an explicit `$marker` to simulate an
	 * external edit the run never saw).
	 */
	private function primeRead( int $id, ?string $marker = null ): void {
		$post   = get_post( $id );
		$marker = $marker ?? ( is_object( $post ) ? (string) ( $post->post_modified_gmt ?? '' ) : '' );

		$run     = $this->store->getRun( $this->runId );
		$objects = ( null !== $run && is_array( $run->objects ) ) ? $run->objects : array();
		$objects = Tracker::recordRead( $objects, $id, $marker );

		$this->store->updateRun( $this->runId, array( 'objects_json' => $objects ) );
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

		// Pattern index 0 is hero; index 1 is text-section. Deliberately no
		// image: most callers use this for concerns unrelated to the 0.3
		// "new page needs an image" rule (slug collisions, invalid markup,
		// permission refusals, `sections` composition — several of which
		// concatenate two calls' worth of patterns, where a hero-bearing
		// image block would double up and trip the one-hero rule instead).
		// {@see validContentWithImage()} is the variant that DOES carry one,
		// for the tests that expect a page creation to actually succeed.
		return $vocabulary->all()[0]['markup'] . "\n\n" . $vocabulary->all()[1]['markup'];
	}

	/**
	 * {@see validContent()} plus one `media-text` section (0.3 quality fix:
	 * images required on new pages; there is no valid way to add a BARE
	 * `core/image` — every image in this vocabulary lives inside a matched
	 * pattern shape) — for tests that expect `create-post` on a page to
	 * succeed under the new rule.
	 */
	private function validContentWithImage(): string {
		return $this->validContent() . "\n\n" . $this->mediaTextMarkup();
	}

	/** The vocabulary's own `media-text` sample markup, by slug (not position). */
	private function mediaTextMarkup(): string {
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			if ( 'senroflux/media-text' === $pattern['name'] ) {
				return (string) $pattern['markup'];
			}
		}

		self::fail( 'media-text pattern not found in the vocabulary' );
	}

	private function seedPost( int $id = 100, string $post_type = 'page', string $status = 'draft', string $title = 'Existing', string $slug = 'existing' ): \stdClass {
		$post                    = new \stdClass();
		$post->ID                = $id;
		$post->post_type         = $post_type;
		$post->post_title        = $title;
		$post->post_content      = '<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->';
		$post->post_status       = $status;
		$post->post_name         = $slug;
		$post->post_parent       = 0;
		$post->post_excerpt      = '';
		$post->post_author       = 7;
		$post->post_date         = '2026-01-01 00:00:00';
		$post->post_modified     = '2026-01-01 00:00:00';
		$post->post_modified_gmt = senroflux_test_next_modified_marker();

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
		$refused = $ability->check_permissions(
			array(
				'id'     => 100,
				'status' => 'publish',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'use_publish_post', $refused->get_error_code() );
	}

	/** …and refuses a target that is already public, even for a plain edit. */
	public function test_update_permission_refuses_an_already_public_target(): void {
		$this->seedPost( 100, 'page', 'publish' );
		$ability = $this->ability( 'senroflux/update-post' );

		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );
		$refused = $ability->check_permissions( array( 'id' => 100 ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'use_publish_post', $refused->get_error_code() );
		$this->assertStringContainsString( 'publish-post', $refused->get_error_message() );
	}

	/**
	 * Live run 2026-09-28: publish-post on a draft with no `status` was
	 * refused with an empty reason, six times in a row.
	 */
	public function test_publish_permission_on_a_draft_without_a_status_says_to_send_one(): void {
		$this->seedPost( 100, 'page', 'draft' );
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$refused = $this->ability( 'senroflux/publish-post' )->check_permissions( array( 'id' => 100 ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'publish_needs_status', $refused->get_error_code() );
		$this->assertStringContainsString( '"status": "publish"', $refused->get_error_message() );
	}

	public function test_a_routing_explanation_never_replaces_a_missing_capability(): void {
		$this->seedPost( 100, 'page', 'draft' );
		$this->grant( 'edit_pages' );

		$this->assertFalse( $this->ability( 'senroflux/publish-post' )->check_permissions( array( 'id' => 100 ) ) );
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
		$refused = $ability->check_permissions( array( 'id' => 100 ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'publish_needs_status', $refused->get_error_code() );
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
				'content'   => $this->validContentWithImage(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertCount( 1, $GLOBALS['senroflux_test_inserted_posts'] );
		$this->assertSame( 'draft', $GLOBALS['senroflux_test_inserted_posts'][0]['post_status'] );
	}

	/**
	 * One-H1 fix (0.3 quality feature 2): a page whose content carries an H1
	 * (the curated hero's own heading) gets the theme's `page-no-title`
	 * template when the active theme ships one, so `page.html` printing the
	 * post title never adds a SECOND H1.
	 */
	public function test_create_post_assigns_no_title_template_when_theme_has_one_and_content_has_an_h1(): void {
		$this->grant( 'edit_pages' );
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $this->validContentWithImage(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 'page-no-title', get_post_meta( $result['id'], '_wp_page_template', true ) );
	}

	/**
	 * 0.3 quality fix (cover-hero): its own H1 triggers the no-title template
	 * exactly like `hero`'s does — {@see HeroTemplate::shouldAssign()} matches
	 * on ANY `<h1` in the content, not the pattern name, so this is a
	 * regression check that the image-led hero was not left out.
	 */
	public function test_create_post_assigns_no_title_template_for_a_cover_hero_page(): void {
		$this->grant( 'edit_pages' );
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';

		$vocabulary = new Vocabulary();
		$cover_hero = null;
		foreach ( $vocabulary->all() as $pattern ) {
			if ( 'senroflux/cover-hero' === $pattern['name'] ) {
				$cover_hero = (string) $pattern['markup'];
			}
		}
		$this->assertNotNull( $cover_hero );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $cover_hero . "\n\n" . $this->mediaTextMarkup(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 'page-no-title', get_post_meta( $result['id'], '_wp_page_template', true ) );
	}

	/**
	 * Without the theme template, behaviour is unchanged: no template is
	 * ever assigned.
	 */
	public function test_create_post_leaves_template_unchanged_without_the_theme_template(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $this->validContentWithImage(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( '', get_post_meta( $result['id'], '_wp_page_template', true ) );
	}

	public function test_create_post_refuses_invalid_markup(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => '<!-- wp:video --><figure></figure><!-- /wp:video -->',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'unknown_block', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	// --- 0.3 quality fix: images required on new pages ---------------------

	/** An otherwise-valid new PAGE with no image anywhere is refused. */
	public function test_create_post_refuses_a_new_page_with_no_image(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_needs_image', $result->get_error_code() );
		$this->assertStringContainsString( 'cover-hero', $result->get_error_message() );
		$this->assertStringContainsString( 'media-text', $result->get_error_message() );
		$this->assertStringContainsString( 'media-search', $result->get_error_message() );
		$this->assertStringContainsString( 'no_image_reason', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'], 'a refusal must persist nothing' );
	}

	/**
	 * 0.3 quality fix 2: `no_image_reason` lets a genuinely image-less page
	 * (a legal page, a short utility page) through, and echoes the reason
	 * back so it is visible in the transcript.
	 */
	public function test_create_post_with_no_image_reason_is_accepted_and_echoed(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type'       => 'page',
				'title'           => 'Terms of Service',
				'content'         => $this->validContent(),
				'no_image_reason' => 'Legal page: terms of service, text only.',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'Legal page: terms of service, text only.', $result['no_image_reason'] ?? null );
	}

	public function test_create_post_refuses_no_image_reason_on_a_page_longer_than_two_sections(): void {
		// A live run skipped the image on a five-section homepage with
		// "this homepage is intentionally text-led".
		$this->grant( 'edit_pages' );
		$text_section = ( new Vocabulary() )->all()[1]['markup'];

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type'       => 'page',
				'title'           => 'Home',
				'content'         => $this->validContent() . "\n\n" . $text_section,
				'no_image_reason' => 'This homepage is intentionally text-led.',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_needs_image', $result->get_error_code() );
		$this->assertStringContainsString( 'media-generate', $result->get_error_message() );
	}

	// --- Stock-photo-fallback build plan: the pageImageCheck() escape hatch -

	private function exhaustImagesBudget(): void {
		$limit = Budget::defaults()[ Budget::IMAGES ];
		for ( $i = 0; $i < $limit; $i++ ) {
			$this->store->appendStep( $this->runId, StepKind::ToolResult, null, 'senroflux/generate-image', null, 'ok' );
		}
	}

	private function longPageWithReason( string $reason ): array|WP_Error {
		$text_section = ( new Vocabulary() )->all()[1]['markup'];

		return $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type'       => 'page',
				'title'           => 'Home',
				'content'         => $this->validContent() . "\n\n" . $text_section,
				'no_image_reason' => $reason,
			)
		);
	}

	/**
	 * The escape hatch's build-plan condition: `images` budget exhausted AND
	 * stock images disabled site-wide. A long page's `no_image_reason` is
	 * accepted at ANY length, not just the normal short-page ceiling.
	 */
	public function test_create_post_accepts_no_image_reason_on_a_long_page_when_budget_exhausted_and_stock_disabled(): void {
		$this->grant( 'edit_pages' );
		$this->exhaustImagesBudget();
		add_filter( 'senroflux_stock_images_enabled', static fn () => false );

		$result = $this->longPageWithReason( 'This run cannot generate or find any image.' );

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'This run cannot generate or find any image.', $result['no_image_reason'] ?? null );

		remove_all_filters( 'senroflux_stock_images_enabled' );
	}

	/**
	 * The escape hatch's other qualifying condition: `images` budget
	 * exhausted AND this run already tried `stock-image-search`, which came
	 * back with zero results.
	 */
	public function test_create_post_accepts_no_image_reason_on_a_long_page_when_stock_search_found_nothing(): void {
		$this->grant( 'edit_pages' );
		$this->exhaustImagesBudget();

		$message = array(
			'role'  => 'user',
			'parts' => array(
				array(
					'functionResponse' => array(
						'id'       => 'call-1',
						'name'     => 'senroflux/stock-image-search',
						'response' => array( 'results' => array() ),
					),
				),
			),
		);
		$this->store->appendStep( $this->runId, StepKind::ToolResult, $message, 'senroflux/stock-image-search', null, 'ok' );

		$result = $this->longPageWithReason( 'Nothing suitable was found anywhere.' );

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'Nothing suitable was found anywhere.', $result['no_image_reason'] ?? null );
	}

	/**
	 * Budget exhaustion ALONE is not enough: stock search must genuinely have
	 * been tried (or be disabled) first — otherwise the ordinary length-based
	 * refusal still stands, exactly as before this feature.
	 */
	public function test_create_post_still_refuses_a_long_page_when_budget_exhausted_but_stock_never_tried(): void {
		$this->grant( 'edit_pages' );
		$this->exhaustImagesBudget();

		$result = $this->longPageWithReason( 'This homepage is intentionally text-led.' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_needs_image', $result->get_error_code() );
	}

	/** A new POST with no image is unaffected: the rule is pages-only. */
	public function test_create_post_does_not_require_an_image_for_a_post(): void {
		$this->grant( 'edit_posts' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'A post',
				'content'   => $this->validContent(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	/** A text-section of two one-line paragraphs, as live runs wrote them. */
	private function thinTextSectionPage(): string {
		return (string) preg_replace(
			'#(senroflux/text-section.*?)<p>[^<]+</p>(.*?)<p>[^<]+</p>#s',
			'$1<p>Rehabilitation for sports injuries.</p>$2<p>Book online today.</p>',
			$this->validContentWithImage(),
			1
		);
	}

	public function test_create_post_refuses_a_page_with_a_thin_text_section(): void {
		$this->grant( 'edit_pages' );
		$content = $this->thinTextSectionPage();
		$this->assertStringContainsString( 'Rehabilitation for sports injuries.', $content );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'content'   => $content,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'section_too_thin', $result->get_error_code() );
		$this->assertStringContainsString( 'Section 1 (senroflux/text-section) has 7 words', $result->get_error_message() );
		// A live run sent 57 of 60 words four times over: say how many to add and what counts.
		$this->assertStringContainsString( 'add at least 53 more', $result->get_error_message() );
		$this->assertStringContainsString( 'headings do not count', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] ?? array() );
	}

	public function test_create_post_refuses_a_feature_grid_column_under_its_minimum(): void {
		$this->grant( 'edit_pages' );
		$grid = '';
		foreach ( ( new Vocabulary() )->all() as $pattern ) {
			if ( 'senroflux/feature-grid' === $pattern['name'] ) {
				$grid = (string) $pattern['markup'];
			}
		}
		$grid = (string) preg_replace( '#<p>[^<]+</p>#', '<p>Physiotherapy after surgery.</p>', $grid, 1 );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'content'   => $this->validContentWithImage() . "\n\n" . $grid,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'section_too_thin', $result->get_error_code() );
		$this->assertSame( 3, $result->get_error_data()['words'] ?? null );
	}

	public function test_update_post_with_thin_content_is_refused(): void {
		$this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->thinTextSectionPage(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'section_too_thin', $result->get_error_code() );
	}

	public function test_the_copy_minimum_filter_can_turn_the_rule_off(): void {
		$this->grant( 'edit_pages' );
		add_filter( 'senroflux_min_section_words', static fn () => array() );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'content'   => $this->thinTextSectionPage(),
			)
		);

		remove_all_filters( 'senroflux_min_section_words' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * `senroflux_require_page_image` (default true) turns the rule off
	 * outright, for a site with no media library worth using.
	 */
	public function test_create_post_filter_can_disable_the_image_requirement(): void {
		$this->grant( 'edit_pages' );
		add_filter( 'senroflux_require_page_image', static fn () => false );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Pricing',
				'content'   => $this->validContent(),
			)
		);

		remove_all_filters( 'senroflux_require_page_image' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
	}

	/**
	 * 0.3 quality fix 2 (live runs: an existing page turned into the front
	 * page via update-post, or otherwise given full content, ended up with
	 * no image at all — update-post was wholly exempt from create-post's
	 * image rule). An update that supplies content with no image is now
	 * refused the same way create-post is, unless `no_image_reason` is given.
	 */
	public function test_update_post_with_content_and_no_image_is_refused(): void {
		$this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'page_needs_image', $result->get_error_code() );
		$this->assertStringContainsString( 'no_image_reason', $result->get_error_message() );
	}

	/** `no_image_reason` is accepted, applies the write, and is echoed back. */
	public function test_update_post_with_content_and_no_image_reason_is_accepted_and_echoed(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'              => 100,
				'content'         => $this->validContent(),
				'no_image_reason' => 'Legal page: terms of service, text only.',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $this->validContent(), $post->post_content );
		$this->assertSame( 'Legal page: terms of service, text only.', $result['no_image_reason'] ?? null );
	}

	/** An update WITH an image needs no reason, same as create. */
	public function test_update_post_with_content_and_an_image_is_unaffected(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContentWithImage(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $this->validContentWithImage(), $post->post_content );
		$this->assertArrayNotHasKey( 'no_image_reason', $result );
	}

	/**
	 * Bug 2 (live run: a café photo repeated at the top of a post) — the
	 * mirrored refusal on the content side: a write that resends content
	 * carrying the SAME image already set as the post's featured image is
	 * refused, not just the `set-featured-image` call itself.
	 */
	public function test_update_post_refuses_content_that_repeats_the_posts_own_featured_image(): void {
		$posts_vocabulary = new PostsVocabulary();
		Abilities::registerSource( 'posts', new PostsValidator( $posts_vocabulary ), $posts_vocabulary, 'edit_posts' );
		Abilities::useRunPack( 'posts' );

		$this->seedPost( 100, 'post' );
		$this->primeRead( 100 );
		$this->grant( 'edit_posts', 'edit_post' );

		$attachment                         = new \stdClass();
		$attachment->ID                     = 5;
		$attachment->post_type              = 'attachment';
		$GLOBALS['senroflux_test_posts'][5] = $attachment;
		$GLOBALS['senroflux_test_postmeta'][100]['_thumbnail_id'] = 5;

		$content = '<!-- wp:paragraph --><p>Hello there.</p><!-- /wp:paragraph -->'
			. '<!-- wp:image {"alt":"A cafe photo"} --><figure class="wp-block-image"><img src="https://example.test/wp-content/uploads/5.jpg" alt="A cafe photo"/></figure><!-- /wp:image -->';

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $content,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'featured_image_duplicated', $result->get_error_code() );
	}

	/** A metadata-only update (no content sent at all) is never affected. */
	public function test_update_post_metadata_only_does_not_require_an_image(): void {
		$this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'A new title only',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/** A scheduled post needs a way to carry its site-local publication date. */
	public function test_update_post_writes_a_site_local_date(): void {
		$this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'   => 100,
				'date' => '2026-10-12 09:00:00',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '2026-10-12 09:00:00', $GLOBALS['senroflux_test_posts'][100]->post_date );
	}

	/** @return array<string,mixed>|WP_Error */
	private function publishAt( array $input ): array|WP_Error {
		$post               = $this->seedPost( 100, 'page', 'draft' );
		$post->post_content = $this->validContent();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );
		// Site is UTC+08:00; "now" is 2026-10-03 07:00 local.
		$GLOBALS['senroflux_test_timezone'] = 'Asia/Singapore';
		Clock::useFixed( gmmktime( 23, 0, 0, 10, 2, 2026 ) );

		$result = $this->ability( 'senroflux/publish-post' )->execute( array( 'id' => 100 ) + $input );
		unset( $GLOBALS['senroflux_test_timezone'] );
		Clock::reset();

		return $result;
	}

	public function test_future_status_without_a_date_is_refused_before_any_write(): void {
		$result = $this->publishAt( array( 'status' => 'future' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'schedule_needs_future_date', $result->get_error_code() );
		$this->assertStringContainsString( 'Y-m-d H:i:s', $result->get_error_message() );
		$this->assertSame( 'draft', $GLOBALS['senroflux_test_posts'][100]->post_status );
	}

	public function test_future_status_with_a_past_date_is_refused(): void {
		$result = $this->publishAt(
			array(
				'status' => 'future',
				'date'   => '2026-10-03 06:00:00',
			)
		);

		$this->assertSame( 'schedule_needs_future_date', $result->get_error_code() );
	}

	public function test_future_status_with_exactly_now_in_site_time_is_refused(): void {
		$result = $this->publishAt(
			array(
				'status' => 'future',
				'date'   => '2026-10-03 07:00:00',
			)
		);

		$this->assertSame( 'schedule_needs_future_date', $result->get_error_code() );
	}

	public function test_future_status_one_second_ahead_in_site_time_is_accepted(): void {
		$result = $this->publishAt(
			array(
				'status' => 'future',
				'date'   => '2026-10-03 07:00:01',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'future', $result['status'] );
		$this->assertSame( '2026-10-03 07:00:01', $GLOBALS['senroflux_test_posts'][100]->post_date );
	}

	public function test_publish_status_with_a_future_date_is_refused_as_ambiguous(): void {
		$result = $this->publishAt(
			array(
				'status' => 'publish',
				'date'   => '2026-10-12 09:00:00',
			)
		);

		$this->assertSame( 'use_future_status', $result->get_error_code() );
	}

	public function test_update_post_refuses_a_malformed_date(): void {
		$this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'   => 100,
				'date' => 'next Monday 9am',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_date', $result->get_error_code() );
	}

	/** `senroflux_require_page_image` off disables the update-post rule too. */
	public function test_update_post_filter_can_disable_the_image_requirement(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );
		add_filter( 'senroflux_require_page_image', static fn () => false );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
			)
		);

		remove_all_filters( 'senroflux_require_page_image' );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $this->validContent(), $post->post_content );
	}

	/** A POST update with content and no image is unaffected — pages only. */
	public function test_update_post_does_not_require_an_image_for_a_post(): void {
		$this->seedPost( 100, 'post' );
		$this->primeRead( 100 );
		$this->grant( 'edit_posts', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContent(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public function test_create_post_refuses_a_script_payload_and_persists_nothing(): void {
		$this->grant( 'edit_pages' );

		$content = str_replace(
			'<p>Open with the point a visitor came for: what this is, who it suits and what they get from it. Use the business\'s own facts, such as its services, place, hours and people, and name them exactly.</p>',
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
				'content'   => $this->validContentWithImage(),
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
				'content'   => $this->validContentWithImage(),
			)
		);

		$this->assertIsArray( $result );
	}

	// --- update-post execute (Tier 1: draft-state edits only) --------------

	public function test_update_post_draft_to_draft_writes_the_cleaned_content(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'title'   => 'Renamed',
				'content' => $this->validContentWithImage(),
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 100, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'Renamed', $post->post_title );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertStringContainsString( '"name":"senroflux/hero"', $post->post_content );
	}

	/**
	 * One-H1 fix (0.3 quality feature 2), update path: assigning the
	 * theme's `page-no-title` template on `update-post` too, not only on
	 * create — a page whose hero was added or changed later must get the
	 * same fix.
	 */
	public function test_update_post_assigns_no_title_template_when_theme_has_one_and_content_has_an_h1(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );
		$GLOBALS['senroflux_test_stylesheet_dir'] = dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';
		$GLOBALS['senroflux_test_template_dir']   = dirname( __DIR__, 2 ) . '/fixtures/theme-with-no-title-template';

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContentWithImage(),
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'page-no-title', get_post_meta( (int) $post->ID, '_wp_page_template', true ) );
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
		$this->primeRead( 100 );
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
		$this->primeRead( 100 );
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
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'title'   => 'Renamed',
				'content' => '<!-- wp:video --><figure></figure><!-- /wp:video -->',
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
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'      => 100,
				'content' => $this->validContentWithImage(),
				'status'  => 'publish',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
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
		$this->primeRead( 100 );
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
		$this->primeRead( 100 );
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
		$this->primeRead( 100 );
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
		$this->primeRead( 100 );
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
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post', 'publish_pages' );

		$result = $this->ability( 'senroflux/publish-post' )->execute(
			array(
				'id'     => 100,
				'status' => 'future',
				'date'   => '2999-01-01 09:00:00',
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

	// --- stale write (0.3 S8) -----------------------------------------------

	public function test_update_post_refuses_a_write_with_no_prior_read(): void {
		$post = $this->seedPost();
		$this->grant( 'edit_pages', 'edit_post' );
		// Deliberately no primeRead(): this run never read the object.

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'      => 100,
				'title'   => 'Sneaky edit',
				'content' => $this->validContent(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
		$this->assertSame( 'Existing', $post->post_title, 'a stale-write refusal must persist nothing' );
	}

	public function test_update_post_refuses_a_write_after_an_external_edit(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		// An edit the run never saw — e.g. a human editing the same page in
		// the block editor between this run's read and its write.
		$post->post_modified_gmt = 'external-edit';
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'Sneaky edit',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'stale_write', $result->get_error_code() );
		$this->assertSame( 'Existing', $post->post_title );
	}

	public function test_update_post_succeeds_after_a_read(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'Renamed after read',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Renamed after read', $post->post_title );
	}

	/** create-post's new object counts as read at creation — no separate read needed. */
	public function test_create_then_update_without_a_read_succeeds(): void {
		$this->grant( 'edit_pages', 'edit_post' );

		$created = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Fresh draft',
				'content'   => $this->validContentWithImage(),
			)
		);
		$this->assertIsArray( $created );
		$id = $created['id'];

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => $id,
				'title' => 'Fresh draft, edited',
			)
		);

		$this->assertIsArray( $result, 'a create-post object is read-at-creation, so an immediate update must not be stale' );
		$this->assertSame( 'Fresh draft, edited', get_post( $id )->post_title );
	}

	/** A run may keep editing its own write without re-reading in between. */
	public function test_two_consecutive_writes_by_the_run_both_succeed(): void {
		$post = $this->seedPost();
		$this->primeRead( 100 );
		$this->grant( 'edit_pages', 'edit_post' );

		$first = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'First write',
			)
		);
		$this->assertIsArray( $first, 'the first write must succeed' );

		$second = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'    => 100,
				'title' => 'Second write',
			)
		);

		$this->assertIsArray( $second, 'a second write by the SAME run must not be stale, even without an intervening read' );
		$this->assertSame( 'Second write', $post->post_title );
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

	public function test_read_content_accepts_the_plain_title_content_and_excerpt_field_names(): void {
		// A live run asked for `title`/`content`/`excerpt`, got neither back,
		// and rewrote the homepage without ever seeing it.
		$this->seedPost();
		$this->grant( 'edit_pages', 'read_post' );

		$result = $this->ability( 'senroflux/read-content' )->execute(
			array(
				'id'     => 100,
				'fields' => array( 'id', 'title', 'content', 'excerpt' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'title_raw', $result );
		$this->assertArrayHasKey( 'content_raw', $result );
		$this->assertArrayHasKey( 'excerpt_raw', $result );
	}

	public function test_read_content_lists_its_field_names_in_the_input_schema(): void {
		$schema = $this->ability( 'senroflux/read-content' )->get_input_schema();
		$items  = $schema['oneOf'][0]['properties']['fields']['items'] ?? array();

		$this->assertContains( 'content_raw', $items['enum'] ?? array() );
		$this->assertContains( 'content', $items['enum'] ?? array() );
	}

	/**
	 * Live run ("make my site better"): the model rewrote Home by editing the
	 * markup read-content returned, so the page lost the theme design.
	 */
	public function test_update_and_publish_steer_a_page_rewrite_to_sections(): void {
		foreach ( array( 'senroflux/update-post', 'senroflux/publish-post' ) as $name ) {
			$schema = $this->ability( $name )->get_input_schema();

			$this->assertStringContainsString( '`sections`', $schema['properties']['content']['description'] ?? '', $name );
		}
	}

	/**
	 * The live personas are a physio clinic and a café: examples in
	 * model-facing text must not lean toward either.
	 */
	public function test_create_post_description_names_no_specific_industry(): void {
		$description = $this->ability( 'senroflux/create-post' )->get_description();

		foreach ( array( 'clinic', 'physio', 'massage', 'café', 'cafe' ) as $word ) {
			$this->assertStringNotContainsStringIgnoringCase( $word, $description );
		}
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
		$this->assertCount( 9, $result['patterns'] );
		$this->assertArrayHasKey( 'name', $result['patterns'][0] );
	}

	/**
	 * No `names` input returns a compact INDEX — no
	 * markup, no constraints, no slots — so the payload no longer costs the
	 * conversation ~11k tokens on every call it did not ask a full entry for.
	 */
	public function test_list_patterns_with_no_names_returns_a_compact_index(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );

		$this->assertCount( 9, $result['patterns'] );
		foreach ( $result['patterns'] as $pattern ) {
			$this->assertArrayHasKey( 'name', $pattern );
			$this->assertArrayHasKey( 'title', $pattern );
			$this->assertArrayHasKey( 'description', $pattern );
			$this->assertArrayNotHasKey( 'constraints', $pattern );
			$this->assertArrayNotHasKey( 'markup', $pattern );
			$this->assertArrayNotHasKey( 'slots', $pattern );
		}
	}

	/**
	 * 0.3 S7 gap fix: prose shape lines lost detail a live run needed
	 * (`style.spacing.padding`) and was refused for omitting; the sample
	 * markup is the exact input `BlockShells` matches against, so it is the
	 * one thing a model can copy and always pass editor parity. Now
	 * only returned for the names the model actually asks for, in the
	 * vocabulary's own order (not the order requested).
	 */
	public function test_list_patterns_with_names_includes_full_entries_in_vocabulary_order(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute(
			array( 'names' => array( 'senroflux/cta', 'senroflux/hero' ) )
		);

		$this->assertCount( 2, $result['patterns'] );
		$this->assertSame( 'senroflux/hero', $result['patterns'][0]['name'], 'vocabulary order, not requested order' );
		$this->assertSame( 'senroflux/cta', $result['patterns'][1]['name'] );
		$this->assertArrayNotHasKey( 'not_found', $result );

		foreach ( $result['patterns'] as $pattern ) {
			$this->assertArrayHasKey( 'constraints', $pattern );
			$this->assertArrayHasKey( 'markup', $pattern );
			$this->assertStringContainsString( '<!-- wp:', $pattern['markup'] );
		}
	}

	/** An unrecognised name never fails the whole call — it comes back in `not_found`. */
	public function test_list_patterns_unknown_names_are_reported_not_found(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute(
			array( 'names' => array( 'senroflux/hero', 'senroflux/no-such-pattern' ) )
		);

		$this->assertCount( 1, $result['patterns'] );
		$this->assertSame( 'senroflux/hero', $result['patterns'][0]['name'] );
		$this->assertSame( array( 'senroflux/no-such-pattern' ), $result['not_found'] );
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

	// --- 0.3 S21: sections + theme patterns ---------------------------------

	/**
	 * No shipped pack offers numbered-slot theme patterns any more (both
	 * build them through layouts); the route stays for a vocabulary that
	 * opts in.
	 */
	private function useThemeSlotPack(): Vocabulary {
		$vocabulary = new class() extends Vocabulary {
			public function offersThemeSlots(): bool {
				return true;
			}
		};
		Abilities::registerSource( 'pages', new Validator( $vocabulary ), $vocabulary, 'edit_pages' );
		Abilities::useRunPack( 'pages' );
		$this->grant( 'edit_pages' );

		return $vocabulary;
	}

	/**
	 * Render a real fixture under `tests/ThemePatterns/` and register it as
	 * the only theme-owned pattern the stub `WP_Block_Patterns_Registry`
	 * answers, exactly as {@see \Specflux\SenroFlux\Tests\Packs\Pages\ThemePatternsTest}
	 * does.
	 */
	private function registerThemeFixture( string $slug ): void {
		$dir  = dirname( __DIR__, 2 ) . '/ThemePatterns';
		$path = $dir . '/' . $slug . '.php';
		$raw  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.

		$header = array();
		foreach ( array( 'Title', 'Slug', 'Description', 'Categories', 'Inserter' ) as $key ) {
			if ( preg_match( '/^\s*\*\s*' . preg_quote( $key, '/' ) . ':\s*(.+)$/mi', $raw, $m ) ) {
				$header[ $key ] = trim( $m[1] );
			}
		}

		ob_start();
		include $path;
		$content = trim( (string) ob_get_clean() );

		$entry = array(
			'name'        => $header['Slug'] ?? $slug,
			'title'       => $header['Title'] ?? $slug,
			'description' => $header['Description'] ?? '',
			'content'     => $content,
			'filePath'    => $path,
			'categories'  => isset( $header['Categories'] ) ? array_map( 'trim', explode( ',', $header['Categories'] ) ) : array(),
		);
		if ( isset( $header['Inserter'] ) ) {
			$entry['inserter'] = ! in_array( strtolower( $header['Inserter'] ), array( 'no', 'false' ), true );
		}

		$GLOBALS['senroflux_test_theme_patterns'] = array( $entry );
		$GLOBALS['senroflux_test_stylesheet_dir'] = $dir;
		ThemePatterns::resetCache();
	}

	public function test_list_patterns_reports_theme_patterns_skipped_count(): void {
		// `hidden-blog-heading` is real but `Inserter: no` — it is this
		// theme's own pattern, just not an ELIGIBLE one.
		$this->registerThemeFixture( 'hidden-blog-heading' );
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );

		$this->assertSame( 1, $result['theme_patterns_skipped'] );
		$this->assertCount( 9, $result['patterns'], 'the ineligible theme pattern never joins the list' );
	}

	public function test_list_patterns_counts_what_the_switch_and_the_filter_removed_as_skipped(): void {
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );
		$skipped  = ThemePatterns::skippedCount();
		$eligible = count( ThemePatterns::eligible() );
		$this->assertGreaterThan( 3, $eligible );

		add_filter( 'senroflux_theme_patterns', static fn ( array $patterns ): array => array_slice( $patterns, 3 ) );
		ThemePatterns::resetCache();
		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );
		$this->assertSame( $skipped + 3, $result['theme_patterns_skipped'], 'the filter removed 3' );

		remove_all_filters( 'senroflux_theme_patterns' );
		update_option( ThemePatterns::OPTION, false );
		ThemePatterns::resetCache();
		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );
		$this->assertSame( $skipped + $eligible, $result['theme_patterns_skipped'], 'the switch removed every eligible pattern' );
	}

	/**
	 * D6: the one `use_when` sentence per layout is rendered into the
	 * `sections` schema and the pages skill from the same source, so changing
	 * the source changes both.
	 */
	public function test_use_when_reaches_the_sections_schema_and_the_pages_skill_from_one_source(): void {
		$layouts = Layouts::names();
		$source  = Layouts::useWhen();
		$this->assertEqualsCanonicalizing( $layouts, array_keys( $source ), 'every layout has exactly one sentence' );

		$read = function (): array {
			Abilities::reset();
			Abilities::registerCategory();
			Abilities::register();
			$schema = $this->ability( 'senroflux/create-post' )->get_input_schema();
			$page   = null;
			foreach ( $schema['oneOf'] as $branch ) {
				if ( array( 'page' ) === ( $branch['properties']['post_type']['enum'] ?? array() ) ) {
					$page = $branch;
				}
			}
			$this->assertNotNull( $page );

			return array(
				$page['properties']['sections']['items']['properties']['layout']['description'],
				implode( "\n", array_map( static fn ( $skill ) => $skill->body, ( new PagesPack() )->skills() ) ),
			);
		};

		list( $description, $skill ) = $read();
		foreach ( $source as $layout => $sentence ) {
			$this->assertStringContainsString( $sentence, $description, $layout );
			$this->assertStringContainsString( $sentence, $skill, $layout );
		}

		add_filter(
			'senroflux_layout_use_when',
			static fn ( array $when ): array => array_replace( $when, array( 'faq' => 'a changed sentence for the test' ) )
		);
		list( $description, $skill ) = $read();
		remove_all_filters( 'senroflux_layout_use_when' );

		$this->assertStringContainsString( 'a changed sentence for the test', $description );
		$this->assertStringContainsString( 'a changed sentence for the test', $skill );
		$this->assertStringNotContainsString( $source['faq'], $description );
		$this->assertStringNotContainsString( $source['faq'], $skill );
	}

	public function test_sections_builds_a_page_from_layouts(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => LayoutsTest::outline(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$stored = $GLOBALS['senroflux_test_posts'][ $result['id'] ]->post_content;
		$this->assertStringContainsString( 'senroflux/twentytwentyfive/hero-full-width-image', $stored );
		$this->assertStringContainsString( 'senroflux/twentytwentyfive/services-3-col', $stored );
		$this->assertStringContainsString( 'What happens at your first visit', $stored );
	}

	/**
	 * 0.3 quality fix (images budget 0): an image-less services section
	 * (no card gives a photo) must be accepted by the REAL entry point the
	 * abilities use — `create-post` on a page, which runs the section
	 * through {@see \Specflux\SenroFlux\Packs\Pages\Layouts::render()} and
	 * then the pages {@see \Specflux\SenroFlux\Packs\Pages\Validator} — not
	 * just `Layouts::render()` in isolation.
	 */
	public function test_sections_builds_a_page_with_an_image_less_services_section(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$outline = LayoutsTest::outline();
		foreach ( $outline[2]['items'] as &$item ) {
			unset( $item['image'] );
		}
		unset( $item );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => $outline,
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$stored = $GLOBALS['senroflux_test_posts'][ $result['id'] ]->post_content;
		$this->assertStringContainsString( 'senroflux/twentytwentyfive/services-3-col', $stored );

		// The hero and text-with-image sections legitimately keep their own
		// images; only the services section's own markup must be image-less.
		$services = null;
		foreach ( parse_blocks( $stored ) as $block ) {
			if ( 'senroflux/twentytwentyfive/services-3-col' === ( $block['attrs']['metadata']['name'] ?? null ) ) {
				$services = $block;
			}
		}
		$this->assertIsArray( $services, 'the services section was not found in the stored content' );
		$this->assertStringNotContainsString( '<!-- wp:image', serialize_block( $services ) );
	}

	/**
	 * Bug 1 (live run: the same hero image on every page). A second page
	 * built with the SAME hero image as an already-created page is refused,
	 * naming the other page.
	 */
	/**
	 * Live batches 2026-09-28-fix5 / 2026-09-29-final: Services pages built
	 * as a hero followed by five `text` layouts in a row read as a wall of
	 * text (Visual 2-3).
	 */
	public function test_three_text_layouts_in_a_row_are_refused(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$outline = LayoutsTest::outline();
		$text    = $outline[1];
		array_splice( $outline, 2, 2, array( $text, $text ) );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => $outline,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'layout_wall_of_text', $result->get_error_code() );
		$this->assertStringContainsString( 'Sections 2 to 4', $result->get_error_message() );
	}

	/**
	 * D3b page rule through create-post: the model's own tones may not sit side
	 * by side or exceed two. The image-less hero's `contrast` default is
	 * SenroFlux's choice, so it steps aside rather than cause a refusal.
	 */
	public function test_create_post_refuses_adjacent_and_excess_section_tones(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		$GLOBALS['senroflux_test_theme_patterns']  = array();
		$GLOBALS['senroflux_test_global_settings'] = array(
			'color' => array(
				'palette' => array(
					array(
						'slug'  => 'base',
						'color' => '#FFFFFF',
					),
					array(
						'slug'  => 'contrast',
						'color' => '#111111',
					),
					array(
						'slug'  => 'accent-3',
						'color' => '#503AA8',
					),
				),
			),
		);
		ThemePatterns::resetCache();
		$this->grant( 'edit_pages' );

		$outline = LayoutsTest::outline();
		$hero    = $outline[0];
		unset( $hero['image'] );
		$faq  = $outline[4];
		$page = 0;
		$call = function ( array $sections ) use ( &$page ) {
			return $this->ability( 'senroflux/create-post' )->execute(
				array(
					'post_type'       => 'page',
					'title'           => 'Home ' . ( ++$page ),
					'sections'        => $sections,
					'no_image_reason' => 'A text-only page for the test.',
				)
			);
		};

		$adjacent = $call( array( $outline[1], array( 'tone' => 'contrast' ) + $outline[1], array( 'tone' => 'contrast' ) + $faq ) );
		$this->assertInstanceOf( WP_Error::class, $adjacent );
		$this->assertSame( 'layout_tone_adjacent', $adjacent->get_error_code() );
		$this->assertStringContainsString( 'Sections 2 and 3', $adjacent->get_error_message() );

		$too_many = $call( array( array( 'tone' => 'accent' ) + $outline[1], $outline[1], array( 'tone' => 'accent' ) + $faq, $outline[1], array( 'tone' => 'contrast' ) + $outline[5] ) );
		$this->assertInstanceOf( WP_Error::class, $too_many );
		$this->assertSame( 'layout_tone_count', $too_many->get_error_code() );

		$beside = $call( array( $hero, array( 'tone' => 'contrast' ) + $outline[1] ) );
		$this->assertIsArray( $beside, $beside instanceof WP_Error ? $beside->get_error_message() : '' );
		$this->assertSame( 1, substr_count( $GLOBALS['senroflux_test_posts'][ $beside['id'] ]->post_content, '"backgroundColor":"contrast"' ) );

		$fine = $call( array( $hero, array( 'tone' => 'accent' ) + $faq ) );
		$this->assertIsArray( $fine, $fine instanceof WP_Error ? $fine->get_error_message() : '' );
		$stored = $GLOBALS['senroflux_test_posts'][ $fine['id'] ]->post_content;
		$this->assertStringContainsString( '"backgroundColor":"contrast"', $stored );
		$this->assertStringContainsString( '"backgroundColor":"accent-3"', $stored );

		unset( $GLOBALS['senroflux_test_global_settings'] );
		ThemePatterns::resetCache();
	}

	public function test_hero_image_reuse_across_pages_is_refused_naming_the_other_page(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$home = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Home',
				'sections'  => LayoutsTest::outline(),
			)
		);
		$this->assertIsArray( $home, is_wp_error( $home ) ? $home->get_error_message() : '' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => LayoutsTest::outline(),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hero_image_reused', $result->get_error_code() );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'already the hero on "Home"', $message );
		$this->assertStringContainsString( '(page ' . $home['id'] . ')', $message );
		$this->assertStringContainsString( 'media-search', $message );
		$this->assertCount( 1, $GLOBALS['senroflux_test_inserted_posts'], 'the refused Services write persisted nothing' );
	}

	/** Editing the page that OWNS the hero image is unaffected — it is not reusing anyone else's. */
	public function test_hero_image_reuse_is_allowed_when_editing_the_page_that_owns_it(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$home = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Home',
				'sections'  => LayoutsTest::outline(),
			)
		);
		$this->assertIsArray( $home, is_wp_error( $home ) ? $home->get_error_message() : '' );
		$this->primeRead( (int) $home['id'] );
		$this->grant( 'edit_pages', 'edit_post' );

		$result = $this->ability( 'senroflux/update-post' )->execute(
			array(
				'id'       => $home['id'],
				'sections' => LayoutsTest::outline(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Any image already on another page is refused, not only the hero: live
	 * batch 2026-09-29-final2 shipped About and a second Services page whose
	 * photos all repeated Home's (Visual 3).
	 */
	public function test_reusing_a_non_hero_image_across_pages_is_refused_naming_the_other_page(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$home = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Home',
				'sections'  => LayoutsTest::outline(),
			)
		);
		$this->assertIsArray( $home, is_wp_error( $home ) ? $home->get_error_message() : '' );

		// Same `text-with-image` (index 3) image as Home, but a DIFFERENT hero.
		$sections             = LayoutsTest::outline();
		$sections[0]['image'] = array(
			'url' => 'http://localhost:8897/wp-content/uploads/2026/09/services-hero.webp',
			'alt' => 'A physiotherapist demonstrating a stretch',
		);

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => $sections,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'image_reused_across_pages', $result->get_error_code() );
		$this->assertStringContainsString( 'already on "Home" (page ' . $home['id'] . ')', $result->get_error_message() );
	}

	/**
	 * 0.3 quality fix (hero readability): the real Twenty Twenty-Five
	 * `hero-full-width-image` fixture ({@see LayoutsTest::FIXTURES}) ships
	 * `dimRatio":10` ({@see tests/ThemePatterns/hero-full-width-image.php}) —
	 * too light an overlay for the white hero text on top of it. The `hero`
	 * layout fills it via {@see \Specflux\SenroFlux\Packs\Pages\Layouts::finish()}
	 * (which only ever swaps the image url/alt, never the overlay), so the
	 * enforcement has to happen later, in the pack's own
	 * {@see \Specflux\SenroFlux\Packs\Pages\Validator::clean()} the write
	 * still passes through on the way to `wp_insert_post()` — proving the
	 * theme-pattern path gets the same enforcement as the plugin's own
	 * `cover-hero` pattern, not a special case wired into `Layouts`.
	 */
	public function test_a_theme_pattern_hero_with_a_low_dim_ratio_is_raised_on_create(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => LayoutsTest::outline(),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$stored = $GLOBALS['senroflux_test_posts'][ $result['id'] ]->post_content;
		$this->assertStringContainsString( 'senroflux/twentytwentyfive/hero-full-width-image', $stored );
		$this->assertStringNotContainsString( '"dimRatio":10', $stored );
		$this->assertStringNotContainsString( 'has-background-dim-10', $stored );
		$this->assertStringContainsString( '"dimRatio":50', $stored );
	}

	public function test_an_image_reused_across_layout_sections_is_refused(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );
		$sections                         = LayoutsTest::outline();
		$sections[2]['items'][1]['image'] = $sections[0]['image'];

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => $sections,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'layout_image_reused', $result->get_error_code() );
		$this->assertStringStartsWith( 'Section 3 reuses an image already used in section 1.', $result->get_error_message() );
	}

	public function test_a_refused_layout_field_reaches_the_model_by_name(): void {
		require_once dirname( __DIR__, 2 ) . '/Packs/Pages/LayoutsTest.php';
		LayoutsTest::registerThemeFixtures();
		$this->grant( 'edit_pages' );
		$sections                     = LayoutsTest::outline();
		$sections[0]['button']['url'] = '';

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Services',
				'sections'  => $sections,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'layout_field', $result->get_error_code() );
		$this->assertStringContainsString( 'Section 1 (hero): `button.url`', $result->get_error_message() );
	}

	public function test_sections_composes_content_from_markup_items(): void {
		$this->grant( 'edit_pages' );
		$vocabulary = new Vocabulary();
		$hero       = $vocabulary->resolveThemePattern( 'no-such-pattern' ); // null: sanity only.
		$this->assertNull( $hero );

		$hero_markup  = $vocabulary->all()[0]['markup'];
		$text_markup  = $vocabulary->all()[1]['markup'];
		$image_markup = $this->mediaTextMarkup();

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Sectioned page',
				'sections'  => array(
					array( 'markup' => $hero_markup ),
					array( 'markup' => $text_markup ),
					array( 'markup' => $image_markup ),
				),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 'draft', $result['status'] );
		$stored = $GLOBALS['senroflux_test_posts'][ $result['id'] ];
		$this->assertStringContainsString( 'senroflux/hero', $stored->post_content );
		$this->assertStringContainsString( 'senroflux/text-section', $stored->post_content );
	}

	/**
	 * 0.3 quality fix (content+sections refusal, 9/11 live runs): the FIRST
	 * write of a run often sends a non-empty `content` summary ALONGSIDE a
	 * real `sections` array. Refusing the whole call taught the model
	 * nothing about which to keep and cost a wasted round trip on every
	 * scenario. The write now succeeds using `sections` — the one form the
	 * pack itself renders and validates — and reports `content_ignored` so
	 * the model can see plainly that its `content` was dropped.
	 */
	public function test_sections_and_content_together_uses_sections_and_reports_it(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'content'   => 'My plan for this page.',
				'sections'  => array( array( 'markup' => $this->validContentWithImage() ) ),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertTrue( $result['content_ignored'] );
		$stored = $GLOBALS['senroflux_test_posts'][ $result['id'] ];
		$this->assertStringNotContainsString( 'My plan for this page.', $stored->post_content );
	}

	/**
	 * 0.3 quality fix (content+sections refusal, posts side): the schema
	 * itself — not just the execute-time refusal — must never offer
	 * `sections` for `post_type: "post"`, so a model reading the tool
	 * definition never has a reason to send it in the first place.
	 */
	public function test_create_post_schema_never_offers_sections_for_post_type_post(): void {
		$schema = $this->ability( 'senroflux/create-post' )->get_input_schema();

		$this->assertArrayHasKey( 'oneOf', $schema );

		$post_branch = null;
		$page_branch = null;
		foreach ( $schema['oneOf'] as $branch ) {
			$enum = $branch['properties']['post_type']['enum'] ?? array();
			if ( array( 'post' ) === $enum ) {
				$post_branch = $branch;
			} elseif ( array( 'page' ) === $enum ) {
				$page_branch = $branch;
			}
		}

		$this->assertNotNull( $post_branch, 'a post_type:post branch must exist' );
		$this->assertNotNull( $page_branch, 'a post_type:page branch must exist' );
		$this->assertArrayNotHasKey( 'sections', $post_branch['properties'], 'the posts pack must never be offered sections' );
		$this->assertArrayHasKey( 'sections', $page_branch['properties'] );
	}

	public function test_sections_unknown_theme_pattern_is_refused(): void {
		$this->useThemeSlotPack();

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'sections'  => array(
					array(
						'pattern' => 'twentytwentyfive/no-such-pattern',
						'slots'   => array( 'Some text' ),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'theme_pattern_unknown', $result->get_error_code() );
		$this->assertStringContainsString(
			'twentytwentyfive/no-such-pattern',
			$result->get_error_message(),
			'a genuinely unknown name must be named back, not just called "not available"'
		);
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-1-1: the model put a stray
	 * `{label, url}` object (no `layout`) into `sections`. Core's own JSON
	 * schema validator used to refuse this before {@see Abilities::renderSections()}
	 * ever ran — "label is not a valid property of Object" — which never
	 * says what a `label`/`url` pair even means in a `sections` item. The
	 * section item's `additionalProperties` is now `true` (see
	 * {@see \Specflux\SenroFlux\Packs\Content\Abilities::sectionsSchema()}),
	 * so the call reaches this pack's own message instead.
	 */
	public function test_a_stray_button_shaped_section_names_the_mistake(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'sections'  => array(
					array( 'markup' => $this->validContentWithImage() ),
					array(
						'label' => 'Learn more',
						'url'   => 'https://example.test/learn-more',
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'section_invalid', $result->get_error_code() );
		$this->assertSame(
			'Section 2 has no layout; a button belongs inside its section as `button: {label, url}`.',
			$result->get_error_message()
		);
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-1-1: the model named a
	 * non-existent `page-links` layout whose `items` carried a `button` key.
	 * Core's schema validator used to refuse this first — "button is not a
	 * valid property of Object" — before {@see Layouts::render()}'s own
	 * "is not a layout" message (which names the real layouts) ever had a
	 * chance to run. The `items` sub-schema's `additionalProperties` is now
	 * `true` and `layout` no longer carries a schema `enum`, so an invented
	 * layout name reaches `Layouts::render()` and gets ITS message.
	 */
	public function test_an_invented_layout_with_a_button_in_its_items_gets_the_layout_message(): void {
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'sections'  => array(
					array(
						'layout' => 'page-links',
						'items'  => array(
							array(
								'button' => array(
									'label' => 'Pricing',
									'url'   => '/pricing',
								),
							),
						),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'layout_unknown', $result->get_error_code() );
		$this->assertSame(
			'Section 1: "page-links" is not a layout. Use one of: hero, text-with-image, services, faq, cta, text.',
			$result->get_error_message()
		);
	}

	/**
	 * A schema-shape check, independent of the two behavioural tests above:
	 * core's own JSON schema validator runs BEFORE {@see Abilities::renderSections()}
	 * ever sees the call, so those two tests (which call `execute()` directly)
	 * cannot by themselves prove core would let the malformed shapes through.
	 * This asserts the schema itself no longer blocks them.
	 */
	public function test_sections_item_schema_tolerates_unknown_properties(): void {
		$schema      = $this->ability( 'senroflux/create-post' )->get_input_schema();
		$page_branch = null;
		foreach ( $schema['oneOf'] as $branch ) {
			if ( array( 'page' ) === ( $branch['properties']['post_type']['enum'] ?? array() ) ) {
				$page_branch = $branch;
			}
		}
		$this->assertNotNull( $page_branch );

		$item_schema = $page_branch['properties']['sections']['items'];
		$this->assertTrue( $item_schema['additionalProperties'] ?? false, 'a stray `label`/`url` must reach renderSections(), not a generic schema refusal' );
		$this->assertArrayNotHasKey( 'enum', $item_schema['properties']['layout'] ?? array(), 'an invented layout name must reach Layouts::render(), not a generic enum refusal' );
		$this->assertTrue(
			$item_schema['properties']['items']['items']['additionalProperties'] ?? false,
			'a `button` nested inside items must reach Layouts::render(), not a generic schema refusal'
		);
	}

	/**
	 * 0.3 quality fix (live run, services page — `scenario-2-1`, 2026-09-26):
	 * `{"pattern":"senroflux/hero","slots":[...]}` refused with the SAME
	 * generic "not available" message a genuine typo would get. `senroflux/hero`
	 * is a real, curated pattern — {@see \Specflux\SenroFlux\Packs\Pages\Vocabulary::resolveThemePattern()}
	 * only ever searches theme-derived ones, so it is unconditionally "unknown"
	 * there. `ToolExecutor` drops a WP_Error's `data` before the model ever
	 * sees it ({@see \Specflux\SenroFlux\Tools\ToolExecutor::execute()} —
	 * only `get_error_message()` reaches the model), so the message ITSELF
	 * must say the pattern is curated and point at `markup` — without this,
	 * the model cannot tell "typo" from "wrong write form" and gives up on
	 * every theme pattern in the same call, as the live run did.
	 */
	public function test_sections_curated_pattern_sent_as_slots_names_the_fix(): void {
		$this->useThemeSlotPack();

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'sections'  => array(
					array(
						'pattern' => 'senroflux/hero',
						'slots'   => array( 'Headline', 'Subheadline', 'Get started' ),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'theme_pattern_unknown', $result->get_error_code() );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'senroflux/hero', $message );
		$this->assertStringContainsString( 'markup', $message, 'the message must point the model at the write form it actually needs' );
	}

	/**
	 * 0.3 S21: the posts pack never sees theme patterns, and more broadly
	 * never offers `sections` at all — the shared ability refuses it
	 * outright rather than silently ignoring it.
	 */
	public function test_sections_not_supported_for_the_posts_pack(): void {
		$posts_vocabulary = new PostsVocabulary();
		Abilities::registerSource( 'posts', new PostsValidator( $posts_vocabulary ), $posts_vocabulary, 'edit_posts' );
		Abilities::useRunPack( 'posts' );
		$this->grant( 'edit_posts' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'T',
				'sections'  => array( array( 'markup' => '<!-- wp:paragraph --><p>hi there today</p><!-- /wp:paragraph -->' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'sections_not_supported', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	/**
	 * The posts pack's OWN vocabulary/payload never carries a theme-derived
	 * entry, even with theme patterns registered globally — `Posts\Vocabulary`
	 * does not implement {@see \Specflux\SenroFlux\Packs\Content\ThemePatternSource}
	 * at all.
	 */
	public function test_posts_pack_vocabulary_never_carries_theme_derived_entries(): void {
		$this->registerThemeFixture( 'banner-intro' );

		$posts_vocabulary = new PostsVocabulary();
		$this->assertNotInstanceOf( \Specflux\SenroFlux\Packs\Content\ThemePatternSource::class, $posts_vocabulary );

		Abilities::registerSource( 'posts', new PostsValidator( $posts_vocabulary ), $posts_vocabulary, 'edit_posts' );
		Abilities::useRunPack( 'posts' );
		$this->grant( 'edit_posts' );

		$result = $this->ability( 'senroflux/list-patterns' )->execute( array() );

		foreach ( $result['patterns'] as $pattern ) {
			$this->assertArrayNotHasKey( 'theme_derived', $pattern );
		}
	}

	/**
	 * Live run (services page, 2026-09-28): offered both layouts and
	 * numbered-slot theme patterns, the model mixed them and left a pattern's
	 * sample text on the page. The pages pack refuses the numbered-slot form
	 * and names the layouts instead.
	 */
	public function test_pages_pack_refuses_a_numbered_slot_theme_pattern_and_names_the_layouts(): void {
		$this->registerThemeFixture( 'banner-intro' );
		$this->grant( 'edit_pages' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'T',
				'sections'  => array(
					array(
						'pattern' => 'twentytwentyfive/banner-intro',
						'slots'   => array( 'A brand new promise for this brand' ),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'theme_pattern_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( '`layout`', $result->get_error_message() );
		$this->assertStringContainsString( 'services', $result->get_error_message() );
		$this->assertSame( array(), $GLOBALS['senroflux_test_inserted_posts'] );
	}

	public function test_sections_fills_a_theme_pattern_and_persists_the_filled_markup(): void {
		$this->registerThemeFixture( 'banner-intro' );
		$vocabulary = $this->useThemeSlotPack();
		$theme      = $vocabulary->resolveThemePattern( 'twentytwentyfive/banner-intro' );
		$this->assertNotNull( $theme, 'the fixture must be eligible' );

		$result = $this->ability( 'senroflux/create-post' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Themed page',
				'sections'  => array(
					array(
						'pattern' => 'twentytwentyfive/banner-intro',
						'slots'   => array( 'A brand new promise for this brand' ),
					),
					array( 'markup' => $vocabulary->all()[1]['markup'] ),
					array( 'markup' => $this->mediaTextMarkup() ),
				),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$stored = $GLOBALS['senroflux_test_posts'][ $result['id'] ];
		$this->assertStringContainsString( 'A brand new promise for this brand', $stored->post_content );
	}
}
