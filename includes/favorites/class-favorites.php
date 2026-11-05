<?php
/**
 * Favorites of users.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Favorites;

use EasyQ\Actor;
use EasyQ\Categories\Categories;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Repository;
use EasyQ\Search\Basic_Search;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores what users follow: questions, other users, tags and categories.
 *
 * Ported from Question2Answer `qa-include/app/favorites.php` and `db/favorites.php`.
 *
 * @since 0.1.0
 */
final class Favorites {

	/**
	 * Entity codes of the table by type name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public const TYPES = array(
		'question' => 'Q',
		'user'     => 'U',
		'tag'      => 'T',
		'category' => 'C',
	);

	/**
	 * Prefixes of the events by type name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const EVENTS = array(
		'question' => 'q',
		'user'     => 'u',
		'tag'      => 'tag',
		'category' => 'cat',
	);

	/**
	 * Favorites loaded during the current request, keyed by `user:code`, as lists of entity IDs.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int[]>
	 */
	private array $loaded = array();

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts      Post storage.
	 * @param Categories      $categories Categories.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Categories $categories
	) {
	}

	/**
	 * Hooks the service into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_q_delete', array( self::class, 'question_deleted' ) );
	}

	/**
	 * Removes a deleted question from all favorites.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters with `post_id`.
	 */
	public static function question_deleted( array $params ): void {
		self::forget( 'question', is_numeric( $params['post_id'] ?? null ) ? (int) $params['post_id'] : 0 );
	}

	/**
	 * Returns the stored ID of an entity, if it exists and can be a favorite.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $type    Type name.
	 * @param int|string $id      ID, for tags the name.
	 * @param bool       $to_add  Whether it is about to be added: hidden questions can only be removed.
	 * @return int|null
	 */
	public function entity_id( string $type, int|string $id, bool $to_add = true ): ?int {
		if ( 'tag' === $type ) {
			$tag      = (string) $id;
			$word_ids = ( new Basic_Search() )->word_ids( array( $tag ), create: false );

			return $word_ids[ $tag ] ?? null;
		}

		$number   = (int) $id;
		$question = 'question' === $type ? $this->posts->find( $number ) : null;

		return match ( $type ) {
			'question' => Post::QUESTION === ( $to_add ? $question?->type : $question?->base_type() ) ? $number : null,
			'user'     => false === get_userdata( $number ) ? null : $number,
			'category' => null === $this->categories->find( $number ) ? null : $number,
			default    => null,
		};
	}

	/**
	 * Adds or removes a favorite and reports the event, as `qa_user_favorite_set()` does.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor      $actor    Logged in user.
	 * @param string     $type     Type name.
	 * @param int|string $id       ID, for tags the name.
	 * @param bool       $favorite Whether to add or to remove it.
	 * @return true|WP_Error
	 */
	public function set( Actor $actor, string $type, int|string $id, bool $favorite ): bool|WP_Error {
		$user_id = $actor->user_id;
		$code    = self::TYPES[ $type ] ?? null;
		$entity  = null === $code ? null : $this->entity_id( $type, $id, to_add: $favorite );
		if ( null === $user_id || null === $code || null === $entity ) {
			return new WP_Error( 'easyq_favorite_not_found', __( 'This cannot be added to favorites.', 'easyq' ) );
		}

		if ( 'user' === $type && $entity === $user_id ) {
			return new WP_Error( 'easyq_favorite_self', __( 'You cannot add yourself to your favorites.', 'easyq' ) );
		}

		$wpdb  = DB::wpdb();
		$table = Schema::table( 'userfavorites' );
		$sql   = $favorite
			? $wpdb->prepare( 'INSERT IGNORE INTO %i (user_id, entity_type, entity_id, no_user_events) VALUES (%d, %s, %d, 0)', $table, $user_id, $code, $entity )
			: $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND entity_type = %s AND entity_id = %d', $table, $user_id, $code, $entity );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
		unset( $this->loaded[ $user_id . ':' . $code ] );

		$params = match ( $type ) {
			'question' => array( 'post_id' => $entity ),
			'user'     => array( 'user_id' => $entity ),
			'tag'      => array(
				'word_id' => $entity,
				'tag'     => (string) $id,
			),
			default    => array( 'category_id' => $entity ),
		};
		Events::dispatch( self::EVENTS[ $type ] . ( $favorite ? '_favorite' : '_unfavorite' ), $params, $actor );

		return true;
	}

	/**
	 * Checks whether a user has a favorite.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $user_id User ID.
	 * @param string     $type    Type name.
	 * @param int|string $id      ID, for tags the name.
	 * @return bool
	 */
	public function has( int $user_id, string $type, int|string $id ): bool {
		$entity = $this->entity_id( $type, $id, to_add: false );

		return null !== $entity && in_array( $entity, $this->ids( $user_id, $type ), true );
	}

	/**
	 * Returns all favorites of a type of a user, newest entity first.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Type name.
	 * @return int[] Entity IDs.
	 */
	public function ids( int $user_id, string $type ): array {
		$code = self::TYPES[ $type ] ?? '';
		$key  = $user_id . ':' . $code;
		if ( ! isset( $this->loaded[ $key ] ) ) {
			$wpdb = DB::wpdb();

			$this->loaded[ $key ] = DB::int_col(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
				$wpdb->get_col(
					$wpdb->prepare( 'SELECT entity_id FROM %i WHERE user_id = %d AND entity_type = %s ORDER BY entity_id DESC', Schema::table( 'userfavorites' ), $user_id, $code )
				)
			);
		}

		return $this->loaded[ $key ];
	}

	/**
	 * Returns the tags a user follows.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{name: string, count: int}>
	 */
	public function tags( int $user_id ): array {
		$wpdb = DB::wpdb();
		$rows = DB::rows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT w.word, w.tag_count FROM %i AS f JOIN %i AS w ON w.id = f.entity_id WHERE f.user_id = %d AND f.entity_type = 'T' ORDER BY w.tag_count DESC, w.word ASC",
					Schema::table( 'userfavorites' ),
					Schema::table( 'words' ),
					$user_id
				)
			)
		);

		return array_values(
			array_map(
				static fn ( object $row ): array => array(
					'name'  => Row::string( $row, 'word' ),
					'count' => Row::int( $row, 'tag_count' ),
				),
				$rows
			)
		);
	}

	/**
	 * Removes an entity that no longer exists from all favorites.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Type name.
	 * @param int    $id   Entity ID.
	 */
	public static function forget( string $type, int $id ): void {
		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE entity_type = %s AND entity_id = %d', Schema::table( 'userfavorites' ), self::TYPES[ $type ] ?? '', $id );
		if ( null !== $sql ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
			$wpdb->query( $sql );
		}
	}
}
