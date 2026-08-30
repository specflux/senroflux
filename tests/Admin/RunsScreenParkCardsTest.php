<?php
/**
 * The three park cards, the terminal report, and the detail chrome (S13/S15).
 *
 * TARGET REPO PATH: tests/Admin/RunsScreenParkCardsTest.php
 *
 * `RunsScreenTest` covers the new-run form and the resume-shape assembly; this
 * file covers what the OTHER half of the screen actually renders — the ARIA
 * wiring a screen-reader user depends on, and the escaping boundary around
 * model-authored text (S15 says that text is rendered VERBATIM as content,
 * which makes escaping it the only thing standing between a hostile model
 * response and script execution in wp-admin).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\RunsScreen;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use WP_Error;
use wpdb;

final class RunsScreenParkCardsTest extends TestCase {

	/** A payload no admin screen may ever echo back live. */
	private const XSS = '<script>alert(1)</script>';

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_user_caps']       = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_filters']         = array();
		unset( $_POST, $_GET );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_runs_capability' );
		remove_all_filters( 'senroflux_can_tick' );
		remove_all_filters( 'senroflux_enable_preapproval' );
		unset( $_POST, $_GET );
	}

	// ------------------------------------------------------------------
	// Question card (S6/S15)
	// ------------------------------------------------------------------

	public function test_question_card_associates_the_rationale_with_aria_describedby(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingUser,
			StepKind::Question,
			array(
				'text'      => 'Which tone?',
				'choices'   => array( 'light', 'formal' ),
				'rationale' => 'The goal did not say.',
			)
		);

		// The ATTRIBUTE must survive as an attribute. Escaping the whole
		// ` aria-describedby="…"` fragment turned the quotes into &quot; and
		// the association silently died.
		$this->assertStringContainsString( '<fieldset aria-describedby="senroflux-rationale">', $html );
		$this->assertStringContainsString( 'id="senroflux-rationale"', $html );
		$this->assertStringNotContainsString( 'aria-describedby=&quot;', $html );
		$this->assertStringNotContainsString( '&lt;fieldset', $html );
	}

	public function test_question_card_omits_aria_describedby_when_there_is_no_rationale(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingUser,
			StepKind::Question,
			array( 'text' => 'Which tone?' )
		);

		$this->assertStringContainsString( '<fieldset>', $html );
		$this->assertStringNotContainsString( 'aria-describedby', $html );
	}

	public function test_question_card_wires_the_heading_legend_and_radios(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingUser,
			StepKind::Question,
			array(
				'text'    => 'Which tone?',
				'choices' => array( 'light', 'formal' ),
			)
		);

		$this->assertStringContainsString( 'aria-labelledby="senroflux-park-question-heading"', $html );
		$this->assertStringContainsString( 'id="senroflux-park-question-heading"', $html );
		$this->assertStringContainsString( 'tabindex="-1"', $html );
		$this->assertStringContainsString( '<legend>Which tone?</legend>', $html );
		// S15: radios, never a <select>.
		$this->assertStringContainsString( 'type="radio" name="senroflux_answer_choice" value="light"', $html );
		$this->assertStringNotContainsString( '<select', $html );
		// The "Other" affordance controls its textarea.
		$this->assertStringContainsString( 'aria-controls="senroflux_answer_other"', $html );
	}

	public function test_question_card_escapes_model_authored_text(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingUser,
			StepKind::Question,
			array(
				'text'      => 'Tone? ' . self::XSS,
				'choices'   => array( 'light' . self::XSS ),
				'rationale' => 'Because ' . self::XSS,
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		// The choice VALUE is an attribute, so it must be attribute-escaped too.
		$this->assertStringNotContainsString( 'value="light<script>', $html );
	}

	// ------------------------------------------------------------------
	// Plan card (S7/S15)
	// ------------------------------------------------------------------

	public function test_plan_card_renders_an_ordered_list_and_marks_tier_two_steps(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps'       => array(
					array(
						'text'  => 'Draft the page',
						'verbs' => array( 'create' ),
						'tier'  => 1,
					),
					array(
						'text'  => 'Publish it',
						'verbs' => array( 'publish' ),
						'tier'  => 2,
					),
				),
				'assumptions' => array( 'The site is in English' ),
			)
		);

		$this->assertStringContainsString( 'aria-labelledby="senroflux-park-plan-heading"', $html );
		$this->assertStringContainsString( '<ol class="senroflux-plan-steps">', $html );
		$this->assertStringContainsString( 'Draft the page', $html );
		$this->assertStringContainsString( 'needs approval', $html );
		$this->assertStringContainsString( 'The site is in English', $html );
		// One labelled note textarea, one submit (S15).
		$this->assertStringContainsString( 'for="senroflux_plan_note"', $html );
		$this->assertStringContainsString( 'value="veto"', $html );
	}

	public function test_plan_card_escapes_model_authored_step_text(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps'       => array( array( 'text' => 'Draft ' . self::XSS ) ),
				'assumptions' => array( 'Assume ' . self::XSS ),
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	// ------------------------------------------------------------------
	// Approval card (S6/S15)
	// ------------------------------------------------------------------

	public function test_approval_card_renders_verb_tier_and_arguments(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingApproval,
			StepKind::Approval,
			array(
				'verb' => 'publish',
				'tier' => 2,
				'args' => array( 'title' => 'About us' ),
			)
		);

		$this->assertStringContainsString( 'aria-labelledby="senroflux-park-approval-heading"', $html );
		$this->assertStringContainsString( '<code>publish</code>', $html );
		$this->assertStringContainsString( 'name="senroflux_approval_action" value="approve"', $html );
		$this->assertStringContainsString( 'name="senroflux_approval_action" value="reject"', $html );
		$this->assertStringContainsString( 'About us', $html );
	}

	public function test_approval_card_escapes_the_argument_payload(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingApproval,
			StepKind::Approval,
			array(
				'verb' => 'publish' . self::XSS,
				'tier' => 2,
				'args' => array( 'title' => self::XSS ),
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	// ------------------------------------------------------------------
	// Detail chrome: status label, cancel link, report
	// ------------------------------------------------------------------

	public function test_the_status_badge_shows_a_translated_label_not_the_raw_enum(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingUser,
			StepKind::Question,
			array( 'text' => 'Which tone?' )
		);

		// The machine value stays where machines read it…
		$this->assertStringContainsString( 'data-status="awaiting_user"', $html );
		$this->assertStringContainsString( 'senroflux-badge-awaiting_user', $html );
		// …and the human reads a label.
		$this->assertStringContainsString( '>awaiting your answer</span>', $html );
	}

	public function test_the_cancel_link_carries_no_inline_onclick(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingUser,
			StepKind::Question,
			array( 'text' => 'Which tone?' )
		);

		// runs.js owns the one confirmation; an inline handler would double it
		// AND break on any translation containing an apostrophe.
		$this->assertStringContainsString( 'action=senroflux_cancel_run', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
	}

	public function test_a_terminal_run_escapes_the_model_authored_report_summary(): void {
		$run_id = $this->seedRun();
		$store  = new WpdbRunStore( $GLOBALS['wpdb'] );
		$store->updateRun(
			$run_id,
			array(
				'status'      => RunStatus::Completed->value,
				'result_json' => wp_json_encode(
					array(
						'summary' => 'Done. ' . self::XSS,
						'changes' => array(
							array(
								'object_type' => 'page',
								'title'       => 'About ' . self::XSS,
								'status'      => 'publish',
								'verified'    => true,
							),
						),
					)
				),
			)
		);

		$html = $this->render( $run_id );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'senroflux-badge-verified', $html );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Seed a run parked in `$status` with one `$kind` step carrying `$payload`,
	 * then render the detail view.
	 *
	 * @param array<string,mixed> $payload The stored park payload.
	 */
	private function renderPark( RunStatus $status, StepKind $kind, array $payload ): string {
		$run_id = $this->seedRun();
		$store  = new WpdbRunStore( $GLOBALS['wpdb'] );
		$store->appendStep( $run_id, $kind, $payload );
		$store->updateRun( $run_id, array( 'status' => $status->value ) );

		return $this->render( $run_id );
	}

	/** Render the detail view for one run. */
	private function render( int $run_id ): string {
		$_GET['run_id'] = (string) $run_id;

		ob_start();
		( new RunsScreen() )->render();

		return (string) ob_get_clean();
	}

	/** A run owned by the current user. */
	private function seedRun(): int {
		$this->seedRunnerGraph();

		return ( new WpdbRunStore( $GLOBALS['wpdb'] ) )->createRun(
			1,
			'senroflux-admin',
			'A goal',
			array( 'senroflux/read-content' ),
			array(
				'max_steps'      => 5,
				'max_tool_calls' => 5,
				'max_tokens'     => 100,
				'max_questions'  => 1,
				'max_plans'      => 1,
			)
		);
	}

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls on this screen' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );
	}
}
