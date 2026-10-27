<?php
/**
 * Q&A pages in the WordPress sitemaps.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Posts\Post;
use EasyQ\Posts\Tags;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Sitemaps;
use WP_Sitemaps_Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Adds questions, tags, categories and, if the site owner allows it, user profiles to
 * the core sitemaps at `/wp-sitemap-easyq-{subtype}-{page}.xml`.
 *
 * Only what visitors may see is listed. Profiles are left out by default because they
 * are pages about people (privacy by default); the setting `sitemap_users` adds them.
 *
 * @since 0.1.0
 */
final class Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Sets up the provider.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->name        = 'easyq';
		$this->object_type = 'easyq';
	}

	/**
	 * Registers the provider with the core sitemaps.
	 *
	 * @since 0.1.0
	 */
	public static function register(): void {
		add_action( 'wp_sitemaps_init', array( self::class, 'add_to' ) );
	}

	/**
	 * Adds the provider to the core sitemaps.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Sitemaps $sitemaps Core sitemaps.
	 */
	public static function add_to( WP_Sitemaps $sitemaps ): void {
		$sitemaps->registry->add_provider( 'easyq', new self() );
	}

	/**
	 * Returns the subtypes: one sitemap per kind of Q&A page.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, object> Subtypes keyed by name.
	 */
	public function get_object_subtypes(): array {
		if ( null !== Services::permissions()->error( 'permit_view_q_page', new Actor(), check_blocks: false ) ) {
			return array();
		}

		$names = array( 'questions', 'tags', 'categories' );
		if ( Options::get_bool( 'viewing', 'sitemap_users' ) ) {
			$names[] = 'users';
		}

		$subtypes = array();
		foreach ( $names as $name ) {
			$subtypes[ $name ] = (object) array( 'name' => $name );
		}

		return $subtypes;
	}

	/**
	 * Returns the addresses of a sitemap page.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $page_num       Page number, starting at 1.
	 * @param string $object_subtype Subtype, see get_object_subtypes().
	 * @return array<int, array<string, string>> Entries with `loc` and, for questions, `lastmod`.
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		if ( ! isset( $this->get_object_subtypes()[ $object_subtype ] ) ) {
			return array();
		}

		$max    = wp_sitemaps_get_max_urls( $this->object_type );
		$offset = max( 0, (int) $page_num - 1 ) * $max;

		return match ( $object_subtype ) {
			'questions'  => $this->questions( $offset, $max ),
			'tags'       => $this->tags( (int) $page_num, $max ),
			'categories' => $this->categories( $offset, $max ),
			'users'      => $this->users( $offset, $max ),
			default      => array(),
		};
	}

	/**
	 * Returns the number of pages of a sitemap.
	 *
	 * @since 0.1.0
	 *
	 * @param string $object_subtype Subtype.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		if ( ! isset( $this->get_object_subtypes()[ $object_subtype ] ) ) {
			return 0;
		}

		$wpdb  = DB::wpdb();
		$total = match ( $object_subtype ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			'questions'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type = %s', Schema::table( 'posts' ), Post::QUESTION ) ),
			'tags'       => ( new Tags() )->popular( '', 1, 1 )['total'],
			'categories' => count( Services::categories()->all() ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			'users'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'userpoints' ) ) ),
			default      => 0,
		};

		return (int) ceil( $total / wp_sitemaps_get_max_urls( $this->object_type ) );
	}

	/**
	 * Returns visible questions, oldest first, so pages stay stable when questions are added.
	 *
	 * @since 0.1.0
	 *
	 * @param int $offset Questions to skip.
	 * @param int $limit  Maximum number of questions.
	 * @return array<int, array<string, string>>
	 */
	private function questions( int $offset, int $limit ): array {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$ids   = DB::int_col( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE type = %s ORDER BY created, id LIMIT %d, %d', Schema::table( 'posts' ), Post::QUESTION, $offset, $limit ) ) );
		$posts = Services::posts()->find_many( $ids );

		$entries = array();
		foreach ( $ids as $id ) {
			$question = $posts[ $id ] ?? null;
			if ( null === $question ) {
				continue;
			}

			$entry = array( 'loc' => URLs::question( $question ) );
			$time  = strtotime( ( $question->updated ?? $question->created ) . ' UTC' );
			if ( false !== $time ) {
				$entry['lastmod'] = gmdate( DATE_W3C, $time );
			}
			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Returns tags used by visible questions, most used first.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page  Page number.
	 * @param int $limit Tags per page.
	 * @return array<int, array<string, string>>
	 */
	private function tags( int $page, int $limit ): array {
		return array_map(
			static fn ( array $tag ): array => array( 'loc' => URLs::tag( $tag['name'] ) ),
			( new Tags() )->popular( '', $page, $limit )['tags']
		);
	}

	/**
	 * Returns the categories.
	 *
	 * @since 0.1.0
	 *
	 * @param int $offset Categories to skip.
	 * @param int $limit  Maximum number of categories.
	 * @return array<int, array<string, string>>
	 */
	private function categories( int $offset, int $limit ): array {
		$entries = array();
		foreach ( array_slice( Services::categories()->all(), $offset, $limit ) as $category ) {
			$entries[] = array( 'loc' => URLs::category( $category ) );
		}

		return $entries;
	}

	/**
	 * Returns the profiles of users who took part in the Q&A area, by points.
	 *
	 * @since 0.1.0
	 *
	 * @param int $offset Users to skip.
	 * @param int $limit  Maximum number of users.
	 * @return array<int, array<string, string>>
	 */
	private function users( int $offset, int $limit ): array {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
		$ids = DB::int_col( $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM %i ORDER BY user_id LIMIT %d, %d', Schema::table( 'userpoints' ), $offset, $limit ) ) );
		cache_users( $ids );

		$entries = array();
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( false !== $user && Services::user_query()->is_member( $user ) ) {
				$entries[] = array( 'loc' => URLs::user( $user ) );
			}
		}

		return $entries;
	}
}
