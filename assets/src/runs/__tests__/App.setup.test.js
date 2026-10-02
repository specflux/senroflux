/**
 * Stage 22b: the Runs screen refreshes its start state when the user comes
 * back to the tab (J1: configure a provider in Connectors, return to Runs,
 * Start is enabled without a manual reload), and starts a follow-up from a
 * finished run with `follow_up_of`.
 */

import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import App from '../components/App';
import { listRuns, getRun, startRun, tickRun, fetchSetupState } from '../api';

jest.mock( '../api' );

const packs = [ { name: 'posts', label: 'posts' } ];

describe( 'Start is blocked by a blocking setup check and refreshed on focus (J1)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		listRuns.mockResolvedValue( [] );
		document.body.insertAdjacentHTML(
			'afterbegin',
			'<div id="senroflux-setup-panel"><div class="notice">Configure a model provider in Settings → Connectors.</div></div>'
		);
	} );

	afterEach( () => {
		const panel = document.getElementById( 'senroflux-setup-panel' );
		if ( panel ) {
			panel.remove();
		}
	} );

	const config = { nonce: 'n', consumer: 'senroflux-admin', gateMode: 'built_in', packs, startBlocked: true, examples: [] };

	it( 'keeps Start disabled while the blocking check fails', async () => {
		render( <App config={ config } /> );

		const box = await screen.findByPlaceholderText( 'Describe what you want done…' );
		fireEvent.change( box, { target: { value: 'Write a post' } } );

		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toBeDisabled();
	} );

	it( 're-enables Start after a window focus that finds the check passing, and refreshes the panel', async () => {
		fetchSetupState.mockResolvedValue( {
			html: '<div id="senroflux-setup-panel"></div>',
			start_enabled: true,
			packs,
			unavailable_packs: [],
		} );
		render( <App config={ config } /> );
		const box = await screen.findByPlaceholderText( 'Describe what you want done…' );
		fireEvent.change( box, { target: { value: 'Write a post' } } );
		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toBeDisabled();

		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );

		await waitFor( () => {
			expect( screen.getByRole( 'button', { name: 'Start run' } ) ).not.toBeDisabled();
		} );
		expect( fetchSetupState ).toHaveBeenCalledTimes( 1 );
		expect( document.getElementById( 'senroflux-setup-panel' ).textContent ).toBe( '' );
	} );

	it( 'also refreshes when the tab becomes visible again', async () => {
		fetchSetupState.mockResolvedValue( { html: '', start_enabled: true, packs, unavailable_packs: [] } );
		render( <App config={ config } /> );
		await screen.findByPlaceholderText( 'Describe what you want done…' );

		await act( async () => {
			document.dispatchEvent( new Event( 'visibilitychange' ) );
		} );

		await waitFor( () => expect( fetchSetupState ).toHaveBeenCalled() );
	} );

	it( 'blocks Start again if a later refresh finds the check failing', async () => {
		fetchSetupState.mockResolvedValue( { html: '', start_enabled: false, packs, unavailable_packs: [] } );
		render( <App config={ { ...config, startBlocked: false } } /> );
		const box = await screen.findByPlaceholderText( 'Describe what you want done…' );
		fireEvent.change( box, { target: { value: 'Write a post' } } );
		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).not.toBeDisabled();

		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );

		await waitFor( () => {
			expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toBeDisabled();
		} );
	} );

	it( 'survives a failed refresh without changing the start state', async () => {
		fetchSetupState.mockRejectedValue( new Error( 'offline' ) );
		render( <App config={ config } /> );
		await screen.findByPlaceholderText( 'Describe what you want done…' );

		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );

		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toBeDisabled();
	} );
} );

describe( 'a follow-up run from the screen (S20)', () => {
	const finishedRun = {
		id: 4,
		goal: 'Original goal',
		status: 'cancelled',
		pack: 'posts',
		gate_mode: 'built_in',
		viewer_may_tick: true,
		step_count: 3,
		report: { summary: '', changes: [], gate_mode: 'built_in', withheld_roles: [] },
	};

	beforeEach( () => {
		jest.clearAllMocks();
		listRuns.mockResolvedValue( [ finishedRun ] );
		getRun.mockResolvedValue( { run: finishedRun, steps: [], suggestions: [] } );
		startRun.mockResolvedValue( { run: { id: 5, status: 'running', step_count: 1, pack: 'posts' }, new_steps: [] } );
		tickRun.mockResolvedValue( { run: { id: 5, status: 'completed', step_count: 2 }, new_steps: [] } );
	} );

	const config = { initialRunId: '4', nonce: 'n', consumer: 'senroflux-admin', gateMode: 'built_in', packs, examples: [] };

	it( 'starts the follow-up from a cancelled run with follow_up_of and an empty goal box', async () => {
		render( <App config={ config } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Start a follow-up' } ) );

		const box = screen.getByPlaceholderText( 'Describe what you want done…' );
		expect( box ).toHaveValue( '' );
		fireEvent.change( box, { target: { value: 'Now add the images' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Start run' } ) );

		await waitFor( () => expect( startRun ).toHaveBeenCalled() );
		expect( startRun.mock.calls[ 0 ][ 0 ] ).toBe( 'Now add the images' );
		expect( startRun.mock.calls[ 0 ][ 2 ] ).toBe( 'posts' );
		expect( startRun.mock.calls[ 0 ][ 4 ] ).toBe( 4 );
	} );

	it( 'does not offer a follow-up when the viewer cannot run the source run\'s pack', async () => {
		render( <App config={ { ...config, packs: [ { name: 'pages', label: 'pages' } ] } } /> );

		await screen.findByRole( 'heading', { name: 'Original goal' } );
		expect( screen.queryByRole( 'button', { name: 'Start a follow-up' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'the report arrives with the terminal tick', () => {
	it( 'keeps the run\'s pack and shows the report from the tick\'s ui.report', async () => {
		jest.clearAllMocks();
		const parked = {
			id: 9,
			goal: 'Make a post',
			status: 'awaiting_approval',
			pack: 'posts',
			gate_mode: 'built_in',
			viewer_may_tick: true,
			step_count: 4,
			withheld_roles: [ 'upload' ],
			withheld_notice: 'Images are off for this run — your account can\'t upload files.',
		};
		listRuns.mockResolvedValue( [ parked ] );
		getRun.mockResolvedValue( {
			run: parked,
			steps: [ { seq: 1, kind: 'approval', status: 'parked', message: { verb: 'senroflux/publish-post', tier: 2, args: {} } } ],
		} );
		const report = {
			summary: 'Done',
			changes: [ { object_type: 'post', object_id: '3', title: 'My post', status: 'draft', edit_url: null, verified: false } ],
			gate_mode: 'built_in',
			withheld_roles: [ 'upload' ],
		};
		// The ajax RunState carries only the thin run row plus ui.report.
		tickRun.mockResolvedValue( {
			run: { id: 9, goal: 'Make a post', status: 'completed', step_count: 6, gate_mode: 'built_in' },
			new_steps: [],
			ui: { report },
		} );

		render( <App config={ { initialRunId: '9', nonce: 'n', consumer: 'c', gateMode: 'built_in', packs, examples: [] } } /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		expect( await screen.findByText( '1 change, 1 not checked' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not checked after the change' ) ).toBeInTheDocument();
		// The fields the thin ajax row lacks survive the merge.
		expect( document.querySelector( '.senroflux-withheld-roles' ) ).toHaveTextContent( /Images are off/ );
		expect( screen.getByRole( 'button', { name: 'Start a follow-up' } ) ).toBeInTheDocument();
	} );
} );
