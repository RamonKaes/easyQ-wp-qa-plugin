/**
 * Editor components of the easyQ blocks.
 *
 * @since 0.1.0
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BlockContextProvider,
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
} from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { RawHTML, useEffect } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import Inspector, { UserPicker } from './inspector';
import { questionsPath, usersPath, useEasyQ } from './data';
import {
	ANSWER_LIST,
	COMMENT_LIST,
	QUESTION_ITEM,
	QUESTION_LIST,
	QUESTION_PAGE,
	USER_ITEM,
	USER_LIST,
	USER_PAGE,
} from './templates';

/**
 * Shows a short text in place of a block that only has content on Q&A pages.
 *
 * @since 0.1.0
 *
 * @param {Object} props       Properties.
 * @param {string} props.label Text.
 * @return {Element} Placeholder.
 */
function Placeholder( { label } ) {
	return (
		<div { ...useBlockProps() }>
			<p>{ label }</p>
		</div>
	);
}

/**
 * Preview of one field of the post from block context.
 *
 * @since 0.1.0
 *
 * @param {Object} props            Properties.
 * @param {string} props.name       Block name.
 * @param {Object} props.attributes Attributes.
 * @param {Object} props.context    Block context.
 * @param {string} props.title      Block title, shown without post.
 * @return {Element} Preview.
 */
function FieldPreview( { name, attributes, context, title } ) {
	const blockProps = useBlockProps();
	const postId = context[ 'easyq/postId' ];
	const post = useEasyQ( postId ? `posts/${ postId }` : null );

	if ( ! post ) {
		return (
			<div { ...blockProps }>
				<p>{ title }</p>
			</div>
		);
	}

	switch ( name ) {
		case 'easyq/post-title': {
			const Tag = `h${ attributes.level || 2 }`;
			return post.title ? (
				<Tag { ...blockProps }>
					<RawHTML>{ post.title.rendered }</RawHTML>
				</Tag>
			) : null;
		}
		case 'easyq/post-content':
			return (
				<div { ...blockProps }>
					<RawHTML>{ post.content.rendered }</RawHTML>
				</div>
			);
		case 'easyq/post-excerpt':
			return <p { ...blockProps }>{ post.excerpt }</p>;
		case 'easyq/post-votes':
			return attributes.display === 'separated' ? (
				<div { ...blockProps }>
					<span aria-hidden="true">▲</span>
					<span>{ post.votes.up }</span>
					<span aria-hidden="true">▼</span>
					<span>{ post.votes.down }</span>
				</div>
			) : (
				<div { ...blockProps }>
					<span aria-hidden="true">▲</span>
					<span>{ post.votes.net }</span>
					<span aria-hidden="true">▼</span>
				</div>
			);
		case 'easyq/post-answer-count':
			return (
				<div { ...blockProps }>
					{ sprintf(
						/* translators: %s: number of answers. */
						_n(
							'%s answer',
							'%s answers',
							post.answer_count ?? 0,
							'easyq'
						),
						post.answer_count ?? 0
					) }
				</div>
			);
		case 'easyq/post-view-count':
			return (
				<div { ...blockProps }>
					{ sprintf(
						/* translators: %s: number of views. */
						_n( '%s view', '%s views', post.views ?? 0, 'easyq' ),
						post.views ?? 0
					) }
				</div>
			);
		case 'easyq/post-date':
			return (
				<div { ...blockProps }>
					<time dateTime={ post.date_gmt }>
						{ new Date( post.date_gmt ).toLocaleDateString() }
					</time>
				</div>
			);
		case 'easyq/post-author':
			return (
				<div { ...blockProps }>
					{ attributes.showAvatar && (
						<img
							src={ post.author.avatar }
							alt=""
							width={ attributes.avatarSize }
							height={ attributes.avatarSize }
						/>
					) }
					<span>{ post.author.name }</span>
				</div>
			);
		case 'easyq/post-tags':
			return post.tags?.length ? (
				<div { ...blockProps }>
					{ attributes.prefix }
					{ post.tags.join( attributes.separator ?? ', ' ) }
					{ attributes.suffix }
				</div>
			) : null;
		case 'easyq/post-category':
			return post.category ? (
				<div { ...blockProps }>
					{ attributes.prefix }
					{ post.category.title }
					{ attributes.suffix }
				</div>
			) : null;
		case 'easyq/post-activity':
			return (
				<div { ...blockProps }>
					{ attributes.showAuthor
						? sprintf(
								/* translators: 1: date, 2: author name. */
								__( 'asked %1$s by %2$s', 'easyq' ),
								new Date( post.date_gmt ).toLocaleDateString(),
								post.author.name
							)
						: sprintf(
								/* translators: %s: date. */
								__( 'asked %s', 'easyq' ),
								new Date( post.date_gmt ).toLocaleDateString()
							) }
				</div>
			);
		default:
			return (
				<div { ...blockProps }>
					<p>{ title }</p>
				</div>
			);
	}
}

/**
 * Preview of one part of the user from block context.
 *
 * @since 0.1.0
 *
 * @param {Object} props            Properties.
 * @param {string} props.name       Block name.
 * @param {Object} props.attributes Attributes.
 * @param {Object} props.context    Block context.
 * @param {string} props.title      Block title, shown without user.
 * @return {Element} Preview.
 */
function UserFieldPreview( { name, attributes, context, title } ) {
	const blockProps = useBlockProps();
	const userId = context[ 'easyq/userId' ];
	const user = useEasyQ( userId ? `users/${ userId }` : null );

	if ( ! user ) {
		return (
			<div { ...blockProps }>
				<p>{ title }</p>
			</div>
		);
	}

	const term = ( label, value ) => [
		<dt key={ `${ label }-term` }>{ label }</dt>,
		<dd key={ `${ label }-value` }>{ value }</dd>,
	];

	switch ( name ) {
		case 'easyq/user-name':
			return <div { ...blockProps }>{ user.name }</div>;
		case 'easyq/user-avatar':
			return (
				<div { ...blockProps }>
					<img
						src={ user.avatar }
						alt=""
						width={ attributes.avatarSize }
						height={ attributes.avatarSize }
					/>
				</div>
			);
		case 'easyq/user-points':
			return (
				<div { ...blockProps }>
					{ sprintf(
						/* translators: %s: number of points. */
						_n( '%s point', '%s points', user.points, 'easyq' ),
						user.points
					) }
					{ attributes.showRank &&
						user.rank &&
						' ' +
							sprintf(
								/* translators: %s: rank of the user by points. */
								__( '(ranked #%s)', 'easyq' ),
								user.rank
							) }
				</div>
			);
		case 'easyq/user-title':
			return user.title ? (
				<div { ...blockProps }>{ user.title }</div>
			) : null;
		case 'easyq/user-fields':
			return user.fields?.length ? (
				<dl { ...blockProps }>
					{ user.fields.flatMap( ( field ) =>
						term( field.label, field.value )
					) }
				</dl>
			) : (
				<div { ...blockProps }>
					<p>{ title }</p>
				</div>
			);
		case 'easyq/user-stats':
			return (
				<dl { ...blockProps }>
					{ term( __( 'Points', 'easyq' ), user.points ) }
					{ term(
						__( 'Questions', 'easyq' ),
						user.counts.questions
					) }
					{ term( __( 'Answers', 'easyq' ), user.counts.answers ) }
					{ term( __( 'Comments', 'easyq' ), user.counts.comments ) }
				</dl>
			);
		case 'easyq/user-navigation':
			return (
				<nav { ...blockProps }>
					<a href={ user.link }>{ __( 'Profile', 'easyq' ) }</a>{ ' ' }
					<a href={ user.link }>{ __( 'Questions', 'easyq' ) }</a>{ ' ' }
					<a href={ user.link }>{ __( 'Answers', 'easyq' ) }</a>{ ' ' }
					<a href={ user.link }>
						{ __( 'Recent activity', 'easyq' ) }
					</a>
				</nav>
			);
		default:
			return (
				<div { ...blockProps }>
					<p>{ title }</p>
				</div>
			);
	}
}

/**
 * Renders inner blocks with a user as context.
 *
 * @since 0.1.0
 *
 * @param {Object}           props          Properties.
 * @param {number|undefined} props.userId   User ID for the context.
 * @param {Array}            props.template Default inner blocks.
 * @return {Element} Inner blocks.
 */
function UserContextBlocks( { userId, template } ) {
	const innerBlocksProps = useInnerBlocksProps( useBlockProps(), {
		template,
	} );

	return (
		<BlockContextProvider value={ { 'easyq/userId': userId } }>
			<div { ...innerBlocksProps } />
		</BlockContextProvider>
	);
}

/**
 * Renders inner blocks with a post as context.
 *
 * @since 0.1.0
 *
 * @param {Object}           props          Properties.
 * @param {number|undefined} props.postId   Post ID for the context.
 * @param {Array}            props.template Default inner blocks.
 * @param {string}           props.tagName  Element of the wrapper.
 * @return {Element} Inner blocks.
 */
function PostContextBlocks( { postId, template, tagName = 'div' } ) {
	const innerBlocksProps = useInnerBlocksProps( useBlockProps(), {
		template,
	} );
	const Tag = tagName;

	return (
		<BlockContextProvider value={ { 'easyq/postId': postId } }>
			<Tag { ...innerBlocksProps } />
		</BlockContextProvider>
	);
}

/**
 * Container that gives its block a unique query ID.
 *
 * @since 0.1.0
 *
 * @param {Object}                       props               Properties.
 * @param {Object}                       props.attributes    Attributes.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @param {Array}                        props.template      Default inner blocks.
 * @return {Element} Container.
 */
function ListContainer( { attributes, setAttributes, template } ) {
	const instanceId = useInstanceId( ListContainer );

	useEffect( () => {
		if ( ! attributes.queryId ) {
			setAttributes( { queryId: instanceId + 100 } );
		}
	}, [ attributes.queryId, instanceId, setAttributes ] );

	return <div { ...useInnerBlocksProps( useBlockProps(), { template } ) } />;
}

/**
 * Returns the options of a category select, with subcategories indented below their parent.
 *
 * @since 0.1.0
 *
 * @param {Array|undefined} categories Categories from the REST API.
 * @param {number|null}     parent     Parent category.
 * @param {number}          depth      Depth of the parent.
 * @return {Array<{value: string, label: string}>} Options.
 */
function categoryOptions( categories, parent = null, depth = 0 ) {
	if ( ! Array.isArray( categories ) ) {
		return [];
	}

	return categories
		.filter( ( category ) => ( category.parent ?? null ) === parent )
		.flatMap( ( category ) => [
			{
				value: String( category.id ),
				label: '— '.repeat( depth ) + category.title,
			},
			...categoryOptions( categories, category.id, depth + 1 ),
		] );
}

/**
 * Settings of a question list.
 *
 * @since 0.1.0
 *
 * @param {Object}                       props               Properties.
 * @param {Object}                       props.attributes    Attributes.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element} Sidebar.
 */
function QueryInspector( { attributes, setAttributes } ) {
	const query = attributes.query ?? {};
	const categories = useEasyQ( 'categories' );
	const update = ( change ) =>
		setAttributes( { query: { ...query, ...change } } );

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Questions', 'easyq' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Questions of the current page', 'easyq' ) }
					help={ __(
						'Shows the questions of the Q&A page, e.g. of the tag or category.',
						'easyq'
					) }
					checked={ !! query.inherit }
					onChange={ ( inherit ) => update( { inherit } ) }
				/>
				{ ! query.inherit && (
					<>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Questions', 'easyq' ) }
							value={ query.source }
							options={ [
								{
									label: __( 'Recent', 'easyq' ),
									value: 'recent',
								},
								{ label: __( 'Hot', 'easyq' ), value: 'hot' },
								{
									label: __( 'Most votes', 'easyq' ),
									value: 'votes',
								},
								{
									label: __( 'Most answers', 'easyq' ),
									value: 'answers',
								},
								{
									label: __( 'Most views', 'easyq' ),
									value: 'views',
								},
								{
									label: __( 'Without answers', 'easyq' ),
									value: 'unanswered',
								},
								{
									label: __(
										'Without selected answer',
										'easyq'
									),
									value: 'unselected',
								},
								{
									label: __(
										'Without upvoted answers',
										'easyq'
									),
									value: 'unupvoted',
								},
								{
									label: __( 'Recent activity', 'easyq' ),
									value: 'activity',
								},
								{
									label: __( 'Recently answered', 'easyq' ),
									value: 'recent_answers',
								},
								{
									label: __( 'Recently commented', 'easyq' ),
									value: 'recent_comments',
								},
							] }
							onChange={ ( source ) => update( { source } ) }
						/>
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Category', 'easyq' ) }
							value={ String( query.category ?? 0 ) }
							options={ [
								{
									value: '0',
									label: __( 'All categories', 'easyq' ),
								},
								...categoryOptions( categories ),
							] }
							onChange={ ( category ) =>
								update( { category: Number( category ) } )
							}
						/>
						<UserPicker
							label={ __( 'Author', 'easyq' ) }
							help={ __(
								'Empty shows the questions of everyone.',
								'easyq'
							) }
							value={ query.author ?? 0 }
							onChange={ ( author ) => update( { author } ) }
						/>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Tag', 'easyq' ) }
							value={ query.tag ?? '' }
							onChange={ ( tag ) => update( { tag } ) }
						/>
					</>
				) }
				<RangeControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Questions per page (0 for the default)',
						'easyq'
					) }
					value={ query.perPage ?? 0 }
					min={ 0 }
					max={ 100 }
					onChange={ ( perPage ) => update( { perPage } ) }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Settings of a user list.
 *
 * @since 0.1.0
 *
 * @param {Object}                       props               Properties.
 * @param {Object}                       props.attributes    Attributes.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element} Sidebar.
 */
function UserQueryInspector( { attributes, setAttributes } ) {
	const query = attributes.query ?? {};
	const update = ( change ) =>
		setAttributes( { query: { ...query, ...change } } );

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Users', 'easyq' ) }>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Sort order of the current page', 'easyq' ) }
					help={ __(
						'Lets visitors switch between top, newest and special users on the users page.',
						'easyq'
					) }
					checked={ !! query.inherit }
					onChange={ ( inherit ) => update( { inherit } ) }
				/>
				{ ! query.inherit && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Users', 'easyq' ) }
						value={ query.sort ?? 'points' }
						options={ [
							{
								label: __( 'Top users by points', 'easyq' ),
								value: 'points',
							},
							{
								label: __( 'Newest users', 'easyq' ),
								value: 'newest',
							},
							{
								label: __( 'Special users', 'easyq' ),
								value: 'special',
							},
						] }
						onChange={ ( sort ) => update( { sort } ) }
					/>
				) }
				<RangeControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Users per page (0 for the default)',
						'easyq'
					) }
					value={ query.perPage ?? 0 }
					min={ 0 }
					max={ 100 }
					onChange={ ( perPage ) => update( { perPage } ) }
				/>
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * Returns the editor component of a block.
 *
 * @since 0.1.0
 *
 * @param {Object} metadata Block metadata.
 * @return {(props: Object) => Element} Edit component.
 */
export default function editFor( metadata ) {
	const name = metadata.name;

	return function Edit( props ) {
		const { attributes, setAttributes, context } = props;
		const inspector = (
			<Inspector
				metadata={ metadata }
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
		);

		switch ( name ) {
			case 'easyq/question-query':
				return (
					<>
						<QueryInspector { ...props } />
						<ListContainer
							{ ...props }
							template={ QUESTION_LIST }
						/>
					</>
				);
			case 'easyq/question-template':
				return <QuestionTemplateEdit context={ context } />;
			case 'easyq/question':
				return (
					<>
						{ inspector }
						<QuestionEdit questionId={ attributes.questionId } />
					</>
				);
			case 'easyq/answer-list':
				return (
					<>
						{ inspector }
						<ListContainer { ...props } template={ ANSWER_LIST } />
					</>
				);
			case 'easyq/answer-template':
				return <ChildTemplateEdit context={ context } type="answers" />;
			case 'easyq/comment-list':
				return (
					<>
						{ inspector }
						<CommentListEdit />
					</>
				);
			case 'easyq/comment-template':
				return (
					<ChildTemplateEdit context={ context } type="comments" />
				);
			case 'easyq/user-query':
				return (
					<>
						<UserQueryInspector { ...props } />
						<ListContainer { ...props } template={ USER_LIST } />
					</>
				);
			case 'easyq/user-template':
				return <UserTemplateEdit context={ context } />;
			case 'easyq/user':
				return (
					<>
						{ inspector }
						<UserEdit userId={ attributes.userId } />
					</>
				);
			case 'easyq/account':
			case 'easyq/tags':
			case 'easyq/categories':
			case 'easyq/activity-count':
			case 'easyq/search':
			case 'easyq/ask-box':
			case 'easyq/sort-navigation':
			case 'easyq/ask-form':
				return (
					<>
						{ inspector }
						<ServerRendered
							name={ name }
							attributes={ attributes }
						/>
					</>
				);
			default:
				if ( name.startsWith( 'easyq/user-' ) ) {
					return (
						<>
							{ inspector }
							<UserFieldPreview
								name={ name }
								attributes={ attributes }
								context={ context }
								title={ metadata.title }
							/>
						</>
					);
				}

				if ( name.startsWith( 'easyq/post-' ) ) {
					return (
						<>
							{ inspector }
							<FieldPreview
								name={ name }
								attributes={ attributes }
								context={ context }
								title={ metadata.title }
							/>
						</>
					);
				}

				return (
					<>
						{ inspector }
						<Placeholder label={ metadata.title } />
					</>
				);
		}
	};
}

/**
 * Editor of the comment list.
 *
 * @since 0.1.0
 *
 * @return {Element} Comment list.
 */
function CommentListEdit() {
	return (
		<div
			{ ...useInnerBlocksProps( useBlockProps(), {
				template: COMMENT_LIST,
			} ) }
		/>
	);
}

/**
 * Preview rendered by the server, for blocks that do not depend on a post.
 *
 * @since 0.1.0
 *
 * @param {Object} props            Properties.
 * @param {string} props.name       Block name.
 * @param {Object} props.attributes Attributes.
 * @return {Element} Preview.
 */
function ServerRendered( { name, attributes } ) {
	return (
		<div { ...useBlockProps() }>
			<ServerSideRender block={ name } attributes={ attributes } />
		</div>
	);
}

/**
 * Editor of the question template: the first question of the list is shown with editable blocks.
 *
 * @since 0.1.0
 *
 * @param {Object} props         Properties.
 * @param {Object} props.context Block context with the query.
 * @return {Element} Template.
 */
function QuestionTemplateEdit( { context } ) {
	const questions = useEasyQ( questionsPath( context[ 'easyq/query' ], 1 ) );
	const first = Array.isArray( questions ) ? questions[ 0 ] : undefined;

	return (
		<PostContextBlocks
			postId={ first?.id }
			template={ QUESTION_ITEM }
			tagName="div"
		/>
	);
}

/**
 * Editor of the question block: shows the latest question or the chosen one.
 *
 * @since 0.1.0
 *
 * @param {Object} props            Properties.
 * @param {number} props.questionId Chosen question, 0 for the question of the page.
 * @return {Element} Question.
 */
function QuestionEdit( { questionId } ) {
	const latest = useEasyQ( questionId ? null : questionsPath( {}, 1 ) );
	const postId =
		questionId || ( Array.isArray( latest ) ? latest[ 0 ]?.id : undefined );

	return <PostContextBlocks postId={ postId } template={ QUESTION_PAGE } />;
}

/**
 * Editor of answer and comment templates: shows the first answer or comment.
 *
 * @since 0.1.0
 *
 * @param {Object} props         Properties.
 * @param {Object} props.context Block context with the parent post.
 * @param {string} props.type    `answers` or `comments`.
 * @return {Element} Template.
 */
function ChildTemplateEdit( { context, type } ) {
	const parentId = context[ 'easyq/postId' ];
	const path =
		parentId &&
		( type === 'answers'
			? `questions/${ parentId }/answers?per_page=1`
			: `posts/${ parentId }/comments` );
	const children = useEasyQ( path || null );
	const first = Array.isArray( children ) ? children[ 0 ] : undefined;

	return <PostContextBlocks postId={ first?.id } template={ [] } />;
}

/**
 * Editor of the user template: the first user of the list is shown with editable blocks.
 *
 * @since 0.1.0
 *
 * @param {Object} props         Properties.
 * @param {Object} props.context Block context with the query.
 * @return {Element} Template.
 */
function UserTemplateEdit( { context } ) {
	const users = useEasyQ( usersPath( context[ 'easyq/userQuery' ] ) );
	const first = Array.isArray( users ) ? users[ 0 ] : undefined;

	return <UserContextBlocks userId={ first?.id } template={ USER_ITEM } />;
}

/**
 * Editor of the user block: shows the chosen user, or the current user as an example.
 *
 * @since 0.1.0
 *
 * @param {Object} props        Properties.
 * @param {number} props.userId Chosen user, 0 for the user of the page.
 * @return {Element} User.
 */
function UserEdit( { userId } ) {
	const me = useEasyQ( userId ? null : 'users/me' );

	return (
		<UserContextBlocks userId={ userId || me?.id } template={ USER_PAGE } />
	);
}
