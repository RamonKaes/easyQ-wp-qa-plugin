/**
 * Editor components of the easyQ blocks.
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
import { RawHTML, useEffect, useInstanceId } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

import Inspector from './inspector';
import { questionsPath, useEasyQ } from './data';
import {
	ANSWER_LIST,
	COMMENT_LIST,
	QUESTION_ITEM,
	QUESTION_LIST,
	QUESTION_PAGE,
} from './templates';

/**
 * Shows a short text in place of a block that only has content on Q&A pages.
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
			return (
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
					{ post.tags.join( attributes.separator ?? ', ' ) }
				</div>
			) : null;
		case 'easyq/post-category':
			return post.category ? (
				<div { ...blockProps }>{ post.category.title }</div>
			) : null;
		default:
			return (
				<div { ...blockProps }>
					<p>{ title }</p>
				</div>
			);
	}
}

/**
 * Renders inner blocks with a post as context.
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
 * Settings of a question list.
 *
 * @param {Object}                       props               Properties.
 * @param {Object}                       props.attributes    Attributes.
 * @param {(attributes: Object) => void} props.setAttributes Updates attributes.
 * @return {Element} Sidebar.
 */
function QueryInspector( { attributes, setAttributes } ) {
	const query = attributes.query ?? {};
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
							] }
							onChange={ ( source ) => update( { source } ) }
						/>
						<RangeControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Category ID (0 for all)', 'easyq' ) }
							value={ query.category ?? 0 }
							min={ 0 }
							max={ 10000 }
							onChange={ ( category ) => update( { category } ) }
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
 * Returns the editor component of a block.
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
				return <ListContainer { ...props } template={ ANSWER_LIST } />;
			case 'easyq/answer-template':
				return <ChildTemplateEdit context={ context } type="answers" />;
			case 'easyq/comment-list':
				return <CommentListEdit />;
			case 'easyq/comment-template':
				return (
					<ChildTemplateEdit context={ context } type="comments" />
				);
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
