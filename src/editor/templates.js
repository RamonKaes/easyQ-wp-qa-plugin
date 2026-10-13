/**
 * Default inner blocks of the container blocks, matching the server templates.
 */

const meta = [
	'core/group',
	{ layout: { type: 'flex', flexWrap: 'wrap' } },
	[
		[ 'easyq/post-tags' ],
		[ 'easyq/post-category' ],
		[ 'easyq/post-author', { showAvatar: false } ],
		[ 'easyq/post-date' ],
	],
];

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
				{ layout: { type: 'flex', orientation: 'vertical' } },
			],
			[ 'easyq/post-answer-count' ],
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
