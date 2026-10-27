/**
 * Registers the easyQ blocks in the editor.
 *
 * @since 0.1.0
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';
import {
	breadcrumbs,
	buttons,
	chartBar,
	check,
	chevronUpDown,
	commentAuthorAvatar,
	commentAuthorName,
	details,
	heading,
	help,
	info,
	layout,
	listView,
	login,
	loop,
	navigation,
	pencil,
	people,
	plusCircle,
	postAuthor,
	postCategories,
	postComments,
	postCommentsCount,
	postCommentsForm,
	postContent,
	postDate,
	postExcerpt,
	postList,
	queryPagination,
	scheduled,
	search,
	seen,
	starFilled,
	tag,
	termDescription,
	title,
	trendingUp,
} from '@wordpress/icons';

import editFor from './edit';

const CONTAINERS = [
	'easyq/question-query',
	'easyq/question-template',
	'easyq/question',
	'easyq/answer-list',
	'easyq/answer-template',
	'easyq/comment-list',
	'easyq/comment-template',
	'easyq/user-query',
	'easyq/user-template',
	'easyq/user',
];

/**
 * Icons of the blocks, from the core icon library; blocks with a core counterpart use its icon.
 *
 * @since 0.1.0
 *
 * @type {Object<string, Element>}
 */
const ICONS = {
	'easyq/account': login,
	'easyq/activity-count': trendingUp,
	'easyq/answer-form': postCommentsForm,
	'easyq/answer-list': postComments,
	'easyq/answer-template': layout,
	'easyq/ask-box': pencil,
	'easyq/ask-form': plusCircle,
	'easyq/breadcrumbs': breadcrumbs,
	'easyq/categories': postCategories,
	'easyq/comment-form': postCommentsForm,
	'easyq/comment-list': postComments,
	'easyq/comment-template': layout,
	'easyq/notices': info,
	'easyq/page-title': heading,
	'easyq/post-actions': buttons,
	'easyq/post-activity': scheduled,
	'easyq/post-answer-count': postCommentsCount,
	'easyq/post-author': postAuthor,
	'easyq/post-category': postCategories,
	'easyq/post-content': postContent,
	'easyq/post-date': postDate,
	'easyq/post-excerpt': postExcerpt,
	'easyq/post-status': check,
	'easyq/post-tags': tag,
	'easyq/post-title': title,
	'easyq/post-view-count': seen,
	'easyq/post-votes': chevronUpDown,
	'easyq/query-no-results': loop,
	'easyq/query-pagination': queryPagination,
	'easyq/question': help,
	'easyq/question-query': loop,
	'easyq/question-template': layout,
	'easyq/related-questions': postList,
	'easyq/search': search,
	'easyq/sort-navigation': listView,
	'easyq/tags': tag,
	'easyq/user': commentAuthorAvatar,
	'easyq/user-avatar': commentAuthorAvatar,
	'easyq/user-fields': details,
	'easyq/user-name': commentAuthorName,
	'easyq/user-navigation': navigation,
	'easyq/user-points': starFilled,
	'easyq/user-query': people,
	'easyq/user-stats': chartBar,
	'easyq/user-template': layout,
	'easyq/user-title': termDescription,
};

const metadataFiles = require.context( '../../blocks', true, /block\.json$/ );

metadataFiles.keys().forEach( ( file ) => {
	const metadata = metadataFiles( file );

	registerBlockType( metadata, {
		icon: ICONS[ metadata.name ],
		edit: editFor( metadata ),
		save: CONTAINERS.includes( metadata.name )
			? () => <InnerBlocks.Content />
			: () => null,
	} );
} );
