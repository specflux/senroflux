/**
 * Runs-pack fix: the message box's pack picker. A pack-less start deadlocks
 * every run (the Runs screen's own consumer has an empty verb map without
 * one — {@see \Specflux\SenroFlux\Tools\VerbTier}), so `MessageBox` must
 * never let `onSend` fire without a pack chosen whenever one is required.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import MessageBox from '../components/MessageBox';

describe( 'MessageBox pack picker', () => {
	it( 'preselects the single pack and starts immediately with it', () => {
		const onSend = jest.fn();
		render(
			<MessageBox state="idle" onSend={ onSend } packs={ [ { name: 'pages', label: 'pages' } ] } />
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Publish the spring page' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Start run' } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Publish the spring page', 'pages', null );
	} );

	it( 'disables Start until a pack is chosen when there are two or more packs', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [
					{ name: 'pages', label: 'pages' },
					{ name: 'site', label: 'site' },
				] }
			/>
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do something' },
		} );

		const startButton = screen.getByRole( 'button', { name: 'Start run' } );
		expect( startButton ).toBeDisabled();

		fireEvent.click( startButton );
		expect( onSend ).not.toHaveBeenCalled();

		fireEvent.change( screen.getByLabelText( 'Pack' ), { target: { value: 'site' } } );
		expect( startButton ).not.toBeDisabled();

		fireEvent.click( startButton );
		expect( onSend ).toHaveBeenCalledWith( 'Do something', 'site', null );
	} );

	it( 'renders no pack picker and keeps Start disabled when there are no packs', () => {
		const onSend = jest.fn();
		render( <MessageBox state="idle" onSend={ onSend } packs={ [] } /> );

		expect( screen.queryByLabelText( 'Pack' ) ).not.toBeInTheDocument();

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do something' },
		} );

		const startButton = screen.getByRole( 'button', { name: 'Start run' } );
		expect( startButton ).toBeDisabled();

		fireEvent.click( startButton );
		expect( onSend ).not.toHaveBeenCalled();
	} );
} );

describe( 'MessageBox unavailable-packs notice', () => {
	it( 'renders no notice when every pack is runnable', () => {
		render( <MessageBox state="idle" onSend={ jest.fn() } packs={ [ { name: 'pages', label: 'pages' } ] } /> );

		expect( screen.queryByText( /pack unavailable/i ) ).not.toBeInTheDocument();
	} );

	it( 'lists an unavailable pack and its reason alongside a runnable one', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				unavailablePacks={ [ { name: 'widgets', reason: 'Bind `user:1` to the widgets pack.' } ] }
			/>
		);

		expect( screen.getByText( 'widgets pack unavailable: Bind `user:1` to the widgets pack.' ) ).toBeInTheDocument();
	} );

	it( 'zero-runnable case: renders no picker but still explains every pack via the notice', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [] }
				unavailablePacks={ [
					{ name: 'pages', reason: 'The run\'s skills exceed the instruction ceiling.' },
					{ name: 'site', reason: 'Bind `user:1` to the site pack.' },
				] }
			/>
		);

		expect( screen.queryByLabelText( 'Pack' ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( 'pages pack unavailable: The run\'s skills exceed the instruction ceiling.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'site pack unavailable: Bind `user:1` to the site pack.' ) ).toBeInTheDocument();

		const startButton = screen.getByRole( 'button', { name: 'Start run' } );
		expect( startButton ).toBeDisabled();
	} );
} );

/**
 * The model picker in the message box: hidden when there is nothing to
 * choose, grouped by provider when there is, and its choice reaches `onSend`
 * as the documented `{ provider, id } | null` shape (the third argument,
 * after `goal` and `pack`).
 */
const modelChoices = {
	openai: {
		name: 'OpenAI',
		models: [
			{ id: 'gpt-5', name: 'GPT-5' },
			{ id: 'gpt-5-mini', name: 'GPT-5 Mini' },
		],
	},
	anthropic: {
		name: 'Anthropic',
		models: [ { id: 'claude-opus', name: 'Claude Opus' } ],
	},
};

describe( 'model picker visibility', () => {
	it( 'renders no model select at all when modelChoices is empty', () => {
		render( <MessageBox state="idle" onSend={ () => {} } packs={ [ { name: 'pages', label: 'pages' } ] } modelChoices={ {} } /> );
		expect( screen.queryByLabelText( 'Model' ) ).not.toBeInTheDocument();
	} );

	it( 'renders no model select at all when modelChoices is omitted', () => {
		render( <MessageBox state="idle" onSend={ () => {} } packs={ [ { name: 'pages', label: 'pages' } ] } /> );
		expect( screen.queryByLabelText( 'Model' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a select grouped by provider, Automatic first', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ () => {} }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				modelChoices={ modelChoices }
			/>
		);

		const select = screen.getByLabelText( 'Model' );
		const options = Array.from( select.querySelectorAll( 'option' ) );
		expect( options[ 0 ] ).toHaveTextContent( 'Automatic (let WordPress choose)' );

		const groups = Array.from( select.querySelectorAll( 'optgroup' ) ).map( ( g ) => g.label );
		expect( groups ).toEqual( [ 'OpenAI', 'Anthropic' ] );

		expect( screen.getByText( 'GPT-5' ) ).toBeInTheDocument();
		expect( screen.getByText( 'GPT-5 Mini' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Claude Opus' ) ).toBeInTheDocument();
	} );
} );

describe( 'model picker selection reaches onSend', () => {
	it( 'calls onSend with null when left on Automatic', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				modelChoices={ modelChoices }
			/>
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do the thing' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /start run/i } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Do the thing', 'pages', null );
	} );

	it( 'calls onSend with the chosen {provider, id} pair', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				modelChoices={ modelChoices }
			/>
		);

		fireEvent.change( screen.getByLabelText( 'Model' ), { target: { value: '2' } } );
		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do the thing' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /start run/i } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Do the thing', 'pages', { provider: 'anthropic', id: 'claude-opus' } );
	} );
} );
