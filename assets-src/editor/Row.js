/**
 * One string: original, editable translation (autosave on blur), status,
 * warnings and row actions.
 */
import { Button } from '@wordpress/components';
import { forwardRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Chip } from '../admin/format';
import { parts, plainText } from './text';

const STATUS = {
	none: [ 'neutral', __( 'Untranslated', 'wp-site-translator' ) ],
	machine: [ 'warning', __( 'Machine', 'wp-site-translator' ) ],
	manual: [ 'ok', __( 'Manual', 'wp-site-translator' ) ],
};

const WARNINGS = {
	segmented: __(
		'Translated piece by piece (a plain-text provider could not keep the tags in one sentence): word order may be off.',
		'wp-site-translator'
	),
	tags_repaired: __(
		'The provider changed the tag syntax; it was repaired. Check the result.',
		'wp-site-translator'
	),
	failed: __( 'Machine translation failed:', 'wp-site-translator' ),
};

function Original( { item } ) {
	if ( item.kind !== 'inline' ) {
		return <span className="wst-original__text">{ item.original }</span>;
	}
	return (
		<span className="wst-original__text">
			{ parts( item.original ).map( ( part, index ) =>
				part.tag ? (
					<code
						key={ index }
						className="wst-tag"
						title={ part.value }
					>
						{ part.value.replace(
							/^<\/?\s*([a-z0-9-]+)[\s\S]*$/i,
							( match, name ) =>
								( part.value.startsWith( '</' ) ? '/' : '' ) +
								name
						) }
					</code>
				) : (
					<span key={ index }>{ plainText( part.value ) }</span>
				)
			) }
		</span>
	);
}

function Row(
	{
		item,
		language,
		draft,
		state,
		canMt,
		onDraft,
		onSave,
		onReset,
		onMove,
		onFocus,
		onAction,
		selected,
	},
	ref
) {
	const value = draft === undefined ? item.translation || '' : draft;
	const [ tone, label ] = STATUS[ item.status ] || STATUS.none;
	const fieldId = `wst-translation-${ item.id }`;
	const busy = state && state.busy;

	const onKeyDown = ( event ) => {
		if ( ( event.ctrlKey || event.metaKey ) && event.key === 'Enter' ) {
			event.preventDefault();
			onSave( item, value );
			onMove( item, 1 );
		} else if ( event.key === 'Escape' ) {
			event.preventDefault();
			onReset( item );
		} else if (
			event.altKey &&
			( event.key === 'ArrowDown' || event.key === 'ArrowUp' )
		) {
			event.preventDefault();
			onMove( item, event.key === 'ArrowDown' ? 1 : -1 );
		}
	};

	return (
		<li
			className={ `wst-row${ selected ? ' is-selected' : '' }${ state && state.error ? ' has-error' : '' }` }
			data-id={ item.id }
		>
			<div className="wst-row__original">
				<span className="wst-kind" title={ item.kind }>
					{ item.kind === 'inline' ? '</>' : 'T' }
				</span>
				<Original item={ item } />
				{ item.global && (
					<Chip tone="neutral">
						{ __( 'Site-wide', 'wp-site-translator' ) }
					</Chip>
				) }
			</div>
			<div className="wst-row__translation">
				<label className="screen-reader-text" htmlFor={ fieldId }>
					{ sprintf(
						/* translators: %s: original text */
						__( 'Translation of: %s', 'wp-site-translator' ),
						plainText( item.original ).slice( 0, 80 )
					) }
				</label>
				<textarea
					id={ fieldId }
					ref={ ref }
					dir="auto"
					lang={ language.tag }
					rows={ Math.min(
						8,
						Math.max(
							1,
							Math.ceil( value.length / 60 ),
							value.split( '\n' ).length
						)
					) }
					value={ value }
					disabled={ busy }
					onChange={ ( event ) =>
						onDraft( item, event.target.value )
					}
					onFocus={ () => onFocus( item ) }
					onBlur={ () => onSave( item, value ) }
					onKeyDown={ onKeyDown }
					placeholder={ __(
						'Type the translation…',
						'wp-site-translator'
					) }
				/>
				{ item.kind === 'inline' && (
					<p className="wst-hint">
						{ __(
							'Keep every tag exactly as in the original; you may move tags with their words.',
							'wp-site-translator'
						) }
					</p>
				) }
				<div className="wst-row__meta">
					<Chip tone={ tone }>{ label }</Chip>
					{ item.provider && item.status === 'machine' && (
						<span className="wst-muted">{ item.provider }</span>
					) }
					{ item.queue === 'pending' ||
					item.queue === 'processing' ? (
						<Chip tone="neutral">
							{ __(
								'Waiting for machine translation',
								'wp-site-translator'
							) }
						</Chip>
					) : null }
					<span className="wst-row__state" aria-live="polite">
						{ state &&
							state.busy &&
							__( 'Saving…', 'wp-site-translator' ) }
						{ state &&
							state.saved &&
							! state.busy &&
							__( 'Saved', 'wp-site-translator' ) }
					</span>
				</div>
				{ item.warnings.map( ( code ) => (
					<p key={ code } className="wst-warning">
						{ WARNINGS[ code ] || code }{ ' ' }
						{ code === 'failed' && item.error }
					</p>
				) ) }
				{ state && state.error && (
					<p className="wst-row__error" role="alert">
						{ state.error }{ ' ' }
						<Button
							variant="link"
							onClick={ () => onSave( item, value, true ) }
						>
							{ __( 'Try again', 'wp-site-translator' ) }
						</Button>
					</p>
				) }
				<div className="wst-row__actions">
					{ item.status === 'none' && canMt && (
						<Button
							variant="secondary"
							size="small"
							disabled={ busy }
							onClick={ () => onAction( item, 'suggest' ) }
						>
							{ __(
								'Translate with provider',
								'wp-site-translator'
							) }
						</Button>
					) }
					{ item.status === 'machine' && (
						<Button
							variant="secondary"
							size="small"
							disabled={ busy }
							onClick={ () => onAction( item, 'manual' ) }
						>
							{ __( 'Keep as manual', 'wp-site-translator' ) }
						</Button>
					) }
					{ item.status === 'manual' && canMt && (
						<Button
							variant="secondary"
							size="small"
							disabled={ busy }
							onClick={ () => onAction( item, 'revert' ) }
						>
							{ __(
								'Revert to machine translation',
								'wp-site-translator'
							) }
						</Button>
					) }
					{ item.status !== 'none' && (
						<Button
							variant="tertiary"
							size="small"
							isDestructive
							disabled={ busy }
							onClick={ () => onAction( item, 'remove' ) }
						>
							{ __( 'Remove translation', 'wp-site-translator' ) }
						</Button>
					) }
				</div>
			</div>
		</li>
	);
}

export default forwardRef( Row );
