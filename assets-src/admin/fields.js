/**
 * Settings fields bound to the shared draft, with server-side errors.
 */
import {
	Card,
	CardBody,
	CardHeader,
	RadioControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

export function Section( { title, description, children } ) {
	return (
		<Card className="wst-section">
			<CardHeader>
				<h2>{ title }</h2>
			</CardHeader>
			<CardBody>
				{ description && (
					<p className="wst-section__description">{ description }</p>
				) }
				{ children }
			</CardBody>
		</Card>
	);
}

export function FieldError( { error } ) {
	return error ? (
		<p className="wst-field-error" role="alert">
			{ error }
		</p>
	) : null;
}

export function Toggle( { name, label, help, draft, update, errors } ) {
	return (
		<div className="wst-field">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				checked={ !! draft[ name ] }
				onChange={ ( value ) => update( name, value ) }
			/>
			<FieldError error={ errors[ name ] } />
		</div>
	);
}

export function Choice( {
	name,
	label,
	help,
	options,
	draft,
	update,
	errors,
	radio = false,
} ) {
	const onChange = ( value ) => update( name, value );
	// RadioControl passes unknown props to every input, so it gets
	// `selected` only; SelectControl gets `value`.
	return (
		<div className="wst-field">
			{ radio ? (
				<RadioControl
					label={ label }
					help={ help }
					options={ options }
					selected={ draft[ name ] }
					onChange={ onChange }
				/>
			) : (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ label }
					help={ help }
					options={ options }
					value={ draft[ name ] }
					onChange={ onChange }
				/>
			) }
			<FieldError error={ errors[ name ] } />
		</div>
	);
}

export function Text( {
	name,
	label,
	help,
	draft,
	update,
	errors,
	placeholder,
	type = 'text',
} ) {
	return (
		<div className="wst-field">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				help={ help }
				type={ type }
				placeholder={ placeholder }
				value={ draft[ name ] }
				onChange={ ( value ) => update( name, value ) }
			/>
			<FieldError error={ errors[ name ] } />
		</div>
	);
}

/**
 * Integer setting; anything that is not a whole number is sent as typed so
 * the server rejects it with a message instead of it being dropped here.
 *
 * @param {Object}                                props        Field properties.
 * @param {string}                                props.name
 * @param {string}                                props.label
 * @param {string}                                props.help
 * @param {Object}                                props.draft
 * @param {(key: string, value: unknown) => void} props.update
 * @param {Object}                                props.errors
 * @param {number}                                props.min
 */
export function Integer( {
	name,
	label,
	help,
	draft,
	update,
	errors,
	min = 0,
} ) {
	return (
		<div className="wst-field">
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				help={ help }
				type="number"
				min={ min }
				value={ String( draft[ name ] ) }
				onChange={ ( value ) =>
					update(
						name,
						/^\d+$/.test( value ) ? parseInt( value, 10 ) : value
					)
				}
			/>
			<FieldError error={ errors[ name ] } />
		</div>
	);
}

/**
 * A list setting edited one item per line. Lines are kept exactly as typed;
 * the shell trims them and drops empty ones when comparing and saving.
 *
 * @param {Object}                                props        Field properties.
 * @param {string}                                props.name
 * @param {string}                                props.label
 * @param {string}                                props.help
 * @param {Object}                                props.draft
 * @param {(key: string, value: unknown) => void} props.update
 * @param {Object}                                props.errors
 * @param {number}                                props.rows
 */
export function Lines( {
	name,
	label,
	help,
	draft,
	update,
	errors,
	rows = 5,
} ) {
	return (
		<div className="wst-field">
			<TextareaControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
				rows={ rows }
				value={ ( draft[ name ] || [] ).join( '\n' ) }
				onChange={ ( value ) => update( name, value.split( '\n' ) ) }
			/>
			<FieldError error={ errors[ name ] } />
		</div>
	);
}
