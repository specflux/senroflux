/**
 * Pure helpers for the Runs screen (S10). No WordPress or DOM API calls here
 * so every one of these is unit-testable without a browser.
 */

/** Parked statuses: the run is waiting on a human answer of some kind. */
const PARKED_STATUSES = [ 'awaiting_approval', 'awaiting_user', 'awaiting_plan' ];

/** Terminal statuses: nothing more will happen to this run. */
const TERMINAL_STATUSES = [ 'completed', 'failed', 'cancelled' ];

/** Is this run's status one that means "waiting on a human"? */
export function isParkedStatus( status ) {
	return PARKED_STATUSES.includes( status );
}

/** Is this run's status terminal (completed/failed/cancelled)? */
export function isTerminalStatus( status ) {
	return TERMINAL_STATUSES.includes( status );
}

/**
 * Compare two run ids for identity, tolerant of type. `WP_Scripts::
 * localize()` (core `wp_localize_script()`) casts every scalar value to a
 * STRING before JSON-encoding it, so `window.senrofluxRunsConfig.
 * initialRunId` always arrives in JS as a string (e.g. `"1"`), while every
 * `run.id` in a REST/ajax JSON payload is a genuine JSON number (`1`). A
 * strict `===`/`!==` comparison between a run id seeded from config and one
 * that came back from the server silently treats the SAME run as a
 * different one forever — a live-review defect (S10, 17d): the re-render
 * after resolving a park was silently dropped for any run opened via a
 * deep link (`?run_id=`). Every place `App.js` decides "is this update for
 * the run that's still selected" goes through this helper instead of a raw
 * comparison, so no future id source can reintroduce the same class of bug.
 */
export function sameRunId( a, b ) {
	if ( null === a || undefined === a || null === b || undefined === b ) {
		return a === b;
	}
	return String( a ) === String( b );
}

/**
 * The four S10 tabs, in order. Each predicate is the SINGLE source of truth
 * for both the tab's count and the rows it shows — a count computed any other
 * way could drift from the list it claims to describe (a named live-review
 * finding).
 */
export const RUN_TABS = [
	{
		key: 'needs_you',
		label: 'Needs you',
		predicate: ( run ) => Boolean( run.viewer_may_tick ) && isParkedStatus( run.status ),
	},
	{
		// Everything active that is not this viewer's own park: `pending`
		// (a run has been started but has not ticked yet — it belongs here,
		// not in a fourth invisible bucket), `running`, and a park belonging
		// to someone else. This predicate plus `needs_you` and `finished`
		// must partition every `RunStatus` case exactly once — a live-review
		// finding was a `pending` run that landed in NONE of the three tabs
		// and only showed up in "All" (see live-review-findings tests).
		key: 'running',
		label: 'Running',
		predicate: ( run ) =>
			! isTerminalStatus( run.status ) &&
			! ( Boolean( run.viewer_may_tick ) && isParkedStatus( run.status ) ),
	},
	{
		key: 'finished',
		label: 'Finished',
		predicate: ( run ) => isTerminalStatus( run.status ),
	},
	{
		key: 'all',
		label: 'All',
		predicate: () => true,
	},
];

/** Runs matching a tab key, using the SAME predicate the count is derived from. */
export function runsForTab( runs, tabKey ) {
	const tab = RUN_TABS.find( ( t ) => t.key === tabKey );
	if ( ! tab ) {
		return [];
	}
	return runs.filter( tab.predicate );
}

/** `{ [tabKey]: count }` for every tab, each computed from `runsForTab`. */
export function tabCounts( runs ) {
	const counts = {};
	RUN_TABS.forEach( ( tab ) => {
		counts[ tab.key ] = runsForTab( runs, tab.key ).length;
	} );
	return counts;
}

/**
 * Group a run's steps into ledger entries for the chat stream.
 *
 * Consecutive `tool_result` steps collapse into one ledger group of
 * "N actions". A real run's step kinds alternate `model, tool_result, model,
 * tool_result, ...`, because each tool call is itself preceded by a `model`
 * step that only carries the function-call request, with no user-visible
 * prose — a live-review finding (stage-17a) found this literal-adjacency
 * rule NEVER fires against a real run, rendering six consecutive "1 action"
 * rows instead of one "6 actions" row. So a `model` step with no prose
 * (`stepText() === ''`) is transparent to grouping: it neither breaks a run
 * of `tool_result`s nor is emitted as its own bubble. A `model` step that
 * DOES carry prose still breaks the group and renders as its own entry, same
 * as before. Anything else (goal, an UNresolved park, a report) also still
 * breaks the group. An `approval`
 * step that already carries its resolution is folded INTO the ledger group
 * too (S10: "a resolved approval is marked on its call"), rather than
 * appearing as its own bubble, because the very next `tool_result` step is
 * that same call re-run after the click.
 *
 * A `tool_result` step immediately preceded (in the RAW step order) by an
 * `approval` step is marked `approvedByViewer: true` on its call entry — S10
 * gives approvals no separate "approved" flag on the tool_result row itself,
 * so this is derived from adjacency, the only signal the payload carries.
 *
 * @param {Array} steps Step rows as returned by `GET /senroflux/v1/runs/{id}`.
 * @return {Array} A list of `{ type: 'ledger', calls: [...] }` or
 *                 `{ type: 'step', step }` entries, in original order.
 */
export function groupSteps( steps ) {
	const entries = [];
	let group = [];

	const flush = () => {
		if ( group.length > 0 ) {
			entries.push( { type: 'ledger', calls: group } );
			group = [];
		}
	};

	let previousKind = null;
	steps.forEach( ( step ) => {
		if ( 'tool_result' === step.kind ) {
			group.push( {
				step,
				approvedByViewer: 'approval' === previousKind && 'ok' === step.status,
			} );
			previousKind = step.kind;
			return;
		}
		if ( 'approval' === step.kind && step.status && 'parked' !== step.status ) {
			// A resolved approval step (a reject leaves the approval step
			// itself at 'parked' and appends its OWN rejected tool_result
			// instead) folds into the ledger rather than opening a new bubble.
			previousKind = step.kind;
			return;
		}
		if ( 'model' === step.kind && '' === stepText( step ) ) {
			// A call-only model step (the function-call request itself, no
			// prose): transparent to the ledger, must not break a run of
			// tool_result groups either side of it.
			previousKind = step.kind;
			return;
		}
		flush();
		entries.push( { type: 'step', step } );
		previousKind = step.kind;
	} );
	flush();

	return entries;
}

/**
 * The prose text carried by a `model` (or `user`) step, when its message has
 * any. The stored shape is a php-ai-client `Message`/`MessagePart` array
 * (`{ parts: [{ text: '...' } | { functionCall: {...} } | ...] }`); a step
 * whose parts are ALL calls (no text part) returns ''. `[assumed]`: 0.2's
 * server-rendered screen never displayed model prose as a bubble (it only
 * ever dumped the raw step JSON in a `<details>`), so this shape is read
 * directly from the AI-client `Message`/`MessagePart` contract rather than
 * ported from an existing renderer — flagged in the stage-17a report.
 */
export function stepText( step ) {
	if ( ! step.message || 'object' !== typeof step.message || ! Array.isArray( step.message.parts ) ) {
		return '';
	}
	return step.message.parts
		.filter( ( part ) => part && 'string' === typeof part.text )
		.map( ( part ) => part.text )
		.join( '\n' );
}

/** The verb/ability id carried by a step, whichever shape produced it. */
export function stepVerb( step ) {
	if ( step.message && 'object' === typeof step.message && step.message.verb ) {
		return step.message.verb;
	}
	return step.tool_name || '';
}

/**
 * The tier for a step, when the payload carries one (only approval-kind
 * steps do — S10 does not have a per-call tier annotation for an ordinary,
 * un-parked tool_result, so this returns `null` rather than guessing).
 */
export function stepTier( step ) {
	// 0.3 S23: `Plugin::get()` now lifts the tier onto the step itself
	// (`step.tier`) for every kind, including a plain `tool_result` that ran
	// without parking — the pre-S23 payload only had it nested inside an
	// `approval` step's own message. Prefer the top-level field; fall back to
	// `message.tier` for any payload shape that still only carries it there.
	if ( Number.isInteger( step.tier ) ) {
		return step.tier;
	}
	if ( step.message && 'object' === typeof step.message && Number.isInteger( step.message.tier ) ) {
		return step.message.tier;
	}
	return null;
}

/**
 * Best-effort per-plan-step progress for the pinned plan card.
 *
 * S10 asks for "live per-step state and counts (10 / 21)". The REST payload
 * does not tag a `tool_result` step with the plan-step index it belongs to
 * (there is no `ps` field like the prototype's canned data used), so this
 * matches each plan step's declared verbs against executed tool_result verbs
 * IN ORDER, greedily consuming one execution per expected verb occurrence.
 * This is an approximation, not an authoritative index — flagged as such in
 * the stage-17a report. It degrades gracefully: a mismatch only ever under-
 * or over-counts a step's progress, it never throws or blocks rendering.
 *
 * @param {Object} plan  The plan message payload (`{ steps: [{ verbs }] }`).
 * @param {Array}  steps All of the run's steps, in order.
 * @return {Array} One entry per plan step: `{ done, total, active, waiting }`.
 */
export function planProgress( plan, steps ) {
	let lastPlan = -1;
	steps.forEach( ( step, index ) => {
		if ( 'plan' === step.kind ) {
			lastPlan = index;
		}
	} );
	// A tool_result records the verb its plan names it by (`plan_verb`);
	// older rows only carry the function name.
	const executedVerbs = steps
		.slice( lastPlan + 1 )
		.filter( ( step ) => 'tool_result' === step.kind && 'ok' === step.status )
		.map( ( step ) => ( step.message && step.message.plan_verb ) || stepVerb( step ) );

	let cursor = 0;
	const parkedVerb = ( () => {
		const openApproval = [ ...steps ].reverse().find( ( step ) => 'approval' === step.kind && 'parked' === step.status );
		return openApproval ? stepVerb( { message: openApproval.message } ) : null;
	} )();

	return ( plan.steps || [] ).map( ( planStep ) => {
		const verbs = Array.isArray( planStep.verbs ) ? planStep.verbs : [];
		let done = 0;
		verbs.forEach( ( verb ) => {
			// Search forward: unplanned calls in between, or a planned verb
			// that never ran, must not stall the steps after it.
			const found = executedVerbs.indexOf( verb, cursor );
			if ( -1 !== found ) {
				done++;
				cursor = found + 1;
			}
		} );
		return {
			done,
			total: verbs.length,
			waiting: null !== parkedVerb && verbs.includes( parkedVerb ) && done < verbs.length,
		};
	} );
}

/**
 * How many approvals a plan implies (S7/S10), so the plan card discloses
 * this BEFORE the human accepts it, not step by step as the run goes.
 *
 * Built-in mode: every verb OCCURRENCE that is itself Tier >= 1, across the
 * whole plan — a step naming three Tier >= 1 verbs parks three times, once
 * per call. A verb's own tier comes from the step's `verb_tiers` map; the
 * step-level `tier` is only the MAX over its verbs, so counting every verb of
 * a step by it over-counted (live J4: five steps of [Tier 0, Tier 1, Tier 0]
 * verbs told the approver "15" when exactly 5 approvals happened). A verb
 * with no tier of its own (a plan stored before `verb_tiers` existed) falls
 * back to the step's `tier`, and with neither it is treated as Tier 2 — fail
 * closed, the same rule `VerbTier::tierFor()` uses server-side.
 *
 * Agent Safety mode: no count at all — S3's built-in-only approval count has
 * no AS-mode equivalent here (a Tier-2 verb's own tier badge already
 * discloses it per call), so this returns `null` and the caller renders
 * nothing.
 *
 * @param {Object} plan     The plan message payload (`{ steps: [{ verbs, tier, verb_tiers }] }`).
 * @param {string} gateMode 'agent_safety' | 'built_in'.
 * @return {number|null} The approval count in built-in mode, else `null`.
 */
export function planApprovalCount( plan, gateMode ) {
	if ( 'built_in' !== gateMode ) {
		return null;
	}

	const FAIL_CLOSED_TIER = 2;
	const THRESHOLD = 1;

	return ( plan.steps || [] ).reduce( ( total, step ) => {
		const verbs = Array.isArray( step.verbs ) ? step.verbs : [];
		const verbTiers = step.verb_tiers && 'object' === typeof step.verb_tiers ? step.verb_tiers : {};
		const stepTier = Number.isInteger( step.tier ) ? step.tier : FAIL_CLOSED_TIER;
		const qualifying = verbs.filter( ( verb ) => {
			const tier = Number.isInteger( verbTiers[ verb ] ) ? verbTiers[ verb ] : stepTier;
			return tier >= THRESHOLD;
		} );
		return total + qualifying.length;
	}, 0 );
}

/**
 * A human-readable label for a ledger row, derived from the raw ability id.
 *
 * A live-review finding: the ledger showed the raw mangled ability id
 * (`wpab__senroflux__read-content`) as its own label, with nothing readable
 * next to it. S10 asks for BOTH a readable label and the ability id — the id
 * stays visible (`stepVerb()`, rendered separately in `LedgerGroup`), this
 * only derives the readable half. The mcp-adapter/Abilities-API id shape is
 * `wpab__<namespace>__<ability-slug>` (WordPress ability ids are namespaced
 * with a double underscore, mangled again with `wpab__` for MCP tool-name
 * rules that forbid `/`); this takes the LAST `__`-separated segment (the
 * ability slug) and turns its dashes/underscores into spaces, Sentence case.
 * A verb that doesn't match the shape (no `__`) is returned as-is rather
 * than guessed at.
 *
 * `utils.js` deliberately makes no WordPress calls (no `@wordpress/i18n`), so
 * it cannot translate the "no verb at all" fallback itself — the caller
 * supplies the already-translated label via `fallbackLabel`
 * ({@see components/LedgerGroup.js}). Omitting `fallbackLabel` (e.g. from a
 * unit test) falls back to this untranslated sentinel rather than throwing.
 */
export function stepLabel( step, fallbackLabel ) {
	const verb = stepVerb( step );
	if ( ! verb ) {
		return undefined !== fallbackLabel ? fallbackLabel : 'Tool call';
	}
	const segments = verb.split( '__' );
	const slug = segments[ segments.length - 1 ];
	if ( ! slug || slug === verb ) {
		return verb;
	}
	const words = slug.replace( /[-_]+/g, ' ' ).trim();
	if ( ! words ) {
		return verb;
	}
	return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
}

/**
 * The distinct labels of a ledger group's calls, in first-seen order, so a
 * collapsed group's summary never hides a write between reads.
 *
 * @param {Array}  calls         `{ step }` entries from `groupSteps`.
 * @param {string} fallbackLabel Label for a call with no verb.
 * @return {Array<string>} Labels.
 */
export function ledgerLabels( calls, fallbackLabel ) {
	return [ ...new Set( calls.map( ( call ) => stepLabel( call.step, fallbackLabel ) ) ) ];
}

/**
 * The plain-text result shown under a ledger row.
 *
 * Rejected calls are NOT handled here: `LedgerGroup.js` branches on
 * `call.step.status === 'rejected'` and renders its own translated
 * "Rejected by you, not done" span BEFORE ever calling `stepResult()` — this
 * function's only call site only reaches it in the non-rejected branch (see
 * `components/LedgerGroup.js`), so a `status === 'rejected'` case here would
 * be dead code. Kept out rather than translated.
 */
export function stepResult( step ) {
	if ( step.message && 'object' === typeof step.message && Array.isArray( step.message.parts ) ) {
		const part = step.message.parts.find( ( p ) => p && p.functionResponse );
		if ( part ) {
			const response = part.functionResponse.response;
			if ( response && 'object' === typeof response && 'string' === typeof response.error ) {
				return response.error;
			}
			try {
				return JSON.stringify( response );
			} catch ( e ) {
				return '';
			}
		}
	}
	return '';
}

/**
 * The store report the run produced on demand (J13), if any: the newest
 * successful `store-report` tool result, in the output shape
 * `senroflux/store-report` declares. `save-store-report` is a different
 * ability (it writes a page) and never counts. Returns null when the run made
 * none, or the result does not have the expected shape.
 *
 * @param {Array} steps The run's steps.
 * @return {?Object} The report output, or null.
 */
export function storeReportFromSteps( steps ) {
	for ( let i = ( steps || [] ).length - 1; i >= 0; i-- ) {
		const step = steps[ i ];
		if ( 'tool_result' !== step.kind || 'ok' !== step.status ) {
			continue;
		}
		if ( ! /(^|__|\/)store-report$/.test( step.tool_name || '' ) ) {
			continue;
		}
		const part =
			step.message && Array.isArray( step.message.parts )
				? step.message.parts.find( ( p ) => p && p.functionResponse )
				: null;
		const response = part ? part.functionResponse.response : null;
		if ( response && 'object' === typeof response && Number.isInteger( response.order_count ) ) {
			return response;
		}
	}
	return null;
}

/** `{ total, notChecked }` over a report's change rows. */
export function reportCounts( report ) {
	const changes = report && Array.isArray( report.changes ) ? report.changes : [];
	return {
		total: changes.length,
		notChecked: changes.filter( ( change ) => ! change.verified ).length,
	};
}
