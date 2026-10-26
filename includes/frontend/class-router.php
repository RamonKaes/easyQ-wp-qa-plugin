<?php
/**
 * Request routing.
 *
 * @package easyQ
 */

namespace EasyQ\Frontend;

use EasyQ\Posts\Post;
use EasyQ\Services;
use WP;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes Q&A requests and resolves their route.
 *
 * Q&A pages are virtual: WordPress does not query posts for them, and the
 * page content comes from the block template of the route.
 */
final class Router {

	/**
	 * Option that remembers a hash of the rewrite rules WordPress has stored.
	 *
	 * @var string
	 */
	public const RULES_OPTION = 'easyq_rewrite_rules_hash';

	/**
	 * Route of the current request, once resolved.
	 *
	 * @var Route|null
	 */
	private ?Route $current = null;

	/**
	 * Hooks the router into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ) );
		add_filter( 'posts_pre_query', array( $this, 'skip_posts_query' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'handle_404' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rules' ) );
	}

	/**
	 * Adds the rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		foreach ( Routes::rewrite_rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Rebuilds the rewrite rules after they changed: a new base slug or new routes after an update.
	 */
	public function maybe_flush_rules(): void {
		$hash = md5( (string) wp_json_encode( Routes::rewrite_rules() ) );

		if ( get_option( self::RULES_OPTION ) !== $hash ) {
			flush_rewrite_rules( false );
			update_option( self::RULES_OPTION, $hash, true );
		}
	}

	/**
	 * Registers the query variables.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		return array_merge( $vars, array( Routes::ROUTE_VAR, Routes::ARG_VAR, Routes::SECTION_VAR ) );
	}

	/**
	 * Resolves the route of a Q&A request.
	 *
	 * @param WP $wp Request.
	 */
	public function parse_request( WP $wp ): void {
		$this->current = null;

		$name = $wp->query_vars[ Routes::ROUTE_VAR ] ?? null;
		if ( ! is_string( $name ) || '' === $name ) {
			return;
		}

		$arg           = $wp->query_vars[ Routes::ARG_VAR ] ?? null;
		$section       = $wp->query_vars[ Routes::SECTION_VAR ] ?? null;
		$this->current = $this->resolve( $name, is_string( $arg ) ? $arg : null, is_string( $section ) ? $section : null );
	}

	/**
	 * Prevents the posts query on Q&A pages; they have no WordPress posts.
	 *
	 * @param array<mixed>|null $posts Posts, or null to run the query.
	 * @param WP_Query          $query Query.
	 * @return array<mixed>|null
	 */
	public function skip_posts_query( ?array $posts, WP_Query $query ): ?array {
		if ( null === $this->current || ! $query->is_main_query() ) {
			return $posts;
		}

		$query->is_home     = false;
		$query->is_404      = false;
		$query->found_posts = 0;

		return array();
	}

	/**
	 * Tells WordPress that Q&A pages exist even though no posts were found.
	 *
	 * @param bool $handled Whether the 404 handling was already done.
	 * @return bool
	 */
	public function handle_404( bool $handled ): bool {
		if ( null === $this->current || 'not_found' === $this->current->name ) {
			return $handled;
		}

		status_header( 200 );

		return true;
	}

	/**
	 * Returns the route of the current request.
	 *
	 * @return Route|null Null if the request is not a Q&A page.
	 */
	public function current(): ?Route {
		return $this->current;
	}

	/**
	 * Sets the current route, e.g. for tests or blocks previewed in the editor.
	 *
	 * @param Route|null $route Route.
	 */
	public function set_current( ?Route $route ): void {
		$this->current = $route;
	}

	/**
	 * Resolves a route from the request variables.
	 *
	 * @param string      $name    Route name.
	 * @param string|null $arg     Route argument.
	 * @param string|null $section Second argument.
	 * @return Route A route; `not_found` if the request does not match anything.
	 */
	public function resolve( string $name, ?string $arg, ?string $section ): Route {
		switch ( $name ) {
			case 'question':
				$post = is_numeric( $arg ) ? Services::posts()->find( (int) $arg ) : null;
				return null !== $post && Post::QUESTION === $post->base_type() ? new Route( 'question', $post ) : Route::not_found();

			case 'tag':
				$tag = null === $arg ? '' : sanitize_text_field( rawurldecode( $arg ) );
				return '' === $tag ? Route::not_found() : new Route( 'tag', $tag );

			case 'category':
				$slugs    = array_map( 'rawurldecode', explode( '/', (string) $arg ) );
				$category = Services::categories()->find_by_slugs( array_map( 'sanitize_title', $slugs ) );
				return null === $category ? Route::not_found() : new Route( 'category', $category );

			case 'user':
			case 'message':
				$user = null === $arg ? false : get_user_by( 'slug', sanitize_title( rawurldecode( $arg ) ) );
				if ( false === $user || ! Services::user_query()->is_member( $user ) ) {
					return Route::not_found();
				}
				$section = 'user' === $name && in_array( $section, Routes::USER_SECTIONS, true ) ? $section : null;
				return new Route( $name, $user, $section );

			case 'home':
				return new Route( 'home' );

			default:
				return isset( Routes::SIMPLE[ $name ] ) ? new Route( $name ) : Route::not_found();
		}
	}
}
