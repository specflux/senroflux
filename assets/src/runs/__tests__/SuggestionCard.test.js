import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import SuggestionCard from '../components/SuggestionCard';

/**
 * 0.3 S20: the brief-suggestion UI ported into React. The split this proves
 * is the whole point of the stage — an administrator (`manage_options`) gets
 * Save/Dismiss with editable text; anyone else gets the text as read-only,
 * copyable content and NO buttons at all (the REST route would 403 a
 * non-admin regardless, so offering buttons that always fail would be a
 * lie). `canManageBrief` is always passed in as if server-computed — this
 * component never re-derives it from a role guess.
 */
describe( 'SuggestionCard: administrator view', () => {
	const suggestion = { seq: 3, text: 'Add our returns policy to the brief.', status: 'pending' };

	it( 'shows editable text with Save and Dismiss', () => {
		render( <SuggestionCard suggestion={ suggestion } canManageBrief={ true } onResolve={ () => Promise.resolve() } /> );

		expect( screen.getByRole( 'textbox', { name: 'Suggested brief text' } ) ).toHaveValue( suggestion.text );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Dismiss' } ) ).toBeInTheDocument();
	} );

	it( 'calls onResolve( seq, "save", text ) with the (possibly edited) text', async () => {
		const onResolve = jest.fn( () => Promise.resolve( { text: 'Edited text.' } ) );
		render( <SuggestionCard suggestion={ suggestion } canManageBrief={ true } onResolve={ onResolve } /> );

		const textarea = screen.getByRole( 'textbox', { name: 'Suggested brief text' } );
		fireEvent.change( textarea, { target: { value: 'Edited text.' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( onResolve ).toHaveBeenCalledWith( 3, 'save', 'Edited text.' ) );
	} );

	it( 'calls onResolve( seq, "dismiss", text ) on Dismiss', async () => {
		const onResolve = jest.fn( () => Promise.resolve( {} ) );
		render( <SuggestionCard suggestion={ suggestion } canManageBrief={ true } onResolve={ onResolve } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Dismiss' } ) );

		await waitFor( () => expect( onResolve ).toHaveBeenCalledWith( 3, 'dismiss', suggestion.text ) );
	} );

	it( 'surfaces a refusal (e.g. brief_too_long) visibly rather than truncating silently', async () => {
		const onResolve = jest.fn( () =>
			Promise.reject( Object.assign( new Error( 'The site brief may be at most 2000 characters.' ), { code: 'brief_too_long' } ) )
		);
		render( <SuggestionCard suggestion={ suggestion } canManageBrief={ true } onResolve={ onResolve } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( await screen.findByText( 'The site brief may be at most 2000 characters.' ) ).toBeInTheDocument();
		// Retryable: the buttons must not be left stuck disabled.
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Save' } ) ).not.toBeDisabled() );
	} );

	it( 'renders no editable form once already resolved', () => {
		render(
			<SuggestionCard
				suggestion={ { ...suggestion, status: 'saved' } }
				canManageBrief={ true }
				onResolve={ () => Promise.resolve() }
			/>
		);

		expect( screen.queryByRole( 'button', { name: 'Save' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Dismiss' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Saved to the site brief.' ) ).toBeInTheDocument();
	} );
} );

describe( 'SuggestionCard: non-administrator view', () => {
	const suggestion = { seq: 5, text: 'Mention our extended warranty.', status: 'pending' };

	it( 'shows the text read-only, with no Save/Dismiss buttons at all', () => {
		render( <SuggestionCard suggestion={ suggestion } canManageBrief={ false } onResolve={ () => Promise.resolve() } /> );

		expect( screen.getByText( suggestion.text ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Save' } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Dismiss' } ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'An administrator can add this to the site brief.', { exact: false } ) ).toBeInTheDocument();
	} );

	it( 'never offers a button even without an onResolve handler at all', () => {
		render( <SuggestionCard suggestion={ suggestion } canManageBrief={ false } /> );

		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
	} );
} );
