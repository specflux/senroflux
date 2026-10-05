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

describe( 'built-in mode counts only the verbs that are themselves Tier >= 1', () => {
	// Live J4: five steps of [Tier 0, Tier 1, Tier 0] verbs plus a read step.
	// The step tier is the max over its verbs (1), but only update-alt parks:
	// exactly 5 approvals happened, the card said 15.
	const liveJ4Steps = () => [
		...Array.from( { length: 5 }, ( _, i ) => ( {
			text: `Alt text ${ i + 1 }`,
			verbs: [ 'posts/generate-alt-text', 'posts/update-alt', 'posts/read-media' ],
			tier: 1,
			verb_tiers: {
				'posts/generate-alt-text': 0,
				'posts/update-alt': 1,
				'posts/read-media': 0,
			},
		} ) ),
		{ text: 'Read the post', verbs: [ 'posts/read' ], tier: 0, verb_tiers: { 'posts/read': 0 } },
	];

	it( 'the live J4 plan -> 5, not 15', () => {
		expect( planApprovalCount( { steps: liveJ4Steps() }, 'built_in' ) ).toBe( 5 );
	} );

	it( 'a verb missing from verb_tiers counts as approvable alongside a known Tier 0 verb', () => {
		const steps = [
			{
				text: 'Mixed',
				verbs: [ 'posts/read', 'posts/mystery' ],
				tier: 2,
				verb_tiers: { 'posts/read': 0 },
			},
		];
		expect( planApprovalCount( { steps }, 'built_in' ) ).toBe( 1 );
	} );

	it( 'a step with no tier information at all counts every verb (fail closed)', () => {
		const steps = [ { text: 'Unknown', verbs: [ 'a/one', 'a/two' ] } ];
		expect( planApprovalCount( { steps }, 'built_in' ) ).toBe( 2 );
	} );

	it( 'a plan stored before verb_tiers existed falls back to the step tier', () => {
		const steps = [
			{ text: 'Old write', verbs: [ 'a/one', 'a/two' ], tier: 1 },
			{ text: 'Old read', verbs: [ 'a/three' ], tier: 0 },
		];
		expect( planApprovalCount( { steps }, 'built_in' ) ).toBe( 2 );
	} );

	it( 'Agent Tollgate mode still returns null for the same plan', () => {
		expect( planApprovalCount( { steps: liveJ4Steps() }, 'agent_safety' ) ).toBeNull();
	} );
} );

describe( 'Agent Tollgate mode shows no approval-count paragraph at all', () => {
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

describe( 'a step naming N existing objects asks once per object per verb', () => {
	const objects = ( n ) => Array.from( { length: n }, ( _, i ) => ( { id: String( i + 1 ), title: '', type: 'product' } ) );

	it( 'counts max(1, named objects) for each qualifying verb', () => {
		const steps = [
			{ text: 'Price three products', verbs: [ 'commerce/price-change' ], tier: 2, objects: objects( 3 ) },
			{ text: 'Price one without naming', verbs: [ 'commerce/price-change' ], tier: 2 },
			{ text: 'Read', verbs: [ 'commerce/product-read' ], tier: 0, objects: objects( 4 ) },
		];

		// 3 + 1 + 0: the Tier 0 read still counts nothing however many it names.
		expect( planApprovalCount( { steps }, 'built_in' ) ).toBe( 4 );
	} );

	it( 'multiplies every qualifying verb of the step', () => {
		const steps = [
			{ text: 'Update two', verbs: [ 'posts/update-draft', 'posts/set-featured-image' ], tier: 1, objects: objects( 2 ) },
		];

		expect( planApprovalCount( { steps }, 'built_in' ) ).toBe( 4 );
	} );
} );
