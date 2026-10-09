/**
 * Stage 22b (S12): the report view at a run's terminal state. Every row's
 * state must be readable without colour — text plus a mark, never a colour
 * alone — and every sentence comes from `__()` so the pseudo-locale gate can
 * see it.
 */

import { render, screen, within } from '@testing-library/react';
import ReportView from '../components/ReportView';

const verifiedRow = {
	object_type: 'post',
	object_id: '12',
	title: 'Launch Day',
	status: 'draft',
	edit_url: 'https://example.test/wp-admin/post.php?post=12&action=edit',
	preview_url: null,
	verified: true,
};

const uncheckedRow = {
	...verifiedRow,
	object_id: '13',
	title: 'Second post',
	edit_url: 'https://example.test/wp-admin/post.php?post=13&action=edit',
	verified: false,
};

const baseReport = {
	summary: 'All done.',
	changes: [ verifiedRow, uncheckedRow ],
	gate_mode: 'built_in',
	withheld_roles: [],
};

describe( 'ReportView summary', () => {
	it( 'counts the changes and the ones not checked', () => {
		const changes = [ verifiedRow, verifiedRow, uncheckedRow ];
		render( <ReportView report={ { ...baseReport, changes } } steps={ [] } /> );

		expect( screen.getByText( '3 changes, 1 not checked' ) ).toBeInTheDocument();
	} );

	it( 'leaves the not-checked half out when every change was checked', () => {
		render( <ReportView report={ { ...baseReport, changes: [ verifiedRow ] } } steps={ [] } /> );

		expect( screen.getByText( '1 change' ) ).toBeInTheDocument();
	} );

	it( 'says so when the run changed nothing', () => {
		render( <ReportView report={ { ...baseReport, changes: [] } } steps={ [] } /> );

		expect( screen.getByText( 'No changes' ) ).toBeInTheDocument();
	} );
} );

describe( 'ReportView change rows', () => {
	it( 'renders one row per change, each with an edit link named for its object', () => {
		render( <ReportView report={ baseReport } steps={ [] } /> );

		const rows = screen.getAllByRole( 'listitem' ).filter( ( li ) => li.classList.contains( 'senroflux-report-change' ) );
		expect( rows ).toHaveLength( 2 );

		const link = screen.getByRole( 'link', { name: 'Edit Launch Day' } );
		expect( link ).toHaveAttribute( 'href', verifiedRow.edit_url );
	} );

	it( 'marks a verified row with text, not only a colour', () => {
		render( <ReportView report={ { ...baseReport, changes: [ verifiedRow ] } } steps={ [] } /> );

		const row = screen.getByText( 'Launch Day' ).closest( 'li' );
		expect( within( row ).getByText( 'Verified' ) ).toBeInTheDocument();
		expect( row ).toHaveClass( 'is-verified' );
	} );

	it( 'gives a row that was never re-read the "Not checked after the change" state, in text', () => {
		render( <ReportView report={ { ...baseReport, changes: [ uncheckedRow ] } } steps={ [] } /> );

		const row = screen.getByText( 'Second post' ).closest( 'li' );
		expect( within( row ).getByText( 'Not checked after the change' ) ).toBeInTheDocument();
		expect( within( row ).queryByText( 'Verified' ) ).not.toBeInTheDocument();
		expect( row ).toHaveClass( 'is-unchecked' );
		// The mark differs from the verified one and is hidden from the
		// accessibility tree: the TEXT carries the state for everyone.
		const mark = row.querySelector( '.senroflux-report-mark' );
		expect( mark ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( mark.textContent ).not.toBe( '✓' );
	} );

	it( 'keeps the edit link on a not-checked row', () => {
		render( <ReportView report={ { ...baseReport, changes: [ uncheckedRow ] } } steps={ [] } /> );

		expect( screen.getByRole( 'link', { name: 'Edit Second post' } ) ).toBeInTheDocument();
	} );

	it( 'renders no link for a row with no edit url, or a non-http one', () => {
		const changes = [
			{ ...verifiedRow, edit_url: null },
			{ ...uncheckedRow, edit_url: 'javascript:alert(1)' },
		];
		render( <ReportView report={ { ...baseReport, changes } } steps={ [] } /> );

		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Launch Day' ) ).toBeInTheDocument();
	} );

	it( 'never shows the fail-closed "unknown" type', () => {
		render( <ReportView report={ { ...baseReport, changes: [ { ...verifiedRow, object_type: 'unknown', status: '' } ] } } steps={ [] } /> );

		expect( screen.queryByText( /unknown/ ) ).not.toBeInTheDocument();
	} );

	it( 'falls back to the object id when a row has no title', () => {
		render( <ReportView report={ { ...baseReport, changes: [ { ...verifiedRow, title: '' } ] } } steps={ [] } /> );

		expect( screen.getByText( '#12' ) ).toBeInTheDocument();
	} );
} );

describe( 'ReportView non-post rows', () => {
	const attachmentRow = {
		object_type: 'attachment',
		object_id: 'attachment:3',
		title: 'desk-photo.jpg',
		status: 'inherit',
		edit_url: 'https://example.test/wp-admin/post.php?post=3&action=edit',
		preview_url: 'https://example.test/wp-content/uploads/desk-photo.jpg',
		verified: true,
	};

	const termRow = {
		object_type: 'category',
		object_id: 'term:5',
		title: 'Workstation Comfort',
		status: '',
		edit_url: 'https://example.test/wp-admin/term.php?taxonomy=category&tag_ID=5',
		preview_url: null,
		verified: false,
	};

	it( 'names an attachment row "Media", links to its edit screen and says it was verified', () => {
		render( <ReportView report={ { ...baseReport, changes: [ attachmentRow ] } } steps={ [] } /> );

		const row = screen.getByText( 'desk-photo.jpg' ).closest( 'li' );
		expect( within( row ).getByText( /Media/ ) ).toBeInTheDocument();
		expect( within( row ).queryByText( /attachment/ ) ).not.toBeInTheDocument();
		expect( within( row ).getByRole( 'link', { name: 'Edit desk-photo.jpg' } ) ).toHaveAttribute( 'href', attachmentRow.edit_url );
		expect( within( row ).getByText( 'Verified' ) ).toBeInTheDocument();
	} );

	it( 'names a term row by its taxonomy, links to the term screen and says it was not checked', () => {
		render( <ReportView report={ { ...baseReport, changes: [ termRow ] } } steps={ [] } /> );

		const row = screen.getByText( 'Workstation Comfort' ).closest( 'li' );
		expect( within( row ).getByText( /Category/ ) ).toBeInTheDocument();
		expect( within( row ).getByRole( 'link', { name: 'Edit Workstation Comfort' } ) ).toHaveAttribute( 'href', termRow.edit_url );
		expect( within( row ).getByText( 'Not checked after the change' ) ).toBeInTheDocument();
	} );

	it( 'labels the other object kinds a run can write in plain words', () => {
		const kinds = {
			post_tag: 'Tag',
			shop_coupon: 'Coupon',
			shop_order: 'Order',
			shipping_zone: 'Shipping zone',
			tax_rate: 'Tax rate',
			product: 'Product',
		};
		Object.entries( kinds ).forEach( ( [ type, label ], index ) => {
			const { unmount } = render(
				<ReportView report={ { ...baseReport, changes: [ { ...termRow, object_type: type, object_id: `x:${ index }`, title: `Row ${ index }` } ] } } steps={ [] } />
			);
			expect( screen.getByText( `Row ${ index }` ).closest( 'li' ) ).toHaveTextContent( label );
			unmount();
		} );
	} );

	it( 'falls back to the bare number, not the internal prefix, when a prefixed row has no title', () => {
		render( <ReportView report={ { ...baseReport, changes: [ { ...termRow, title: '' } ] } } steps={ [] } /> );

		expect( screen.getByText( '#5' ) ).toBeInTheDocument();
		expect( screen.queryByText( /term:5/ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'ReportView gate mode, withheld roles and grants', () => {
	it( 'names the built-in gate mode and the server line about where approvals are recorded', () => {
		const report = {
			...baseReport,
			gate_mode_note: 'Approvals for this run are recorded only on this page.',
		};
		render( <ReportView report={ report } steps={ [] } /> );

		expect( screen.getByText( /Gate mode:/ ) ).toHaveTextContent( 'Built-in approvals' );
		expect( screen.getByText( 'Approvals for this run are recorded only on this page.' ) ).toBeInTheDocument();
	} );

	it( 'names SenroGate mode and carries no built-in line', () => {
		render( <ReportView report={ { ...baseReport, gate_mode: 'agent_safety' } } steps={ [] } /> );

		expect( screen.getByText( /Gate mode:/ ) ).toHaveTextContent( 'SenroGate' );
		expect( screen.queryByText( /recorded only on this page/ ) ).not.toBeInTheDocument();
	} );

	it( 'lists withheld roles next to the gate mode, and nothing when none', () => {
		const { rerender } = render(
			<ReportView report={ { ...baseReport, withheld_roles: [ 'upload', 'generate' ] } } steps={ [] } />
		);
		expect( screen.getByText( /Withheld roles:/ ) ).toHaveTextContent( 'upload, generate' );

		rerender( <ReportView report={ baseReport } steps={ [] } /> );
		expect( screen.queryByText( /Withheld roles:/ ) ).not.toBeInTheDocument();
	} );

	it( 'lists grants with their expiry in SenroGate mode', () => {
		const report = {
			...baseReport,
			gate_mode: 'agent_safety',
			grants: [
				{
					verb: 'senroflux/publish-post',
					status: 'revoked',
					remaining: 1,
					expires_at: '2026-10-03T10:00:00Z',
				},
			],
		};
		render( <ReportView report={ report } steps={ [] } /> );

		expect( screen.getByRole( 'heading', { name: 'Pre-approvals' } ) ).toBeInTheDocument();
		const item = screen.getByText( 'senroflux/publish-post' ).closest( 'li' );
		expect( within( item ).getByText( /Expires/ ) ).toBeInTheDocument();
		expect( item.querySelector( 'time' ) ).toHaveAttribute( 'datetime', '2026-10-03T10:00:00Z' );
	} );

	it( 'shows no grants section when there are none', () => {
		render( <ReportView report={ baseReport } steps={ [] } /> );

		expect( screen.queryByRole( 'heading', { name: 'Pre-approvals' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'ReportView adopted objects and leftovers (S7)', () => {
	const adopted = [
		{ object_id: '7', title: 'About', status: 'publish', edit_url: 'https://example.test/wp-admin/post.php?post=7&action=edit' },
	];
	const leftovers = [ { object_id: '2', title: 'Sample Page', status: 'publish', edit_url: null } ];

	it( 'lists Adopted objects and a "Left for you to remove" list', () => {
		render( <ReportView report={ { ...baseReport, adopted, left_for_you: leftovers } } steps={ [] } /> );

		const adoptedList = screen.getByRole( 'heading', { name: 'Adopted' } ).nextElementSibling;
		expect( within( adoptedList ).getByText( 'About' ) ).toBeInTheDocument();
		expect( within( adoptedList ).getByRole( 'link', { name: 'Edit About' } ) ).toBeInTheDocument();

		const leftList = screen.getByRole( 'heading', { name: 'Left for you to remove' } ).nextElementSibling;
		expect( within( leftList ).getByText( 'Sample Page' ) ).toBeInTheDocument();
	} );

	it( 'shows neither section when the plan named none', () => {
		render( <ReportView report={ baseReport } steps={ [] } /> );

		expect( screen.queryByRole( 'heading', { name: 'Adopted' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'Left for you to remove' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'ReportView store report (J13)', () => {
	const storeStep = {
		kind: 'tool_result',
		status: 'ok',
		tool_name: 'wpab__senroflux__store-report',
		message: {
			parts: [
				{
					functionResponse: {
						name: 'wpab__senroflux__store-report',
						response: {
							from: '2026-09-01',
							to: '2026-09-30',
							order_count: 4,
							gross_sales: 120.5,
							net_sales: 100,
							refunds_total: 20.5,
							currency: 'USD',
							low_stock_products: [ 5, 9 ],
						},
					},
				},
			],
		},
	};

	it( 'renders the store report the run produced', () => {
		render( <ReportView report={ baseReport } steps={ [ storeStep ] } /> );

		expect( screen.getByRole( 'heading', { name: 'Store report' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Orders' ).nextElementSibling ).toHaveTextContent( '4' );
		expect( screen.getByText( 'Low stock products' ).nextElementSibling ).toHaveTextContent( '2' );
	} );

	it( 'ignores an errored store-report call and the save-store-report call', () => {
		const errored = { ...storeStep, status: 'error' };
		const save = { ...storeStep, tool_name: 'wpab__senroflux__save-store-report' };
		render( <ReportView report={ baseReport } steps={ [ errored, save ] } /> );

		expect( screen.queryByRole( 'heading', { name: 'Store report' } ) ).not.toBeInTheDocument();
	} );
} );
