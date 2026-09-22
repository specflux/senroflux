import { render, screen } from '@testing-library/react';
import PinnedPlan from '../components/PinnedPlan';

/**
 * Stage 19c2 (RTL/i18n follow-ups): the "~N / M" progress counter had two
 * bugs — an untranslatable literal format string, and no bidi isolation (it
 * read "1 / 0~" in an RTL admin locale, i.e. "1 of 0"). This covers both: the
 * counter still renders the expected "~done / total" text (proving the
 * sprintf/__() wrap didn't change the visible format), and it is isolated
 * with `dir="ltr"` so a numeric ratio always reads LTR even in RTL UIs.
 */
describe( 'PinnedPlan progress counter (translatable format + LTR isolation)', () => {
	const plan = {
		goal: 'Publish the spring workshops page',
		steps: [ { text: 'Draft the copy', verbs: [ 'draft-copy' ] } ],
	};

	it( 'renders the approximate "~done / total" counter text', () => {
		render( <PinnedPlan plan={ plan } steps={ [] } /> );

		expect( screen.getByText( '~0 / 1' ) ).toBeInTheDocument();
	} );

	it( 'isolates the counter with dir="ltr" so the ratio never reads RTL', () => {
		render( <PinnedPlan plan={ plan } steps={ [] } /> );

		expect( screen.getByText( '~0 / 1' ) ).toHaveAttribute( 'dir', 'ltr' );
	} );

	it( 'marks the plan goal and step text as data with dir="auto"', () => {
		render( <PinnedPlan plan={ plan } steps={ [] } /> );

		expect( screen.getByText( plan.goal ) ).toHaveAttribute( 'dir', 'auto' );
		expect( screen.getByText( 'Draft the copy' ) ).toHaveAttribute( 'dir', 'auto' );
	} );
} );
