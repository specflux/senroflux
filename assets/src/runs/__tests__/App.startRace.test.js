/**
 * Starting a run fires two requests at once: the full run-detail read (the
 * selected-run effect) and the first tick. When the read is answered while
 * the tick is still running server-side but reaches the browser after the
 * tick's response, its older snapshot used to replace the parked state: the
 * list said "waiting on the plan" while the detail pane showed no plan card
 * until a reload (seen in CI as a 30 s wait for #senroflux-park-heading).
 */

import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import App from '../components/App';
import { listRuns, getRun, startRun, tickRun, fetchSetupState } from '../api';

jest.mock( '../api' );

describe( 'a run-detail read that lands after the first tick', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'keeps the plan park the tick delivered', async () => {
		const goal = 'Build the launch page';
		const thin = { id: 9, goal, status: 'running', step_count: 1, viewer_may_tick: true, pack: 'pages' };

		listRuns.mockResolvedValue( [] );
		fetchSetupState.mockResolvedValue( { html: '', start_enabled: true, packs: [ { name: 'pages', label: 'pages' } ], unavailable_packs: [] } );
		startRun.mockResolvedValue( { run: thin, new_steps: [] } );

		let releaseRead;
		getRun.mockReturnValue(
			new Promise( ( resolve ) => {
				releaseRead = resolve;
			} )
		);

		tickRun.mockResolvedValue( {
			run: { ...thin, status: 'awaiting_plan', step_count: 3 },
			new_steps: [
				{ seq: 2, kind: 'model', message: { text: 'Here is my plan.' } },
				{ seq: 3, kind: 'plan', message: { goal, steps: [], assumptions: [] } },
			],
			ui: { plan: { step_id: 3, remaining_plans: 1, preapprove_available: false, review_url: '' } },
		} );

		render( <App config={ { nonce: 'abc', consumer: 'admin', gateMode: 'built_in', packs: [ { name: 'pages', label: 'pages' } ], examples: [] } } /> );

		const box = await screen.findByPlaceholderText( 'Describe what you want done…' );
		fireEvent.change( box, { target: { value: goal } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Start run' } ) );

		await waitFor( () => {
			expect( document.getElementById( 'senroflux-park-heading' ) ).not.toBeNull();
		} );

		// The read was answered mid-tick: the model turn is recorded, the park is not.
		await act( async () => {
			releaseRead( {
				run: { ...thin, step_count: 2, tokens_used: 15 },
				steps: [ { seq: 2, kind: 'model', message: { text: 'Here is my plan.' } } ],
			} );
		} );

		expect( document.getElementById( 'senroflux-park-heading' ) ).not.toBeNull();
	} );
} );
