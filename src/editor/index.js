/**
 * Registers the easyQ blocks in the editor.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

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

const metadataFiles = require.context( '../../blocks', true, /block\.json$/ );

metadataFiles.keys().forEach( ( file ) => {
	const metadata = metadataFiles( file );

	registerBlockType( metadata, {
		edit: editFor( metadata ),
		save: CONTAINERS.includes( metadata.name )
			? () => <InnerBlocks.Content />
			: () => null,
	} );
} );
