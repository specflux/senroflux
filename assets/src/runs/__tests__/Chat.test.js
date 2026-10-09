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

describe( 'the plan card offers pre-approval from the run-detail plan UI (proof shakedown)', () => {
	// What a page reload gives the client: the stored plan step (goal, steps,
	// assumptions only) plus `ui.plan` from the run-detail read.
	const planStep = {
		seq: 3,
		kind: 'plan',
		message: { goal: 'Schedule three posts', steps: [ { text: 'Draft', verbs: [ 'senroflux/read-content' ], tier: 0 } ], assumptions: [] },
	};
	const parkedRun = { ...baseRun, status: 'awaiting_plan' };

	it( 'renders "Accept and pre-approve" when ui.plan says it is available', () => {
		render( <Chat run={ parkedRun } steps={ [ planStep ] } planUi={ { step_id: 3, preapprove_available: true } } /> );

		expect( screen.getByRole( 'radio', { name: /accept and pre-approve/i } ) ).toBeInTheDocument();
	} );

	it( 'renders only Accept / Veto when ui.plan says it is off', () => {
		render( <Chat run={ parkedRun } steps={ [ planStep ] } planUi={ { step_id: 3, preapprove_available: false } } /> );

		expect( screen.getByRole( 'radio', { name: 'Accept' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'radio', { name: /pre-approve/i } ) ).not.toBeInTheDocument();
	} );

	it( 'renders no pre-approve option without ui.plan, or when it describes another plan step', () => {
		const { rerender } = render( <Chat run={ parkedRun } steps={ [ planStep ] } /> );
		expect( screen.queryByRole( 'radio', { name: /pre-approve/i } ) ).not.toBeInTheDocument();

		rerender( <Chat run={ parkedRun } steps={ [ planStep ] } planUi={ { step_id: 2, preapprove_available: true } } /> );
		expect( screen.queryByRole( 'radio', { name: /pre-approve/i } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'a failed run says why (empty-model-turn shakedown)', () => {
	it( 'shows the run error message on a failed run', () => {
		const run = { ...baseRun, status: 'failed', error: { code: 'empty_model_turn', message: 'The model returned an empty reply twice in a row.' } };
		render( <Chat run={ run } steps={ [] } /> );

		expect( screen.getByRole( 'alert' ) ).toHaveTextContent( 'The model returned an empty reply twice in a row.' );
	} );

	it( 'shows nothing extra when the failure carries no message, or the run is not failed', () => {
		const { rerender } = render( <Chat run={ { ...baseRun, status: 'failed', error: { code: 'budget_exceeded', which: 'max_steps' } } } steps={ [] } /> );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();

		rerender( <Chat run={ { ...baseRun, status: 'completed', error: { message: 'stale' } } } steps={ [] } /> );
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'no empty bubble between ledger groups', () => {
	const toolResult = ( seq ) => ( {
		seq,
		kind: 'tool_result',
		status: 'ok',
		tool_name: 'senroflux__ask-user',
		message: { verb: 'senroflux/ask-user', response: { answer: 'x' } },
	} );
	const model = ( seq, text ) => ( { seq, kind: 'model', status: 'ok', message: { parts: [ { text } ] } } );

	it.each( [ [ 'whitespace', ' \n ' ], [ 'empty', '' ] ] )( 'renders no bubble for a %s-only model step', ( _label, text ) => {
		const { container } = render(
			<Chat run={ { ...baseRun, status: 'awaiting_plan' } } steps={ [ toolResult( 1 ), model( 2, text ), toolResult( 3 ) ] } />
		);

		// Only the goal bubble: the blank model step is transparent, so the two
		// one-action ledger groups also merge into a single group.
		expect( container.querySelectorAll( '.senroflux-chat-bubble-bot' ) ).toHaveLength( 0 );
		expect( container.querySelectorAll( '.senroflux-ledger-group' ) ).toHaveLength( 1 );
	} );

	it( 'still renders a bubble for real model prose', () => {
		const { container } = render(
			<Chat run={ { ...baseRun, status: 'awaiting_plan' } } steps={ [ toolResult( 1 ), model( 2, 'Thinking it over.' ), toolResult( 3 ) ] } />
		);

		expect( container.querySelectorAll( '.senroflux-chat-bubble-bot' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '.senroflux-ledger-group' ) ).toHaveLength( 2 );
	} );
} );
