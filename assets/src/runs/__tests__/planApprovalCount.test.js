/**
 * Ported from the deleted `RunsScreenParkCardsTest` (0c1107e) after the
 * coordinator flagged that retiring the PHP plan card silently dropped a
 * safety disclosure: the built-in gate's whole guarantee is that a human
 * sees how many approvals a plan implies BEFORE accepting it. The original
 * defect (live run): counting plan STEPS instead of Tier >= 1 VERB
 * OCCURRENCES told the approver "approve 2" when the real answer was 4.
 */

import { render, screen } from '@testing-library/react';
import { planApprovalCount } from '../utils';
import ParkCard from '../components/ParkCard';

const livePlanSteps = () => [
	{ text: 'Create the draft post', verbs: [ 'posts/create-draft' ], tier: 1 },
	{ text: 'Generate the image', verbs: [ 'posts/media-generate' ], tier: 1 },
	{ text: 'Update the alt text', verbs: [ 'posts/update-alt' ], tier: 1 },
	{ text: 'Set the featured image', verbs: [ 'posts/set-featured-image' ], tier: 1 },
	{ text: 'Read the post', verbs: [ 'posts/read' ], tier: 0 },
	{ text: 'Search for media', verbs: [ 'posts/media-search' ], tier: 0 },
];

const liveMultiVerbPlanSteps = () => [
	{ text: 'Create the draft post', verbs: [ 'posts/create-draft' ], tier: 1 },
	{
		text: 'Generate and attach the image',
		verbs: [ 'posts/media-generate', 'posts/update-alt', 'posts/set-featured-image' ],
		tier: 1,
	},
	{ text: 'Read the post', verbs: [ 'posts/read' ], tier: 0 },
];

describe( 'built-in mode counts every Tier >= 1 verb occurrence, not every step', () => {
	it( 'six steps, four of them Tier >= 1 (one verb each) -> 4', () => {
		expect( planApprovalCount( { steps: livePlanSteps() }, 'built_in' ) ).toBe( 4 );
	} );

	it( 'a single step grouping three Tier >= 1 verbs counts as 3, not 1 (the live-run defect)', () => {
		// The exact live-run shape that broke the old step-counting logic:
		// [create-draft], [media-generate, update-alt, set-featured-image], [read]
		// -> 1 + 3 + 0 = 4, never "approve 2".
		expect( planApprovalCount( { steps: liveMultiVerbPlanSteps() }, 'built_in' ) ).toBe( 4 );
	} );

	it( 'renders the exact disclosure sentence in the plan park card', () => {
		render(
			<ParkCard kind="plan" gateMode="built_in" payload={ { steps: livePlanSteps(), assumptions: [] } } />
		);
		expect( screen.getByText( 'This plan will ask you to approve 4 changes.' ) ).toBeInTheDocument();
	} );
} );

describe( 'Agent Safety mode shows no approval-count paragraph at all', () => {
	it( 'planApprovalCount returns null in agent_safety mode', () => {
		expect( planApprovalCount( { steps: livePlanSteps() }, 'agent_safety' ) ).toBeNull();
	} );

	it( 'the plan park card renders no .senroflux-plan-approval-count node', () => {
		const { container } = render(
			<ParkCard kind="plan" gateMode="agent_safety" payload={ { steps: livePlanSteps(), assumptions: [] } } />
		);
		expect( container.querySelector( '.senroflux-plan-approval-count' ) ).toBeNull();
	} );
} );
