/**
 * The left-panel sections.
 *
 * Sections come from a registry that add-ons can extend:
 *
 *     wp.hooks.addFilter( 'authlify.designer.sections', 'my-plugin', ( sections ) => [
 *         ...sections,
 *         { name: 'my-panel', title: 'My panel', order: 35, render: ( { design, set, controls } ) => … },
 *     ] );
 *
 * Built-in orders: colours 10, layout 20, background 30, logo 40, form 50,
 * fields 60, button 70, links 80, messages 90, custom CSS 100. A section
 * may also carry `badge` (e.g. "Pro") and `initialOpen`.
 *
 * `authlify.designer.sectionBefore` (element|null, name, props) adds a note
 * at the top of any section.
 */
import { __ } from '@wordpress/i18n';
import { useState, useRef, Fragment } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { Button, Notice } from '@wordpress/components';
import * as controls from './controls';
import { contrast, over, toCss } from './color';
import { imageUrl } from './utils';

const { ColorField, ColorGroup, NumberField, Select, Toggle, Text, TextArea, ImageField, Segmented, Section, SubHead, Help } = controls;

const data = () => window.authlifyDesigner || {};
/**
 * Tiny visual of a design for the template gallery.
 *
 * @param {Object} props
 */
export function Thumb( { design, backdrop } ) {
	const b = design.background || {};
	const t = design.tokens || {};
	const f = design.form || {};
	let background = b.color || t.background || '#f0f0f1';
	if ( b.type === 'gradient' && b.gradient_from && b.gradient_to ) {
		background = `linear-gradient(${ b.gradient_angle || 135 }deg, ${ b.gradient_from }, ${ b.gradient_to })`;
	}
	// Add-on effects (Authlify Pro's animated backgrounds) show their colours.
	const fx = design.pro || {};
	if ( [ 'flow', 'aurora', 'orbs' ].indexOf( fx.effect ) >= 0 && fx.c1 ) {
		background = `linear-gradient(135deg, ${ fx.c1 }, ${ fx.c2 || fx.c1 } 50%, ${ fx.c3 || fx.c2 || fx.c1 })`;
	}
	const image = b.type === 'image' && b.image ? `url("${ imageUrl( b.image ) }") center / cover no-repeat` : null;
	const split = ( design.layout || '' ).indexOf( 'split' ) === 0;
	const sidebar = ( design.layout || '' ).indexOf( 'sidebar' ) === 0;
	const card = f.background || t.surface || '#fff';
	const panel = b.panel || t.surface || '#fff';
	const btn = design.button?.background || t.primary || '#3858e9';
	const text = f.text || t.text || '#1e1e1e';

	const cardEl = (
		<span className="al-thumb__card" style={ { background: sidebar ? 'transparent' : card, borderRadius: Math.min( 6, ( f.radius || t.radius || 4 ) / 3 ) } }>
			<span className="al-thumb__line" style={ { background: text, opacity: 0.55, width: '45%' } } />
			<span className="al-thumb__field" style={ { borderColor: design.inputs?.border || t.border || '#8c8f94', background: design.inputs?.background || '#fff' } } />
			<span className="al-thumb__field" style={ { borderColor: design.inputs?.border || t.border || '#8c8f94', background: design.inputs?.background || '#fff' } } />
			<span className="al-thumb__btn" style={ { background: btn } } />
		</span>
	);

	return (
		<span className={ 'al-thumb al-thumb--' + ( design.layout || 'center' ) } style={ { background: split ? panel : image || background, backgroundColor: backdrop || undefined } }>
			{ split ? <span className="al-thumb__art" style={ { background: image || background } } /> : null }
			{ sidebar ? <span className="al-thumb__side" style={ { background: panel } }>{ cardEl }</span> : cardEl }
		</span>
	);
}


/**
 * Template gallery plus "Match my site".
 *
 * The gallery is one radio group (one tab stop, arrow keys move), filtered
 * by category when the templates have more than one.
 *
 * @param {Object} props
 */
export function Gallery( { design, applyTemplate, matchSite, matching } ) {
	const templates = data().templates || [];
	const [ category, setCategory ] = useState( 'all' );
	const grid = useRef( null );

	const categories = [];
	templates.forEach( ( t ) => {
		const key = t.category || 'essentials';
		if ( ! categories.find( ( c ) => c.key === key ) ) {
			categories.push( { key, label: t.category_label || __( 'Essentials', 'modify-login' ) } );
		}
	} );
	const shown = category === 'all' ? templates : templates.filter( ( t ) => ( t.category || 'essentials' ) === category );
	const activeIndex = shown.findIndex( ( t ) => t.key === design.template );

	const onKeyDown = ( e ) => {
		const cols = 2;
		const moves = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: cols, ArrowUp: -cols, Home: -999, End: 999 };
		if ( moves[ e.key ] === undefined ) {
			return;
		}
		const items = [ ...grid.current.querySelectorAll( '.al-gallery__item' ) ];
		const index = items.indexOf( document.activeElement );
		if ( index < 0 ) {
			return;
		}
		e.preventDefault();
		const next = Math.max( 0, Math.min( items.length - 1, index + moves[ e.key ] ) );
		items.forEach( ( item, i ) => item.setAttribute( 'tabindex', i === next ? '0' : '-1' ) );
		items[ next ].focus();
	};

	return (
		<Section title={ __( 'Templates', 'modify-login' ) } initialOpen className="al-section--templates">
			<div className="al-match">
				<Button variant="secondary" icon="admin-appearance" onClick={ matchSite } isBusy={ matching } disabled={ matching } __next40pxDefaultSize>
					{ __( 'Match my site', 'modify-login' ) }
				</Button>
				<Help>{ __( "Builds a design from your theme's colours, font and logo.", 'modify-login' ) }</Help>
			</div>
			{ categories.length > 1 ? (
				<div className="al-chips" role="group" aria-label={ __( 'Template categories', 'modify-login' ) }>
					{ [ { key: 'all', label: __( 'All', 'modify-login' ) }, ...categories ].map( ( c ) => (
						<button
							type="button"
							key={ c.key }
							className={ 'al-chip' + ( category === c.key ? ' is-selected' : '' ) }
							aria-pressed={ category === c.key }
							onClick={ () => setCategory( c.key ) }
						>
							{ c.label }
						</button>
					) ) }
				</div>
			) : null }
			{ /* Buttons with aria-pressed: arrow keys move between templates,
			   Enter or Space applies one (applying replaces the whole design,
			   so it never happens just by moving focus). */ }
			<div
				className="al-gallery"
				role="group"
				aria-label={ __( 'Templates', 'modify-login' ) }
				ref={ grid }
				onKeyDown={ onKeyDown }
			>
				{ shown.map( ( tpl, i ) => {
					const active = design.template === tpl.key;
					return (
						<button
							key={ tpl.key }
							type="button"
							aria-pressed={ active }
							tabIndex={ ( activeIndex < 0 ? i === 0 : active ) ? 0 : -1 }
							className={ 'al-gallery__item' + ( active ? ' is-active' : '' ) }
							onClick={ () => applyTemplate( tpl ) }
						>
							<Thumb design={ tpl.design } backdrop={ tpl.backdrop } />
							<span className="al-gallery__meta">
								<span className="al-gallery__name">
									{ tpl.name }
									{ tpl.badge ? <span className="al-badge">{ tpl.badge }</span> : null }
								</span>
								{ tpl.description ? <span className="al-gallery__desc">{ tpl.description }</span> : null }
							</span>
						</button>
					);
				} ) }
			</div>
		</Section>
	);
}

const LAYOUTS = () => [
	{ value: 'center', label: __( 'Centred card', 'modify-login' ) },
	{ value: 'split-left', label: __( 'Split, image left', 'modify-login' ) },
	{ value: 'split-right', label: __( 'Split, image right', 'modify-login' ) },
	{ value: 'sidebar-left', label: __( 'Sidebar left', 'modify-login' ) },
	{ value: 'sidebar-right', label: __( 'Sidebar right', 'modify-login' ) },
	{ value: 'full', label: __( 'Full bleed (no card)', 'modify-login' ) },
	{ value: 'classic', label: __( 'WordPress placement', 'modify-login' ) },
];

/**
 * Values used when a number field is empty (what the login page really shows).
 *
 * @param {Object} d Design.
 * @return {Object} Fallbacks.
 */
function fallbacks( d ) {
	const box = d.form.card === 'box' || d.layout === 'full';
	const radius = d.tokens.radius !== '' && d.tokens.radius !== undefined ? d.tokens.radius : '';
	return {
		width: box ? 400 : 320,
		padding: box ? 32 : 26,
		formRadius: radius !== '' ? radius : 0,
		border: box ? 0 : 1,
		inputRadius: radius !== '' ? radius : 4,
		buttonRadius: radius !== '' ? radius : 3,
	};
}

/**
 * The built-in sections.
 *
 * @return {Array} Sections.
 */
export function coreSections() {
	return [
		{
			name: 'colours',
			title: __( 'Colours', 'modify-login' ),
			order: 10,
			render: ( p ) => (
				<>
					<ColorGroup help={ __( 'The base palette. Every section below uses these unless you set its own colour.', 'modify-login' ) }>
						<ColorField label={ __( 'Accent', 'modify-login' ) } path="tokens.primary" fallback="#2271b1" { ...p } />
						<ColorField label={ __( 'Text on accent', 'modify-login' ) } path="tokens.on_primary" fallback="#ffffff" { ...p } />
						<ColorField label={ __( 'Page background', 'modify-login' ) } path="tokens.background" fallback="#f0f0f1" { ...p } />
						<ColorField label={ __( 'Card background', 'modify-login' ) } path="tokens.surface" fallback="#ffffff" { ...p } />
						<ColorField label={ __( 'Text', 'modify-login' ) } path="tokens.text" fallback="#3c434a" { ...p } />
						<ColorField label={ __( 'Muted text', 'modify-login' ) } path="tokens.muted" fallback="#50575e" { ...p } />
						<ColorField label={ __( 'Borders', 'modify-login' ) } path="tokens.border" fallback="#8c8f94" { ...p } />
					</ColorGroup>
					<NumberField label={ __( 'Corner radius', 'modify-login' ) } path="tokens.radius" min={ 0 } max={ 40 } { ...p } />
				</>
			),
		},
		{
			name: 'layout',
			title: __( 'Layout', 'modify-login' ),
			order: 20,
			render: ( p ) => {
				const layout = p.design.layout;
				return (
					<>
						<div className="al-layouts" role="radiogroup" aria-label={ __( 'Layout', 'modify-login' ) }>
							{ LAYOUTS().map( ( opt ) => (
								<button
									type="button"
									key={ opt.value }
									role="radio"
									className={ 'al-layouts__item' + ( layout === opt.value ? ' is-active' : '' ) }
									aria-checked={ layout === opt.value }
									onClick={ () => p.set( 'layout', opt.value ) }
								>
									<span className={ 'al-layout-icon al-layout-icon--' + opt.value } aria-hidden="true"><span /></span>
									{ opt.label }
								</button>
							) ) }
						</div>
						<Help>{ __( 'On phones and small tablets (under 782px) every layout becomes one centred column.', 'modify-login' ) }</Help>
						<Segmented
							label={ __( 'Card', 'modify-login' ) }
							path="form.card"
							options={ [
								{ value: 'form', label: __( 'Form only', 'modify-login' ) },
								{ value: 'box', label: __( 'Whole box', 'modify-login' ) },
							] }
							help={ __( '"Whole box" puts the logo, links and messages inside the card, so they stay readable over photos.', 'modify-login' ) }
							{ ...p }
						/>
						<NumberField label={ __( 'Width', 'modify-login' ) } path="form.width" min={ 280 } max={ 640 } fallback={ fallbacks( p.design ).width } { ...p } />
					</>
				);
			},
		},
		{
			name: 'background',
			title: __( 'Background', 'modify-login' ),
			order: 30,
			render: ( p ) => {
				const d = p.design;
				const split = d.layout.indexOf( 'split' ) === 0;
				const sidebar = d.layout.indexOf( 'sidebar' ) === 0;
				const bgType = d.background.type;
				return (
					<>
						<Segmented
							label={ __( 'Type', 'modify-login' ) }
							path="background.type"
							options={ [
								{ value: 'color', label: __( 'Colour', 'modify-login' ) },
								{ value: 'gradient', label: __( 'Gradient', 'modify-login' ) },
								{ value: 'image', label: __( 'Image', 'modify-login' ) },
							] }
							{ ...p }
						/>
						<ColorGroup>
							<ColorField label={ bgType === 'color' ? __( 'Colour', 'modify-login' ) : __( 'Base colour', 'modify-login' ) } path="background.color" fallback={ d.tokens.background || '#f0f0f1' } { ...p } />
							{ bgType === 'gradient' ? (
								<>
									<ColorField label={ __( 'Gradient from', 'modify-login' ) } path="background.gradient_from" { ...p } />
									<ColorField label={ __( 'Gradient to', 'modify-login' ) } path="background.gradient_to" { ...p } />
								</>
							) : null }
							{ split || sidebar ? (
								<ColorField label={ split ? __( 'Form side colour', 'modify-login' ) : __( 'Sidebar colour', 'modify-login' ) } path="background.panel" fallback={ split ? d.tokens.background : d.tokens.surface } { ...p } />
							) : null }
							<ColorField label={ __( 'Text on the page', 'modify-login' ) } path="background.text" fallback={ d.tokens.muted || d.tokens.text } { ...p } />
						</ColorGroup>
						<Help>{ __( '"Text on the page" colours the footer text, the language switcher and, for "Form only" cards, the links under the form.', 'modify-login' ) }</Help>
						{ bgType === 'gradient' ? (
							<NumberField label={ __( 'Angle', 'modify-login' ) } path="background.gradient_angle" min={ 0 } max={ 360 } unit="°" fallback={ 135 } { ...p } />
						) : null }
						{ bgType === 'image' ? (
							<>
								<ImageField label={ __( 'Image', 'modify-login' ) } path="background.image" idPath="background.image_id" { ...p } />
								<div className="al-row-2">
									<Select
										label={ __( 'Size', 'modify-login' ) }
										path="background.size"
										options={ [
											{ value: 'cover', label: __( 'Cover', 'modify-login' ) },
											{ value: 'contain', label: __( 'Contain', 'modify-login' ) },
											{ value: 'auto', label: __( 'Original size', 'modify-login' ) },
										] }
										{ ...p }
									/>
									<Select
										label={ __( 'Repeat', 'modify-login' ) }
										path="background.repeat"
										options={ [
											{ value: 'no-repeat', label: __( 'No repeat', 'modify-login' ) },
											{ value: 'repeat', label: __( 'Tile', 'modify-login' ) },
											{ value: 'repeat-x', label: __( 'Across', 'modify-login' ) },
											{ value: 'repeat-y', label: __( 'Down', 'modify-login' ) },
										] }
										{ ...p }
									/>
								</div>
								<Select
									label={ __( 'Position', 'modify-login' ) }
									path="background.position"
									options={ [ 'center center', 'center top', 'center bottom', 'left center', 'left top', 'left bottom', 'right center', 'right top', 'right bottom' ].map( ( v ) => ( { value: v, label: v } ) ) }
									{ ...p }
								/>
								<ColorGroup>
									<ColorField label={ __( 'Overlay colour', 'modify-login' ) } path="background.overlay" { ...p } />
								</ColorGroup>
								<NumberField label={ __( 'Overlay opacity', 'modify-login' ) } path="background.overlay_opacity" min={ 0 } max={ 100 } unit="%" fallback={ 0 } { ...p } />
							</>
						) : null }
						<ImageField
							label={ __( 'Phone image (optional)', 'modify-login' ) }
							path="background.mobile_image"
							idPath="background.mobile_image_id"
							help={ split
								? __( 'Split layouts drop their artwork under 782px wide. Pick an image to use as the phone background instead.', 'modify-login' )
								: __( 'Shown instead under 782px wide, e.g. a portrait crop.', 'modify-login' ) }
							{ ...p }
						/>
					</>
				);
			},
		},
		{
			name: 'logo',
			title: __( 'Logo', 'modify-login' ),
			order: 40,
			render: ( p ) => {
				const logo = p.design.logo;
				return (
					<>
						<Toggle label={ __( 'Hide the logo', 'modify-login' ) } path="logo.hide" { ...p } />
						{ ! logo.hide ? (
							<>
								<Select
									label={ __( 'Logo', 'modify-login' ) }
									path="logo.type"
									options={ [
										{ value: 'wordpress', label: __( 'WordPress logo', 'modify-login' ) },
										{ value: 'image', label: __( 'Image', 'modify-login' ) },
										{ value: 'site-icon', label: __( 'Site icon', 'modify-login' ) },
										{ value: 'text', label: __( 'Text', 'modify-login' ) },
									] }
									help={ logo.type === 'site-icon' && ! data().siteIcon ? __( 'This site has no site icon yet (Settings → General).', 'modify-login' ) : undefined }
									{ ...p }
								/>
								{ logo.type === 'image' ? <ImageField label={ __( 'Logo image', 'modify-login' ) } path="logo.image" idPath="logo.image_id" { ...p } /> : null }
								{ logo.type === 'text' ? (
									<>
										<Text label={ __( 'Text', 'modify-login' ) } path="logo.text" placeholder={ data().siteName } { ...p } />
										<ColorGroup>
											<ColorField label={ __( 'Text colour', 'modify-login' ) } path="logo.text_color" fallback={ p.design.form.text || p.design.tokens.text } { ...p } />
										</ColorGroup>
										<NumberField label={ __( 'Text size', 'modify-login' ) } path="logo.text_size" min={ 12 } max={ 72 } fallback={ 28 } { ...p } />
									</>
								) : (
									<>
										<NumberField label={ __( 'Width', 'modify-login' ) } path="logo.width" min={ 16 } max={ 400 } fallback={ 84 } { ...p } />
										<NumberField label={ __( 'Height', 'modify-login' ) } path="logo.height" min={ 16 } max={ 400 } fallback={ 84 } { ...p } />
									</>
								) }
								<SubHead>{ __( 'Link', 'modify-login' ) }</SubHead>
								<Text label={ __( 'Link address', 'modify-login' ) } type="url" path="logo.link" placeholder={ data().homeUrl } { ...p } />
								<Text label={ __( 'Title (screen readers)', 'modify-login' ) } path="logo.title" placeholder={ data().siteName } { ...p } />
							</>
						) : null }
					</>
				);
			},
		},
		{
			name: 'form',
			title: __( 'Form', 'modify-login' ),
			order: 50,
			render: ( p ) => {
				const d = p.design;
				const f = fallbacks( d );
				return (
					<>
						<ColorGroup help={ __( 'Lower the background opacity for a glass effect, then add blur.', 'modify-login' ) }>
							<ColorField label={ __( 'Background', 'modify-login' ) } path="form.background" fallback={ d.tokens.surface || '#ffffff' } { ...p } />
							<ColorField label={ __( 'Text', 'modify-login' ) } path="form.text" fallback={ d.tokens.text || '#3c434a' } { ...p } />
							<ColorField label={ __( 'Labels', 'modify-login' ) } path="form.label" fallback={ d.form.text || d.tokens.text || '#3c434a' } { ...p } />
							<ColorField label={ __( 'Border colour', 'modify-login' ) } path="form.border_color" fallback={ d.tokens.border || '#c3c4c7' } { ...p } />
						</ColorGroup>
						<NumberField label={ __( 'Glass blur', 'modify-login' ) } path="form.blur" min={ 0 } max={ 40 } fallback={ 0 } { ...p } />
						<NumberField label={ __( 'Padding', 'modify-login' ) } path="form.padding" min={ 0 } max={ 80 } fallback={ f.padding } { ...p } />
						<NumberField label={ __( 'Corner radius', 'modify-login' ) } path="form.radius" min={ 0 } max={ 40 } fallback={ f.formRadius } { ...p } />
						<NumberField label={ __( 'Border width', 'modify-login' ) } path="form.border_width" min={ 0 } max={ 8 } fallback={ f.border } { ...p } />
						<Select
							label={ __( 'Shadow', 'modify-login' ) }
							path="form.shadow"
							options={ [
								{ value: '', label: __( 'Default', 'modify-login' ) },
								{ value: 'none', label: __( 'None', 'modify-login' ) },
								{ value: 'sm', label: __( 'Subtle', 'modify-login' ) },
								{ value: 'md', label: __( 'Medium', 'modify-login' ) },
								{ value: 'lg', label: __( 'Large', 'modify-login' ) },
							] }
							{ ...p }
						/>
					</>
				);
			},
		},
		{
			name: 'fields',
			title: __( 'Fields', 'modify-login' ),
			order: 60,
			render: ( p ) => {
				const d = p.design;
				return (
					<>
						<ColorGroup>
							<ColorField label={ __( 'Background', 'modify-login' ) } path="inputs.background" fallback={ d.tokens.surface || '#ffffff' } { ...p } />
							<ColorField label={ __( 'Text', 'modify-login' ) } path="inputs.text" fallback={ d.tokens.text || '#2c3338' } { ...p } />
							<ColorField label={ __( 'Border', 'modify-login' ) } path="inputs.border" fallback={ d.tokens.border || '#8c8f94' } { ...p } />
							<ColorField label={ __( 'Focus ring', 'modify-login' ) } path="inputs.focus" fallback={ d.tokens.primary || '#2271b1' } { ...p } />
						</ColorGroup>
						<NumberField label={ __( 'Corner radius', 'modify-login' ) } path="inputs.radius" min={ 0 } max={ 40 } fallback={ fallbacks( d ).inputRadius } { ...p } />
						<NumberField label={ __( 'Text size', 'modify-login' ) } path="inputs.size" min={ 12 } max={ 28 } fallback={ 24 } help={ __( '16px or more stops phones zooming in.', 'modify-login' ) } { ...p } />
					</>
				);
			},
		},
		{
			name: 'button',
			title: __( 'Button', 'modify-login' ),
			order: 70,
			render: ( p ) => {
				const d = p.design;
				return (
					<>
						<ColorGroup>
							<ColorField label={ __( 'Background', 'modify-login' ) } path="button.background" fallback={ d.tokens.primary || '#2271b1' } { ...p } />
							<ColorField label={ __( 'Text', 'modify-login' ) } path="button.text" fallback={ d.tokens.on_primary || '#ffffff' } { ...p } />
							<ColorField label={ __( 'Hover background', 'modify-login' ) } path="button.hover_background" fallback={ d.button.background || d.tokens.primary || '#135e96' } { ...p } />
							<ColorField label={ __( 'Hover text', 'modify-login' ) } path="button.hover_text" fallback={ d.button.text || d.tokens.on_primary || '#ffffff' } { ...p } />
						</ColorGroup>
						<NumberField label={ __( 'Corner radius', 'modify-login' ) } path="button.radius" min={ 0 } max={ 40 } fallback={ fallbacks( d ).buttonRadius } { ...p } />
						<Toggle label={ __( 'Full width', 'modify-login' ) } path="button.full_width" { ...p } />
						<Text label={ __( 'Log in button text', 'modify-login' ) } path="text.button_label" placeholder={ __( 'Log In', 'modify-login' ) } { ...p } />
					</>
				);
			},
		},
		{
			name: 'links',
			title: __( 'Links & text', 'modify-login' ),
			order: 80,
			render: ( p ) => (
				<>
					<ColorGroup>
						<ColorField label={ __( 'Link colour', 'modify-login' ) } path="links.color" { ...p } />
						<ColorField label={ __( 'Link hover colour', 'modify-login' ) } path="links.hover" fallback={ p.design.links.color } { ...p } />
					</ColorGroup>
					<div className="al-toggles">
						<Toggle label={ __( 'Hide "Go to site" link', 'modify-login' ) } path="links.hide_backtoblog" { ...p } />
						<Toggle label={ __( 'Hide the language switcher', 'modify-login' ) } path="links.hide_language" { ...p } />
						<Toggle label={ __( 'Hide the privacy policy link', 'modify-login' ) } path="links.hide_privacy" { ...p } />
					</div>
					<SubHead>{ __( 'Type', 'modify-login' ) }</SubHead>
					<Select label={ __( 'Font', 'modify-login' ) } path="text.font" options={ data().fonts || [] } help={ __( 'System fonts load nothing. Bundled fonts are served from this site, never from Google.', 'modify-login' ) } { ...p } />
					{ p.design.text.font === 'theme' ? <Text label={ __( 'Theme font family', 'modify-login' ) } path="text.custom_family" { ...p } /> : null }
					<NumberField label={ __( 'Base text size', 'modify-login' ) } path="text.size" min={ 11 } max={ 22 } fallback={ 13 } { ...p } />
					<SubHead>{ __( 'Your text', 'modify-login' ) }</SubHead>
					<TextArea label={ __( 'Message above the form', 'modify-login' ) } path="text.message" help={ __( 'Shown on the log-in screen. Links, bold and italics allowed.', 'modify-login' ) } { ...p } />
					<TextArea label={ __( 'Footer text', 'modify-login' ) } path="text.footer" help={ __( 'Shown under the form on every screen.', 'modify-login' ) } { ...p } />
				</>
			),
		},
		{
			name: 'messages',
			title: __( 'Messages', 'modify-login' ),
			order: 90,
			render: ( p ) => (
				<ColorGroup help={ __( 'Errors, notices and success messages above the form. Preview them with the Screen menu (Lockout message).', 'modify-login' ) }>
					<ColorField label={ __( 'Error accent', 'modify-login' ) } path="messages.error" fallback="#d63638" { ...p } />
					<ColorField label={ __( 'Notice accent', 'modify-login' ) } path="messages.notice" fallback={ p.design.tokens.primary || '#72aee6' } { ...p } />
					<ColorField label={ __( 'Success accent', 'modify-login' ) } path="messages.success" fallback="#00a32a" { ...p } />
					<ColorField label={ __( 'Background', 'modify-login' ) } path="messages.background" fallback={ p.design.tokens.surface || '#ffffff' } { ...p } />
					<ColorField label={ __( 'Text', 'modify-login' ) } path="messages.text" fallback={ p.design.tokens.text || '#3c434a' } { ...p } />
				</ColorGroup>
			),
		},
		{
			name: 'css',
			title: __( 'Custom CSS', 'modify-login' ),
			order: 100,
			render: ( p ) => (
				<TextArea
					className="al-code"
					label={ __( 'CSS', 'modify-login' ) }
					path="custom_css"
					rows={ 10 }
					placeholder={ 'body.login.authlify-designed #login h1 a {\n  /* … */\n}' }
					help={ __( 'Added after the design. Use body.login.authlify-designed in selectors to win over WordPress. Variables: --al-primary, --al-surface, --al-text, --al-link, --al-radius. @import is not allowed.', 'modify-login' ) }
					{ ...p }
				/>
			),
		},
	];
}

/**
 * All sections, built-in plus add-ons, in order.
 *
 * @param {Object} props design, set.
 */
export function Sections( { design, set } ) {
	const list = applyFilters( 'authlify.designer.sections', coreSections(), { data: data() } );
	const sorted = ( Array.isArray( list ) ? list : [] )
		.filter( ( s ) => s && s.name && typeof s.render === 'function' )
		.map( ( s, i ) => ( { ...s, order: typeof s.order === 'number' ? s.order : 1000 + i } ) )
		.sort( ( a, b ) => a.order - b.order );
	const props = { design, set, controls, data: data() };

	return sorted.map( ( s ) => {
		const before = applyFilters( 'authlify.designer.sectionBefore', null, s.name, props );
		let body = null;
		try {
			body = s.render( props );
		} catch ( e ) {
			// One broken add-on panel must not take the designer down.
			// eslint-disable-next-line no-console
			console.error( e );
			body = <Notice status="error" isDismissible={ false }>{ __( 'This section could not be shown.', 'modify-login' ) }</Notice>;
		}
		return (
			<Section key={ s.name } title={ s.title } badge={ s.badge } initialOpen={ !! s.initialOpen } className={ 'al-section--' + s.name }>
				{ before ? <Fragment>{ before }</Fragment> : null }
				{ body }
			</Section>
		);
	} );
}

/**
 * Contrast problems in the current design (WCAG AA 4.5:1 for text).
 *
 * @param {Object} d Design.
 * @return {string[]} Problems.
 */
export function contrastIssues( d ) {
	const t = d.tokens;
	const pick = ( ...values ) => values.find( ( v ) => v !== '' && v !== undefined && v !== null ) || '';
	const b = d.background;
	const split = d.layout.indexOf( 'split' ) === 0 || d.layout.indexOf( 'sidebar' ) === 0;
	const box = d.form.card === 'box' || d.layout === 'full';
	if ( b.type === 'image' && ! split && ! b.overlay ) {
		return [];
	}
	let page = split ? pick( b.panel, t.surface, '#ffffff' ) : pick( b.color, t.background, '#f0f0f1' );
	if ( b.type === 'image' && ! split && b.overlay ) {
		const alpha = ( b.overlay_opacity || 0 ) / 100;
		if ( alpha < 0.6 ) {
			return [];
		}
		page = toCss( over( b.overlay, toCss( over( page, '#808080' ) ) ) );
	}
	const pageRgb = over( page, '#ffffff' );
	const card = over( pick( d.form.background, t.surface, '#ffffff' ), toCss( pageRgb ) );
	const checks = [
		[ __( 'Form text', 'modify-login' ), pick( d.form.text, t.text ), card ],
		[ __( 'Labels', 'modify-login' ), pick( d.form.label, d.form.text, t.text ), card ],
		[ __( 'Field text', 'modify-login' ), pick( d.inputs.text, t.text ), over( pick( d.inputs.background, t.surface, '#ffffff' ), toCss( card ) ) ],
		[ __( 'Button text', 'modify-login' ), pick( d.button.text, t.on_primary ), over( pick( d.button.background, t.primary ), toCss( card ) ) ],
		[ __( 'Links', 'modify-login' ), pick( d.links.color ), box ? card : pageRgb ],
	];
	return checks
		.filter( ( c ) => c[ 1 ] && ( c[ 2 ] || [] ).length )
		.map( ( c ) => [ c[ 0 ], contrast( c[ 1 ], c[ 2 ] ) ] )
		.filter( ( c ) => c[ 1 ] < 4.5 )
		.map( ( c ) => `${ c[ 0 ] }: ${ c[ 1 ].toFixed( 1 ) }:1` );
}

export function ContrastNotice( { design } ) {
	const issues = contrastIssues( design );
	if ( ! issues.length ) {
		return null;
	}
	return (
		<Notice status="warning" isDismissible={ false } className="al-contrast">
			<strong>{ __( 'Low contrast (WCAG AA needs 4.5:1):', 'modify-login' ) }</strong> { issues.join( ', ' ) }
		</Notice>
	);
}
