/**
 * Form controls bound to the design.
 *
 * These are also handed to add-ons (Authlify Pro) through the section
 * registry, so extra panels look and behave exactly like the built-in ones.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useRef } from '@wordpress/element';
import {
	Button,
	ColorPicker,
	Dropdown,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { getIn, imageUrl } from './utils';

/**
 * One sidebar section: a collapsible panel with evenly spaced fields.
 *
 * @param {Object}  props
 * @param {string}  props.title       Title.
 * @param {string}  props.badge       Optional badge ("Pro").
 * @param {boolean} props.initialOpen Open at first.
 * @param {Object}  props.children    Fields.
 */
export function Section( { title, badge, initialOpen = false, children, className } ) {
	return (
		<PanelBody
			className={ 'al-section' + ( className ? ' ' + className : '' ) }
			title={ badge ? (
				<>
					{ title }
					<span className="al-badge">{ badge }</span>
				</>
			) : title }
			initialOpen={ initialOpen }
		>
			<div className="al-fields">{ children }</div>
		</PanelBody>
	);
}

/**
 * A small heading that groups fields inside a section.
 *
 * @param {Object} props
 */
export function SubHead( { children } ) {
	return <h3 className="al-subhead">{ children }</h3>;
}

/**
 * Help text.
 *
 * @param {Object} props
 */
export function Help( { children } ) {
	return <p className="al-help">{ children }</p>;
}

/**
 * Colours listed together in one bordered group.
 *
 * @param {Object} props
 */
export function ColorGroup( { label, children, help } ) {
	return (
		<div className="al-color-group-wrap">
			{ label ? <span className="al-label">{ label }</span> : null }
			<div className="al-color-group">{ children }</div>
			{ help ? <Help>{ help }</Help> : null }
		</div>
	);
}

/**
 * Colour field: swatch, value, picker with alpha, back to default.
 *
 * When empty, the swatch shows the colour actually used (the fallback) and
 * the value reads "Default".
 *
 * @param {Object}   props
 * @param {string}   props.label    Label.
 * @param {string}   props.path     Design path.
 * @param {Object}   props.design   Design.
 * @param {Function} props.set      Setter (path, value).
 * @param {string}   props.help     Help.
 * @param {string}   props.fallback Colour used when empty.
 */
export function ColorField( { label, path, design, set, help, fallback } ) {
	const value = getIn( design, path ) || '';
	const shown = value || fallback || '';
	return (
		<div className="al-color">
			<Dropdown
				className="al-color__dropdown"
				popoverProps={ { placement: 'left-start', offset: 12 } }
				renderToggle={ ( { isOpen, onToggle } ) => (
					<button
						type="button"
						className="al-color__toggle"
						onClick={ onToggle }
						aria-expanded={ isOpen }
					>
						<span className={ 'al-color__swatch' + ( shown ? '' : ' is-none' ) }>
							<span style={ { background: shown || 'transparent' } } />
						</span>
						<span className="al-color__label">{ label }</span>
						{ value ? (
							<span className="al-color__value">{ value }</span>
						) : (
							<span className="al-color__value is-default">{ __( 'Default', 'modify-login' ) }</span>
						) }
					</button>
				) }
				renderContent={ () => (
					<div className="al-color__popover">
						<ColorPicker
							color={ value || fallback || '#ffffff' }
							onChange={ ( next ) => set( path, next ) }
							enableAlpha
						/>
						<div className="al-color__actions">
							<Button variant="tertiary" size="small" onClick={ () => set( path, '' ) } disabled={ ! value }>
								{ __( 'Use default', 'modify-login' ) }
							</Button>
						</div>
					</div>
				) }
			/>
			{ help ? <Help>{ help }</Help> : null }
		</div>
	);
}

/**
 * Number slider. An empty value means "default": the label says so, the
 * slider sits at the value really used (fallback), and Reset clears it.
 *
 * @param {Object} props
 */
export function NumberField( { label, path, design, set, min, max, step = 1, help, unit = 'px', fallback } ) {
	const raw = getIn( design, path );
	// Some fields store their default as a number (blur 0, angle 135) rather than empty.
	const schemaDefault = getIn( ( window.authlifyDesigner || {} ).defaults || {}, path );
	const hasDefault = schemaDefault !== '' && schemaDefault !== undefined && schemaDefault !== null;
	const empty = raw === '' || raw === undefined || raw === null || ( hasDefault && Number( raw ) === Number( schemaDefault ) );
	const value = raw === '' || raw === undefined || raw === null ? undefined : Number( raw );
	const start = fallback !== undefined && fallback !== '' ? Number( fallback ) : min;
	return (
		<div className={ 'al-number' + ( empty ? ' is-default' : '' ) }>
			<div className="al-field-head">
				<span className="al-label" aria-hidden="true">
					{ label }
					{ unit ? <span className="al-unit"> ({ unit })</span> : null }
				</span>
				{ empty ? (
					<span className="al-tag">
						{ fallback !== undefined && fallback !== ''
							/* translators: %s: value used when the field is empty, e.g. "24px". */
							? sprintf( __( 'Default · %s', 'modify-login' ), `${ fallback }${ unit || '' }` )
							: __( 'Default', 'modify-login' ) }
					</span>
				) : (
					<Button variant="link" className="al-reset" onClick={ () => set( path, hasDefault ? schemaDefault : '' ) }>
						{ __( 'Reset', 'modify-login' ) }
						<span className="screen-reader-text">
							{ /* translators: %s: field label. */ sprintf( __( '%s to default', 'modify-login' ), label ) }
						</span>
					</Button>
				) }
			</div>
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ unit ? `${ label } (${ unit })` : label }
				hideLabelFromVision
				value={ value }
				min={ min }
				max={ max }
				step={ step }
				help={ help }
				initialPosition={ start }
				withInputField
				onChange={ ( next ) => set( path, next === undefined || next === null || Number.isNaN( next ) ? '' : next ) }
			/>
		</div>
	);
}

export function Select( { label, path, design, set, options, help } ) {
	return (
		<SelectControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			value={ getIn( design, path ) }
			options={ options }
			help={ help }
			onChange={ ( next ) => set( path, next ) }
		/>
	);
}

export function Toggle( { label, path, design, set, help } ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ label }
			help={ help }
			checked={ !! getIn( design, path ) }
			onChange={ ( next ) => set( path, next ) }
		/>
	);
}

export function Text( { label, path, design, set, help, placeholder, type = 'text' } ) {
	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			type={ type }
			help={ help }
			placeholder={ placeholder }
			value={ getIn( design, path ) || '' }
			onChange={ ( next ) => set( path, next ) }
		/>
	);
}

export function TextArea( { label, path, design, set, help, placeholder, rows = 3, className } ) {
	return (
		<TextareaControl
			__nextHasNoMarginBottom
			className={ className }
			label={ label }
			help={ help }
			rows={ rows }
			placeholder={ placeholder }
			value={ getIn( design, path ) || '' }
			onChange={ ( next ) => set( path, next ) }
		/>
	);
}

/**
 * Media picker (wp.media) for an image, or another media type.
 *
 * @param {Object} props
 * @param {string} props.type    Library type: image (default) or video.
 * @param {string} props.name    File name to show instead of a preview (videos).
 */
export function ImageField( { label, path, idPath, design, set, help, type = 'image', name } ) {
	const frame = useRef( null );
	const value = getIn( design, path ) || '';

	const open = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		if ( ! frame.current ) {
			frame.current = window.wp.media( {
				title: label,
				library: { type },
				multiple: false,
				button: { text: type === 'video' ? __( 'Use this video', 'modify-login' ) : __( 'Use this image', 'modify-login' ) },
			} );
		}
		frame.current.off( 'select' );
		frame.current.on( 'select', () => {
			const attachment = frame.current.state().get( 'selection' ).first().toJSON();
			set( path, attachment.url, idPath ? { [ idPath ]: attachment.id } : null );
		} );
		frame.current.open();
	};

	const fileName = name || ( value ? decodeURIComponent( value.split( '/' ).pop() ) : '' );

	return (
		<div className="al-image">
			<span className="al-label">{ label }</span>
			{ value && type === 'image' ? (
				<div className="al-image__preview" style={ { backgroundImage: `url("${ imageUrl( value ) }")` } } />
			) : null }
			{ value && type !== 'image' ? <div className="al-image__file">{ fileName }</div> : null }
			<div className="al-image__buttons">
				<Button variant="secondary" size="compact" onClick={ open }>
					{ value ? __( 'Replace', 'modify-login' ) : ( type === 'video' ? __( 'Choose video', 'modify-login' ) : __( 'Choose image', 'modify-login' ) ) }
				</Button>
				{ value ? (
					<Button variant="tertiary" size="compact" isDestructive onClick={ () => set( path, '', idPath ? { [ idPath ]: 0 } : null ) }>
						{ __( 'Remove', 'modify-login' ) }
					</Button>
				) : null }
			</div>
			{ value && value.indexOf( 'plugin:' ) === 0 ? (
				/* translators: %s: file name. */
				<Help>{ sprintf( __( 'Built-in artwork: %s', 'modify-login' ), value.slice( 14 ) ) }</Help>
			) : null }
			{ help ? <Help>{ help }</Help> : null }
		</div>
	);
}

/**
 * Segmented choice, styled like WordPress's toggle group.
 *
 * @param {Object} props
 */
export function Segmented( { label, path, design, set, options, help } ) {
	const value = getIn( design, path );
	// Radio group keys: arrows move and select, one tab stop.
	const onKeyDown = ( e ) => {
		const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
		if ( ! keys[ e.key ] ) {
			return;
		}
		e.preventDefault();
		const index = Math.max( 0, options.findIndex( ( o ) => o.value === value ) );
		const next = ( index + keys[ e.key ] + options.length ) % options.length;
		set( path, options[ next ].value );
		const buttons = e.currentTarget.querySelectorAll( 'button' );
		if ( buttons[ next ] ) {
			buttons[ next ].focus();
		}
	};
	return (
		<div className="al-segmented">
			<span className="al-label" aria-hidden="true">{ label }</span>
			<div className="al-segmented__buttons" role="radiogroup" aria-label={ label } onKeyDown={ onKeyDown }>
				{ options.map( ( opt ) => (
					<button
						type="button"
						key={ opt.value }
						role="radio"
						aria-checked={ value === opt.value }
						tabIndex={ value === opt.value || ( ! options.some( ( o ) => o.value === value ) && opt === options[ 0 ] ) ? 0 : -1 }
						className={ 'al-segmented__button' + ( value === opt.value ? ' is-selected' : '' ) }
						onClick={ () => set( path, opt.value ) }
					>
						{ opt.label }
					</button>
				) ) }
			</div>
			{ help ? <Help>{ help }</Help> : null }
		</div>
	);
}
