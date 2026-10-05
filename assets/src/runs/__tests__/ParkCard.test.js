import { render, screen, fireEvent, within } from '@testing-library/react';
import ParkCard from '../components/ParkCard';

/**
 * The pre-approve radio's three visibility rules (17c), ported from the
 * retired `RunsScreenParkCardsTest`:
 *   - hidden by default;
 *   - offered once `preapprove_available` is true (grants on);
 *   - hidden again while it is false (grants off, even with the
 *     `senroflux_enable_preapproval` filter on — this component never
 *     re-derives that from anything of its own, it only reads the flag
 *     `Runner::planUi()` already computed server-side, S14).
 */
describe( 'the plan card pre-approve radio', () => {
	const plan = {
		steps: [ { text: 'Publish it', tier: 2 } ],
	};

	it( 'is hidden by default', () => {
		render( <ParkCard kind="plan" gateMode="agent_safety" payload={ plan } /> );

		expect( screen.queryByRole( 'radio', { name: /pre-approve/i } ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'radio', { name: 'Accept' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'radio', { name: 'Veto' } ) ).toBeInTheDocument();
	} );

	it( 'is offered once grants are on (preapprove_available: true)', () => {
		render(
			<ParkCard
				kind="plan"
				gateMode="agent_safety"
				payload={ { ...plan, preapprove_available: true } }
			/>
		);

		expect( screen.getByRole( 'radio', { name: /pre-approve/i } ) ).toBeInTheDocument();
		// S15: the choice carries its one-line warning.
		expect( screen.getByText( /without asking again/i ) ).toBeInTheDocument();
	} );

	it( 'is hidden while Agent Tollgate grants are off (preapprove_available: false)', () => {
		render(
			<ParkCard
				kind="plan"
				gateMode="agent_safety"
				payload={ { ...plan, preapprove_available: false } }
			/>
		);

		expect( screen.queryByRole( 'radio', { name: /pre-approve/i } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'park resolutions call onResolve with the S5 resume shape', () => {
	it( 'Answer (free text) resumes with { answer: { text } }', () => {
		const onResolve = jest.fn();
		render(
			<ParkCard
				kind="question"
				gateMode="built_in"
				payload={ { text: 'Which one?' } }
				onResolve={ onResolve }
			/>
		);

		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'The blue one' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Answer' } ) );

		expect( onResolve ).toHaveBeenCalledWith( { answer: { text: 'The blue one' } } );
	} );

	it( 'Skip resumes with { skip: true }', () => {
		const onResolve = jest.fn();
		render(
			<ParkCard kind="question" gateMode="built_in" payload={ { text: 'Which one?' } } onResolve={ onResolve } />
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Skip' } ) );

		expect( onResolve ).toHaveBeenCalledWith( { skip: true } );
	} );

	it( 'Approve resumes with { action: "approve" }', () => {
		const onResolve = jest.fn();
		render(
			<ParkCard
				kind="approval"
				gateMode="agent_safety"
				payload={ { verb: 'senroflux/publish-page', tier: 2, args: {} } }
				onResolve={ onResolve }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Approve' } ) );

		expect( onResolve ).toHaveBeenCalledWith( { action: 'approve' } );
	} );

	it( 'Reject resumes with { action: "reject" }', () => {
		const onResolve = jest.fn();
		render(
			<ParkCard
				kind="approval"
				gateMode="agent_safety"
				payload={ { verb: 'senroflux/publish-page', tier: 2, args: {} } }
				onResolve={ onResolve }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Reject' } ) );

		expect( onResolve ).toHaveBeenCalledWith( { action: 'reject' } );
	} );

	it( 'Accept resumes with { plan: { action: "accept" } }', () => {
		const onResolve = jest.fn();
		render(
			<ParkCard
				kind="plan"
				gateMode="built_in"
				payload={ { steps: [ { text: 'Publish it', tier: 2 } ] } }
				onResolve={ onResolve }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Accept plan' } ) );

		expect( onResolve ).toHaveBeenCalledWith( { plan: { action: 'accept' } } );
	} );

	it( 'Veto is disabled until a note is typed, then resumes with { plan: { action: "veto", note } }', () => {
		const onResolve = jest.fn();
		render(
			<ParkCard
				kind="plan"
				gateMode="built_in"
				payload={ { steps: [ { text: 'Publish it', tier: 2 } ] } }
				onResolve={ onResolve }
			/>
		);

		fireEvent.click( screen.getByRole( 'radio', { name: 'Veto' } ) );
		const vetoButton = screen.getByRole( 'button', { name: 'Veto' } );
		expect( vetoButton ).toBeDisabled();

		fireEvent.change( screen.getByRole( 'textbox' ), { target: { value: 'Too risky' } } );
		expect( vetoButton ).not.toBeDisabled();

		fireEvent.click( vetoButton );

		expect( onResolve ).toHaveBeenCalledWith( { plan: { action: 'veto', note: 'Too risky' } } );
	} );
} );

describe( 'the plan card names the existing objects a step will change', () => {
	const plan = {
		steps: [
			{
				text: 'Raise the mug price',
				verbs: [ 'commerce/price-change' ],
				tier: 2,
				objects: [
					{ id: '12', title: 'Ceramic Mug', type: 'product' },
					{ id: 'term:7', title: 'Accessories', type: 'term' },
					{ id: '99', title: '', type: 'product' },
				],
			},
			{ text: 'Create a post', verbs: [ 'posts/create-draft' ], tier: 1 },
		],
	};

	it( 'renders each object as plain text marked as site content', () => {
		const { container } = render( <ParkCard kind="plan" gateMode="agent_safety" payload={ plan } /> );

		const mug = screen.getByText( 'Ceramic Mug (#12)' );
		expect( mug ).toHaveAttribute( 'data-senroflux-content' );
		expect( screen.getByText( 'Accessories (term:7)' ) ).toBeInTheDocument();
		expect( screen.getByText( '#99' ) ).toBeInTheDocument();
		expect( container.querySelectorAll( '.senroflux-plan-objects' ) ).toHaveLength( 1 );
	} );

	it( 'never interprets a hostile title as markup', () => {
		const hostile = {
			steps: [
				{
					text: 'Edit',
					verbs: [ 'commerce/price-change' ],
					tier: 2,
					objects: [ { id: '5', title: '<img src=x onerror=alert(1)>', type: 'product' } ],
				},
			],
		};
		const { container } = render( <ParkCard kind="plan" gateMode="agent_safety" payload={ hostile } /> );

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( screen.getByText( '<img src=x onerror=alert(1)> (#5)' ) ).toBeInTheDocument();
	} );
} );

describe( 'the plan card lists the existing pages it keeps and leaves alone', () => {
	const plan = {
		steps: [ { text: 'Edit the About page', verbs: [ 'posts/update' ], tier: 1 } ],
		adopted: [ { id: '10', title: 'About', status: 'publish' } ],
		left_for_you: [
			{ id: '2', title: 'Sample Page', status: 'publish' },
			{ id: '3', title: 'Privacy Policy', status: 'draft' },
			{ id: '9', title: 'Gone', status: null },
		],
	};

	it( 'renders both lists with title, #id and status in words', () => {
		render( <ParkCard kind="plan" gateMode="agent_safety" payload={ plan } /> );

		const kept = screen.getByRole( 'heading', { name: 'Existing pages kept' } ).nextElementSibling;
		expect( kept.tagName ).toBe( 'UL' );
		expect( within( kept ).getByText( 'About · #10 · published' ) ).toHaveAttribute( 'data-senroflux-content' );

		const left = screen.getByRole( 'heading', { name: 'Left as they are' } ).nextElementSibling;
		expect( left.tagName ).toBe( 'UL' );
		expect( within( left ).getByText( 'Sample Page · #2 · published' ) ).toBeInTheDocument();
		expect( within( left ).getByText( 'Privacy Policy · #3 · draft' ) ).toBeInTheDocument();
		expect( within( left ).getByText( 'Gone · #9' ) ).toBeInTheDocument();
	} );

	it( 'renders neither heading for empty or missing lists', () => {
		render(
			<ParkCard
				kind="plan"
				gateMode="agent_safety"
				payload={ { steps: [], adopted: [], left_for_you: undefined } }
			/>
		);

		expect( screen.queryByRole( 'heading', { name: 'Existing pages kept' } ) ).toBeNull();
		expect( screen.queryByRole( 'heading', { name: 'Left as they are' } ) ).toBeNull();
	} );
} );

describe( 'a site plan card lists the pages already on the site', () => {
	it( 'renders the server list with title, #id and status, before the plan\'s own lists', () => {
		const plan = {
			steps: [ { text: 'Build the skeleton', verbs: [ 'site/create' ], tier: 1 } ],
			site_pages: [
				{ id: '3', title: 'Privacy Policy', status: 'draft' },
				{ id: '2', title: 'Sample Page', status: 'publish' },
			],
		};
		render( <ParkCard kind="plan" gateMode="agent_safety" payload={ plan } /> );

		const list = screen.getByRole( 'heading', { name: 'Pages on the site now' } ).nextElementSibling;
		expect( list.tagName ).toBe( 'UL' );
		expect( within( list ).getByText( 'Privacy Policy · #3 · draft' ) ).toBeInTheDocument();
		expect( within( list ).getByText( 'Sample Page · #2 · published' ) ).toBeInTheDocument();
	} );

	it( 'renders no heading when the server sends no pages', () => {
		render( <ParkCard kind="plan" gateMode="agent_safety" payload={ { steps: [], site_pages: [] } } /> );

		expect( screen.queryByRole( 'heading', { name: 'Pages on the site now' } ) ).toBeNull();
	} );
} );
