/**
 * Reproduces a real live-review defect (stage 17d): clicking Approve on a
 * parked run reached by a deep link (`?run_id=42` on the Runs screen)
 * reaches admin-ajax, the tick runs, and the run advances server-side — but
 * the screen never updates. Root cause: `wp_localize_script()` casts every
 * scalar value to a STRING before JSON-encoding it (a documented WordPress
 * core behaviour, `WP_Scripts::localize()`), so `senrofluxRunsConfig.
 * initialRunId` arrives in JS as the STRING `"1"`, while every `run.id` in a
 * REST/ajax JSON payload is a genuine JSON NUMBER `1`. `App.js` seeded
 * `activeRunRef.current` from that string and never normalized it, so the
 * strict `runId !== activeRunRef.current` staleness guards in
 * `applyRunState()` / `driveTicks()`'s `step()` compared `1 !== "1"`, which
 * is always true — every state update for THIS run was silently discarded
 * as if it belonged to some other, no-longer-selected run.
 *
 * This test seeds `config.initialRunId` as a string (as it always is coming
 * from `wp_localize_script`) and every server `run.id` as a number (as it
 * always is coming from `wp_json_encode` of a PHP int over REST/ajax), then
 * asserts the approval card actually reflects a server-confirmed status
 * change after Approve is clicked. It fails on the pre-fix code (the card
 * never disappears) and passes once id comparisons are type-tolerant.
 */

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import App from '../components/App';
import { listRuns, getRun, tickRun } from '../api';

jest.mock( '../api' );

describe( 'the re-render defect: deep-linked run id (string) vs server run id (number)', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'applies a successful tick result even when initialRunId arrived as a string', async () => {
		const parkedRun = {
			id: 1,
			goal: 'Publish the spring page',
			status: 'awaiting_approval',
			pack: 'pages',
			viewer_may_tick: true,
		};

		listRuns.mockResolvedValue( [ parkedRun ] );
		getRun.mockResolvedValue( {
			run: parkedRun,
			steps: [
				{
					kind: 'approval',
					message: { verb: 'senroflux/publish-page', tier: 1, args: {} },
				},
			],
		} );

		// The server's tick response: a real JSON NUMBER id, and the run has
		// moved off `awaiting_approval` — this is what "the run advances
		// server-side" looks like from the client's point of view.
		tickRun.mockResolvedValue( {
			run: { ...parkedRun, id: 1, status: 'running', step_count: 3 },
			new_steps: [ { kind: 'model', message: { text: 'Continuing…' } } ],
		} );

		// `wp_localize_script()` casts every scalar to a string — this is
		// the exact shape `window.senrofluxRunsConfig` has in production
		// whenever the Runs screen opens via `?run_id=1`.
		const config = { initialRunId: '1', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety' };

		render( <App config={ config } /> );

		const approveButton = await screen.findByRole( 'button', { name: 'Approve' } );

		fireEvent.click( approveButton );

		// The card must reflect the server's confirmed state: once the run
		// is no longer `awaiting_approval`, there is no more open park, so
		// the Approve/Reject actions must be gone.
		await waitFor( () => {
			expect( screen.queryByRole( 'button', { name: 'Approve' } ) ).not.toBeInTheDocument();
		} );
	} );

	it( 'disables the action buttons synchronously on click, before the server responds', async () => {
		const parkedRun = {
			id: 2,
			goal: 'Send the newsletter',
			status: 'awaiting_approval',
			pack: 'pages',
			viewer_may_tick: true,
		};

		listRuns.mockResolvedValue( [ parkedRun ] );
		getRun.mockResolvedValue( {
			run: parkedRun,
			steps: [ { kind: 'approval', message: { verb: 'senroflux/send-newsletter', tier: 1, args: {} } } ],
		} );

		// Never resolves within this test — proves the disabling doesn't wait
		// on the network round-trip at all (a double-submit guard that only
		// engaged once the server replied would be too late).
		let releaseTick;
		tickRun.mockReturnValue( new Promise( ( resolve ) => {
			releaseTick = resolve;
		} ) );

		const config = { initialRunId: '2', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety' };
		render( <App config={ config } /> );

		const approveButton = await screen.findByRole( 'button', { name: 'Approve' } );
		const rejectButton = screen.getByRole( 'button', { name: 'Reject' } );

		fireEvent.click( approveButton );

		expect( approveButton ).toBeDisabled();
		expect( rejectButton ).toBeDisabled();

		// Clean up the pending promise so it doesn't leak into other tests.
		releaseTick( { run: { ...parkedRun, status: 'running', step_count: 3 }, new_steps: [] } );
		await waitFor( () => {
			expect( screen.queryByRole( 'button', { name: 'Approve' } ) ).not.toBeInTheDocument();
		} );
	} );

	it( 'surfaces a visible error when the resolution fails, and re-enables the buttons', async () => {
		const parkedRun = {
			id: 3,
			goal: 'Draft the FAQ page',
			status: 'awaiting_approval',
			pack: 'pages',
			viewer_may_tick: true,
		};

		listRuns.mockResolvedValue( [ parkedRun ] );
		getRun.mockResolvedValue( {
			run: parkedRun,
			steps: [ { kind: 'approval', message: { verb: 'senroflux/draft-faq', tier: 1, args: {} } } ],
		} );
		tickRun.mockRejectedValue( Object.assign( new Error( 'The run could not be resolved.' ), { code: 'senroflux_tick_conflict' } ) );

		const config = { initialRunId: '3', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety' };
		render( <App config={ config } /> );

		const approveButton = await screen.findByRole( 'button', { name: 'Approve' } );
		fireEvent.click( approveButton );

		expect( await screen.findByText( 'The run could not be resolved.' ) ).toBeInTheDocument();

		// Silent failure is exactly the defect this stage fixes: a real error
		// must re-enable the actions rather than leave the UI stuck disabled
		// with no way to retry.
		await waitFor( () => {
			expect( screen.getByRole( 'button', { name: 'Approve' } ) ).not.toBeDisabled();
		} );
	} );

	it( 'refreshes the run list (and its tab counts) once a park resolves', async () => {
		const parkedRun = {
			id: 4,
			goal: 'Update the pricing page',
			status: 'awaiting_approval',
			pack: 'pages',
			viewer_may_tick: true,
		};
		// Terminal, not `running`: `running` is neither parked nor terminal,
		// so `driveTicks()` would keep auto-continuing it (by design) and the
		// exact call count below would depend on that unrelated loop.
		const advancedRun = { ...parkedRun, status: 'completed' };

		listRuns.mockResolvedValueOnce( [ parkedRun ] ).mockResolvedValue( [ advancedRun ] );
		getRun.mockResolvedValue( {
			run: parkedRun,
			steps: [ { kind: 'approval', message: { verb: 'senroflux/update-pricing', tier: 1, args: {} } } ],
		} );
		tickRun.mockResolvedValue( { run: advancedRun, new_steps: [] } );

		const config = { initialRunId: '4', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety' };
		render( <App config={ config } /> );

		// "Needs you" is the default tab and starts with this one parked run.
		expect( await screen.findByRole( 'tab', { name: 'Needs you (1)' } ) ).toBeInTheDocument();

		const approveButton = await screen.findByRole( 'button', { name: 'Approve' } );
		fireEvent.click( approveButton );

		await waitFor( () => {
			expect( screen.getByRole( 'tab', { name: 'Needs you (0)' } ) ).toBeInTheDocument();
		} );
		expect( listRuns ).toHaveBeenCalledTimes( 2 );
	} );
} );
