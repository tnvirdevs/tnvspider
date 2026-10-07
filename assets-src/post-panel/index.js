/**
 * Block editor panel: page translation mode, coverage, "Translate this page now".
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

const META_KEY = '_wst_mode';
const config = window.wstPostPanel || {
	canTranslate: false,
	modes: {},
	language: '',
};

const SOURCES = {
	page: __( 'this page', 'wp-site-translator' ),
	path: __( 'path rule', 'wp-site-translator' ),
	site: __( 'site mode', 'wp-site-translator' ),
};

function coverageText( coverage ) {
	if ( ! coverage || 0 === coverage.total ) {
		return __(
			'No strings recorded yet: the translated page has not been visited or scanned.',
			'wp-site-translator'
		);
	}
	return sprintf(
		/* translators: 1: translated strings, 2: all strings, 3: percent */
		__(
			'Translated: %1$d of %2$d strings (%3$d%%).',
			'wp-site-translator'
		),
		coverage.translated,
		coverage.total,
		coverage.percent
	);
}

function TranslationPanel() {
	const { postType, postId, isSaving } = useSelect( ( select ) => {
		const editor = select( 'core/editor' );
		return {
			postType: editor.getCurrentPostType(),
			postId: editor.getCurrentPostId(),
			isSaving: editor.isSavingPost() && ! editor.isAutosavingPost(),
		};
	}, [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const [ info, setInfo ] = useState( null );
	const [ status, setStatus ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = useCallback( () => {
		if ( ! config.canTranslate || ! postId ) {
			return;
		}
		apiFetch( { path: `/wst/v1/pages?include[]=${ postId }` } )
			.then( ( items ) => setInfo( items[ 0 ] || null ) )
			.catch( ( error ) =>
				setStatus( { type: 'error', text: error.message } )
			);
	}, [ postId ] );

	// Reload after each save so the applied mode and coverage stay current.
	useEffect( () => {
		if ( ! isSaving ) {
			load();
		}
	}, [ isSaving, load ] );

	if ( ! meta || ! ( META_KEY in meta ) ) {
		return null;
	}
	const mode = meta[ META_KEY ] || 'inherit';
	const unsaved = info && info.mode !== mode;
	const effective = info ? info.effective : null;
	const canQueue =
		config.canTranslate &&
		info &&
		! unsaved &&
		'off' !== effective.mode &&
		info.coverage.total > info.coverage.translated;

	const translateNow = () => {
		setBusy( true );
		setStatus( null );
		apiFetch( {
			path: '/wst/v1/strings/translate',
			method: 'POST',
			data: { post_id: postId, mode: 'queue' },
		} )
			.then( ( result ) =>
				setStatus( {
					type: 'success',
					text: sprintf(
						/* translators: %d: number of strings */
						_n(
							'%d string queued for translation.',
							'%d strings queued for translation.',
							result.queued,
							'wp-site-translator'
						),
						result.queued
					),
				} )
			)
			.catch( ( error ) =>
				setStatus( { type: 'error', text: error.message } )
			)
			.finally( () => {
				setBusy( false );
				load();
			} );
	};

	return (
		<PluginDocumentSettingPanel
			name="wst-translation"
			title={ sprintf(
				/* translators: %s: target language name */
				__( 'Translation (%s)', 'wp-site-translator' ),
				config.language
			) }
		>
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Translation mode', 'wp-site-translator' ) }
				value={ mode }
				options={ Object.entries( config.modes ).map(
					( [ value, label ] ) => ( { value, label } )
				) }
				onChange={ ( value ) =>
					setMeta( { ...meta, [ META_KEY ]: value } )
				}
				help={ __(
					'The mode decides whether visits discover and queue new strings. Translations already made apply on every page.',
					'wp-site-translator'
				) }
			/>
			{ unsaved && (
				<p>
					{ __(
						'Save the post to apply the new mode.',
						'wp-site-translator'
					) }
				</p>
			) }
			{ effective && ! unsaved && (
				<p>
					{ sprintf(
						/* translators: 1: mode label, 2: where it comes from */
						__( 'Applies now: %1$s (%2$s).', 'wp-site-translator' ),
						config.modes[ effective.mode ] || effective.mode,
						SOURCES[ effective.source ] || effective.source
					) }
				</p>
			) }
			{ config.canTranslate && ! info && ! status && <Spinner /> }
			{ info && <p>{ coverageText( info.coverage ) }</p> }
			{ canQueue && (
				<Button
					variant="secondary"
					onClick={ translateNow }
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Translate this page now', 'wp-site-translator' ) }
				</Button>
			) }
			{ status && (
				<Notice status={ status.type } isDismissible={ false }>
					{ status.text }
				</Notice>
			) }
		</PluginDocumentSettingPanel>
	);
}

registerPlugin( 'wst-post-panel', { render: TranslationPanel } );
