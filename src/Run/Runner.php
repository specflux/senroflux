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
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolOutcome;
use Specflux\SenroFlux\Tools\ToolRegistry;
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
			if ( $run->status->isTerminal() ) {
				return $this->state( $run, array(), null );
			}

			$new_steps = array();

			// S5: a park resolution only makes sense on a parked run — a
			// pending/running/terminal run carrying one is a protocol error,
			// never a hint to be ignored.
			if ( null !== $resume && ! $run->status->isParked() ) {
				return new WP_Error(
					'resume_mismatch',
					__( 'This run is not waiting on a human; it accepts no park resolution.', 'senroflux' ),
					array( 'status' => 400 )
				);
			}

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
							// S7 fills this in (stage 4); no run can reach the
							// plan park before then.
							return new WP_Error(
								'senroflux_park_unimplemented',
								__( 'Plan parks resolve from stage 4 (S7) onward.', 'senroflux' ),
								array( 'status' => 400 )
							);
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
					// AwaitingPlan with no resolution: nothing to re-surface
					// until the S7 plan card exists.
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
			$this->failError( $run, 'missing_approval_context', 'No parked context for an awaiting_approval run.' );

			return array(
				'run'   => $this->refresh( $run ),
				'steps' => $new_steps,
				'ui'    => null,
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
			$outcome = $this->executeCall( ToolRegistry::forRun( $run ), $call );

			if ( 'approval_required' === $outcome->kind ) {
				return array(
					'run'   => $this->refresh( $run ),
					'steps' => $new_steps,
					'ui'    => $this->approvalUi( $outcome, $call ),
				);
			}

			$new_steps[] = $this->appendToolResult( run_id: $run->id, call: $call, outcome: $outcome );
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

		// S6: the harness tool is in EVERY run's declarations while a question
		// remains, withdrawn at zero. It is not an ability, so it is merged
		// onto the permission-agnostic declaration surface only.
		$harness_declarations = HarnessTools::declarations( $this->remainingQuestions( $run ) );
		if ( array() !== $harness_declarations ) {
			$registry = $registry->withDeclarations( $harness_declarations );
		}

		// S8: the whole system instruction is rendered per tick, audited at
		// seq 0, and a skills ceiling breach fails the run (never truncation).
		$instruction = $this->instructionFor( $run, $new_steps );
		if ( is_wp_error( $instruction ) ) {
			$this->failError( $run, (string) $instruction->get_error_code(), $instruction->get_error_message() );

			return array(
				'run' => $this->refresh( $run ),
				'ui'  => null,
			);
		}

		$tool_calls_used = 0;
		foreach ( $this->store->getSteps( $run->id ) as $existing_step ) {
			if ( StepKind::ToolResult === $existing_step->kind ) {
				// S6: a harness ANSWER (the ok tool_result to a parked ask) is
				// NOT a tool-call consumer — the ask-user round-trip is
				// metered by max_questions, not max_tool_calls. An
				// invalid/exhausted ask-user is status 'error' and DOES count.
				if ( HarnessTools::toolName() === $existing_step->toolName && 'ok' === $existing_step->status ) {
					continue;
				}
				++$tool_calls_used;
			}
		}

		while ( true ) {
			if ( $run->stepCount >= $run->budget['max_steps'] ) {
				$this->failBudget( $run, 'max_steps' );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => null,
				);
			}
			if ( $run->tokensIn + $run->tokensOut >= $run->budget['max_tokens'] ) {
				$this->failBudget( $run, 'max_tokens' );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => null,
				);
			}

			$pending_calls = $this->unconsumedCalls( $run );

			if ( null === $pending_calls ) {
				$history = $this->historyForPrompt( $run );
				$turn    = $this->gateway->generateTurn( $history, $instruction, $registry );

				if ( $turn instanceof WP_Error ) {
					$this->failError( $run, 'model_error', $turn->get_error_message() );

					return array(
						'run' => $this->refresh( $run ),
						'ui'  => null,
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
					$this->complete( $run );

					return array(
						'run' => $this->refresh( $run ),
						'ui'  => null,
					);
				}
			}

			foreach ( $pending_calls as $index => $call ) {
				if ( $tool_calls_used >= $run->budget['max_tool_calls'] ) {
					$this->failBudget( $run, 'max_tool_calls' );

					return array(
						'run' => $this->refresh( $run ),
						'ui'  => null,
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

				$outcome = $this->executeCall( $registry, $call );
				++$tool_calls_used;

				if ( 'approval_required' === $outcome->kind ) {
					return $this->park( $run, $new_steps, $call, $remaining, $outcome );
				}

				$new_steps[] = $this->appendToolResult( $run->id, $call, $outcome );
				$run         = $this->refresh( $run );
				// Errors/denials flow back to the model as error text; it decides.
			}

			if ( $run->stepCount >= $run->budget['max_steps'] ) {
				$this->failBudget( $run, 'max_steps' );

				return array(
					'run' => $this->refresh( $run ),
					'ui'  => null,
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
	 * @param array{id:string,name:string,args:mixed} $call Originating call.
	 * @return array{seq:int,kind:string,message:array<string,mixed>,tool_name:string,status:string}
	 */
	private function appendToolResult( int $run_id, array $call, ToolOutcome $outcome ): array {
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

	private function failBudget( Run $run, string $which ): void {
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
	}

	private function failError( Run $run, string $code, string $message ): void {
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
	}

	private function complete( Run $run ): void {
		$this->store->updateRun(
			$run->id,
			array(
				'status'      => RunStatus::Completed->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
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
	 * @param list<array<string,mixed>> $new_steps Accumulator.
	 * @return string|WP_Error
	 */
	private function instructionFor( Run $run, array &$new_steps ): string|WP_Error {
		$skills = SkillSet::collect( $run->consumer, $run->goal );

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
				StepKind::ToolResult => ++$tool_used,
				default              => null,
			};
		}

		return new Tail(
			remaining_questions: max( 0, $run->budget['max_questions'] - $questions ),
			remaining_plans: max( 0, $run->budget['max_plans'] - $plans ),
			remaining_steps: max( 0, $run->budget['max_steps'] - $run->stepCount ),
			remaining_tool_calls: max( 0, $run->budget['max_tool_calls'] - $tool_used ),
			remaining_tokens: max( 0, $run->budget['max_tokens'] - $run->tokensIn - $run->tokensOut )
		);
	}

	/**
	 * S8 audit: the seq-0 system step records the first render verbatim plus
	 * a per-skill body fingerprint; every later tick re-renders and compares
	 * fingerprints — a drift appends a `skills_changed` note and the run
	 * CONTINUES with the new text (never rewrites the audit record).
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

		$stored  = (array) ( $recorded['skills'] ?? array() );
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
			$new_steps[] = array(
				'seq'         => $this->store->appendSystemNote(
					$run->id,
					array(
						'note' => 'skills_changed',
						'ids'  => $changed,
					)
				),
				'kind'        => StepKind::System->value,
				'message'     => array(
					'note' => 'skills_changed',
					'ids'  => $changed,
				),
				'tool_name'   => null,
				'approval_id' => null,
				'status'      => 'ok',
			);
		}
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
			$this->failError( $run, 'missing_question_context', 'No parked question for an awaiting_user run.' );

			return array(
				'run' => $this->refresh( $run ),
				'ui'  => array(),
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
		// Newest model step first — that is the parked message. (Steps are
		// ordered by seq ASC; the park's model step carries the ask-user call.)
		foreach ( array_reverse( $this->store->getSteps( $run_id ) ) as $step ) {
			if ( StepKind::Model !== $step->kind || null === $step->messageArray ) {
				continue;
			}
			foreach ( $this->extractCalls( Message::fromArray( $step->messageArray ) ) as $call ) {
				if ( HarnessTools::functionName() === $call['name'] ) {
					return (string) $call['id'];
				}
			}
			break; // Only the newest model step is the parked message.
		}

		return null;
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
}
