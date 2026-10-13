/**
 * Settings sidebar generated from the block attributes.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

/**
 * Labels of the attributes shown in the sidebar.
 *
 * @type {Object<string, string>}
 */
const LABELS = {
	level: __( 'Heading level', 'easyq' ),
	isLink: __( 'Link to the question', 'easyq' ),
	length: __( 'Maximum length', 'easyq' ),
	showModified: __( 'Show when the post was edited', 'easyq' ),
	showAvatar: __( 'Show avatar', 'easyq' ),
	avatarSize: __( 'Avatar size', 'easyq' ),
	showPoints: __( 'Show points', 'easyq' ),
	separator: __( 'Separator', 'easyq' ),
	placeholder: __( 'Placeholder', 'easyq' ),
	perPage: __( 'Number of items (0 for the default)', 'easyq' ),
	showCounts: __( 'Show counts', 'easyq' ),
	showChildrenOfCurrent: __(
		'Only subcategories of the current category',
		'easyq'
	),
	count: __( 'Number of questions', 'easyq' ),
	message: __( 'Message', 'easyq' ),
	questionId: __( 'Question ID (0 for the question of the page)', 'easyq' ),
};

/**
 * Returns the control of one attribute.
 *
 * @param {Object}                       props               Properties.
 * @param {string}                       props.name          Attribute name.
 * @param {Object}                       props.definition    Attribute definition from block.json.
 * @param {string|number|boolean}        props.value         Current value.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element|null} Control.
 */
function AttributeControl( { name, definition, value, setAttributes } ) {
	const label = LABELS[ name ];
	if ( ! label ) {
		return null;
	}

	const onChange = ( next ) => setAttributes( { [ name ]: next } );

	if ( definition.type === 'boolean' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ !! value }
				onChange={ onChange }
			/>
		);
	}

	if ( definition.type === 'number' ) {
		const range = name === 'level' ? [ 1, 6 ] : [ 0, 200 ];
		return (
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ value }
				min={ range[ 0 ] }
				max={ range[ 1 ] }
				onChange={ onChange }
			/>
		);
	}

	if ( definition.enum ) {
		return (
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				value={ value }
				options={ definition.enum.map( ( option ) => ( {
					label: option,
					value: option,
				} ) ) }
				onChange={ onChange }
			/>
		);
	}

	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			value={ value ?? '' }
			onChange={ onChange }
		/>
	);
}

/**
 * Sidebar with a control for every simple attribute of a block.
 *
 * @param {Object}                       props               Properties.
 * @param {Object}                       props.metadata      Block metadata.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element|null} Sidebar.
 */
export default function Inspector( { metadata, attributes, setAttributes } ) {
	const names = Object.keys( metadata.attributes ?? {} ).filter(
		( name ) => LABELS[ name ]
	);
	if ( ! names.length ) {
		return null;
	}

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Settings', 'easyq' ) }>
				{ names.map( ( name ) => (
					<AttributeControl
						key={ name }
						name={ name }
						definition={ metadata.attributes[ name ] }
						value={ attributes[ name ] }
						setAttributes={ setAttributes }
					/>
				) ) }
			</PanelBody>
		</InspectorControls>
	);
}
