import { render, screen, within } from '@testing-library/react';
import ParkCard from '../components/ParkCard';
import Chat from '../components/Chat';

/**
 * Bug 8 (proof shakedown): the Runs-screen approval card showed the verb and
 * raw argument JSON. It now leads with the pack's human summary
 * (`payload.summary`, sanitised server-side to text plus plain links), keeps
 * the raw arguments secondary inside a collapsed `<details>`, and renders the
 * summary without ever injecting markup.
 */
const SUMMARY =
	'Change price of &quot;Blue Mug&quot; — current regular 10.00 — proposed regular 5.00 — <a href="https://example.test/?p=1">preview</a>';

const payload = {
	verb: 'senroflux/product-update',
	tier: 2,
	args: { id: 100, regular_price: '5.00' },
	summary: SUMMARY,
};

function renderCard( overrides = {} ) {
	return render( <ParkCard kind="approval" gateMode="agent_safety" payload={ { ...payload, ...overrides } } /> );
}

describe( 'the approval card shows the pack summary', () => {
	it( 'renders the summary text with entities decoded, readable without colour', () => {
		renderCard();

		const summary = document.querySelector( '.senroflux-approval-summary' );
		expect( summary ).not.toBeNull();
		expect( summary.textContent ).toContain( 'Change price of "Blue Mug" — current regular 10.00 — proposed regular 5.00' );
		// A text label names the block, so nothing depends on colour.
		expect( screen.getByText( 'What will change:' ) ).toBeInTheDocument();
	} );

	it( 'renders the pack\'s preview link as a real link', () => {
		renderCard();

		const link = screen.getByRole( 'link', { name: 'preview' } );
		expect( link ).toHaveAttribute( 'href', 'https://example.test/?p=1' );
		expect( link ).toHaveAttribute( 'rel', expect.stringContaining( 'noopener' ) );
	} );

	it( 'marks the summary as DATA for the pseudo-locale check, but not the chrome labels', () => {
		renderCard();

		const summary = document.querySelector( '.senroflux-approval-summary' );
		expect( summary.hasAttribute( 'data-senroflux-content' ) ).toBe( true );
		expect( screen.getByText( 'What will change:' ).closest( '[data-senroflux-content]' ) ).toBeNull();
		expect( screen.getByText( 'Arguments' ).closest( '[data-senroflux-content]' ) ).toBeNull();
	} );

	it( 'keeps the raw arguments available but secondary: collapsed inside details', () => {
		renderCard();

		const details = document.querySelector( 'details.senroflux-approval-args' );
		expect( details ).not.toBeNull();
		expect( details.open ).toBe( false );
		expect( within( details ).getByText( 'Arguments' ).tagName ).toBe( 'SUMMARY' );
		expect( details.querySelector( 'pre.senroflux-args' ).textContent ).toContain( '"regular_price": "5.00"' );
	} );

	it( 'opens the arguments when no pack summarises the verb', () => {
		renderCard( { summary: '' } );

		expect( document.querySelector( '.senroflux-approval-summary' ) ).toBeNull();
		expect( document.querySelector( 'details.senroflux-approval-args' ).open ).toBe( true );
	} );

	it( 'shows why the human is asked again when the earlier request expired, and nothing otherwise', () => {
		renderCard( { notice: 'The earlier approval request expired, so Agent Safety asked again. Approve to continue.' } );
		expect( screen.getByRole( 'status' ).textContent ).toContain( 'earlier approval request expired' );
	} );

	it( 'renders no notice on a normal approval card', () => {
		renderCard();
		expect( document.querySelector( '.senroflux-approval-notice' ) ).toBeNull();
	} );

	it( 'shows the same card in built-in mode', () => {
		render( <ParkCard kind="approval" gateMode="built_in" payload={ { ...payload, tier: null } } /> );

		expect( document.querySelector( '.senroflux-approval-summary' ).textContent ).toContain( 'proposed regular 5.00' );
	} );
} );

describe( 'the summary never injects markup', () => {
	it( 'drops scripts, images, handlers and javascript: links', () => {
		renderCard( {
			summary:
				'Go <script>window.__pwned = 1</script><img src="x" onerror="window.__pwned = 2"> ' +
				'<a href="javascript:window.__pwned=3" onclick="window.__pwned=4">bad</a> ' +
				'<b onmouseover="window.__pwned=5">bold</b> <a href="https://example.test/ok" onclick="x()">ok</a>',
		} );

		const summary = document.querySelector( '.senroflux-approval-summary' );
		expect( summary.querySelector( 'script, img, b' ) ).toBeNull();
		expect( summary.innerHTML ).not.toMatch( /onerror|onclick|onmouseover|javascript:/i );
		expect( summary.textContent ).not.toContain( 'window.__pwned = 1' );
		expect( window.__pwned ).toBeUndefined();

		const links = summary.querySelectorAll( 'a' );
		expect( links ).toHaveLength( 1 );
		expect( links[ 0 ].getAttribute( 'href' ) ).toBe( 'https://example.test/ok' );
		// Text of the disallowed elements survives as plain text.
		expect( summary.textContent ).toContain( 'bad' );
		expect( summary.textContent ).toContain( 'bold' );
	} );

	it( 'shows literal angle brackets that arrive escaped as text, not as elements', () => {
		renderCard( { summary: 'Note: &lt;script&gt;alert(1)&lt;/script&gt;' } );

		const summary = document.querySelector( '.senroflux-approval-summary' );
		expect( summary.querySelector( 'script' ) ).toBeNull();
		expect( summary.textContent ).toContain( '<script>alert(1)</script>' );
	} );
} );

describe( 'a reload keeps the summary (run-detail ui.approval)', () => {
	const approvalStep = {
		seq: 4,
		kind: 'approval',
		approval_id: 'builtin:abc',
		message: { parked: true, approval_id: 'builtin:abc', verb: 'senroflux/product-update', tier: null, args: { id: 100 } },
	};
	const run = { id: 1, goal: 'Reprice', gate_mode: 'built_in', status: 'awaiting_approval' };

	it( 'merges ui.approval into the parked approval step', () => {
		render( <Chat run={ run } steps={ [ approvalStep ] } approvalUi={ { approval_id: 'builtin:abc', summary: SUMMARY } } /> );

		expect( document.querySelector( '.senroflux-approval-summary' ).textContent ).toContain( 'proposed regular 5.00' );
	} );

	it( 'ignores a summary that describes a different approval', () => {
		render( <Chat run={ run } steps={ [ approvalStep ] } approvalUi={ { approval_id: 'builtin:other', summary: SUMMARY } } /> );

		expect( document.querySelector( '.senroflux-approval-summary' ) ).toBeNull();
	} );
} );
