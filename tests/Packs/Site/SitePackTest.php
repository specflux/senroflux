<?php
/**
 * SitePack build-contract tests (stage 7, S7).
 *
 * TARGET REPO PATH: tests/Packs/Site/SitePackTest.php
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\SitePack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Skills\SkillSet;

final class SitePackTest extends TestCase {

	private function loadShims(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
	}

	protected function setUp(): void {
		$this->loadShims();
		remove_all_filters( 'senroflux_skills_max_tokens' );
		remove_all_filters( 'senroflux_run_skills' );
		remove_all_filters( 'senroflux_default_budget' );
		Plugin::reset();
	}

	protected function tearDown(): void {
		Plugin::reset();
	}

	public function test_name_is_site(): void {
		$this->assertSame( 'site', ( new SitePack() )->name() );
	}

	public function test_run_capability_is_manage_options(): void {
		$this->assertSame( 'manage_options', ( new SitePack() )->runCapability() );
	}

	public function test_verb_map_tiers_update_navigation_and_set_front_page_at_tier_2(): void {
		$map = ( new SitePack() )->verbMap();

		$this->assertSame( 0, $map['site/read-navigation'] );
		$this->assertSame( 2, $map['site/update-navigation'] );
		$this->assertSame( 0, $map['site/read-front-page'] );
		$this->assertSame( 2, $map['site/set-front-page'] );
	}

	public function test_agent_safety_verb_map_carries_update_navigation_at_tier_2(): void {
		$map = ( new SitePack() )->agentSafetyVerbMap();

		$this->assertSame( 2, $map['senroflux/update-navigation'] );
		$this->assertSame( 2, $map['senroflux/set-front-page'] );
		$this->assertSame( 0, $map['senroflux/read-navigation'] );
		$this->assertSame( 0, $map['senroflux/read-front-page'] );
		// Draft-state edits must NOT collapse to tier 2 (same S4 rule as pages/posts).
		$this->assertSame( 1, $map['senroflux/update-post'] );
	}

	public function test_default_budget_matches_the_s7_table(): void {
		$this->assertSame(
			array(
				'max_steps'      => 200,
				'max_tool_calls' => 120,
				'max_tokens'     => 1000000,
				'max_questions'  => 8,
				'max_plans'      => 3,
				'images'         => 0,
			),
			( new SitePack() )->defaultBudget()
		);
	}

	public function test_skills_returns_three_pack_skills(): void {
		$skills = ( new SitePack() )->skills();

		$this->assertCount( 3, $skills );
		$ids = array_map( static fn ( $s ) => $s->id, $skills );
		$this->assertContains( 'site/layout-rules', $ids );
		$this->assertContains( 'site/copy-rules', $ids );
		$this->assertContains( 'site/structure-rules', $ids );
	}

	public function test_a_site_run_skill_set_stays_under_the_2000_token_ceiling(): void {
		$skills = SkillSet::collect( 'test-consumer', 'Build a five-page site', new SitePack(), null, 'en_US' );

		$this->assertNull( SkillSet::ceilingError( $skills ) );
	}

	/**
	 * Live run 57: the site pack's layout-rules skill named the nine
	 * patterns but never restated their shipped SHAPE (heading level, align,
	 * buttons layout), so the model guessed the hero's headline as a plain h2
	 * and was refused three times. This proves the rendered skill body now
	 * carries the same shape lines a pages run gets, for every pattern a site
	 * run can write — including the two homepage-only ones.
	 */
	public function test_layout_rules_states_the_shape_of_every_pattern_including_hero_and_page_links(): void {
		$layout = null;
		foreach ( ( new SitePack() )->skills() as $skill ) {
			if ( 'site/layout-rules' === $skill->id ) {
				$layout = $skill;
			}
		}
		$this->assertNotNull( $layout );

		$body = $layout->body;
		$this->assertStringContainsString( 'hero: group align=full > heading level 1', $body );
		$this->assertStringContainsString( 'buttons layout=flex > button', $body );
		$this->assertStringContainsString( 'page-links: group align=full > heading level 2', $body );
		$this->assertStringContainsString( 'intro: group > heading level 2', $body );
	}

	public function test_structure_rules_tell_the_model_to_propose_a_plan_right_after_clarify(): void {
		$skills = ( new SitePack() )->skills();

		$structure = null;
		foreach ( $skills as $skill ) {
			if ( 'site/structure-rules' === $skill->id ) {
				$structure = $skill;
			}
		}

		$this->assertNotNull( $structure );
		$this->assertStringContainsString(
			'senroflux/propose-plan',
			$structure->body,
			'the very next call after clarify must be propose-plan'
		);
		$this->assertStringContainsString(
			'adopted object',
			$structure->body
		);
		$this->assertStringContainsString(
			'publish and navigation steps',
			$structure->body
		);
	}
}
