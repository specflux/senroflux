import { RUN_TABS, runsForTab, tabCounts, groupSteps, planProgress } from '../utils';

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

describe( 'consecutive tool calls collapse into one ledger group', () => {
	it( 'groups adjacent tool_result steps and breaks on anything else', () => {
		const steps = [
			{ kind: 'user', seq: 1 },
			{ kind: 'model', seq: 2 },
			{ kind: 'tool_result', seq: 3, status: 'ok', tool_name: 'core/get-site-info' },
			{ kind: 'tool_result', seq: 4, status: 'ok', tool_name: 'core/content-query' },
			{ kind: 'model', seq: 5 },
			{ kind: 'tool_result', seq: 6, status: 'ok', tool_name: 'senroflux/create-page' },
		];

		const entries = groupSteps( steps );

		expect( entries.map( ( e ) => e.type ) ).toEqual( [
			'step', // user
			'step', // model
			'ledger', // 2 grouped tool calls
			'step', // model
			'ledger', // 1 tool call
		] );
		expect( entries[ 2 ].calls ).toHaveLength( 2 );
		expect( entries[ 4 ].calls ).toHaveLength( 1 );
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
