import { render, screen, fireEvent } from '@testing-library/react';
import Chat from '../components/Chat';

const baseRun = {
	id: 1,
	goal: 'Publish the spring workshops page',
	gate_mode: 'agent_safety',
};

describe( 'a terminal run shows no Cancel button', () => {
	it.each( [ 'completed', 'failed', 'cancelled' ] )(
		'renders no Cancel button element at all when status is %s',
		( status ) => {
			render( <Chat run={ { ...baseRun, status } } steps={ [] } /> );
			expect( screen.queryByRole( 'button', { name: /cancel run/i } ) ).not.toBeInTheDocument();
		}
	);

	it.each( [ 'running', 'awaiting_approval', 'awaiting_user', 'awaiting_plan' ] )(
		'still renders a (disabled, rendering-only) Cancel button while status is %s',
		( status ) => {
			render( <Chat run={ { ...baseRun, status } } steps={ [] } /> );
			expect( screen.getByRole( 'button', { name: /cancel run/i } ) ).toBeInTheDocument();
		}
	);
} );

describe( 'the report view at a terminal state (stage 22b)', () => {
	const report = {
		summary: 'Done.',
		changes: [
			{ object_type: 'post', object_id: '1', title: 'One', status: 'draft', edit_url: null, verified: true },
			{ object_type: 'post', object_id: '2', title: 'Two', status: 'draft', edit_url: null, verified: false },
		],
		gate_mode: 'built_in',
		withheld_roles: [],
	};

	it.each( [ 'completed', 'failed', 'cancelled' ] )( 'shows the report when the %s run carries one', ( status ) => {
		render( <Chat run={ { ...baseRun, status, report } } steps={ [] } /> );

		expect( screen.getByText( '2 changes, 1 not checked' ) ).toBeInTheDocument();
	} );

	it( 'shows no report while the run is still going, or when a terminal run has none', () => {
		const { rerender } = render( <Chat run={ { ...baseRun, status: 'running', report } } steps={ [] } /> );
		expect( screen.queryByText( /not checked/ ) ).not.toBeInTheDocument();

		rerender( <Chat run={ { ...baseRun, status: 'completed', report: null } } steps={ [] } /> );
		expect( screen.queryByRole( 'heading', { name: 'Report' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'the "Start a follow-up" affordance (stage 22b, S20)', () => {
	it.each( [ 'completed', 'failed', 'cancelled' ] )( 'is offered on a %s run the viewer may follow up', ( status ) => {
		const onFollowUp = jest.fn();
		render( <Chat run={ { ...baseRun, status } } steps={ [] } canFollowUp onFollowUp={ onFollowUp } /> );

		const button = screen.getByRole( 'button', { name: 'Start a follow-up' } );
		fireEvent.click( button );

		expect( onFollowUp ).toHaveBeenCalledWith( expect.objectContaining( { id: 1 } ) );
	} );

	it.each( [ 'pending', 'running', 'awaiting_approval', 'awaiting_user', 'awaiting_plan' ] )(
		'is not offered on a %s run',
		( status ) => {
			render( <Chat run={ { ...baseRun, status } } steps={ [] } canFollowUp onFollowUp={ jest.fn() } /> );

			expect( screen.queryByRole( 'button', { name: 'Start a follow-up' } ) ).not.toBeInTheDocument();
		}
	);

	it( 'is not offered when the viewer may not follow the run up', () => {
		render( <Chat run={ { ...baseRun, status: 'completed' } } steps={ [] } canFollowUp={ false } onFollowUp={ jest.fn() } /> );

		expect( screen.queryByRole( 'button', { name: 'Start a follow-up' } ) ).not.toBeInTheDocument();
	} );
} );
