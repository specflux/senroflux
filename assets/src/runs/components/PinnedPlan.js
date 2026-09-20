import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { planProgress } from '../utils';

/** S10: pinned plan above the chat, with a Hide link and the pre-plan sentence. */
export default function PinnedPlan( { plan, steps } ) {
	const [ hidden, setHidden ] = useState( false );

	if ( ! plan ) {
		return (
			<div className="senroflux-pinned-plan senroflux-pinned-plan-none">
				<p>{ __( 'No plan yet. Nothing will change until you accept one.', 'senroflux' ) }</p>
			</div>
		);
	}

	const progress = planProgress( plan, steps );
	const done = progress.reduce( ( sum, p ) => sum + p.done, 0 );
	const total = progress.reduce( ( sum, p ) => sum + p.total, 0 );

	return (
		<div className="senroflux-pinned-plan">
			<div className="senroflux-pinned-plan-header">
				<span className="senroflux-pinned-plan-goal">{ plan.goal }</span>
				<span className="senroflux-pinned-plan-count">{ sprintf( '%d / %d', done, total ) }</span>
				<button type="button" className="senroflux-pinned-plan-toggle" onClick={ () => setHidden( ! hidden ) }>
					{ hidden ? __( 'Show', 'senroflux' ) : __( 'Hide', 'senroflux' ) }
				</button>
			</div>
			{ ! hidden && (
				<ol className="senroflux-pinned-plan-steps">
					{ ( plan.steps || [] ).map( ( planStep, index ) => (
						<li key={ index } className={ progress[ index ] && progress[ index ].waiting ? 'is-waiting' : '' }>
							<span className="senroflux-plan-step-text">{ planStep.text }</span>
							{ progress[ index ] && progress[ index ].total > 1 && (
								<span className="senroflux-plan-step-count">
									{ sprintf( '%d / %d', progress[ index ].done, progress[ index ].total ) }
								</span>
							) }
							{ progress[ index ] && progress[ index ].waiting && (
								<span className="senroflux-plan-step-waiting">{ __( '· waiting for you', 'senroflux' ) }</span>
							) }
						</li>
					) ) }
				</ol>
			) }
		</div>
	);
}
