/**
 * Default inner blocks of the container blocks, matching the server templates.
 */

const meta = [
	'core/group',
	{ layout: { type: 'flex', flexWrap: 'wrap' } },
	[
		[ 'easyq/post-tags' ],
		[ 'easyq/post-category' ],
		[ 'easyq/post-activity' ],
	],
];

// Counts keep their natural width next to long titles (core flex child sizing).
const noShrink = {
	layout: { selfStretch: 'fixedNoShrink', flexSize: 'max-content' },
};

export const QUESTION_ITEM = [
	[
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				verticalAlignment: 'top',
			},
		},
		[
			[
				'easyq/post-votes',
				{
					style: noShrink,
					layout: { type: 'flex', orientation: 'vertical' },
				},
			],
			[ 'easyq/post-answer-count', { style: noShrink } ],
			[
				'core/group',
				{ layout: { type: 'constrained' } },
				[ [ 'easyq/post-title', { isLink: true } ], meta ],
			],
		],
	],
];

export const QUESTION_LIST = [
	[ 'easyq/question-template', {}, QUESTION_ITEM ],
	[ 'easyq/query-pagination' ],
	[ 'easyq/query-no-results' ],
];

const POST_BODY = [
	[
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'nowrap',
				verticalAlignment: 'top',
			},
		},
		[
			[
				'easyq/post-votes',
				{ layout: { type: 'flex', orientation: 'vertical' } },
			],
			[
				'core/group',
				{ layout: { type: 'constrained' } },
				[
					[ 'easyq/post-content' ],
					[
						'core/group',
						{
							layout: {
								type: 'flex',
								flexWrap: 'wrap',
								justifyContent: 'space-between',
							},
						},
						[ [ 'easyq/post-actions' ], [ 'easyq/post-author' ] ],
					],
					[ 'easyq/comment-list' ],
				],
			],
		],
	],
];

export const COMMENT_LIST = [
	[
		'easyq/comment-template',
		{},
		[
			[ 'easyq/post-content' ],
			[
				'core/group',
				{ layout: { type: 'flex', flexWrap: 'wrap' } },
				[
					[ 'easyq/post-author', { showAvatar: false } ],
					[ 'easyq/post-date' ],
					[ 'easyq/post-actions' ],
				],
			],
		],
	],
	[ 'easyq/comment-form' ],
];

export const ANSWER_LIST = [
	[ 'easyq/answer-template', {}, POST_BODY ],
	[ 'easyq/query-pagination' ],
];

export const QUESTION_PAGE = [
	[ 'easyq/breadcrumbs' ],
	[ 'easyq/post-title', { level: 1 } ],
	[
		'core/group',
		{ layout: { type: 'flex', flexWrap: 'wrap' } },
		[
			[ 'easyq/post-status' ],
			[ 'easyq/post-tags' ],
			[ 'easyq/post-category' ],
			[ 'easyq/post-view-count' ],
		],
	],
	...POST_BODY,
	[ 'easyq/answer-list' ],
	[ 'easyq/answer-form' ],
	[ 'easyq/related-questions' ],
];

export const USER_ITEM = [
	[
		'core/group',
		{ layout: { type: 'flex', flexWrap: 'nowrap' } },
		[
			[ 'easyq/user-avatar', { avatarSize: 48 } ],
			[
				'core/group',
				{ layout: { type: 'flex', orientation: 'vertical' } },
				[
					[ 'easyq/user-name' ],
					[ 'easyq/user-points' ],
					[ 'easyq/user-title' ],
				],
			],
		],
	],
];

export const USER_LIST = [
	[
		'easyq/user-template',
		{ layout: { type: 'grid', columnCount: 3 } },
		USER_ITEM,
	],
	[ 'easyq/query-pagination' ],
	[ 'easyq/query-no-results' ],
];

export const USER_PAGE = [
	[
		'core/group',
		{
			layout: {
				type: 'flex',
				flexWrap: 'wrap',
				verticalAlignment: 'center',
			},
		},
		[
			[ 'easyq/user-avatar', { avatarSize: 96 } ],
			[
				'core/group',
				{ layout: { type: 'flex', orientation: 'vertical' } },
				[
					[ 'easyq/user-name', { isLink: false } ],
					[ 'easyq/user-title' ],
					[ 'easyq/user-points', { showRank: true } ],
				],
			],
		],
	],
	[ 'easyq/user-navigation' ],
	[ 'easyq/user-fields' ],
	[ 'easyq/user-stats' ],
];
