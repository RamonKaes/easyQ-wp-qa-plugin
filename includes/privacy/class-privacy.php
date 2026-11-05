<?php
/**
 * Personal data exporters and erasers.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Privacy;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Frontend\URLs;
use EasyQ\Notices\Notices;
use EasyQ\Services;
use EasyQ\Users\Site_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the data of the plugin to the personal data tools of WordPress.
 *
 * Follows `wp_comments_personal_data_exporter()` and `wp_comments_personal_data_eraser()`.
 *
 * @since 0.1.0
 */
final class Privacy {

	/**
	 * Hooks the tools into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_erasers' ) );
	}

	/**
	 * Registers the exporters.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function add_exporters( array $exporters ): array {
		$exporters['easyq-favorites'] = array(
			'exporter_friendly_name' => __( 'Q&A favorites', 'easyq' ),
			'callback'               => array( self::class, 'export_favorites' ),
		);

		$exporters['easyq-updates'] = array(
			'exporter_friendly_name' => __( 'Q&A updates', 'easyq' ),
			'callback'               => array( self::class, 'export_updates' ),
		);

		$exporters['easyq-notices'] = array(
			'exporter_friendly_name' => __( 'Q&A notices', 'easyq' ),
			'callback'               => array( self::class, 'export_notices' ),
		);

		return $exporters;
	}

	/**
	 * Registers the erasers.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function add_erasers( array $erasers ): array {
		$erasers['easyq-favorites'] = array(
			'eraser_friendly_name' => __( 'Q&A favorites', 'easyq' ),
			'callback'             => array( self::class, 'erase_favorites' ),
		);

		$erasers['easyq-updates'] = array(
			'eraser_friendly_name' => __( 'Q&A updates', 'easyq' ),
			'callback'             => array( self::class, 'erase_updates' ),
		);

		$erasers['easyq-notices'] = array(
			'eraser_friendly_name' => __( 'Q&A notices', 'easyq' ),
			'callback'             => array( self::class, 'erase_notices' ),
		);

		return $erasers;
	}

	/**
	 * Exports the notices of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_notices( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( false !== $user ) {
			$actor = new Actor( $user->ID, null );
			foreach ( Services::notices()->for_actor( $actor ) as $notice ) {
				$data[] = array(
					'group_id'          => 'easyq-notices',
					'group_label'       => __( 'Q&A notices', 'easyq' ),
					'group_description' => __( 'Notices for you in the Q&A area.', 'easyq' ),
					'item_id'           => 'easyq-notice-' . $notice['id'],
					'data'              => array(
						array(
							'name'  => __( 'Notice', 'easyq' ),
							'value' => $notice['content'],
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erases the notices of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_notices( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( false !== $user ) {
			$wpdb = DB::wpdb();
			$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'usernotices' ), $user->ID );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$removed = (int) $wpdb->query( $sql );
			}
			Site_Meta::delete( $user->ID, Notices::WELCOME_META );
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Exports the updates a user was told of, and the events the user caused.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_updates( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( false !== $user ) {
			foreach ( Services::updates()->items( $user->ID ) as $item ) {
				$question = Services::posts()->find( $item->question_id );
				$post     = Services::posts()->find( $item->post_id );
				if ( null === $question || ! $question->is_visible() ) {
					continue;
				}

				$data[] = array(
					'group_id'          => 'easyq-updates',
					'group_label'       => __( 'Q&A updates', 'easyq' ),
					'group_description' => __( 'What the updates page shows you.', 'easyq' ),
					'item_id'           => 'easyq-update-' . $item->question_id,
					'data'              => array(
						array(
							'name'  => __( 'Question', 'easyq' ),
							'value' => Services::content_renderer()->text( (string) $question->title ),
						),
						array(
							'name'  => __( 'Update', 'easyq' ),
							'value' => $item->label( $post ),
						),
						array(
							'name'  => __( 'Date', 'easyq' ),
							'value' => $item->time,
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erases the updates of a user and their name from the events of others.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_updates( string $email ): array {
		$user = get_user_by( 'email', $email );

		return array(
			'items_removed'  => false !== $user && Services::updates()->forget_user( $user->ID ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Exports the favorites of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_favorites( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( false === $user ) {
			return array(
				'data' => $data,
				'done' => true,
			);
		}

		$favorites = Services::favorites();
		$rows      = array();
		foreach ( $favorites->ids( $user->ID, 'question' ) as $id ) {
			$question = Services::posts()->find( $id );
			if ( null !== $question && $question->is_visible() ) {
				$rows[] = array( 'question-' . $id, __( 'Question', 'easyq' ), Services::content_renderer()->text( (string) $question->title ), URLs::question( $question ) );
			}
		}
		foreach ( $favorites->ids( $user->ID, 'user' ) as $id ) {
			$followed = get_userdata( $id );
			if ( false !== $followed ) {
				$rows[] = array( 'user-' . $id, __( 'User', 'easyq' ), $followed->display_name, URLs::user( $followed ) );
			}
		}
		foreach ( $favorites->tags( $user->ID ) as $tag ) {
			$rows[] = array( 'tag-' . $tag['name'], __( 'Tag', 'easyq' ), $tag['name'], URLs::tag( $tag['name'] ) );
		}
		foreach ( $favorites->ids( $user->ID, 'category' ) as $id ) {
			$category = Services::categories()->find( $id );
			if ( null !== $category ) {
				$rows[] = array( 'category-' . $id, __( 'Category', 'easyq' ), $category->title, URLs::category( $category ) );
			}
		}

		foreach ( $rows as [ $item_id, $type, $title, $link ] ) {
			$data[] = array(
				'group_id'          => 'easyq-favorites',
				'group_label'       => __( 'Q&A favorites', 'easyq' ),
				'group_description' => __( 'What you follow in the Q&A area.', 'easyq' ),
				'item_id'           => 'easyq-favorite-' . $item_id,
				'data'              => array(
					array(
						'name'  => $type,
						'value' => $title,
					),
					array(
						'name'  => __( 'Address', 'easyq' ),
						'value' => $link,
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erases the favorites of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_favorites( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( false !== $user ) {
			$wpdb = DB::wpdb();
			$sql  = $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userfavorites' ), $user->ID );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; custom tables without a WordPress API.
				$removed = (int) $wpdb->query( $sql );
			}
		}

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
