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
		// 0.3 S10: the screen capability is now computed from the registered
		// packs; this file is about the RENDERED CHROME, not that computation
		// (covered by ScreenCapabilityTest/RunsScreenTest), so pin it back to
		// the pre-0.3 fixed value with no pack fixtures needed.
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );
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

	/**
	 * S19: a step naming an UNGRANTABLE Tier-2 verb is marked "asks every
	 * time" — distinct from (and in addition to) "needs approval" — while an
	 * ordinary grantable Tier-2 verb in the same plan is not.
	 */
	public function test_plan_card_marks_an_ungrantable_verb_as_asking_every_time(): void {
		add_filter(
			'senroflux_packs',
			static fn ( array $packs ): array => $packs + array(
				'commerce' => new class() extends \Specflux\SenroFlux\Packs\Pack {
					public function name(): string {
						return 'commerce';
					}

					/** @return array<string,int> */
					public function verbMap(): array {
						return array(
							'commerce/refund'              => 2,
							'commerce/order-note-customer' => 2,
						);
					}

					/** @return list<string> */
					public function ungrantableVerbs(): array {
						return array( 'commerce/refund' );
					}

					protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
						unset( $user_id );

						return null;
					}
				},
			),
			10,
			1
		);

		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps'       => array(
					array(
						'text'  => 'Refund the order',
						'verbs' => array( 'commerce/refund' ),
						'tier'  => 2,
					),
					array(
						'text'  => 'Note the customer',
						'verbs' => array( 'commerce/order-note-customer' ),
						'tier'  => 2,
					),
				),
				'assumptions' => array(),
			),
			'commerce'
		);

		$this->assertStringContainsString( 'asks every time', $html );

		// Only the refund step's <li> carries the ungrantable marker.
		$refund_li = substr( $html, (int) strpos( $html, 'Refund the order' ) );
		$refund_li = substr( $refund_li, 0, (int) strpos( $refund_li, '</li>' ) );
		$note_li   = substr( $html, (int) strpos( $html, 'Note the customer' ) );
		$note_li   = substr( $note_li, 0, (int) strpos( $note_li, '</li>' ) );

		$this->assertStringContainsString( 'asks every time', $refund_li );
		$this->assertStringNotContainsString( 'asks every time', $note_li );

		remove_all_filters( 'senroflux_packs' );
	}

	/**
	 * S13/S15: the pre-approve radio is an AS-12 affordance. With the feature
	 * off — the default — the human is never offered a decision the Runner
	 * would answer with `preapproval_disabled`.
	 */
	public function test_plan_card_hides_the_preapprove_radio_by_default(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps' => array(
					array(
						'text' => 'Publish it',
						'tier' => 2,
					),
				),
			)
		);

		$this->assertStringContainsString( 'value="accept"', $html );
		$this->assertStringContainsString( 'value="veto"', $html );
		$this->assertStringNotContainsString( 'value="accept_preapprove"', $html );
	}

	public function test_plan_card_offers_the_preapprove_radio_once_grants_are_on(): void {
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true, 10, 1 );
		senroflux_test_grants( true );

		try {
			$html = $this->renderPark(
				RunStatus::AwaitingPlan,
				StepKind::Plan,
				array(
					'steps' => array(
						array(
							'text' => 'Publish it',
							'tier' => 2,
						),
					),
				)
			);
		} finally {
			senroflux_test_no_agent_safety();
		}

		$this->assertStringContainsString( 'value="accept_preapprove"', $html );
		// S15: the choice carries its one-line warning.
		$this->assertStringContainsString( 'without asking again', $html );
	}

	/** The SenroFlux filter alone is not enough: Agent Safety must have AS-12 switched on too. */
	public function test_plan_card_hides_the_preapprove_radio_while_agent_safety_grants_are_off(): void {
		add_filter( 'senroflux_enable_preapproval', static fn (): bool => true, 10, 1 );
		senroflux_test_grants( false );

		try {
			$html = $this->renderPark(
				RunStatus::AwaitingPlan,
				StepKind::Plan,
				array(
					'steps' => array(
						array(
							'text' => 'Publish it',
							'tier' => 2,
						),
					),
				)
			);
		} finally {
			senroflux_test_no_agent_safety();
		}

		$this->assertStringNotContainsString( 'value="accept_preapprove"', $html );
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

	/**
	 * Defect 4 (0.3 live run): the built-in gate parks every call whose tier
	 * is above 0 ({@see \Specflux\SenroFlux\Tools\BuiltinGate}), so the
	 * built-in-mode plan card's approval count must count Tier >= 1 steps —
	 * not Tier >= 2, which is the count that actually matters in AS mode.
	 * The exact verb list from the live run: 4 of the 6 steps are Tier 1
	 * (create-draft, media-generate, update-alt, set-featured-image); the
	 * other two are Tier 0 (read, media-search).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function livePlanSteps(): array {
		return array(
			array(
				'text'  => 'Create the draft post',
				'verbs' => array( 'posts/create-draft' ),
				'tier'  => 1,
			),
			array(
				'text'  => 'Generate the image',
				'verbs' => array( 'posts/media-generate' ),
				'tier'  => 1,
			),
			array(
				'text'  => 'Update the alt text',
				'verbs' => array( 'posts/update-alt' ),
				'tier'  => 1,
			),
			array(
				'text'  => 'Set the featured image',
				'verbs' => array( 'posts/set-featured-image' ),
				'tier'  => 1,
			),
			array(
				'text'  => 'Read the post',
				'verbs' => array( 'posts/read' ),
				'tier'  => 0,
			),
			array(
				'text'  => 'Search for media',
				'verbs' => array( 'posts/media-search' ),
				'tier'  => 0,
			),
		);
	}

	public function test_built_in_mode_plan_card_counts_tier_one_and_above_as_approvals(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps'       => $this->livePlanSteps(),
				'assumptions' => array(),
			),
			null,
			\Specflux\SenroFlux\Run\GateMode::BuiltIn
		);

		$this->assertStringContainsString(
			'This plan will ask you to approve 4 changes.',
			$html,
			'built-in mode parks every Tier >= 1 call, so 4 of the 6 steps need approval'
		);
	}

	/**
	 * Defect B (0.3 live run): the previous fix's test used one verb per
	 * step, so counting STEPS happened to equal counting CALLS by
	 * coincidence. The actual live plan grouped several Tier>=1 verbs into
	 * ONE step — [create-draft], [media-generate, update-alt,
	 * set-featured-image], [read] — and the card said "approve 2 changes"
	 * (2 qualifying steps) when the correct count is 4 (every Tier>=1 verb
	 * occurrence across all steps: 1 + 3 + 0).
	 *
	 * @return list<array<string,mixed>>
	 */
	private function liveMultiVerbPlanSteps(): array {
		return array(
			array(
				'text'  => 'Create the draft post',
				'verbs' => array( 'posts/create-draft' ),
				'tier'  => 1,
			),
			array(
				'text'  => 'Generate and attach the image',
				'verbs' => array( 'posts/media-generate', 'posts/update-alt', 'posts/set-featured-image' ),
				'tier'  => 1,
			),
			array(
				'text'  => 'Read the post',
				'verbs' => array( 'posts/read' ),
				'tier'  => 0,
			),
		);
	}

	public function test_built_in_mode_plan_card_counts_every_call_not_every_step(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps'       => $this->liveMultiVerbPlanSteps(),
				'assumptions' => array(),
			),
			null,
			\Specflux\SenroFlux\Run\GateMode::BuiltIn
		);

		$this->assertStringContainsString(
			'This plan will ask you to approve 4 changes.',
			$html,
			'each Tier >= 1 verb occurrence parks once per call, even when several are grouped into one step'
		);
	}

	public function test_agent_safety_mode_plan_card_counts_only_tier_two_as_approvals(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingPlan,
			StepKind::Plan,
			array(
				'steps'       => $this->livePlanSteps(),
				'assumptions' => array(),
			),
			null,
			\Specflux\SenroFlux\Run\GateMode::AgentSafety
		);

		// AS mode has no approval-count paragraph at all (S3): the "needs
		// approval" markers on the Tier-2 steps are the AS-mode equivalent,
		// and none of these six steps is Tier 2.
		$this->assertStringNotContainsString( 'senroflux-plan-approval-count', $html );
		$this->assertStringNotContainsString( 'needs approval', $html );
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

	/**
	 * The args box scrolls (`overflow: auto; max-height: 240px`), so a tall
	 * publish payload made it a scrollable region nothing could focus — axe
	 * SERIOUS `scrollable-region-focusable` on the live approval card. It must
	 * be keyboard-reachable AND named.
	 */
	public function test_the_scrollable_arguments_box_is_focusable_and_named(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingApproval,
			StepKind::Approval,
			array(
				'verb' => 'pages/publish',
				'tier' => 2,
				'args' => array( 'content' => str_repeat( "line\n", 200 ) ),
			)
		);

		$this->assertMatchesRegularExpression(
			'/<pre class="senroflux-args"[^>]*\btabindex="0"/',
			$html,
			'the scrollable args region must be keyboard-focusable'
		);
		$this->assertMatchesRegularExpression(
			'/<pre class="senroflux-args"[^>]*\baria-labelledby="(senroflux-args-label-\d+)"/',
			$html,
			'a focusable region needs an accessible name'
		);

		// …and the name it points at is actually in the document.
		$this->assertSame(
			1,
			preg_match( '/<pre class="senroflux-args"[^>]*\baria-labelledby="([^"]+)"/', $html, $m )
		);
		$this->assertStringContainsString( 'id="' . $m[1] . '"', $html );
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

	/**
	 * Defect E (live run): a long argument (e.g. a "prompt" string) ran off
	 * the approval card horizontally instead of wrapping, so the approver
	 * could not see the full call they were being asked to approve, and the
	 * goal heading overflowed the viewport the same way. Both must carry a
	 * real wrap rule — checked both in the rendered markup (the class hooks
	 * are present) and in the actual stylesheet (the rule itself is there),
	 * since a class with no matching CSS would pass a markup-only check
	 * while still rendering unwrapped.
	 */
	public function test_the_approval_card_and_run_heading_carry_wrapping_markup(): void {
		$html = $this->renderPark(
			RunStatus::AwaitingApproval,
			StepKind::Approval,
			array(
				'verb' => 'posts/generate-image',
				'tier' => 1,
				'args' => array( 'prompt' => str_repeat( 'a very long unbroken prompt word ', 20 ) ),
			)
		);

		$this->assertStringContainsString( '<h2 class="senroflux-run-heading">', $html, 'the goal heading needs a class the stylesheet can hang a wrap rule on' );
		$this->assertMatchesRegularExpression( '/<pre class="senroflux-args"/', $html );

		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/runs.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local repo asset, not a remote URL.

		$this->assertMatchesRegularExpression(
			'/\.senroflux-args\s*\{[^}]*white-space:\s*pre-wrap;[^}]*overflow-wrap:\s*anywhere;/s',
			$css,
			'.senroflux-args must actually wrap long arguments, not just scroll'
		);
		$this->assertMatchesRegularExpression(
			'/\.senroflux-run-heading\s*\{[^}]*overflow-wrap:\s*anywhere;/s',
			$css,
			'.senroflux-run-heading must wrap a long model-authored goal instead of overflowing the viewport'
		);
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
	// Withheld roles (0.3 S6)
	// ------------------------------------------------------------------

	public function test_the_detail_view_names_a_withheld_role(): void {
		$this->seedRunnerGraph();
		$run_id = ( new WpdbRunStore( $GLOBALS['wpdb'] ) )->createRun(
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
			),
			null,
			null,
			null,
			\Specflux\SenroFlux\Run\GateMode::AgentSafety,
			array( 'generate' )
		);

		$html = $this->render( $run_id );

		$this->assertStringContainsString( 'senroflux-withheld-roles', $html );
		$this->assertStringContainsString( 'generate', $html );
	}

	public function test_the_detail_view_names_nothing_when_no_role_is_withheld(): void {
		$run_id = $this->seedRun();

		$html = $this->render( $run_id );

		$this->assertStringNotContainsString( 'senroflux-withheld-roles', $html );
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
	private function renderPark( RunStatus $status, StepKind $kind, array $payload, ?string $pack = null, \Specflux\SenroFlux\Run\GateMode $gate_mode = \Specflux\SenroFlux\Run\GateMode::AgentSafety ): string {
		$run_id = $this->seedRun( $pack, $gate_mode );
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

	/** A run owned by the current user, optionally bound to a pack. */
	private function seedRun( ?string $pack = null, \Specflux\SenroFlux\Run\GateMode $gate_mode = \Specflux\SenroFlux\Run\GateMode::AgentSafety ): int {
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
			),
			$pack,
			null,
			null,
			$gate_mode
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
