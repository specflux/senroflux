/**
 * A brief suggestion created by a tick must show without a reload. The tick's
 * RunState carries the new `suggestion` step but no `suggestions` list, so the
 * screen used to keep the list from its last full read (S12 known defect).
 */

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import App from '../components/App';
import { listRuns, getRun, tickRun } from '../api';

jest.mock( '../api' );

describe( 'a suggestion created by a tick', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'appears from that tick, without a reload', async () => {
		const parkedRun = {
			id: 3,
			goal: 'Write the launch post',
			status: 'awaiting_approval',
			pack: 'posts',
			viewer_may_tick: true,
			step_count: 4,
		};

		listRuns.mockResolvedValue( [ parkedRun ] );
		getRun.mockResolvedValue( {
			run: parkedRun,
			steps: [ { seq: 4, kind: 'approval', message: { verb: 'senroflux/create-post', tier: 1, args: {} } } ],
			suggestions: [],
		} );
		tickRun.mockResolvedValue( {
			run: { ...parkedRun, status: 'completed', step_count: 6 },
			new_steps: [
				{ seq: 5, kind: 'tool_result', message: { ok: true } },
				{ seq: 6, kind: 'suggestion', message: { text: 'Mention free parking on Saturdays.' } },
			],
			ui: {},
		} );

		render( <App config={ { initialRunId: '3', nonce: 'abc', consumer: 'admin', gateMode: 'built_in' } } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => {
			expect( document.querySelector( '.senroflux-suggestion-card' ) ).not.toBeNull();
		} );
		expect( document.querySelector( '.senroflux-suggestion-card' ).textContent ).toContain( 'Mention free parking on Saturdays.' );
	} );
} );
