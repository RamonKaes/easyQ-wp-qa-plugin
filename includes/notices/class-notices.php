<?php
/**
 * Notices at the top of Q&A pages.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Notices;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Notices for first-time visitors, new users and single users, which they can dismiss.
 *
 * Ported from Question2Answer `qa-include/db/notices.php`, `ajax/notice.php` and the notices
 * of `qa_content_prepare()`.
 *
 * @since 0.1.0
 */
final class Notices {

	/**
	 * ID of the notice for visitors.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VISITOR = 'visitor';

	/**
	 * ID of the notice for new users.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WELCOME = 'welcome';

	/**
	 * Cookie that says a visitor needs no notice for visitors, as `qa_noticed`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COOKIE = 'easyq_noticed';

	/**
	 * User meta that says a user has not dismissed the notice for new users.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WELCOME_META = 'easyq_welcome_notice';

	/**
	 * Hooks the service into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_u_register', array( self::class, 'user_registered' ) );
		add_action( 'template_redirect', array( self::class, 'remember_logged_in' ) );
	}

	/**
	 * Marks a new user for the notice for new users.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $params Event parameters with `user_id`.
	 */
	public static function user_registered( array $params ): void {
		if ( is_numeric( $params['user_id'] ?? null ) ) {
			Site_Meta::update( (int) $params['user_id'], self::WELCOME_META, 1 );
		}
	}

	/**
	 * Users who logged in on a Q&A page are no first-time visitors after they log out.
	 *
	 * @since 0.1.0
	 */
	public static function remember_logged_in(): void {
		if ( is_user_logged_in() && null !== Block_Context::router()->current() && ! isset( $_COOKIE[ self::COOKIE ] ) ) {
			self::remember_visitor();
		}
	}

	/**
	 * Adds a notice for a user, for example from a plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $user_id User ID.
	 * @param string      $content Text.
	 * @param string      $format  `html`, or empty for plain text.
	 * @param string|null $tags    Tags of the notice for other plugins.
	 * @return int ID of the notice.
	 */
	public function add( int $user_id, string $content, string $format = '', ?string $tags = null ): int {
		$wpdb = DB::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom tables without a WordPress API.
		$wpdb->insert(
			Schema::table( 'usernotices' ),
			array(
				'user_id' => $user_id,
				'content' => $content,
				'format'  => $format,
				'tags'    => $tags,
				'created' => current_time( 'mysql', true ),
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * Returns the notices of a user or visitor, the oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor User or visitor.
	 * @return list<array{id: string, content: string}> Content as escaped HTML.
	 */
	public function for_actor( Actor $actor ): array {
		$notices = array();
		if ( null === $actor->user_id ) {
			if ( Options::get_bool( 'users', 'show_notice_visitor' ) && ! isset( $_COOKIE[ self::COOKIE ] ) ) {
				$notices[] = array(
					'id'      => self::VISITOR,
					'content' => wp_kses_post( Options::get_string( 'users', 'notice_visitor' ) ),
				);
			}

			return $notices;
		}

		$wpdb = DB::wpdb();
		$rows = DB::rows(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables without a WordPress API.
			$wpdb->get_results(
				$wpdb->prepare( 'SELECT id, content, format FROM %i WHERE user_id = %d ORDER BY created ASC, id ASC', Schema::table( 'usernotices' ), $actor->user_id )
			)
		);
		foreach ( $rows as $row ) {
			$content   = Row::string( $row, 'content' );
			$notices[] = array(
				'id'      => (string) Row::int( $row, 'id' ),
				'content' => 'html' === Row::string( $row, 'format' ) ? wp_kses_post( $content ) : nl2br( esc_html( $content ) ),
			);
		}

		if ( Options::get_bool( 'users', 'show_notice_welcome' ) && '' !== Site_Meta::get( $actor->user_id, self::WELCOME_META ) ) {
			$notices[] = array(
				'id'      => self::WELCOME,
				'content' => wp_kses_post( Options::get_string( 'users', 'notice_welcome' ) ),
			);
		}

		return $notices;
	}

	/**
	 * Dismisses a notice.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor User or visitor.
	 * @param string $id    Notice ID.
	 * @return bool Whether there was such a notice to dismiss.
	 */
	public function dismiss( Actor $actor, string $id ): bool {
		if ( self::VISITOR === $id ) {
			self::remember_visitor();

			return true;
		}

		if ( null === $actor->user_id ) {
			return false;
		}

		if ( self::WELCOME === $id ) {
			$had = '' !== Site_Meta::get( $actor->user_id, self::WELCOME_META );
			Site_Meta::delete( $actor->user_id, self::WELCOME_META );

			return $had;
		}

		$wpdb = DB::wpdb();
		$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d AND id = %d', Schema::table( 'usernotices' ), $actor->user_id, (int) $id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared here; custom tables without a WordPress API.
		return null !== $sql && (int) $wpdb->query( $sql ) > 0;
	}

	/**
	 * Remembers that this browser needs no notice for visitors.
	 *
	 * @since 0.1.0
	 */
	private static function remember_visitor(): void {
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				'1',
				array(
					'expires'  => time() + 10 * YEAR_IN_SECONDS,
					'path'     => (string) COOKIEPATH,
					'domain'   => (string) COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		$_COOKIE[ self::COOKIE ] = '1';
	}
}
