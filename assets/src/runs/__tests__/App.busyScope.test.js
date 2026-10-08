/**
 * `busy` is "a resolution is in flight". It used to be one flag for the whole
 * screen, so a tick still running for run A greyed out Accept / Approve on a
 * freshly parked run B the viewer had switched to.
 */

import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import App from '../components/App';
import { listRuns, getRun, startRun, tickRun, fetchSetupState } from '../api';

jest.mock( '../api' );

describe( 'an in-flight tick on one run does not disable the park card of another', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'keeps Accept plan enabled on run B while run A is still ticking', async () => {
		const parkedB = { id: 5, goal: 'Run B goal', status: 'awaiting_plan', step_count: 3, viewer_may_tick: true, pack: 'pages', gate_mode: 'built_in' };
		const thinA = { id: 9, goal: 'Run A goal', status: 'running', step_count: 1, viewer_may_tick: true, pack: 'pages', gate_mode: 'built_in' };

		listRuns.mockResolvedValue( [ { ...thinA }, { ...parkedB } ] );
		fetchSetupState.mockResolvedValue( { html: '', start_enabled: true, packs: [ { name: 'pages', label: 'pages' } ], unavailable_packs: [] } );
		startRun.mockResolvedValue( { run: thinA, new_steps: [] } );
		getRun.mockImplementation( ( id ) =>
			Promise.resolve(
				5 === Number( id )
					? {
							run: parkedB,
							steps: [ { seq: 3, kind: 'plan', message: { goal: 'B', steps: [ { text: 'Do it', verbs: [ 'pages/create' ], tier: 1 } ], assumptions: [] } } ],
							ui: { plan: { step_id: 3, remaining_plans: 1, preapprove_available: false, review_url: '' } },
					  }
					: { run: thinA, steps: [] }
			)
		);
		// Run A's tick never returns during this test.
		tickRun.mockReturnValue( new Promise( () => {} ) );

		render( <App config={ { nonce: 'abc', consumer: 'admin', gateMode: 'built_in', packs: [ { name: 'pages', label: 'pages' } ], examples: [] } } /> );

		const box = await screen.findByPlaceholderText( 'Describe what you want done…' );
		fireEvent.change( box, { target: { value: 'Run A goal' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Start run' } ) );
		await waitFor( () => expect( tickRun ).toHaveBeenCalled() );

		// Switch to run B, whose plan is parked and waiting for this viewer.
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: /Run B goal/ } ) );
		} );

		const accept = await screen.findByRole( 'button', { name: 'Accept plan' } );
		expect( accept ).toBeEnabled();
	} );
} );
