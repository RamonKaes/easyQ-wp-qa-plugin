/**
 * Settings sidebar generated from the block attributes.
 *
 * @since 0.1.0
 */
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	// The core Date block uses the same picker; it has no stable export yet (WordPress 6.7 to 7.1).
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalDateFormatPicker as DateFormatPicker,
} from '@wordpress/block-editor';
import {
	BaseControl,
	ComboboxControl,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { getSettings as getDateSettings } from '@wordpress/date';

import { useEasyQ } from './data';

/**
 * Labels of the attributes shown in the sidebar.
 *
 * @since 0.1.0
 *
 * @type {Object<string, string>}
 */
const LABELS = {
	isLink: __( 'Link to the question', 'easyq' ),
	length: __( 'Maximum length', 'easyq' ),
	showModified: __( 'Show when the post was edited', 'easyq' ),
	showAvatar: __( 'Show avatar', 'easyq' ),
	avatarSize: __( 'Avatar size', 'easyq' ),
	showTitle: __( 'Show title', 'easyq' ),
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
	format: __( 'Date format', 'easyq' ),
	linkTarget: __( 'Open in new tab', 'easyq' ),
	rel: __( 'Link relation', 'easyq' ),
	label: __( 'Label', 'easyq' ),
	buttonText: __( 'Button text', 'easyq' ),
	buttonPosition: __( 'Button position', 'easyq' ),
	midSize: __( 'Number of links', 'easyq' ),
	showHierarchy: __( 'Show hierarchy', 'easyq' ),
	showEmpty: __( 'Show empty categories', 'easyq' ),
	displayAsDropdown: __( 'Display as dropdown', 'easyq' ),
	favorites: __( 'Only favorites of the visitor', 'easyq' ),
	rows: __( 'Shown rows', 'easyq' ),
};

/**
 * Labels of the blocks with a heading, which have `showTitle` and `level` as the core Comments Title block.
 *
 * @since 0.1.0
 *
 * @type {Object<string, string>}
 */
const HEADING_LABELS = {
	showTitle: __( 'Show heading', 'easyq' ),
};

/**
 * Labels of the search fields, as in the core Search block.
 *
 * @since 0.1.0
 *
 * @type {Object<string, string>}
 */
const SEARCH_LABELS = {
	showLabel: __( 'Show label', 'easyq' ),
};

/**
 * Labels that differ for some blocks.
 *
 * @since 0.1.0
 *
 * @type {Object<string, Object<string, string>>}
 */
const BLOCK_LABELS = {
	'easyq/post-date': {
		display: __( 'Date', 'easyq' ),
	},
	'easyq/post-votes': {
		display: __( 'Votes', 'easyq' ),
	},
	'easyq/answer-list': {
		perPage: __( 'Answers per page', 'easyq' ),
		...HEADING_LABELS,
	},
	'easyq/answer-form': HEADING_LABELS,
	'easyq/related-questions': HEADING_LABELS,
	'easyq/user-wall': HEADING_LABELS,
	'easyq/messages': HEADING_LABELS,
	'easyq/search': SEARCH_LABELS,
	'easyq/ask-box': SEARCH_LABELS,
	'easyq/post-author': {
		isLink: __( 'Link to the profile', 'easyq' ),
	},
};

/**
 * Help texts of the attributes.
 *
 * @since 0.1.0
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
	midSize: __(
		'Specify how many links can appear before and after the current page number. Links to the first, current and last page are always visible.',
		'easyq'
	),
	favorites: __(
		'Shows what the visitor follows instead of all; visitors who are not signed in see a hint.',
		'easyq'
	),
	label: __( 'Empty uses the default label.', 'easyq' ),
	buttonText: __( 'Empty uses the default text.', 'easyq' ),
	displayAsDropdown: __(
		'A select with a button that opens the chosen category; works without JavaScript.',
		'easyq'
	),
};

/**
 * Help texts that differ for some blocks.
 *
 * @since 0.1.0
 *
 * @type {Object<string, Object<string, string|undefined>>}
 */
const BLOCK_HELP = {
	'easyq/search': { showLabel: undefined },
	'easyq/ask-box': { showLabel: undefined },
	'easyq/messages': {
		showTitle: __(
			'The heading of the conversation with a user.',
			'easyq'
		),
	},
};

/**
 * Conditions under which an attribute is shown, as core hides link options of unlinked blocks.
 *
 * @since 0.1.0
 *
 * @type {Object<string, (attributes: Object) => boolean>}
 */
const SHOWN_IF = {
	linkTarget: ( attributes ) => attributes.isLink !== false,
	rel: ( attributes ) => attributes.isLink !== false,
	midSize: ( attributes ) => attributes.showNumbers !== false,
	format: ( attributes ) => attributes.display !== 'relative',
	showHierarchy: ( attributes ) => ! attributes.favorites,
	showEmpty: ( attributes ) => ! attributes.favorites,
	displayAsDropdown: ( attributes ) => ! attributes.favorites,
};

/**
 * Rows of the blocks with the attribute `rows`, in the order of the block.
 *
 * The rows of the profile fields block also include the fields of the site, see RowsControl.
 *
 * @since 0.1.0
 *
 * @type {Object<string, Array<{value: string, label: string}>>}
 */
const ROWS = {
	'easyq/activity-count': [
		{ value: 'questions', label: __( 'Questions', 'easyq' ) },
		{ value: 'answers', label: __( 'Answers', 'easyq' ) },
		{ value: 'comments', label: __( 'Comments', 'easyq' ) },
		{ value: 'users', label: __( 'Users', 'easyq' ) },
	],
	'easyq/user-stats': [
		{ value: 'member', label: __( 'Member for', 'easyq' ) },
		{ value: 'points', label: __( 'Points', 'easyq' ) },
		{ value: 'questions', label: __( 'Questions', 'easyq' ) },
		{ value: 'answers', label: __( 'Answers', 'easyq' ) },
		{ value: 'comments', label: __( 'Comments', 'easyq' ) },
		{ value: 'votes_given', label: __( 'Votes given', 'easyq' ) },
		{ value: 'votes_received', label: __( 'Votes received', 'easyq' ) },
	],
	'easyq/user-fields': [
		{ value: 'website', label: __( 'Website', 'easyq' ) },
		{ value: 'about', label: __( 'About', 'easyq' ) },
	],
};

const SITE_DEFAULT = { value: '', label: __( 'Site default', 'easyq' ) };

/**
 * Readable options of the attributes with a fixed set of values.
 *
 * @since 0.1.0
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
	buttonPosition: [
		{
			value: 'button-outside',
			label: __( 'Button outside', 'easyq' ),
		},
		{ value: 'button-inside', label: __( 'Button inside', 'easyq' ) },
		{ value: 'no-button', label: __( 'No button', 'easyq' ) },
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
 * @since 0.1.0
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
 * @since 0.1.0
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
 * Toggles that choose the rows a block shows; without the attribute every row is shown.
 *
 * @since 0.1.0
 *
 * @param {Object}                              props           Properties.
 * @param {string}                              props.blockName Block name.
 * @param {string}                              props.label     Label.
 * @param {string[]|undefined}                  props.value     Keys of the shown rows.
 * @param {(value: string[]|undefined) => void} props.onChange  Updates the value.
 * @return {Element} Control.
 */
function RowsControl( { blockName, label, value, onChange } ) {
	// The profile fields of the site come with the profile of the editor, which includes all fields.
	const me = useEasyQ(
		blockName === 'easyq/user-fields' ? 'users/me' : null
	);
	const rows = [
		...( ROWS[ blockName ] ?? [] ),
		...( me?.fields ?? [] ).map( ( field ) => ( {
			value: field.name,
			label: field.label,
		} ) ),
	];
	const all = rows.map( ( row ) => row.value );
	const shown = Array.isArray( value ) ? value : all;

	return (
		<fieldset>
			<legend>{ label }</legend>
			{ rows.map( ( row ) => (
				<ToggleControl
					key={ row.value }
					__nextHasNoMarginBottom
					label={ row.label }
					checked={ shown.includes( row.value ) }
					onChange={ ( checked ) => {
						const next = all.filter( ( key ) =>
							key === row.value ? checked : shown.includes( key )
						);
						// All rows again: the attribute is removed, so that new profile fields show up too.
						onChange(
							next.length === all.length &&
								blockName === 'easyq/user-fields'
								? undefined
								: next
						);
					} }
				/>
			) ) }
		</fieldset>
	);
}

/**
 * Returns the control of one attribute.
 *
 * @since 0.1.0
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

	const blockHelp = BLOCK_HELP[ blockName ] ?? {};
	const help = name in blockHelp ? blockHelp[ name ] : HELP[ name ];
	const onChange = ( next ) => setAttributes( { [ name ]: next } );

	if ( name === 'format' ) {
		// As the core Date block: the picker offers the site format, common formats and a custom one.
		return (
			<BaseControl __nextHasNoMarginBottom>
				<DateFormatPicker
					format={ value || undefined }
					defaultFormat={ getDateSettings().formats.date }
					onChange={ ( next ) => onChange( next ?? '' ) }
				/>
			</BaseControl>
		);
	}

	if ( name === 'linkTarget' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ label }
				checked={ value === '_blank' }
				onChange={ ( blank ) => onChange( blank ? '_blank' : '_self' ) }
			/>
		);
	}

	if ( name === 'rows' ) {
		return (
			<RowsControl
				blockName={ blockName }
				label={ label }
				value={ value }
				onChange={ onChange }
			/>
		);
	}

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
		const min = definition.minimum ?? 0;
		// Without a maximum in block.json a slider would cap the value, so a number field is shown.
		if ( definition.maximum === undefined ) {
			return (
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="number"
					label={ label }
					help={ help }
					value={ value ?? '' }
					min={ min }
					onChange={ ( next ) =>
						// The render raises values below the minimum; an empty field restores the default.
						onChange( next === '' ? undefined : Number( next ) )
					}
				/>
			);
		}

		return (
			<RangeControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ label }
				help={ help }
				value={ value }
				min={ min }
				max={ definition.maximum }
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
 * @since 0.1.0
 *
 * @param {Object}                       props               Properties.
 * @param {Object}                       props.metadata      Block metadata.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element|null} Sidebar.
 */
export default function Inspector( { metadata, attributes, setAttributes } ) {
	const names = Object.keys( metadata.attributes ?? {} ).filter(
		( name ) =>
			LABELS[ name ] && ( SHOWN_IF[ name ]?.( attributes ) ?? true )
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
