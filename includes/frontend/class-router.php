<?php
/**
 * Request routing.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\Actor;
use EasyQ\Feeds\Feeds;
use EasyQ\Posts\Post;
use EasyQ\Posts\Tags;
use EasyQ\Services;
use EasyQ\Util\Text;
use WP;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Recognizes Q&A requests and resolves their route.
 *
 * Q&A pages are virtual: WordPress does not query posts for them, and the
 * page content comes from the block template of the route.
 *
 * @since 0.1.0
 */
final class Router {

	/**
	 * Option that remembers a hash of the rewrite rules WordPress has stored.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULES_OPTION = 'easyq_rewrite_rules_hash';

	/**
	 * Route of the current request, once resolved.
	 *
	 * @since 0.1.0
	 *
	 * @var Route|null
	 */
	private ?Route $current = null;

	/**
	 * Hooks the router into WordPress.
	 *
	 * @since 0.1.0
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
	 *
	 * @since 0.1.0
	 */
	public function add_rewrite_rules(): void {
		foreach ( Routes::rewrite_rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Rebuilds the rewrite rules after they changed: a new base slug or new routes after an update.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param mixed $vars Public query variables.
	 * @return mixed
	 */
	public function query_vars( mixed $vars ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $vars ) ) {
			return $vars;
		}

		return array_merge( $vars, array( Routes::ROUTE_VAR, Routes::ARG_VAR, Routes::SECTION_VAR ) );
	}

	/**
	 * Returns the question of the page if the viewer may see it.
	 *
	 * Without the permission to view questions the page stays, with the reason in the question
	 * block, but its title, address and data are not printed.
	 *
	 * @since 0.1.0
	 *
	 * @return Post|null
	 */
	public function shown_question(): ?Post {
		$question = $this->current?->question();

		return null !== $question && Services::permissions()->shows( $question, self::viewer() ) ? $question : null;
	}

	/**
	 * Returns who views the page: the user, or a visitor with the cookie of their posts, so
	 * that a visitor sees their own waiting post on its page, as the REST API shows it.
	 *
	 * @since 0.1.0
	 *
	 * @return Actor
	 */
	private static function viewer(): Actor {
		return Actor::current( \EasyQ\Users\Visitor_Cookie::current() );
	}

	/**
	 * Resolves the route of a Q&A request.
	 *
	 * @since 0.1.0
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
		$this->current = $this->resolve( $name, is_string( $arg ) ? self::decode( $arg ) : null, is_string( $section ) ? self::decode( $section ) : null );
	}

	/**
	 * Prevents the posts query on Q&A pages; they have no WordPress posts.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed    $posts Posts, or null to run the query.
	 * @param WP_Query $query Query.
	 * @return mixed
	 */
	public function skip_posts_query( mixed $posts, WP_Query $query ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! ( null === $posts || is_array( $posts ) ) ) {
			return $posts;
		}

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
	 * @since 0.1.0
	 *
	 * @param mixed $handled Whether the 404 handling was already done.
	 * @return mixed
	 */
	public function handle_404( mixed $handled ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_bool( $handled ) ) {
			return $handled;
		}

		if ( null === $this->current || 'not_found' === $this->current->name ) {
			return $handled;
		}

		status_header( 200 );

		// A post waiting for approval or hidden is shown only to who may see it, the author by
		// their visitor cookie, which page caches do not know: such a page is never cached, nor
		// a question page seen with the cookie, which may show the visitor's own waiting answers
		// and comments.
		$question = $this->current->question();
		if ( null !== $question && ( ! $question->is_visible() || null !== \EasyQ\Users\Visitor_Cookie::current() ) ) {
			self::no_cache();
		}

		return true;
	}

	/**
	 * Keeps a page out of browser, proxy and page caches.
	 *
	 * @since 0.1.0
	 */
	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// The constant page cache plugins read, as WooCommerce sets it for the cart.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant of the page caches, not one of easyQ.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Returns the route of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @return Route|null Null if the request is not a Q&A page.
	 */
	public function current(): ?Route {
		return $this->current;
	}

	/**
	 * Sets the current route. Only the tests call it; a request gets its route in
	 * `parse_request()`.
	 *
	 * @since 0.1.0
	 *
	 * @param Route|null $route Route.
	 */
	public function set_current( ?Route $route ): void {
		$this->current = $route;
	}

	/**
	 * Resolves a route from the request variables.
	 *
	 * @since 0.1.0
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
				// A hidden or queued question is not found for those who may not see it, as in the REST API and core.
				return null !== $post && Post::QUESTION === $post->base_type() && Services::permissions()->post_rules( $post, self::viewer() )['viewable'] ? new Route( 'question', $post ) : Route::not_found();

			case 'tag':
				$tag = null === $arg ? '' : Text::query_text( wp_strip_all_tags( $arg, true ) );
				// A tag no visible question uses is not found, as core answers a term that does not exist.
				return '' === $tag || ! ( new Tags() )->exists( $tag ) ? Route::not_found() : new Route( 'tag', $tag );

			case 'category':
				$slugs    = explode( '/', (string) $arg );
				$category = Services::categories()->find_by_slugs( array_map( 'sanitize_title', $slugs ) );
				return null === $category ? Route::not_found() : new Route( 'category', $category );

			case 'user':
			case 'message':
				$user   = null === $arg ? false : get_user_by( 'slug', sanitize_title( $arg ) );
				$viewer = get_current_user_id();
				if ( false === $user || ! Services::user_query()->is_shown( $user, 0 === $viewer ? null : $viewer ) ) {
					return Route::not_found();
				}
				$section = 'user' === $name && in_array( $section, Routes::USER_SECTIONS, true ) ? $section : null;
				return new Route( $name, $user, $section );

			case 'feed':
				// The feed checks its argument itself; see Feeds::channel().
				return null !== $arg && isset( Feeds::TYPES[ $arg ] ) ? new Route( 'feed', $arg, null === $section || '' === $section ? null : $section ) : Route::not_found();

			case 'home':
				return new Route( 'home' );

			default:
				return isset( Routes::SIMPLE[ $name ] ) ? new Route( $name ) : Route::not_found();
		}
	}

	/**
	 * Decodes a part of the address once: pretty addresses reach the router as they were
	 * sent, query parameters already decoded by PHP. `resolve()` gets decoded parts.
	 *
	 * @since 0.1.0
	 *
	 * @param string $part Part of the address.
	 * @return string
	 */
	public static function decode( string $part ): string {
		return Routes::pretty() ? rawurldecode( $part ) : $part;
	}
}
