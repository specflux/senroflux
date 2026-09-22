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

	// `planProgress()` is a best-effort sequential match (see its docblock),
	// not an authoritative index the REST payload carries — the "~" prefix
	// and title make that visible in the UI instead of only in a comment, so
	// the count is never presented as more exact than it is.
	const approxTitle = __(
		'This count is estimated by matching the plan against executed calls in order; it may be briefly wrong if calls run out of the plan\'s listed order.',
		'senroflux'
	);
	const approxCount = ( doneCount, totalCount ) =>
		sprintf(
			/* translators: 1: steps done, 2: total steps; "~" marks the count as approximate. */
			__( '~%1$d / %2$d', 'senroflux' ),
			doneCount,
			totalCount
		);

	return (
		<div className="senroflux-pinned-plan">
			<div className="senroflux-pinned-plan-header">
				<span className="senroflux-pinned-plan-goal" dir="auto">{ plan.goal }</span>
				<span className="senroflux-pinned-plan-count" dir="ltr" title={ approxTitle }>
					{ approxCount( done, total ) }
				</span>
				<button type="button" className="senroflux-pinned-plan-toggle" onClick={ () => setHidden( ! hidden ) }>
					{ hidden ? __( 'Show', 'senroflux' ) : __( 'Hide', 'senroflux' ) }
				</button>
			</div>
			{ ! hidden && (
				<ol className="senroflux-pinned-plan-steps">
					{ ( plan.steps || [] ).map( ( planStep, index ) => (
						<li key={ index } className={ progress[ index ] && progress[ index ].waiting ? 'is-waiting' : '' }>
							<span className="senroflux-plan-step-text" dir="auto">{ planStep.text }</span>
							{ progress[ index ] && progress[ index ].total > 1 && (
								<span className="senroflux-plan-step-count" dir="ltr" title={ approxTitle }>
									{ approxCount( progress[ index ].done, progress[ index ].total ) }
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
