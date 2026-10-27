/**
 * Loading easyQ data for previews in the editor.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';

const cache = new Map();

const ACTIVITY_SOURCES = [ 'activity', 'recent_answers', 'recent_comments' ];

/**
 * Loads a REST path once per editor session.
 *
 * @param {string|null} path REST path below easyq/v1, or null to load nothing.
 * @return {Object|Array|undefined} Response data, or undefined while loading or on failure.
 */
export function useEasyQ( path ) {
	const [ data, setData ] = useState( () =>
		path ? cache.get( path ) : undefined
	);

	useEffect( () => {
		if ( ! path ) {
			setData( undefined );
			return;
		}
		if ( cache.has( path ) ) {
			setData( cache.get( path ) );
			return;
		}

		let active = true;
		apiFetch( { path: `/easyq/v1/${ path }` } )
			.then( ( result ) => {
				cache.set( path, result );
				if ( active ) {
					setData( result );
				}
			} )
			.catch( () => {
				if ( active ) {
					setData( undefined );
				}
			} );

		return () => {
			active = false;
		};
	}, [ path ] );

	return data;
}

/**
 * Returns the REST path of a question list query.
 *
 * @param {Object} query   Block query.
 * @param {number} perPage Number of questions.
 * @return {string} Path.
 */
export function questionsPath( query = {}, perPage = 3 ) {
	const args = { per_page: perPage };
	if ( ! query.inherit ) {
		// Activity lists are previewed with the newest questions; the REST API lists questions only.
		args.source = ACTIVITY_SOURCES.includes( query.source )
			? 'recent'
			: query.source || 'recent';
		if ( query.author ) {
			args.author = query.author;
		}
		if ( query.category ) {
			args.category = query.category;
		}
		if ( query.tag ) {
			args.tag = query.tag;
		}
	}

	return addQueryArgs( 'questions', args );
}

/**
 * Returns the REST path of a user list query.
 *
 * @param {Object} query   Block query.
 * @param {number} perPage Number of users.
 * @return {string} Path.
 */
export function usersPath( query = {}, perPage = 1 ) {
	return addQueryArgs( 'users', {
		per_page: perPage,
		sort: ( ! query.inherit && query.sort ) || 'points',
	} );
}
