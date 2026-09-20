import { render, screen } from '@testing-library/react';
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
