<?php
/**
 * The run loop: one tick = at most one model turn plus its tool calls.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Run;

use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSet;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolOutcome;
use Specflux\SenroFlux\Tools\ToolRegistry;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Implements the S4 tick protocol (as amended by 0.2 S5):
 *
 *  1. Ownership + optimistic lock (echoed step_count) + 30s lock transient.
 *  2. Terminal runs return their state unchanged.
 *  3. Parked runs (0.2: awaiting_approval | awaiting_user | awaiting_plan)
 *     resume only with a park resolution whose SHAPE matches the park kind
 *     (S5) — anything else is `resume_mismatch`. The approval park re-runs
 *     the parked call (grant exists by reference) or answers
 *     rejected_by_user; the question and plan parks resolve through S6/S7.
 *  4. History rehydrates from steps; ONE model call via the gateway.
 *  5. Each functionCall runs through ToolExecutor in order; approval_required
 *     parks mid-message; no calls means completed.
 *
 * CRASH RESUME: consumption is tracked BY CALL ID — a call counts as consumed
 * once any later tool_result step carries its FunctionResponse id (executed
 * OR explicitly rejected). A tick that died between a model step and its
 * executions leaves unconsumed ids that the next tick executes WITHOUT
 * another model call.
 */
final class Runner {

	public function __construct(
		private readonly RunStore $store,
		private readonly ToolExecutor $executor,
		private readonly ModelGatewayInterface $gateway,
		private readonly ApprovalBridge $bridge,
		private readonly mixed $post_lookup = null,
		/** @var callable(Run):(array<string,int>|null)|null Verb-map resolver (S9): a pack maps verb => tier; null return = site-wide filter seam. */
		private readonly mixed $verb_map_resolver = null,
		/** @var callable(Run,string,array<string,mixed>):string|null Verb resolver (S9): ability + args => verb; absent = the ability name IS the verb. */
		private readonly mixed $verb_resolver = null,
		/** @var callable(Run):mixed|null Pack resolver (S8/S9): the run's pack descriptor, passed opaquely to SkillSet; absent = a direct-allow run. */
		private readonly mixed $pack_resolver = null,
		/** @var callable(Run,string):string|null Object-id key resolver (S12): which input/output key carries a verb's object id; absent = 'id'. */
		private readonly mixed $object_id_key_resolver = null,
	) {
	}

	/** Expose the store for the container's read paths (get/cancel). */
	public function store(): RunStore {
		return $this->store;
	}

	/**
	 * Advance one run by at most one model turn.
	 *
	 * @param int         $run_id              Run id.
	 * @param int         $expected_step_count Caller's last-known step_count.
	 * @param array<string,mixed>|null $resume Park resolution (S5): its shape
	 *                                         must match the run's park kind,
	 *                                         else `resume_mismatch`. Null on
	 *                                         a plain driving tick. The 0.1
	 *                                         `?string $approval_action`
	 *                                         parameter is REMOVED — strings
	 *                                         are type-rejected here and the
	 *                                         HTTP surfaces refuse the old
	 *                                         field outright.
	 * @return array<string,mixed>|WP_Error RunState or WP_Error codes:
	 *   senroflux_not_found | senroflux_forbidden | senroflux_conflict |
	 *   senroflux_ungoverned | resume_mismatch.
	 */
	public function tick( int $run_id, int $expected_step_count, ?array $resume = null ): array|WP_Error {
		$run = $this->store->getRun( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'senroflux_not_found', __( 'Run not found.', 'senroflux' ), array( 'status' => 404 ) );
		}

		if ( ! $this->mayTick( $run ) ) {
			return new WP_Error( 'senroflux_forbidden', __( 'This run belongs to another user.', 'senroflux' ), array( 'status' => 403 ) );
		}

		if ( ! function_exists( 'get_current_user_id' ) ) {
			return new WP_Error( 'senroflux_ungoverned', __( 'SenroFlux requires a WordPress session.', 'senroflux' ), array( 'status' => 500 ) );
		}

		// Optimistic lock BEFORE the transient: a stale echo must never queue
		// behind someone else's in-flight tick.
		if ( $run->stepCount !== $expected_step_count ) {
			return new WP_Error( 'senroflux_conflict', __( 'The run advanced since your last request; refresh its state.', 'senroflux' ), array( 'status' => 409 ) );
		}

		$lock_key = 'senroflux_lock_' . $run_id;
		if ( false !== get_transient( $lock_key ) ) {
			return new WP_Error( 'senroflux_conflict', __( 'A tick for this run is already in flight.', 'senroflux' ), array( 'status' => 409 ) );
		}
		set_transient( $lock_key, 1, 30 );

		try {
			// S5: a park resolution only makes sense on a parked run — a
			// pending/running/terminal run carrying one is a protocol error,
			// never a hint to be ignored. Terminal runs are checked FIRST so
			// the guard is reachable for them too: a finished run answering a
			// resume with its unchanged state would tell the caller their
			// decision landed when nothing happened.
			if ( null !== $resume && ! $run->status->isParked() ) {
				return new WP_Error(
					'resume_mismatch',
					__( 'This run is not waiting on a human; it accepts no park resolution.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}

			if ( $run->status->isTerminal() ) {
				return $this->state( $run, array(), null );
			}

			$new_steps = array();

			if ( $run->status->isParked() ) {
				if ( null !== $resume ) {
					// S5: the resolution's shape must match the park kind.
					$check = Resume::check( $run->status, $resume );
					if ( is_wp_error( $check ) ) {
						return $check;
					}

					switch ( $run->status ) {
						case RunStatus::AwaitingApproval:
							$resume_park = $this->resumeFromApproval( $run, (string) $resume['action'], $new_steps );
							if ( null !== $resume_park ) {
								// Still parked: return without touching the loop.
								return $this->state( $resume_park['run'], $new_steps, $resume_park['ui'] );
							}
							break;
						case RunStatus::AwaitingUser:
							// S6: an answer/skip re-enters the loop (returns
							// null); a 400 (choice_not_offered) or a
							// still-parked re-surface returns up.
							$from_question = $this->resumeFromQuestion( $run, $resume, $new_steps );
							if ( is_wp_error( $from_question ) ) {
								return $from_question;
							}
							if ( null !== $from_question ) {
								return $this->state( $from_question['run'], $new_steps, $from_question['ui'] );
							}
							break;
						case RunStatus::AwaitingPlan:
							// S7: accept / accept_preapprove / veto re-enter the
							// loop (returns null); a 400 (preapproval_disabled) or
							// a still-parked re-surface returns up. A veto at the
							// plan ceiling returns the CANCELLED run directly.
							$from_plan = $this->resumeFromPlan( $run, $resume, $new_steps );
							if ( is_wp_error( $from_plan ) ) {
								return $from_plan;
							}
							if ( null !== $from_plan ) {
								return $this->state( $from_plan['run'], $new_steps, $from_plan['ui'] );
							}
							break;
					}
				} elseif ( RunStatus::AwaitingApproval === $run->status ) {
					// No decision yet: re-surface the approval card so a
					// polling consumer can render it again.
					$resume_park = $this->resumeFromApproval( $run, '', $new_steps );
					if ( null !== $resume_park ) {
						return $this->state( $resume_park['run'], $new_steps, $resume_park['ui'] );
					}
				} elseif ( RunStatus::AwaitingUser === $run->status ) {
					// No answer yet: re-surface the question card (S6).
					$from_question = $this->resumeFromQuestion( $run, null, $new_steps );
					if ( is_wp_error( $from_question ) ) {
						return $from_question;
					}
					if ( null !== $from_question ) {
						return $this->state( $from_question['run'], $new_steps, $from_question['ui'] );
					}
				} else {
					// No decision yet (AwaitingPlan): re-surface the plan card (S7).
					$from_plan = $this->resumeFromPlan( $run, null, $new_steps );
					if ( is_wp_error( $from_plan ) ) {
						return $from_plan;
					}
					if ( null !== $from_plan ) {
						return $this->state( $from_plan['run'], $new_steps, $from_plan['ui'] );
					}
					return $this->state( $this->refresh( $run ), array(), array() );
				}
			} elseif ( array() === $this->store->getSteps( $run_id ) ) {
				// Fresh run: seed the goal as the first user step.
				$new_steps[] = $this->appendStep(
					$run_id,
					StepKind::User,
					new UserMessage( array( new MessagePart( $run->goal ) ) )
				);
			}

			$run    = $this->refresh( $run );
			$result = $this->driveLoop( $run, $new_steps );

			return $this->state( $result['run'], $new_steps, $result['ui'] );
		} finally {
			$this->releaseLock( $run_id ); // Idempotent safety net.
		}
	}

	// ------------------------------------------------------------------
	// Resume path (S6)
	// ------------------------------------------------------------------

	/**
	 * Resume an awaiting_approval run with approve/reject.
	 *
	 * @param Run                       $run       Snapshot.
	 * @param string                    $action    approve|reject ('' = none yet).
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @return array{run:Run,steps:list<array<string,mixed>>,ui:?array<string,mixed>}|null
	 *         Null = the loop may proceed; array = still parked, return now.
	 */
	private function resumeFromApproval( Run $run, string $action, array &$new_steps ): ?array {
		$parked = $this->latestParkedContext( $run->id );
		if ( null === $parked ) {
			// Corrupted state: fail explicitly rather than looping ungoverned.
			$report = $this->failError( $run, 'missing_approval_context', 'No parked context for an awaiting_approval run.' );

			return array(
				'run'   => $this->refresh( $run ),
				'steps' => $new_steps,
				'ui'    => array( 'report' => $report ),
			);
		}

		if ( '' === $action ) {
			// No decision yet: remain parked, RE-SURFACING the approval card
			// so a polling consumer can render it again.
			$still = ToolOutcome::approvalRequired(
				(string) ( $parked['approval_id'] ?? '' ),
				(string) ( $parked['tool_name'] ?? '' ),
				is_string( $parked['tier'] ?? null ) ? $parked['tier'] : null
			);

			return array(
				'run'   => $this->refresh( $run ),
				'steps' => $new_steps,
				'ui'    => $this->approvalUi(
					$still,
					array(
						'id'   => (string) ( $parked['function_call_id'] ?? '' ),
						'name' => (string) ( $parked['tool_name'] ?? '' ),
						'args' => $parked['args'] ?? array(),
					)
				),
			);
		}

		$approval_id = (string) $parked['approval_id'];
		$call        = array(
			'id'   => (string) ( $parked['function_call_id'] ?? '' ),
			'name' => (string) $parked['tool_name'],
			'args' => $parked['args'] ?? array(),
		);

		if ( 'approve' === $action && $this->bridge->isAvailable() ) {
			$this->bridge->approve( $approval_id, (int) get_current_user_id() );
		}

		if ( 'reject' === $action ) {
			$new_steps[] = $this->appendRejectedResult( run_id: $run->id, call: $call );
		} else {
			// Approve: re-run the parked call. The permission re-check passes
			// via the by-reference grant; if it STILL demands approval (bridge
			// unavailable / grant expired), remain parked.
			$registry = ToolRegistry::forRun( $run );

			// S7, defence in depth: a human approval answers Agent Safety's
			// question, not the harness's. The plan fence is re-checked here
			// because the accepted plan can have changed (or been vetoed away)
			// while the call sat parked, and this is the ONE execution path
			// that does not come through the loop's fence.
			$refusal = $this->fenceRefusal( $registry, $run, $call );
			if ( null !== $refusal ) {
				$new_steps[] = $this->appendFenceRefusal( $run->id, $call, $refusal );
			} else {
				$outcome = $this->executeCall( $registry, $call );

				if ( 'approval_required' === $outcome->kind ) {
					return array(
						'run'   => $this->refresh( $run ),
						'steps' => $new_steps,
						'ui'    => $this->approvalUi( $outcome, $call ),
					);
				}

				$new_steps[] = $this->appendToolResult( run: $run, call: $call, outcome: $outcome );
			}
		}

		// Running again: the loop's crash-resume drains any REMAINING sibling
		// calls of the same model message by their ids.
		$this->store->updateRun( $run->id, array( 'status' => RunStatus::Running->value ) );

		return null;
	}

	// ------------------------------------------------------------------
	// Main loop
	// ------------------------------------------------------------------

	/**
	 * Crash-resume → skills render → budget gates → model turn → tool
	 * executions.
	 *
	 * @param Run                       $run       Snapshot.
	 * @param list<array<string,mixed>> $new_steps Accumulates this tick's steps.
	 * @return array{run:Run,ui:?array<string,mixed>}
	 */
	private function driveLoop( Run $run, array &$new_steps ): array {
		$registry = ToolRegistry::forRun( $run );

		// S8: the whole system instruction is rendered per tick, audited at
		// seq 0, and a skills ceiling breach fails the run (never truncation).
		$instruction = $this->instructionFor( $run, $new_steps );
		if ( is_wp_error( $instruction ) ) {
			$report = $this->failError( $run, (string) $instruction->get_error_code(), $instruction->get_error_message() );

			return array(
				'run' => $this->refresh( $run ),
				'ui'  => array( 'report' => $report ),
			);
		}

		$tool_calls_used = 0;
		foreach ( $this->store->getSteps( $run->id ) as $existing_step ) {
			if ( StepKind::ToolResult === $existing_step->kind && $this->countsAsToolCall( $existing_step ) ) {
				// S6/S7: a harness ANSWER (the ok tool_result to a parked
				// ask-user or propose-plan) and an S7 fence refusal are NOT
				// tool-call consumers. An invalid/exhausted call (status
				// 'error', non-fence code) DOES count.
				++$tool_calls_used;
			}
		}

		while ( true ) {
			if ( $run->stepCount >= $run->budget['max_steps'] ) {
				$report = $this->failBudget( $run, 'max_steps' );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => array( 'report' => $report ),
				);
			}
			if ( $run->tokensIn + $run->tokensOut >= $run->budget['max_tokens'] ) {
				$report = $this->failBudget( $run, 'max_tokens' );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => array( 'report' => $report ),
				);
			}

			$pending_calls = $this->unconsumedCalls( $run );

			if ( null === $pending_calls ) {
				// S6/S7: the harness tools are declared while a question / plan
				// remains and withdrawn at zero. Recomputed for EVERY model call,
				// not once per tick: an `invalid_question` refusal inside this
				// loop moves the remaining count, and a stale declaration set
				// would offer the model a tool the harness has already withdrawn
				// (or hide one it still has). They are not abilities, so they
				// touch the permission-agnostic declaration surface only.
				$harness_declarations = array_merge(
					HarnessTools::declarations( $this->remainingQuestions( $run ) ),
					PlanTools::declarations( $this->remainingPlans( $run ) )
				);
				$tools                = array() !== $harness_declarations
					? $registry->withDeclarations( $harness_declarations )
					: $registry;

				$history = $this->historyForPrompt( $run );
				$turn    = $this->gateway->generateTurn( $history, $instruction, $tools );

				if ( $turn instanceof WP_Error ) {
					$report = $this->failError( $run, 'model_error', $turn->get_error_message() );

					return array(
						'run' => $this->refresh( $run ),
						'ui'  => array( 'report' => $report ),
					);
				}

				$new_steps[] = $this->appendStep(
					run_id: $run->id,
					kind: StepKind::Model,
					message: $turn->message,
					status: 'ok',
					tokens_in: $turn->tokensIn,
					tokens_out: $turn->tokensOut
				);
				$this->addTokens( $run->id, $turn->tokensIn, $turn->tokensOut );
				$run = $this->refresh( $run );

				$pending_calls = $this->extractCalls( $turn->message );

				if ( array() === $pending_calls ) {
					// S12: a finish attempt may be parked by a verify nudge.
					if ( $this->finishAttempt( $run, $new_steps ) ) {
						// Nudged: S12 says KEEP RUNNING. Saying so explicitly
						// matters — a run nudged on its very first tick has
						// never left `pending`, and a consumer polling on
						// status would treat it as never started.
						$this->store->updateRun( $run->id, array( 'status' => RunStatus::Running->value ) );

						return array(
							'run' => $this->refresh( $run ),
							'ui'  => null,
						);
					}

					$report = $this->complete( $run );

					return array(
						'run' => $this->refresh( $run ),
						'ui'  => array( 'report' => $report ),
					);
				}
			}

			foreach ( $pending_calls as $index => $call ) {
				if ( $tool_calls_used >= $run->budget['max_tool_calls'] ) {
					$report = $this->failBudget( $run, 'max_tool_calls' );

					return array(
						'run' => $this->refresh( $run ),
						'ui'  => array( 'report' => $report ),
					);
				}
				$remaining = array_slice( $pending_calls, $index + 1 );

				// Harness tools never reach ToolExecutor, Agent Safety, or the
				// ability allow-list. A valid ask-user parks (ends the tick);
				// an invalid/exhausted one becomes a tool_result and counts as
				// a tool call so the loop keeps running.
				if ( HarnessTools::functionName() === $call['name'] ) {
					$harness = $this->runAskUser( $run, $call, $new_steps );
					if ( isset( $harness['park'] ) ) {
						return $harness['park']; // Park ends the tick.
					}
					++$tool_calls_used;
					$run = $this->refresh( $run );
					continue;
				}

				// S7: same interception for the plan tool.
				if ( PlanTools::functionName() === $call['name'] ) {
					$harness = $this->runProposePlan( $run, $call, $new_steps );
					if ( isset( $harness['park'] ) ) {
						return $harness['park']; // Park ends the tick.
					}
					++$tool_calls_used;
					$run = $this->refresh( $run );
					continue;
				}

				// S7 plan fence: before ANY ability executes, a Tier-1+ call
				// must be inside the accepted plan's verb set. A refusal is a
				// tool_result error the model sees, and is NEVER counted
				// against max_tool_calls (it never executed anything).
				$refusal = $this->fenceRefusal( $registry, $run, $call );
				if ( null !== $refusal ) {
					$new_steps[] = $this->appendFenceRefusal( $run->id, $call, $refusal );
					$run         = $this->refresh( $run );
					continue;
				}

				$outcome = $this->executeCall( $registry, $call );
				++$tool_calls_used;

				if ( 'approval_required' === $outcome->kind ) {
					return $this->park( $run, $new_steps, $call, $remaining, $outcome );
				}

				$new_steps[] = $this->appendToolResult( $run, $call, $outcome );
				$run         = $this->refresh( $run );
				// Errors/denials flow back to the model as error text; it decides.
			}

			if ( $run->stepCount >= $run->budget['max_steps'] ) {
				$report = $this->failBudget( $run, 'max_steps' );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => array( 'report' => $report ),
				);
			}
		}
	}

	// ------------------------------------------------------------------
	// Step persistence helpers
	// ------------------------------------------------------------------

	/**
	 * Append user/model steps carrying a real Message DTO.
	 *
	 * @return array{seq:int,kind:string,message:array<string,mixed>|null,tool_name:string|null,approval_id:string|null,status:string}
	 */
	private function appendStep(
		int $run_id,
		StepKind $kind,
		?Message $message = null,
		string $tool_name = '',
		string $approval_id = '',
		string $status = 'ok',
		int $tokens_in = 0,
		int $tokens_out = 0
	): array {
		$message_array = null !== $message ? $message->toArray() : null;
		$seq           = $this->store->appendStep(
			$run_id,
			$kind,
			$message_array,
			'' !== $tool_name ? $tool_name : null,
			'' !== $approval_id ? $approval_id : null,
			$status,
			$tokens_in,
			$tokens_out
		);

		return array(
			'seq'         => $seq,
			'kind'        => $kind->value,
			'message'     => $message_array,
			'tool_name'   => '' !== $tool_name ? $tool_name : null,
			'approval_id' => '' !== $approval_id ? $approval_id : null,
			'status'      => $status,
		);
	}

	/**
	 * Append a tool_result step from an outcome; payload mirrors S5
	 * normalization and carries the originating call id for crash-resume.
	 *
	 * @param Run                                     $run  The run (S12 needs its verb map).
	 * @param array{id:string,name:string,args:mixed} $call Originating call.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendToolResult( Run $run, array $call, ToolOutcome $outcome ): array {
		$run_id   = $run->id;
		$response = match ( $outcome->kind ) {
			'result' => $outcome->output ?? array(),
			default  => array( 'error' => (string) $outcome->errorMessage ),
		};
		$status = 'result' === $outcome->kind ? 'ok' : 'error';

		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call['id'] ? $call['id'] : null,
							self::functionNameFor( $call['name'] ),
							$response
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			$call['name'],
			null,
			$status
		);

		// S12: fold this successful result into the written-object set.
		$this->trackObjects( $run, $call, $outcome, $seq );

		return array(
			'seq'       => $seq,
			'kind'      => StepKind::ToolResult->value,
			'message'   => $message_array,
			'tool_name' => $call['name'],
			'status'    => $status,
		);
	}

	/**
	 * Rejected-by-user variant of a tool_result.
	 *
	 * @param array{id:string,name:string,args:mixed} $call Parked call.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendRejectedResult( int $run_id, array $call ): array {
		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call['id'] ? $call['id'] : null,
							self::functionNameFor( $call['name'] ),
							array( 'error' => 'rejected_by_user' )
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			$call['name'],
			null,
			'rejected'
		);

		return array(
			'seq'       => $seq,
			'kind'      => StepKind::ToolResult->value,
			'message'   => $message_array,
			'tool_name' => $call['name'],
			'status'    => 'rejected',
		);
	}

	/**
	 * Parking marker: an approval step whose message_json carries everything
	 * the resume needs (the parked call + its remaining siblings).
	 *
	 * @param array{id:string,name:string,args:mixed} $call      Parked call.
	 * @param list<array<string,mixed>>               $remaining Sibling calls queued after it.
	 * @return array{seq:int,kind:'approval',message:array<string,mixed>,tool_name:string,approval_id:string|null,status:'parked'}
	 */
	private function appendApprovalStep( int $run_id, ToolOutcome $outcome, array $call, array $remaining ): array {
		$approval_id = $outcome->approvalId;
		$context     = array(
			'parked'           => true,
			'approval_id'      => $approval_id,
			'verb'             => $outcome->verb ?? $call['name'],
			'tier'             => $outcome->tier,
			'tool_name'        => $call['name'],
			'function_call_id' => $call['id'],
			'args'             => $call['args'] ?? array(),
			'remaining'        => $remaining,
		);

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::Approval,
			$context,
			$call['name'],
			$approval_id,
			'parked'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::Approval->value,
			'message'     => $context,
			'tool_name'   => $call['name'],
			'approval_id' => $outcome->approvalId,
			'status'      => 'parked',
		);
	}

	/**
	 * Park: write the marker, flip status, build the S6 ui payload.
	 *
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @param array{id:string,name:string,args:mixed} $call Parked call.
	 * @param list<array<string,mixed>> $remaining Remaining siblings.
	 * @return array{run:Run,ui:array<string,mixed>}
	 */
	private function park( Run $run, array &$new_steps, array $call, array $remaining, ToolOutcome $outcome ): array {
		$new_steps[] = $this->appendApprovalStep( $run->id, $outcome, $call, $remaining );
		$this->store->updateRun( $run->id, array( 'status' => RunStatus::AwaitingApproval->value ) );

		return array(
			'run' => $this->refresh( $run ),
			'ui'  => $this->approvalUi( $outcome, $call ),
		);
	}

	/**
	 * The S6 inline-approval card payload.
	 *
	 * @param array{id:string,name:string,args:mixed} $call Parked call.
	 * @return array{approval:array<string,mixed>}
	 */
	private function approvalUi( ToolOutcome $outcome, array $call ): array {
		return array(
			'approval' => array(
				'approval_id'  => $outcome->approvalId,
				'verb'         => $outcome->verb ?? $call['name'],
				'tier'         => $outcome->tier,
				'args_preview' => $call['args'] ?? array(),
				'review_url'   => function_exists( 'admin_url' )
					? admin_url( 'tools.php?page=agent-safety-pending' )
					: '',
			),
		);
	}

	// ------------------------------------------------------------------
	// Terminal transitions
	// ------------------------------------------------------------------

	/**
	 * Budget ceilings exhausted: mark the run failed.
	 *
	 * @return array<string,mixed> The harness-built partial report.
	 */
	private function failBudget( Run $run, string $which ): array {
		$this->store->updateRun(
			$run->id,
			array(
				'status'      => RunStatus::Failed->value,
				'error_json'  => array(
					'code'  => 'budget_exceeded',
					'which' => $which,
				),
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $this->report( $run->id );
	}

	/**
	 * Fatal/protocol error: mark the run failed.
	 *
	 * @return array<string,mixed> The harness-built partial report.
	 */
	private function failError( Run $run, string $code, string $message ): array {
		$this->store->updateRun(
			$run->id,
			array(
				'status'      => RunStatus::Failed->value,
				'error_json'  => array(
					'code'    => $code,
					'message' => $message,
				),
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $this->report( $run->id );
	}

	/**
	 * The model produced no function calls: the run completed.
	 *
	 * @return array<string,mixed> The harness-built report.
	 */
	private function complete( Run $run ): array {
		$this->store->updateRun(
			$run->id,
			array(
				'status'      => RunStatus::Completed->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return $this->report( $run->id );
	}

	// ------------------------------------------------------------------
	// Small helpers
	// ------------------------------------------------------------------

	private function mayTick( Run $run ): bool {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		/**
		 * Filters who may advance a run (delegation seam for later).
		 *
		 * @param bool $allowed Default: owner-only.
		 * @param Run  $run     The run.
		 */
		return (bool) apply_filters( 'senroflux_can_tick', $user_id === $run->userId, $run );
	}

	private function releaseLock( int $run_id ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( 'senroflux_lock_' . $run_id );
		}
	}

	private function refresh( Run $run ): Run {
		return $this->store->getRun( $run->id ) ?? $run;
	}

	private function addTokens( int $run_id, int $tokens_in, int $tokens_out ): void {
		$current = $this->store->getRun( $run_id );
		if ( null === $current ) {
			return;
		}

		$this->store->updateRun(
			$run_id,
			array(
				'tokens_in'  => $current->tokensIn + $tokens_in,
				'tokens_out' => $current->tokensOut + $tokens_out,
			)
		);
	}

	/**
	 * Unconsumed functionCall parts of the LAST model step: a call counts as
	 * consumed once ANY later tool_result step carries its FunctionResponse id
	 * (executed or explicitly rejected). Null means "nothing pending".
	 *
	 * @return list<array{id:string,name:string,args:mixed}>|null
	 */
	private function unconsumedCalls( Run $run ): ?array {
		$steps = $this->store->getSteps( $run->id );
		if ( array() === $steps ) {
			return null;
		}

		$model_step   = null;
		$consumed_ids = array();

		// Walk newest-first WITHOUT function calls in a for-loop test part
		// (WPCS): reverse the list once, then iterate.
		$reversed = array_reverse( $steps, false );
		foreach ( $reversed as $step ) {
			if ( StepKind::Model === $step->kind ) {
				$model_step = $step;

				break;
			}
			if ( StepKind::ToolResult === $step->kind && null !== $step->messageArray ) {
				foreach ( (array) ( $step->messageArray['parts'] ?? array() ) as $part ) {
					$id = $part['functionResponse']['id'] ?? null;
					if ( is_string( $id ) && '' !== $id ) {
						$consumed_ids[ $id ] = true;
					}
				}
			}
		}
		unset( $reversed );

		if ( null === $model_step || null === $model_step->messageArray ) {
			return null;
		}

		$calls = $this->extractCalls( Message::fromArray( $model_step->messageArray ) );

		$pending = array_values(
			array_filter(
				$calls,
				static fn ( array $call ): bool => '' !== $call['id']
					&& ! isset( $consumed_ids[ $call['id'] ] )
			)
		);

		return array() === $pending ? null : $pending;
	}

	/**
	 * Rebuild prompt history from history-bearing steps.
	 *
	 * @return list<Message>
	 */
	private function historyForPrompt( Run $run ): array {
		$messages = array();
		foreach ( $this->store->getSteps( $run->id ) as $step ) {
			if ( ! in_array( $step->kind, StepKind::historyKinds(), true ) ) {
				continue;
			}
			$message = $step->toMessage();
			if ( null !== $message ) {
				$messages[] = $message;
			}
		}

		return $messages;
	}

	/**
	 * The tick's system instruction (0.2 S8): the whole skill set is
	 * collected fresh each tick, rendered harness → pack → consumer, then the
	 * dynamic tail. The 0.1 filter survives as a POST-render final-string
	 * escape hatch — it can never inject or remove skills, only reshape the
	 * final text.
	 *
	 * WP_Error (skills_too_large) fails the run — S8 forbids truncation.
	 *
	 * The collection inputs are the SAME ones `start()` used: the run's pack
	 * (reached opaquely through the injected resolver, so the loop still knows
	 * nothing about packs) and the disable list persisted on the run. Dropping
	 * either here would show the model a different instruction from the one the
	 * ceiling was checked against and `skills_json` recorded.
	 *
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @return string|WP_Error
	 */
	private function instructionFor( Run $run, array &$new_steps ): string|WP_Error {
		$pack   = is_callable( $this->pack_resolver ) ? ( $this->pack_resolver )( $run ) : null;
		$skills = SkillSet::collect( $run->consumer, $run->goal, $pack, $run->skillsDisable );

		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		$text = InstructionRenderer::render( $skills, $this->tailFor( $run ) );

		/** This filter is documented in SPEC-SENROFLUX.md S8; post-render only. */
		$text = (string) apply_filters( 'senroflux_system_instruction', $text );

		$this->auditInstruction( $run, $skills, $text, $new_steps );

		return $text;
	}

	/**
	 * The dynamic tail (0.2 S8): remaining budgets from the run's own
	 * counters, rebuilt every tick. Refusal reminders, the verify nudge and
	 * the conversation-language line are appended by the stages that own
	 * them (S7, S12, S15).
	 */
	private function tailFor( Run $run ): Tail {
		$questions = 0;
		$plans     = 0;
		$tool_used = 0;
		foreach ( $this->store->getSteps( $run->id ) as $step ) {
			match ( $step->kind ) {
				StepKind::Question   => ++$questions,
				StepKind::Plan       => ++$plans,
				StepKind::ToolResult => $this->countsAsToolCall( $step ) ? ++$tool_used : null,
				default              => null,
			};
		}

		return new Tail(
			remaining_questions: max( 0, $run->budget['max_questions'] - $questions ),
			remaining_plans: max( 0, $run->budget['max_plans'] - $plans ),
			remaining_steps: max( 0, $run->budget['max_steps'] - $run->stepCount ),
			remaining_tool_calls: max( 0, $run->budget['max_tool_calls'] - $tool_used ),
			remaining_tokens: max( 0, $run->budget['max_tokens'] - $run->tokensIn - $run->tokensOut ),
			// S7: remind the model after a refused write so it fixes course.
			last_refusal: $this->lastRefusalCode( $run->id ),
			// S12: the re-read nudge is a system step the model never sees —
			// the tail is the only place it reaches the prompt at all.
			verify_objects: $this->verifyObjectTitles( $run ),
			// S15: the conversation language is fixed at start for the run's
			// life; a different admin answering a park never switches it.
			conversation_language: ( null !== $run->conversationLocale && '' !== $run->conversationLocale )
				? Tail::languageName( $run->conversationLocale )
				: null,
		);
	}

	/**
	 * S12: the outstanding written objects, named the way a human would name
	 * them ("Pricing (42)"), for the tail's re-read line. Null when nothing is
	 * outstanding, so the line is omitted entirely.
	 *
	 * The harness knows only opaque object ids; the human-readable name comes
	 * from the same injectable lookup the report uses, and a lookup that fails
	 * or knows nothing falls back to the bare id.
	 *
	 * @return list<string>|null
	 */
	private function verifyObjectTitles( Run $run ): ?array {
		$objects = is_array( $run->objects ) ? $run->objects : array();
		$ids     = Tracker::unverified( $objects );
		if ( array() === $ids ) {
			return null;
		}

		$lookup = is_callable( $this->post_lookup ) ? $this->post_lookup : Report::wpPostLookup();

		$titles = array();
		foreach ( $ids as $id ) {
			$title = '';
			try {
				$resolved = $lookup( $id );
				if ( is_array( $resolved ) && is_string( $resolved['title'] ?? null ) ) {
					$title = $resolved['title'];
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // Fail soft: a nameless object is still named by id.
			}

			$titles[] = '' !== $title ? $title . ' (' . $id . ')' : $id;
		}

		return $titles;
	}

	/**
	 * S8 audit: the seq-0 system step records the first render verbatim plus
	 * a per-skill body fingerprint; every later tick re-renders and compares
	 * fingerprints — a drift appends a `skills_changed` note and the run
	 * CONTINUES with the new text (never rewrites the audit record).
	 *
	 * The comparison baseline is the LATEST recorded fingerprint set (seq 0, or
	 * the newest `skills_changed` note), not the immutable seq-0 record: a drift
	 * that persists — a consumer filter that adds a skill for the rest of the
	 * run — is a single event, and re-recording it every tick would both spam
	 * the audit trail and burn one `max_steps` per tick until the run dies of
	 * budget.
	 *
	 * @param list<Skill>               $skills     Freshly collected set.
	 * @param list<array<string,mixed>> $new_steps  Accumulator.
	 */
	private function auditInstruction( Run $run, array $skills, string $text, array &$new_steps ): void {
		$fingerprints = array();
		foreach ( $skills as $skill ) {
			$fingerprints[ $skill->id ] = hash( 'sha256', $skill->body );
		}

		$recorded = $this->findInstructionRecord( $run->id );

		if ( null === $recorded ) {
			$this->store->prependSystemStep(
				$run->id,
				array(
					'note'   => 'system_instruction',
					'text'   => $text,
					'skills' => $fingerprints,
				)
			);
			return;
		}

		$stored  = $this->latestSkillFingerprints( $run->id );
		$changed = array();
		foreach ( $fingerprints as $id => $hash ) {
			if ( ( $stored[ $id ] ?? null ) !== $hash ) {
				$changed[] = $id;
			}
		}
		foreach ( array_keys( $stored ) as $id ) {
			if ( ! isset( $fingerprints[ $id ] ) && ! in_array( $id, $changed, true ) ) {
				$changed[] = $id;
			}
		}

		if ( array() !== $changed ) {
			// The note carries the NEW fingerprints as well as the changed ids:
			// it becomes the baseline the next tick compares against, so a
			// stable drift is recorded exactly once.
			$payload = array(
				'note'   => 'skills_changed',
				'ids'    => $changed,
				'skills' => $fingerprints,
			);

			$new_steps[] = array(
				'seq'         => $this->store->appendSystemNote( $run->id, $payload ),
				'kind'        => StepKind::System->value,
				'message'     => $payload,
				'tool_name'   => null,
				'approval_id' => null,
				'status'      => 'ok',
			);
		}
	}

	/**
	 * The newest recorded per-skill fingerprint map: the seq-0
	 * `system_instruction` record, superseded by any later `skills_changed`
	 * note. Empty when nothing has been recorded yet.
	 *
	 * @return array<string,string>
	 */
	private function latestSkillFingerprints( int $run_id ): array {
		$latest = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::System !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			$note = $step->messageArray['note'] ?? null;
			if ( 'system_instruction' !== $note && 'skills_changed' !== $note ) {
				continue;
			}
			$skills = $step->messageArray['skills'] ?? null;
			if ( is_array( $skills ) ) {
				/** @var array<string,string> $skills */
				$latest = $skills;
			}
		}

		return $latest;
	}

	/**
	 * The seq-0 instruction record's message payload, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findInstructionRecord( int $run_id ): ?array {
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::System !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			if ( 'system_instruction' === ( $step->messageArray['note'] ?? null ) ) {
				return $step->messageArray;
			}
		}

		return null;
	}

	/**
	 * Extract functionCall parts from a model message.
	 *
	 * @return list<array{id:string,name:string,args:mixed}>
	 */
	private function extractCalls( Message $message ): array {
		$calls = array();
		foreach ( $message->getParts() as $part ) {
			$function_call = $part->getFunctionCall();
			if ( $function_call instanceof FunctionCall ) {
				$calls[] = array(
					'id'   => (string) ( $function_call->getId() ?? '' ),
					'name' => (string) $function_call->getName(),
					'args' => $function_call->getArgs(),
				);
			}
		}

		return $calls;
	}

	/**
	 * One tool call to its outcome (ability name mapped back from wpab__ form).
	 *
	 * The allow-list is enforced here, not only when declaring tools to the
	 * model: a hallucinated or injected call outside the run's registry is
	 * `unknown_tool` and never reaches the executor (S5).
	 *
	 * @param array{id:string,name:string,args:mixed} $call Call shape.
	 */
	private function executeCall( ToolRegistry $registry, array $call ): ToolOutcome {
		$name = ToolRegistry::abilityName( $call['name'] );
		if ( ! $registry->admits( $name ) ) {
			return ToolOutcome::unknownTool( $name );
		}
		// Pass the model's args through verbatim: an EMPTY args object must
		// stay an empty array, because core validates it against the ability's
		// input schema — null fails a type:object schema (breaking both the
		// first execution and the approved resume re-run of no-arg abilities),
		// while an empty array validates as the empty object the model sent.
		// Null is sent only when the call carried no args at all, which is the
		// shape abilities WITHOUT an input schema accept.
		$args = $call['args'] ?? null;

		return $this->executor->call(
			$name,
			is_array( $args ) ? $args : null
		);
	}

	/**
	 * Latest parked context from the newest approval step.
	 *
	 * @return array<string,mixed>|null
	 */
	private function latestParkedContext( int $run_id ): ?array {
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Approval !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			if ( true === ( $step->messageArray['parked'] ?? false ) ) {
				return $step->messageArray;
			}
		}

		return null;
	}

	/**
	 * Assemble the RunState DTO.
	 *
	 * @param list<array<string,mixed>> $new_steps Steps appended this tick.
	 * @param array<string,mixed>|null  $ui        UI payload (approval etc.).
	 * @return array{run:array<string,mixed>,new_steps:list<array<string,mixed>>,ui:array<string,mixed>}
	 */
	private function state( Run $run, array $new_steps, ?array $ui ): array {
		return array(
			'run'       => array(
				'id'         => $run->id,
				'user_id'    => $run->userId,
				'consumer'   => $run->consumer,
				'goal'       => $run->goal,
				'status'     => $run->status->value,
				'step_count' => $run->stepCount,
				'tokens_in'  => $run->tokensIn,
				'tokens_out' => $run->tokensOut,
				'error'      => $run->error,
			),
			'new_steps' => $new_steps,
			'ui'        => $ui ?? array(),
		);
	}

	/**
	 * Function-name form for a stored ability name (S4 mapping).
	 */
	private static function functionNameFor( string $ability_name ): string {
		if ( str_starts_with( $ability_name, 'wpab__' ) ) {
			return $ability_name;
		}

		return 'wpab__' . str_replace( '/', '__', $ability_name );
	}

	// ------------------------------------------------------------------
	// S6: ask-the-user park
	// ------------------------------------------------------------------

	/**
	 * remaining_questions = max_questions − count(question steps), floored.
	 */
	private function remainingQuestions( Run $run ): int {
		$used = 0;
		foreach ( $this->store->getSteps( $run->id ) as $step ) {
			if ( StepKind::Question === $step->kind ) {
				++$used;
			}
		}

		return HarnessTools::remaining( (int) ( $run->budget['max_questions'] ?? 0 ), $used );
	}

	/**
	 * Handle a `senroflux__ask-user` call. Returns array{ 'park': array{run,ui} }
	 * when it parked (ends the tick); otherwise it has appended an error
	 * tool_result (invalid payload / questions exhausted) and returns array().
	 *
	 * @param array{id:string,name:string,args:mixed} $call Call shape.
	 * @param list<array<string,mixed>>               $new_steps Accumulator.
	 * @return array<string,mixed>
	 */
	private function runAskUser( Run $run, array $call, array &$new_steps ): array {
		if ( 0 >= $this->remainingQuestions( $run ) ) {
			// Withdrawn, yet still called: fail closed, count as a tool call.
			$new_steps[] = $this->appendAskUserError( $run->id, $call, HarnessTools::ERROR_QUESTIONS_EXHAUSTED );

			return array();
		}

		$payload = HarnessTools::validateAskUser( $call['args'] ?? null );
		if ( is_wp_error( $payload ) ) {
			$new_steps[] = $this->appendAskUserError( $run->id, $call, HarnessTools::ERROR_INVALID_QUESTION );

			return array();
		}

		// Valid: park. message_json holds the VALIDATED payload (S4); the
		// harness tool name goes in tool_name (S4). This step is NOT history.
		$new_steps[] = $this->appendQuestionStep( $run->id, $call, $payload );
		$this->store->updateRun( $run->id, array( 'status' => RunStatus::AwaitingUser->value ) );

		// S6: ui.question is the only park key on a question park.
		return array(
			'park' => array(
				'run' => $this->refresh( $run ),
				'ui'  => array(
					'question' => $this->questionUi(
						$payload,
						$this->latestQuestionStepId( $run->id ),
						$this->remainingQuestions( $run ), // includes this parked question
						$run->id
					),
				),
			),
		);
	}

	/**
	 * Resume an awaiting_user run with an answer/skip (or re-surface it).
	 *
	 * @param Run                       $run       Snapshot.
	 * @param array<string,mixed>|null  $resume    Park resolution (null = re-surface).
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @return array{run:Run,ui:array<string,mixed>}|WP_Error|null
	 *         array = still parked (re-surfaced); WP_Error = 400; null = loop may proceed.
	 */
	private function resumeFromQuestion( Run $run, ?array $resume, array &$new_steps ): array|WP_Error|null {
		$context = $this->latestQuestionContext( $run->id );
		if ( null === $context ) {
			// Corrupted state: fail explicitly rather than looping ungoverned.
			$report = $this->failError( $run, 'missing_question_context', 'No parked question for an awaiting_user run.' );

			return array(
				'run' => $this->refresh( $run ),
				'ui'  => array( 'report' => $report ),
			);
		}

		if ( null === $resume || array() === $resume ) {
			// No decision yet: remain parked, re-surfacing the question card.
			return array(
				'run' => $this->refresh( $run ),
				'ui'  => array(
					'question' => $this->questionUi(
						$context['payload'],
						$context['step_id'],
						$this->remainingQuestions( $run ),
						$run->id
					),
				),
			);
		}

		$payload = $context['payload'];
		$call_id = (string) ( $context['call_id'] ?? '' );

		if ( array_key_exists( 'skip', $resume ) ) {
			$this->appendAnsweredBy( $run, $new_steps );
			$new_steps[] = $this->appendAskUserResult( $run->id, $call_id, array( 'skipped' => true ) );
			$this->store->updateRun( $run->id, array( 'status' => RunStatus::Running->value ) );

			return null;
		}

		$answer = $resume['answer'];
		$text   = is_string( $answer['text'] ?? null ) ? $answer['text'] : '';
		$choice = is_string( $answer['choice'] ?? null ) ? $answer['choice'] : '';

		// S6: a choice not in the stored choices is a 400. Resume::check cannot
		// see the question's choices, so the check lives here.
		if ( '' !== $choice && ! in_array( $choice, (array) ( $payload['choices'] ?? array() ), true ) ) {
			return new WP_Error(
				'choice_not_offered',
				__( 'That answer is not one of the offered choices.', 'senroflux' ),
				array( 'status' => 400 )
			);
		}

		$this->appendAnsweredBy( $run, $new_steps );
		$new_steps[] = $this->appendAskUserResult(
			$run->id,
			$call_id,
			array(
				'answer' => $text,
				'choice' => $choice,
			)
		);
		$this->store->updateRun( $run->id, array( 'status' => RunStatus::Running->value ) );

		return null;
	}

	/**
	 * S6 acting-user rule: when the human answering is NOT the run's owner
	 * (a delegated admin), the run records who answered before the answer
	 * itself. A no-op when the owner answers their own run.
	 *
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 */
	private function appendAnsweredBy( Run $run, array &$new_steps ): void {
		$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $actor === $run->userId ) {
			return;
		}

		$payload = array(
			'note'    => 'answered_by',
			'user_id' => $actor,
		);

		$new_steps[] = array(
			'seq'         => $this->store->appendSystemNote( $run->id, $payload ),
			'kind'        => StepKind::System->value,
			'message'     => $payload,
			'tool_name'   => null,
			'approval_id' => null,
			'status'      => 'ok',
		);
	}

	/**
	 * Append a question park step: message_json = the VALIDATED payload.
	 *
	 * @param array{id:string,name:string,args:mixed} $call    Parked call.
	 * @param array<string,mixed>                     $payload Validated payload.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendQuestionStep( int $run_id, array $call, array $payload ): array {
		unset( $call );
		$seq = $this->store->appendStep(
			$run_id,
			StepKind::Question,
			$payload,
			HarnessTools::toolName(),
			null,
			'parked'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::Question->value,
			'message'     => $payload,
			'tool_name'   => HarnessTools::toolName(),
			'approval_id' => null,
			'status'      => 'parked',
		);
	}

	/**
	 * Tool_result for the ask-user answer (or skip). The FunctionResponse
	 * NAME is the harness function name, NOT `functionNameFor()` — that would
	 * map `senroflux/ask-user` to `wpab__senroflux__ask-user` and desync from
	 * the call id the model used.
	 *
	 * @param string              $call_id  The originating ask-user id (for crash-resume).
	 * @param array<string,mixed> $response FunctionResponse payload.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendAskUserResult( int $run_id, string $call_id, array $response ): array {
		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call_id ? $call_id : null,
							HarnessTools::functionName(),
							$response
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			HarnessTools::toolName(),
			null,
			'ok'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::ToolResult->value,
			'message'     => $message_array,
			'tool_name'   => HarnessTools::toolName(),
			'approval_id' => null,
			'status'      => 'ok',
		);
	}

	/**
	 * Tool_result error to the model for an invalid/exhausted ask-user call.
	 * Counts as a tool call (the caller bumps the counter).
	 *
	 * @param array{id:string,name:string,args:mixed} $call Call shape.
	 * @param string                                  $code invalid_question | questions_exhausted.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendAskUserError( int $run_id, array $call, string $code ): array {
		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call['id'] ? $call['id'] : null,
							HarnessTools::functionName(),
							array( 'error' => $code )
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			HarnessTools::toolName(),
			null,
			'error'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::ToolResult->value,
			'message'     => $message_array,
			'tool_name'   => HarnessTools::toolName(),
			'approval_id' => null,
			'status'      => 'error',
		);
	}

	/**
	 * The S6 question card payload.
	 *
	 * @param array<string,mixed> $payload   Validated payload.
	 * @param int                 $step_id   The question step's seq.
	 * @param int                 $remaining Questions left (minus this parked one).
	 * @param int                 $run_id    For the run-detail review URL.
	 * @return array<string,mixed>
	 */
	private function questionUi( array $payload, int $step_id, int $remaining, int $run_id ): array {
		return array(
			'step_id'     => $step_id,
			'text'        => (string) ( $payload['text'] ?? '' ),
			'choices'     => (array) ( $payload['choices'] ?? array() ),
			'allow_other' => (bool) ( $payload['allow_other'] ?? true ),
			'default'     => (string) ( $payload['default'] ?? '' ),
			'rationale'   => (string) ( $payload['rationale'] ?? '' ),
			'remaining'   => $remaining,
			'review_url'  => function_exists( 'admin_url' )
				? admin_url( 'tools.php?page=senroflux-runs&run=' . (int) $run_id )
				: '',
		);
	}

	/**
	 * Latest parked question context (validated payload + step seq + the
	 * originating ask-user call id).
	 *
	 * @return array{payload:array<string,mixed>,step_id:int,call_id:?string}|null
	 */
	private function latestQuestionContext( int $run_id ): ?array {
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Question === $step->kind && null !== $step->messageArray ) {
				return array(
					'payload' => $step->messageArray,
					'step_id' => (int) $step->seq,
					'call_id' => $this->latestAskUserCallId( $run_id ),
				);
			}
		}

		return null;
	}

	/**
	 * The ask-user call id of the newest model step (the parked message).
	 * The question step's message_json stores only the validated payload (S4),
	 * so the call id is recovered from the model turn for the FunctionResponse.
	 */
	private function latestAskUserCallId( int $run_id ): ?string {
		return $this->unansweredCallId( $run_id, HarnessTools::functionName() );
	}

	/**
	 * The id of the newest model step's first call to `$function_name` that no
	 * tool_result has answered yet.
	 *
	 * The unconsumed check is what makes two harness calls in ONE model message
	 * work: without it the first id is returned again after it has been
	 * answered, so every later answer is written against a call the model
	 * already has a response for, the second question is re-served forever, and
	 * the run only ends when its question budget runs out.
	 */
	private function unansweredCallId( int $run_id, string $function_name ): ?string {
		$consumed = $this->consumedCallIds( $run_id );

		// Newest model step first — that is the parked message. (Steps are
		// ordered by seq ASC; the park's model step carries the harness call.)
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Model !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			foreach ( $this->extractCalls( Message::fromArray( $step->messageArray ) ) as $call ) {
				if ( $function_name === $call['name'] && ! isset( $consumed[ $call['id'] ] ) ) {
					return (string) $call['id'];
				}
			}
			break; // Only the newest model step is the parked message.
		}

		return null;
	}

	/**
	 * Every function-call id some tool_result step has already answered
	 * (executed, refused or explicitly rejected).
	 *
	 * @return array<string,true>
	 */
	private function consumedCallIds( int $run_id ): array {
		$consumed = array();
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::ToolResult !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			foreach ( (array) ( $step->messageArray['parts'] ?? array() ) as $part ) {
				$id = $part['functionResponse']['id'] ?? null;
				if ( is_string( $id ) && '' !== $id ) {
					$consumed[ $id ] = true;
				}
			}
		}

		return $consumed;
	}

	/** Seq of the newest question step, or 0. */
	private function latestQuestionStepId( int $run_id ): int {
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Question === $step->kind ) {
				return (int) $step->seq;
			}
		}

		return 0;
	}

	// ------------------------------------------------------------------
	// S7: plan park + fence
	// ------------------------------------------------------------------

	/**
	 * remaining_plans = max_plans − count(plan steps), floored at 0.
	 */
	private function remainingPlans( Run $run ): int {
		$used = 0;
		foreach ( $this->store->getSteps( $run->id ) as $step ) {
			if ( StepKind::Plan === $step->kind ) {
				++$used;
			}
		}

		return PlanTools::remaining( (int) ( $run->budget['max_plans'] ?? 0 ), $used );
	}

	/** Count of persisted plan steps (the ceiling-rejection probe). */
	private function countPlanSteps( int $run_id ): int {
		$count = 0;
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::Plan === $step->kind ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Handle a `senroflux__propose-plan` call. Returns array{ 'park': array{run,ui} }
	 * when it parked (ends the tick); otherwise it has appended an error
	 * tool_result (invalid payload / plans exhausted) and returns array().
	 *
	 * @param array{id:string,name:string,args:mixed} $call Call shape.
	 * @param list<array<string,mixed>>               $new_steps Accumulator.
	 * @return array<string,mixed>
	 */
	private function runProposePlan( Run $run, array $call, array &$new_steps ): array {
		if ( 0 >= $this->remainingPlans( $run ) ) {
			// Withdrawn, yet still called: fail closed, count as a tool call.
			$new_steps[] = $this->appendPlanError( $run->id, $call, PlanTools::ERROR_PLANS_EXHAUSTED );

			return array();
		}

		$payload = PlanTools::validateProposePlan( $call['args'] ?? null, $run->id );
		if ( is_wp_error( $payload ) ) {
			$new_steps[] = $this->appendPlanError( $run->id, $call, PlanTools::ERROR_INVALID_PLAN );

			return array();
		}

		// Valid: park. message_json holds the VALIDATED (tier-annotated) payload
		// (S4); the harness tool name goes in tool_name. This step is NOT history.
		$new_steps[] = $this->appendPlanStep( $run->id, $call, $payload );
		$this->store->updateRun( $run->id, array( 'status' => RunStatus::AwaitingPlan->value ) );

		// ui is keyed 'plan' (S7: ui.plan = {...}); exactly one park key is
		// present when parked.
		return array(
			'park' => array(
				'run' => $this->refresh( $run ),
				'ui'  => array(
					'plan' => $this->planUi(
						$payload,
						$this->latestPlanStepId( $run->id ),
						$this->remainingPlans( $run ), // includes this parked plan
						$run->id
					),
				),
			),
		);
	}

	/**
	 * Resume an awaiting_plan run with accept / accept_preapprove / veto (or
	 * re-surface the card). S7 acting-user rule as S6: a delegated (non-owner)
	 * human records an `answered_by` system step first.
	 *
	 * @param Run                       $run       Snapshot.
	 * @param array<string,mixed>|null  $resume    Park resolution (null = re-surface).
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @return array{run:Run,ui:array<string,mixed>}|WP_Error|null
	 *         array = cancelled / still parked; WP_Error = 400; null = loop may proceed.
	 */
	private function resumeFromPlan( Run $run, ?array $resume, array &$new_steps ): array|WP_Error|null {
		$context = $this->latestPlanContext( $run->id );
		if ( null === $context ) {
			// Corrupted state: fail explicitly rather than looping ungoverned.
			$report = $this->failError( $run, 'missing_plan_context', 'No parked plan for an awaiting_plan run.' );

			return array(
				'run' => $this->refresh( $run ),
				'ui'  => array( 'report' => $report ),
			);
		}

		if ( null === $resume || array() === $resume ) {
			// No decision yet: remain parked, re-surfacing the plan card (S7).
			return array(
				'run' => $this->refresh( $run ),
				'ui'  => array(
					'plan' => $this->planUi(
						$context['payload'],
						$context['step_id'],
						$this->remainingPlans( $run ),
						$run->id
					),
				),
			);
		}

		// Resume::check already guaranteed exactly { plan: { action, note? } }.
		$action  = (string) $resume['plan']['action'];
		$note    = is_string( $resume['plan']['note'] ?? null ) ? $resume['plan']['note'] : '';
		$call_id = (string) ( $context['call_id'] ?? '' );

		if ( 'accept_preapprove' === $action ) {
			// S7: accepted only when the filter is on AND grants (S14) exist.
			// Stage 4 has no grants, so this always 400s — fail closed, nothing
			// persisted (checked BEFORE the acting-user/result steps).
			if ( ! $this->preapprovalEnabled() ) {
				return new WP_Error(
					'preapproval_disabled',
					__( 'Pre-approval is not enabled for this site.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}
			// Stage 11 wires grants here (per distinct Tier-2 verb in the plan).
		}

		// S7 acting-user rule (as S6) — only once we know the action proceeds.
		$this->appendAnsweredBy( $run, $new_steps );

		if ( 'veto' === $action ) {
			$new_steps[] = $this->appendPlanResult(
				$run->id,
				$call_id,
				array(
					'accepted' => false,
					'mode'     => 'veto',
					'note'     => $note,
				)
			);
			$this->store->updateRun(
				$run->id,
				array(
					'status'                => RunStatus::Running->value,
					'accepted_plan_step_id' => null,
				)
			);

			if ( $this->countPlanSteps( $run->id ) >= (int) $run->budget['max_plans'] ) {
				// S7: exceeded the plan ceiling on a veto — reject the plan.
				$this->store->updateRun(
					$run->id,
					array(
						'status'      => RunStatus::Cancelled->value,
						'error_json'  => array( 'code' => 'plan_rejected' ),
						'finished_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);

				// S12: EVERY terminal transition builds and returns the report,
				// cancellation included — a run that ends without one leaves the
				// consumer with no record of what it changed before the veto.
				$report = $this->report( $run->id );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => array( 'report' => $report ),
				);
			}

			return null; // Model re-plans.
		}

		// accept (or the enabled accept_preapprove): carry the plan forward.
		$new_steps[] = $this->appendPlanResult(
			$run->id,
			$call_id,
			array(
				'accepted' => true,
				'mode'     => $action,
				'note'     => $note,
			)
		);
		$this->store->updateRun(
			$run->id,
			array(
				'status'                => RunStatus::Running->value,
				'accepted_plan_step_id' => $context['step_id'],
			)
		);

		return null;
	}

	/**
	 * Append a plan park step: message_json = the VALIDATED payload.
	 *
	 * @param array{id:string,name:string,args:mixed} $call    Parked call.
	 * @param array<string,mixed>                     $payload Validated payload.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendPlanStep( int $run_id, array $call, array $payload ): array {
		unset( $call );
		$seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			$payload,
			PlanTools::toolName(),
			null,
			'parked'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::Plan->value,
			'message'     => $payload,
			'tool_name'   => PlanTools::toolName(),
			'approval_id' => null,
			'status'      => 'parked',
		);
	}

	/**
	 * Tool_result for a plan accept/veto. The FunctionResponse NAME is the
	 * harness function name, NOT `functionNameFor()` (same rule as the ask-user
	 * answer path) so the id stays aligned with the call the model made.
	 *
	 * @param string              $call_id  The originating propose-plan id (for crash-resume).
	 * @param array<string,mixed> $response FunctionResponse payload.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendPlanResult( int $run_id, string $call_id, array $response ): array {
		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call_id ? $call_id : null,
							PlanTools::functionName(),
							$response
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			PlanTools::toolName(),
			null,
			'ok'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::ToolResult->value,
			'message'     => $message_array,
			'tool_name'   => PlanTools::toolName(),
			'approval_id' => null,
			'status'      => 'ok',
		);
	}

	/**
	 * Tool_result error to the model for an invalid/exhausted propose-plan call.
	 * Counts as a tool call (the caller bumps the counter).
	 *
	 * @param array{id:string,name:string,args:mixed} $call Call shape.
	 * @param string                                  $code invalid_plan | plans_exhausted.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendPlanError( int $run_id, array $call, string $code ): array {
		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call['id'] ? $call['id'] : null,
							PlanTools::functionName(),
							array( 'error' => $code )
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			PlanTools::toolName(),
			null,
			'error'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::ToolResult->value,
			'message'     => $message_array,
			'tool_name'   => PlanTools::toolName(),
			'approval_id' => null,
			'status'      => 'error',
		);
	}

	/**
	 * Fence refusal tool_result for an ABILITY call: the FunctionResponse NAME
	 * is the ability's function name (functionNameFor), matching the call, so
	 * crash-resume sees it consumed. NEVER counted as a tool call.
	 *
	 * @param array{id:string,name:string,args:mixed} $call Call shape.
	 * @param string                                  $code plan_required | not_in_plan.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendFenceRefusal( int $run_id, array $call, string $code ): array {
		$message_array = (
			new UserMessage(
				array(
					new MessagePart(
						new FunctionResponse(
							'' !== $call['id'] ? $call['id'] : null,
							self::functionNameFor( $call['name'] ),
							array( 'error' => $code )
						)
					),
				)
			)
		)->toArray();

		$seq = $this->store->appendStep(
			$run_id,
			StepKind::ToolResult,
			$message_array,
			$call['name'],
			null,
			'error'
		);

		return array(
			'seq'         => $seq,
			'kind'        => StepKind::ToolResult->value,
			'message'     => $message_array,
			'tool_name'   => $call['name'],
			'approval_id' => null,
			'status'      => 'error',
		);
	}

	/**
	 * S7 fence: refuse (return a code) or allow (return null) one ability call.
	 *
	 * A call is fenced by its VERB, and the verb is not always the ability name:
	 * one ability can span several verbs of different tiers depending on its
	 * arguments. Resolving ability + args => verb is domain knowledge, so it
	 * arrives as an injected resolver ({@see $verb_resolver}) rather than
	 * anything this file understands. With no resolver — a direct-allow run,
	 * which has no pack — the ability name IS the verb (S9).
	 *
	 * The tier then comes from {@see VerbTier} against the run's verb map,
	 * never from the model. Tier-0 reads are free before the plan; a Tier-1+
	 * call needs an accepted plan that contains the verb.
	 *
	 * @param ToolRegistry                             $registry The run's tool surface.
	 * @param array{id:string,name:string,args:mixed}  $call     Call shape.
	 * @return string|null 'plan_required' | 'not_in_plan' | null (allow).
	 */
	private function fenceRefusal( ToolRegistry $registry, Run $run, array $call ): ?string {
		$ability = ToolRegistry::abilityName( (string) $call['name'] );

		// An unadmitted call is unknown_tool, not a fence case — the allow-list
		// verdict (downstream in executeCall) must win over the fence.
		if ( ! $registry->admits( $ability ) ) {
			return null;
		}

		$verb = $this->verbFor( $run, $ability, $call['args'] ?? null );

		// S9: a pack resolves the verb map for its own runs; direct-allow runs
		// fall back to the site-wide senroflux_verb_map filter (stage 4 seam).
		$tier = VerbTier::tierFor( $verb, $this->packVerbMap( $run ), $run->id );

		if ( $tier < VerbTier::TIER_1 ) {
			return null; // Tier-0 reads are free before and inside the plan.
		}

		$accepted = $this->acceptedPlan( $run );
		if ( null === $accepted ) {
			return 'plan_required';
		}

		if ( ! in_array( $verb, $this->planVerbSet( $accepted ), true ) ) {
			return 'not_in_plan';
		}

		return null;
	}

	/**
	 * The verb one ability call is fenced as, through the injected resolver.
	 *
	 * Fail closed on a resolver that misbehaves: a non-string answer (or none)
	 * leaves the ability name standing, which no pack verb map answers, so
	 * {@see VerbTier} tiers the call 2 and the fence demands a plan.
	 *
	 * @param Run    $run     The run.
	 * @param string $ability The concrete ability id.
	 * @param mixed  $args    The raw call args.
	 */
	private function verbFor( Run $run, string $ability, mixed $args ): string {
		if ( ! is_callable( $this->verb_resolver ) ) {
			return $ability;
		}

		/** @var array<string,mixed> $call_args */
		$call_args = is_array( $args ) ? $args : array();
		$verb      = ( $this->verb_resolver )( $run, $ability, $call_args );

		return is_string( $verb ) && '' !== $verb ? $verb : $ability;
	}

	/**
	 * The accepted plan's verb set = the union of every step's verbs.
	 *
	 * @param array<string,mixed> $payload The validated plan payload.
	 * @return list<string>
	 */
	private function planVerbSet( array $payload ): array {
		$verbs = array();
		foreach ( (array) ( $payload['steps'] ?? array() ) as $step ) {
			foreach ( (array) ( $step['verbs'] ?? array() ) as $verb ) {
				if ( is_string( $verb ) && '' !== $verb ) {
					$verbs[ $verb ] = true;
				}
			}
		}

		return array_keys( $verbs );
	}

	/**
	 * The accepted plan's stored payload, or null when no plan is accepted (or
	 * the accepted step is missing/corrupt — fail closed to plan_required).
	 *
	 * @return array<string,mixed>|null
	 */
	private function acceptedPlan( Run $run ): ?array {
		if ( null === $run->acceptedPlanStepId ) {
			return null;
		}

		foreach ( $this->store->getSteps( $run->id ) as $step ) {
			if ( StepKind::Plan === $step->kind
				&& $step->seq === $run->acceptedPlanStepId
				&& null !== $step->messageArray ) {
				return $step->messageArray;
			}
		}

		return null;
	}

	/**
	 * Is this persisted tool_result a real budget consumer? False for a harness
	 * answer (ask-user/propose-plan `ok`) and for an S7 fence refusal.
	 */
	private function countsAsToolCall( Step $step ): bool {
		if ( 'ok' === $step->status
			&& ( HarnessTools::toolName() === $step->toolName
				|| PlanTools::toolName() === $step->toolName ) ) {
			return false;
		}

		return ! $this->isFenceRefusal( $step );
	}

	/**
	 * A fence refusal tool_result: status 'error' whose response error is
	 * plan_required or not_in_plan.
	 */
	private function isFenceRefusal( Step $step ): bool {
		if ( 'error' !== $step->status || null === $step->messageArray ) {
			return false;
		}

		return in_array(
			$this->toolResultErrorCode( $step->messageArray ),
			array( 'plan_required', 'not_in_plan' ),
			true
		);
	}

	/**
	 * The `error` code in a tool_result step's FunctionResponse, or null.
	 *
	 * @param array<string,mixed> $message_array Stored message shape.
	 */
	private function toolResultErrorCode( array $message_array ): ?string {
		foreach ( (array) ( $message_array['parts'] ?? array() ) as $part ) {
			$response = $part['functionResponse']['response'] ?? null;
			if ( is_array( $response ) && isset( $response['error'] ) && is_string( $response['error'] ) ) {
				return $response['error'];
			}
		}

		return null;
	}

	/**
	 * The most recent plan-fence refusal code, or null.
	 */
	private function lastRefusalCode( int $run_id ): ?string {
		$steps = $this->store->getSteps( $run_id );
		if ( array() === $steps ) {
			return null;
		}

		$last = $steps[ count( $steps ) - 1 ];
		if ( StepKind::ToolResult !== $last->kind || null === $last->messageArray ) {
			return null;
		}

		$code = $this->toolResultErrorCode( $last->messageArray );

		return in_array( $code, array( 'plan_required', 'not_in_plan' ), true ) ? $code : null;
	}

	/**
	 * The S7 plan card payload.
	 *
	 * @param array<string,mixed> $payload   Validated (tier-annotated) payload.
	 * @param int                 $step_id   The plan step's seq.
	 * @param int                 $remaining Plans left (minus this parked one).
	 * @param int                 $run_id    For the run-detail review URL.
	 * @return array<string,mixed>
	 */
	private function planUi( array $payload, int $step_id, int $remaining, int $run_id ): array {
		return array(
			'step_id'              => $step_id,
			'goal'                 => (string) ( $payload['goal'] ?? '' ),
			'steps'                => (array) ( $payload['steps'] ?? array() ),
			'assumptions'          => (array) ( $payload['assumptions'] ?? array() ),
			'remaining_plans'      => $remaining,
			'preapprove_available' => $this->preapprovalEnabled(),
			'review_url'           => function_exists( 'admin_url' )
				? admin_url( 'tools.php?page=senroflux-runs&run=' . (int) $run_id )
				: '',
		);
	}

	/**
	 * Latest parked plan context (validated payload + step seq + the
	 * originating propose-plan call id).
	 *
	 * @return array{payload:array<string,mixed>,step_id:int,call_id:?string}|null
	 */
	private function latestPlanContext( int $run_id ): ?array {
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Plan === $step->kind && null !== $step->messageArray ) {
				return array(
					'payload' => $step->messageArray,
					'step_id' => (int) $step->seq,
					'call_id' => $this->latestProposePlanCallId( $run_id ),
				);
			}
		}

		return null;
	}

	/**
	 * The propose-plan call id of the newest model step (the parked message).
	 * The plan step's message_json stores only the validated payload (S4), so
	 * the call id is recovered from the model turn for the FunctionResponse.
	 */
	private function latestProposePlanCallId( int $run_id ): ?string {
		return $this->unansweredCallId( $run_id, PlanTools::functionName() );
	}

	/** Seq of the newest plan step, or 0. */
	private function latestPlanStepId( int $run_id ): int {
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Plan === $step->kind ) {
				return (int) $step->seq;
			}
		}

		return 0;
	}

	/**
	 * Is pre-approval offerable? Only when the `senroflux_enable_preapproval`
	 * filter is true AND the Agent Safety grants service exists (S14). Stage 4
	 * wires neither, so this is false and an accept_preapprove resume 400s.
	 */
	private function preapprovalEnabled(): bool {
		if ( ! (bool) apply_filters( 'senroflux_enable_preapproval', false ) ) {
			return false;
		}

		return function_exists( 'agent_safety' ) && method_exists( agent_safety(), 'grants' );
	}

	// ------------------------------------------------------------------
	// S12: verification read-back + harness-built report
	// ------------------------------------------------------------------

	// ------------------------------------------------------------------
	// S12: verification nudge + terminal report
	// ------------------------------------------------------------------

	/**
	 * Build + persist the terminal report (S12).
	 *
	 * Public so Plugin::cancel() can build a partial report on a user-initiated
	 * terminal transition that never passes through the loop (see
	 * Plugin.diff.md §2).
	 *
	 * @return array{summary:string,changes:list<array<string,mixed>>}
	 */
	public function report( int $run_id ): array {
		$fresh   = $this->store->getRun( $run_id );
		$objects = ( null !== $fresh && is_array( $fresh->objects ) ) ? $fresh->objects : array();
		$report  = Report::build( $this->latestModelText( $run_id ), $objects, $this->post_lookup );

		$this->store->updateRun( $run_id, array( 'result_json' => $report ) );

		return $report;
	}

	/**
	 * S12: fold one tool_result into the written-object set.
	 *
	 * Scoped by the call's TIER, which is the only thing that makes the set a
	 * record of CHANGES:
	 *   - Tier ≥ 1 (a write): the returned object id opens a change and
	 *     re-opens verification. A tier-0 read that happens to echo an id is
	 *     not a change and must never appear in the report.
	 *   - Tier 0 (a read): an already-tracked object id in the call's INPUT
	 *     records the verification. Restricting this to reads is the point —
	 *     accepting any successful call carrying the id would let the model
	 *     attest to its own verification by passing the id to a write.
	 *
	 * The id key itself is resolved per verb ({@see $object_id_key_resolver}),
	 * defaulting to `id`, so a pack whose read takes `post_id` still verifies
	 * without the harness learning what a post is.
	 *
	 * A missing/corrupt objects_json is fail-closed to an empty set and never
	 * blocks the tool_result.
	 *
	 * @param Run                                     $run     The run.
	 * @param array{id:string,name:string,args:mixed} $call    Originating call.
	 * @param ToolOutcome                             $outcome Tool outcome.
	 */
	private function trackObjects( Run $run, array $call, ToolOutcome $outcome, int $seq ): void {
		if ( 'result' !== $outcome->kind ) {
			return;
		}

		$current = $this->store->getRun( $run->id );
		$before  = ( null !== $current && is_array( $current->objects ) ) ? $current->objects : array();
		$objects = $before;

		$verb = $this->verbFor( $run, ToolRegistry::abilityName( (string) $call['name'] ), $call['args'] ?? null );
		$tier = VerbTier::tierFor( $verb, $this->packVerbMap( $run ), $run->id );
		$key  = $this->objectIdKeyFor( $run, $verb );

		if ( $tier >= VerbTier::TIER_1 ) {
			$write_id = self::objectIdIn( $outcome->output ?? array(), $key );
			if ( null !== $write_id ) {
				$objects = Tracker::recordWrite( $objects, $write_id, $seq );
			}
		} elseif ( VerbTier::TIER_0 === $tier ) {
			$args    = $call['args'] ?? null;
			$read_id = is_array( $args ) ? self::objectIdIn( $args, $key ) : null;
			if ( null !== $read_id && array_key_exists( $read_id, $objects ) ) {
				$objects = Tracker::recordVerification( $objects, $read_id, $seq );
			}
		}

		if ( $objects !== $before ) {
			$this->store->updateRun( $run->id, array( 'objects_json' => $objects ) );
		}
	}

	/**
	 * The object-id key for one verb: whatever the injected resolver answers,
	 * else S12's default `id`. A resolver that misbehaves falls back to `id`
	 * rather than tracking nothing.
	 */
	private function objectIdKeyFor( Run $run, string $verb ): string {
		if ( ! is_callable( $this->object_id_key_resolver ) ) {
			return 'id';
		}

		$key = ( $this->object_id_key_resolver )( $run, $verb );

		return is_string( $key ) && '' !== $key ? $key : 'id';
	}

	/**
	 * The object id at `$key` in a result output or a call's args, normalised
	 * to a non-empty string; null when absent or unusable.
	 *
	 * @param array<string,mixed> $source Output or args map.
	 */
	private static function objectIdIn( array $source, string $key ): ?string {
		$value = $source[ $key ] ?? null;

		if ( is_int( $value ) ) {
			return (string) $value;
		}

		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}

	/**
	 * The run's pack verb map, or null when it has none (direct-allow: the
	 * site-wide `senroflux_verb_map` filter answers instead).
	 *
	 * @return array<string,int>|null
	 */
	private function packVerbMap( Run $run ): ?array {
		$pack_map = is_callable( $this->verb_map_resolver ) ? ( $this->verb_map_resolver )( $run ) : null;

		/** @var array<string,int>|null */
		return is_array( $pack_map ) ? $pack_map : null;
	}

	/**
	 * S12: decide whether a zero-call model turn completes or is parked by a
	 * verify nudge.
	 *
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @return bool True when a nudge was appended (the tick ends, keep running);
	 *              false when the run may complete.
	 */
	private function finishAttempt( Run $run, array &$new_steps ): bool {
		$fresh   = $this->refresh( $run );
		$objects = is_array( $fresh->objects ) ? $fresh->objects : array();

		$unverified = Tracker::unverified( $objects );
		if ( array() === $unverified ) {
			return false; // Every write was read back: complete.
		}

		$nudge_seq = $this->latestVerifyNudgeSeq( $run->id );
		if ( null !== $nudge_seq && ! $this->newWriteAfterNudge( $objects, $nudge_seq ) ) {
			// A nudge already covers this state: this is the SECOND finish
			// attempt — complete anyway; the objects stay unverified.
			return false;
		}

		// First finish (or a post-new-write finish) attempt: append the nudge
		// and keep the run running so the model re-reads before finishing.
		$payload = array(
			'note'    => 'verify_nudge',
			'objects' => $unverified,
		);

		$new_steps[] = array(
			'seq'         => $this->store->appendSystemNote( $run->id, $payload ),
			'kind'        => StepKind::System->value,
			'message'     => $payload,
			'tool_name'   => null,
			'approval_id' => null,
			'status'      => 'ok',
		);

		return true;
	}

	/**
	 * Seq of the newest verify_nudge system step, or null.
	 */
	private function latestVerifyNudgeSeq( int $run_id ): ?int {
		$seq = null;
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::System !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			if ( 'verify_nudge' === ( $step->messageArray['note'] ?? null ) ) {
				$seq = (int) $step->seq;
			}
		}

		return $seq;
	}

	/**
	 * Did any tracked object get written at a step AFTER the nudge?
	 *
	 * @param array<string,mixed> $objects The objects_json map.
	 */
	private function newWriteAfterNudge( array $objects, int $nudge_seq ): bool {
		foreach ( $objects as $entry ) {
			if ( is_array( $entry ) && isset( $entry['last_write_seq'] ) && (int) $entry['last_write_seq'] > $nudge_seq ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The newest model step's text parts joined ('' when none).
	 *
	 * @return string
	 */
	private function latestModelText( int $run_id ): string {
		$text = '';
		foreach ( $this->store->getSteps( $run_id ) as $step ) {
			if ( StepKind::Model !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			$text = $this->joinedText( $step->messageArray );
		}

		return $text;
	}

	/**
	 * Join the text parts of a stored message shape.
	 *
	 * @param array<string,mixed> $message_array Canonical Message::toArray() shape.
	 */
	private function joinedText( array $message_array ): string {
		$parts_text = array();
		foreach ( (array) ( $message_array['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['type'] ) && 'text' === $part['type'] && is_string( $part['text'] ?? null ) ) {
				$parts_text[] = $part['text'];
			}
		}

		return implode( '', $parts_text );
	}
}
