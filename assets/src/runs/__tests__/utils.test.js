import { RUN_TABS, runsForTab, tabCounts, groupSteps, planProgress, stepLabel, stepResult } from '../utils';

// Every RunStatus case (src/Run/RunStatus.php), kept in sync by hand: a
// future status added there and not here would silently fall through the
// partition property test below exactly like the `pending` live-review
// finding did.
const ALL_RUN_STATUSES = [
	'pending',
	'running',
	'awaiting_approval',
	'awaiting_user',
	'awaiting_plan',
	'completed',
	'failed',
	'cancelled',
];

const run = ( overrides ) => ( {
	id: 1,
	status: 'running',
	viewer_may_tick: false,
	goal: 'Do a thing',
	...overrides,
} );

describe( 'tab counts match the list they filter', () => {
	const runs = [
		run( { id: 1, status: 'awaiting_approval', viewer_may_tick: true } ), // needs_you
		run( { id: 2, status: 'awaiting_user', viewer_may_tick: false } ), // parked, NOT this viewer's to tick
		run( { id: 3, status: 'running' } ), // running
		run( { id: 4, status: 'completed' } ), // finished
		run( { id: 5, status: 'cancelled' } ), // finished
	];

	it.each( RUN_TABS.map( ( tab ) => tab.key ) )(
		'the %s tab count equals the length of the rows it filters to',
		( tabKey ) => {
			const counts = tabCounts( runs );
			const rows = runsForTab( runs, tabKey );
			expect( counts[ tabKey ] ).toBe( rows.length );
		}
	);

	it( 'needs_you only counts PARKED runs the viewer may tick', () => {
		const counts = tabCounts( runs );
		expect( counts.needs_you ).toBe( 1 );
		expect( runsForTab( runs, 'needs_you' ).map( ( r ) => r.id ) ).toEqual( [ 1 ] );
	} );

	it( 'all always equals the full list', () => {
		expect( tabCounts( runs ).all ).toBe( runs.length );
	} );
} );

describe( 'the three visible tabs partition every RunStatus case (live-review finding: a `pending` run appeared in NONE of them)', () => {
	const partitionTabs = [ 'needs_you', 'running', 'finished' ];

	it.each( ALL_RUN_STATUSES.flatMap( ( status ) => [
		[ status, true ],
		[ status, false ],
	] ) )( 'status=%s, viewer_may_tick=%s lands in exactly one of needs_you/running/finished', ( status, viewerMayTick ) => {
		const candidate = run( { id: 99, status, viewer_may_tick: viewerMayTick } );
		const matches = partitionTabs.filter( ( key ) => runsForTab( [ candidate ], key ).length === 1 );
		expect( matches ).toHaveLength( 1 );
	} );

	it( 'needs_you + running + finished always sums to all, over every status', () => {
		const runs = ALL_RUN_STATUSES.map( ( status, index ) =>
			run( { id: index, status, viewer_may_tick: index % 2 === 0 } )
		);
		const counts = tabCounts( runs );
		expect( counts.needs_you + counts.running + counts.finished ).toBe( counts.all );
	} );
} );

describe( 'consecutive tool calls collapse into one ledger group', () => {
	it( 'groups tool_result steps across call-only model steps that carry no prose, and breaks on a model step WITH prose', () => {
		const steps = [
			{ kind: 'user', seq: 1 },
			{ kind: 'model', seq: 2 }, // call-only: no message/text, precedes a tool call
			{ kind: 'tool_result', seq: 3, status: 'ok', tool_name: 'core/get-site-info' },
			{ kind: 'model', seq: 4 }, // call-only: also no prose
			{ kind: 'tool_result', seq: 5, status: 'ok', tool_name: 'core/content-query' },
			{ kind: 'model', seq: 6, message: { parts: [ { text: 'Here is a summary for you.' } ] } }, // has prose: breaks the group
			{ kind: 'model', seq: 7 },
			{ kind: 'tool_result', seq: 8, status: 'ok', tool_name: 'senroflux/create-page' },
		];

		const entries = groupSteps( steps );

		expect( entries.map( ( e ) => e.type ) ).toEqual( [
			'step', // user
			'ledger', // 2 grouped tool calls, the call-only models between/around them are invisible
			'step', // model WITH prose
			'ledger', // 1 tool call
		] );
		expect( entries[ 1 ].calls ).toHaveLength( 2 );
		expect( entries[ 3 ].calls ).toHaveLength( 1 );
	} );

	it( 'a REALISTIC alternating model/tool_result run (live-review finding) collapses to one ledger group, not six', () => {
		// The exact shape that hid the bug: real runs alternate
		// model, tool_result, model, tool_result, ... with the model steps
		// carrying only the function-call request. The old adjacent-
		// tool_result rule never fired against this shape at all.
		const steps = [ { kind: 'user', seq: 0 } ];
		[ 'core/get-site-info', 'core/content-query', 'senroflux/read-content', 'senroflux/update-page', 'core/content-query', 'senroflux/publish-page' ].forEach(
			( tool_name, index ) => {
				steps.push( { kind: 'model', seq: index * 2 + 1 } );
				steps.push( { kind: 'tool_result', seq: index * 2 + 2, status: 'ok', tool_name } );
			}
		);

		const entries = groupSteps( steps );

		expect( entries.map( ( e ) => e.type ) ).toEqual( [ 'step', 'ledger' ] );
		expect( entries[ 1 ].calls ).toHaveLength( 6 );
	} );

	it( 'marks a tool_result that immediately follows a resolved approval as approved by the viewer', () => {
		const steps = [
			{ kind: 'approval', status: 'resolved' },
			{ kind: 'tool_result', status: 'ok', tool_name: 'senroflux/publish-page' },
		];

		const entries = groupSteps( steps );

		expect( entries ).toHaveLength( 1 );
		expect( entries[ 0 ].calls[ 0 ].approvedByViewer ).toBe( true );
	} );

	it( 'does NOT mark a tool_result as approved when nothing preceded it', () => {
		const steps = [ { kind: 'tool_result', status: 'ok', tool_name: 'core/get-site-info' } ];
		const entries = groupSteps( steps );
		expect( entries[ 0 ].calls[ 0 ].approvedByViewer ).toBe( false );
	} );
} );

describe( 'planProgress', () => {
	it( 'never throws on a plan with no steps', () => {
		expect( () => planProgress( { steps: [] }, [] ) ).not.toThrow();
	} );

	it( 'counts executed calls against each plan step in order', () => {
		const plan = {
			steps: [
				{ text: 'Create draft', verbs: [ 'senroflux/create-page' ] },
				{ text: 'Write + re-read', verbs: [ 'senroflux/update-page', 'core/content-query' ] },
			],
		};
		const steps = [
			{ kind: 'tool_result', status: 'ok', tool_name: 'senroflux/create-page' },
			{ kind: 'tool_result', status: 'ok', tool_name: 'senroflux/update-page' },
		];

		const progress = planProgress( plan, steps );
		expect( progress[ 0 ] ).toMatchObject( { done: 1, total: 1 } );
		expect( progress[ 1 ] ).toMatchObject( { done: 1, total: 2 } );
	} );
} );

describe( 'stepLabel derives a readable label, distinct from the raw ability id (live-review finding)', () => {
	it( 'turns a mangled wpab__ ability id into a Sentence case label', () => {
		expect( stepLabel( { tool_name: 'wpab__senroflux__read-content' } ) ).toBe( 'Read content' );
	} );

	it( 'handles a plain namespaced verb the same way', () => {
		expect( stepLabel( { tool_name: 'senroflux__create-page' } ) ).toBe( 'Create page' );
	} );

	it( 'falls back to the raw verb when it carries no __ segment to derive from', () => {
		expect( stepLabel( { tool_name: 'core/get-site-info' } ) ).toBe( 'core/get-site-info' );
	} );

	it( 'falls back to the untranslated sentinel when no fallbackLabel is supplied (utils.js makes no WordPress i18n calls)', () => {
		expect( stepLabel( {} ) ).toBe( 'Tool call' );
	} );

	it( 'uses the caller-supplied fallbackLabel instead of the bare English sentinel when there is no verb at all', () => {
		expect( stepLabel( {}, 'Appel d\'outil' ) ).toBe( 'Appel d\'outil' );
	} );

	it( 'ignores fallbackLabel once a verb IS derivable (fallback only applies to the no-verb case)', () => {
		expect( stepLabel( { tool_name: 'wpab__senroflux__read-content' }, 'Appel d\'outil' ) ).toBe( 'Read content' );
	} );
} );

describe( 'stepResult never returns the bare "Rejected by you, not done" fallback (that case is dead: LedgerGroup.js branches on status === \'rejected\' and renders its own translated span before ever calling stepResult())', () => {
	it( 'returns an empty string for a rejected step, not an untranslated English fallback', () => {
		expect( stepResult( { status: 'rejected' } ) ).toBe( '' );
	} );

	it( 'still extracts a functionResponse error for a non-rejected step', () => {
		const step = {
			status: 'ok',
			message: { parts: [ { functionResponse: { response: { error: 'boom' } } } ] },
		};
		expect( stepResult( step ) ).toBe( 'boom' );
	} );
} );
