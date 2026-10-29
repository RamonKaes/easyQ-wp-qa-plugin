<?php
/**
 * Shared service instances.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ;

use EasyQ\Categories\Categories;
use EasyQ\Limits\Limits;
use EasyQ\Points\Points;
use EasyQ\Posts\Activity_Query;
use EasyQ\Posts\Content_Renderer;
use EasyQ\Posts\Moderation_Query;
use EasyQ\Posts\Post_Repository;
use EasyQ\Posts\Question_Query;
use EasyQ\Posts\Post_Service;
use EasyQ\Posts\Post_Validator;
use EasyQ\Search\Search;
use EasyQ\Users\Permissions;
use EasyQ\Users\User_Fields;
use EasyQ\Users\User_Levels;
use EasyQ\Users\User_Query;
use EasyQ\Users\User_Titles;
use EasyQ\Votes\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin services once and wires their dependencies.
 *
 * Extensions use these accessors to work with easyQ data, e.g.
 * `EasyQ\Services::posts()->find( $id )`.
 *
 * @since 0.1.0
 */
final class Services {

	/**
	 * Created services keyed by name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, object>
	 */
	private static array $instances = array();

	/**
	 * Returns the post storage.
	 *
	 * @since 0.1.0
	 *
	 * @return Post_Repository
	 */
	public static function posts(): Post_Repository {
		return self::shared( Post_Repository::class, static fn (): Post_Repository => new Post_Repository() );
	}

	/**
	 * Returns the post operations.
	 *
	 * @since 0.1.0
	 *
	 * @return Post_Service
	 */
	public static function post_service(): Post_Service {
		return self::shared(
			Post_Service::class,
			static fn (): Post_Service => new Post_Service( self::posts(), self::search(), self::points(), self::categories() )
		);
	}

	/**
	 * Returns the post validator.
	 *
	 * @since 0.1.0
	 *
	 * @return Post_Validator
	 */
	public static function validator(): Post_Validator {
		return self::shared( Post_Validator::class, static fn (): Post_Validator => new Post_Validator() );
	}

	/**
	 * Returns the votes and flags service.
	 *
	 * @since 0.1.0
	 *
	 * @return Votes
	 */
	public static function votes(): Votes {
		return self::shared( Votes::class, static fn (): Votes => new Votes( self::posts(), self::post_service(), self::points() ) );
	}

	/**
	 * Returns the search service.
	 *
	 * @since 0.1.0
	 *
	 * @return Search
	 */
	public static function search(): Search {
		return self::shared( Search::class, static fn (): Search => new Search() );
	}

	/**
	 * Returns the points service.
	 *
	 * @since 0.1.0
	 *
	 * @return Points
	 */
	public static function points(): Points {
		return self::shared( Points::class, static fn (): Points => new Points() );
	}

	/**
	 * Returns the categories service.
	 *
	 * @since 0.1.0
	 *
	 * @return Categories
	 */
	public static function categories(): Categories {
		return self::shared( Categories::class, static fn (): Categories => new Categories( self::posts(), self::search() ) );
	}

	/**
	 * Returns the rate limits service.
	 *
	 * @since 0.1.0
	 *
	 * @return Limits
	 */
	public static function limits(): Limits {
		return self::shared( Limits::class, static fn (): Limits => new Limits() );
	}

	/**
	 * Returns the user levels service.
	 *
	 * @since 0.1.0
	 *
	 * @return User_Levels
	 */
	public static function user_levels(): User_Levels {
		return self::shared( User_Levels::class, static fn (): User_Levels => new User_Levels() );
	}

	/**
	 * Returns the permission checks.
	 *
	 * @since 0.1.0
	 *
	 * @return Permissions
	 */
	public static function permissions(): Permissions {
		return self::shared(
			Permissions::class,
			static fn (): Permissions => new Permissions( self::user_levels(), self::points(), self::limits(), self::votes() )
		);
	}

	/**
	 * Returns the user profile fields service.
	 *
	 * @since 0.1.0
	 *
	 * @return User_Fields
	 */
	public static function user_fields(): User_Fields {
		return self::shared( User_Fields::class, static fn (): User_Fields => new User_Fields() );
	}

	/**
	 * Returns the content renderer.
	 *
	 * @since 0.1.0
	 *
	 * @return Content_Renderer
	 */
	public static function content_renderer(): Content_Renderer {
		return self::shared( Content_Renderer::class, static fn (): Content_Renderer => new Content_Renderer() );
	}

	/**
	 * Returns the question lists.
	 *
	 * @since 0.1.0
	 *
	 * @return Question_Query
	 */
	public static function question_query(): Question_Query {
		return self::shared( Question_Query::class, static fn (): Question_Query => new Question_Query( self::posts(), self::categories() ) );
	}

	/**
	 * Returns the moderation lists.
	 *
	 * @since 0.1.0
	 *
	 * @return Moderation_Query
	 */
	public static function moderation_query(): Moderation_Query {
		return self::shared( Moderation_Query::class, static fn (): Moderation_Query => new Moderation_Query( self::posts(), self::categories(), self::permissions() ) );
	}

	/**
	 * Returns the activity lists.
	 *
	 * @since 0.1.0
	 *
	 * @return Activity_Query
	 */
	public static function activity_query(): Activity_Query {
		return self::shared( Activity_Query::class, static fn (): Activity_Query => new Activity_Query( self::posts(), self::categories() ) );
	}

	/**
	 * Returns the user lists.
	 *
	 * @since 0.1.0
	 *
	 * @return User_Query
	 */
	public static function user_query(): User_Query {
		return self::shared( User_Query::class, static fn (): User_Query => new User_Query( self::user_levels() ) );
	}

	/**
	 * Returns the user titles.
	 *
	 * @since 0.1.0
	 *
	 * @return User_Titles
	 */
	public static function user_titles(): User_Titles {
		return self::shared( User_Titles::class, static fn (): User_Titles => new User_Titles( self::user_levels(), self::points() ) );
	}

	/**
	 * Forgets all created services, so the next call creates new ones. Used by tests.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$instances = array();
		Votes::forget();
	}

	/**
	 * Returns a shared service, creating it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @template T of object
	 * @param string   $name    Service class.
	 * @param callable $factory Creates the service.
	 * @return object
	 * @phpstan-param class-string<T> $name
	 * @phpstan-param callable(): T $factory
	 * @phpstan-return T
	 */
	private static function shared( string $name, callable $factory ): object {
		if ( ! isset( self::$instances[ $name ] ) ) {
			self::$instances[ $name ] = $factory();
		}

		$instance = self::$instances[ $name ];
		assert( $instance instanceof $name );

		return $instance;
	}
}
