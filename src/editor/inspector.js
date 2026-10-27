/**
 * Settings sidebar generated from the block attributes.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import {
	ComboboxControl,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { useEasyQ } from './data';

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
	prefix: __( 'Prefix', 'easyq' ),
	suffix: __( 'Suffix', 'easyq' ),
	placeholder: __( 'Placeholder', 'easyq' ),
	perPage: __( 'Number of items', 'easyq' ),
	showCounts: __( 'Show counts', 'easyq' ),
	showChildrenOfCurrent: __(
		'Only subcategories of the current category',
		'easyq'
	),
	count: __( 'Number of questions', 'easyq' ),
	message: __( 'Message', 'easyq' ),
	questionId: __( 'Question', 'easyq' ),
	userId: __( 'User', 'easyq' ),
	showRank: __( 'Show rank', 'easyq' ),
	showAuthor: __( 'Show who did it', 'easyq' ),
	order: __( 'Order', 'easyq' ),
	selectedFirst: __( 'Best answer first', 'easyq' ),
	showFewerFrom: __( 'Collapse comments from', 'easyq' ),
	showFewerCount: __( 'Comments shown when collapsed', 'easyq' ),
	display: __( 'Display', 'easyq' ),
	showNumbers: __( 'Show page numbers', 'easyq' ),
	arrow: __( 'Arrow', 'easyq' ),
	showLabel: __( 'Show labels', 'easyq' ),
};

/**
 * Labels that differ for some blocks.
 *
 * @type {Object<string, Object<string, string>>}
 */
const BLOCK_LABELS = {
	'easyq/answer-list': {
		perPage: __( 'Answers per page', 'easyq' ),
	},
	'easyq/post-date': {
		display: __( 'Date', 'easyq' ),
	},
	'easyq/post-votes': {
		display: __( 'Votes', 'easyq' ),
	},
};

/**
 * Help texts of the attributes.
 *
 * @type {Object<string, string>}
 */
const HELP = {
	perPage: __( '0 uses the site setting.', 'easyq' ),
	showFewerFrom: __(
		'Number of comments from which only the latest are shown. 0 uses the site setting.',
		'easyq'
	),
	showFewerCount: __( '0 uses the site setting.', 'easyq' ),
	questionId: __( 'Empty shows the question of the page.', 'easyq' ),
	userId: __( 'Empty shows the user of the page.', 'easyq' ),
	showLabel: __(
		'Without labels the arrows are shown; screen readers still read the labels.',
		'easyq'
	),
};

const SITE_DEFAULT = { value: '', label: __( 'Site default', 'easyq' ) };

/**
 * Readable options of the attributes with a fixed set of values.
 *
 * @type {Object<string, Array<{value: string, label: string}>>}
 */
const OPTIONS = {
	order: [
		SITE_DEFAULT,
		{ value: 'votes', label: __( 'Most votes first', 'easyq' ) },
		{ value: 'created', label: __( 'Oldest first', 'easyq' ) },
	],
	selectedFirst: [
		SITE_DEFAULT,
		{ value: 'yes', label: __( 'Yes', 'easyq' ) },
		{ value: 'no', label: __( 'No', 'easyq' ) },
	],
	'easyq/post-date:display': [
		SITE_DEFAULT,
		{
			value: 'relative',
			label: __( 'Relative, e.g. “5 minutes ago”', 'easyq' ),
		},
		{ value: 'absolute', label: __( 'Date', 'easyq' ) },
	],
	'easyq/post-votes:display': [
		SITE_DEFAULT,
		{
			value: 'net',
			label: __( 'Difference of up and down votes', 'easyq' ),
		},
		{
			value: 'separated',
			label: __( 'Up and down votes separately', 'easyq' ),
		},
	],
	arrow: [
		{ value: 'none', label: __( 'None', 'easyq' ) },
		{ value: 'arrow', label: '← →' },
		{ value: 'chevron', label: '« »' },
	],
};

/**
 * Lets editors pick a question by its title.
 *
 * @param {Object}                  props          Properties.
 * @param {string}                  props.label    Label.
 * @param {string}                  props.help     Help text.
 * @param {number}                  props.value    Question ID, 0 for none.
 * @param {(value: number) => void} props.onChange Updates the value.
 * @return {Element} Control.
 */
function QuestionPicker( { label, help, value, onChange } ) {
	const questions = useEasyQ( 'questions?per_page=100' );
	const chosen = useEasyQ( value ? `questions/${ value }` : null );
	const options = ( Array.isArray( questions ) ? questions : [] ).map(
		( question ) => ( {
			value: String( question.id ),
			label: question.title.raw ?? question.title.rendered,
		} )
	);
	if (
		chosen &&
		! options.some( ( option ) => option.value === String( value ) )
	) {
		options.unshift( {
			value: String( value ),
			label: chosen.title.rendered,
		} );
	}

	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ value ? String( value ) : null }
			options={ options }
			onChange={ ( next ) => onChange( next ? Number( next ) : 0 ) }
		/>
	);
}

/**
 * Lets editors pick a user by name.
 *
 * @param {Object}                  props          Properties.
 * @param {string}                  props.label    Label.
 * @param {string}                  props.help     Help text.
 * @param {number}                  props.value    User ID, 0 for none.
 * @param {(value: number) => void} props.onChange Updates the value.
 * @return {Element} Control.
 */
export function UserPicker( { label, help, value, onChange } ) {
	const users = useEasyQ( 'users?per_page=100' );
	const chosen = useEasyQ( value ? `users/${ value }` : null );
	const options = ( Array.isArray( users ) ? users : [] ).map( ( user ) => ( {
		value: String( user.id ),
		label: user.name,
	} ) );
	if (
		chosen &&
		! options.some( ( option ) => option.value === String( value ) )
	) {
		options.unshift( { value: String( value ), label: chosen.name } );
	}

	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ help }
			value={ value ? String( value ) : null }
			options={ options }
			onChange={ ( next ) => onChange( next ? Number( next ) : 0 ) }
		/>
	);
}

/**
 * Returns the control of one attribute.
 *
 * @param {Object}                       props               Properties.
 * @param {string}                       props.blockName     Block name.
 * @param {string}                       props.name          Attribute name.
 * @param {Object}                       props.definition    Attribute definition from block.json.
 * @param {string|number|boolean}        props.value         Current value.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element|null} Control.
 */
function AttributeControl( {
	blockName,
	name,
	definition,
	value,
	setAttributes,
} ) {
	const label =
		BLOCK_LABELS[ blockName ]?.[ name ] ??
		( blockName.startsWith( 'easyq/user-' ) && name === 'isLink'
			? __( 'Link to the profile', 'easyq' )
			: LABELS[ name ] );
	if ( ! label ) {
		return null;
	}

	const help = HELP[ name ];
	const onChange = ( next ) => setAttributes( { [ name ]: next } );

	if ( name === 'questionId' ) {
		return (
			<QuestionPicker
				label={ label }
				help={ help }
				value={ value }
				onChange={ onChange }
			/>
		);
	}

	if ( name === 'userId' ) {
		return (
			<UserPicker
				label={ label }
				help={ help }
				value={ value }
				onChange={ onChange }
			/>
		);
	}

	if ( definition.type === 'boolean' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				help={ help }
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
				help={ help }
				value={ value }
				min={ range[ 0 ] }
				max={ range[ 1 ] }
				onChange={ onChange }
			/>
		);
	}

	const options = OPTIONS[ `${ blockName }:${ name }` ] ?? OPTIONS[ name ];
	if ( definition.enum ) {
		return (
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				help={ help }
				value={ value }
				options={
					options ??
					definition.enum.map( ( option ) => ( {
						label: option,
						value: option,
					} ) )
				}
				onChange={ onChange }
			/>
		);
	}

	return (
		<TextControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ help }
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
						blockName={ metadata.name }
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
