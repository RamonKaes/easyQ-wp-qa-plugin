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
use EasyQ\Messages\Messages;
use EasyQ\Newsletter\Newsletter;
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
	 * @param mixed $exporters Exporters.
	 * @return mixed mixed>
	 */
	public function add_exporters( mixed $exporters ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $exporters ) ) {
			return $exporters;
		}

		$exporters['easyq-posts'] = array(
			'exporter_friendly_name' => __( 'Q&A posts', 'easyq' ),
			'callback'               => array( Post_Data::class, 'export_posts' ),
		);

		$exporters['easyq-votes'] = array(
			'exporter_friendly_name' => __( 'Q&A votes and flags', 'easyq' ),
			'callback'               => array( Post_Data::class, 'export_votes' ),
		);

		$exporters['easyq-profile'] = array(
			'exporter_friendly_name' => __( 'Q&A profile', 'easyq' ),
			'callback'               => array( Profile_Data::class, 'export' ),
		);

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

		$exporters['easyq-messages'] = array(
			'exporter_friendly_name' => __( 'Q&A messages and wall posts', 'easyq' ),
			'callback'               => array( self::class, 'export_messages' ),
		);

		$exporters['easyq-newsletter'] = array(
			'exporter_friendly_name' => __( 'Q&A newsletter', 'easyq' ),
			'callback'               => array( self::class, 'export_newsletter' ),
		);

		return $exporters;
	}

	/**
	 * Registers the erasers.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $erasers Erasers.
	 * @return mixed mixed>
	 */
	public function add_erasers( mixed $erasers ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $erasers ) ) {
			return $erasers;
		}

		$erasers['easyq-posts'] = array(
			'eraser_friendly_name' => __( 'Q&A posts', 'easyq' ),
			'callback'             => array( Post_Data::class, 'erase_posts' ),
		);

		$erasers['easyq-votes'] = array(
			'eraser_friendly_name' => __( 'Q&A votes and flags', 'easyq' ),
			'callback'             => array( Post_Data::class, 'erase_votes' ),
		);

		$erasers['easyq-profile'] = array(
			'eraser_friendly_name' => __( 'Q&A profile', 'easyq' ),
			'callback'             => array( Profile_Data::class, 'erase' ),
		);

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

		$erasers['easyq-messages'] = array(
			'eraser_friendly_name' => __( 'Q&A messages and wall posts', 'easyq' ),
			'callback'             => array( self::class, 'erase_messages' ),
		);

		$erasers['easyq-newsletter'] = array(
			'eraser_friendly_name' => __( 'Q&A newsletter', 'easyq' ),
			'callback'             => array( self::class, 'erase_newsletter' ),
		);

		return $erasers;
	}

	/**
	 * Exports the subscription to the newsletter and the time of the consent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_newsletter( string $email ): array {
		$user  = get_user_by( 'email', $email );
		$state = false === $user ? '' : Newsletter::state( $user->ID );
		if ( false === $user || '' === $state ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$consent = Site_Meta::get( $user->ID, Newsletter::CONSENT_META );

		return array(
			'data' => array(
				array(
					'group_id'          => 'easyq-newsletter',
					'group_label'       => __( 'Q&A newsletter', 'easyq' ),
					'group_description' => __( 'Your subscription to the newsletter.', 'easyq' ),
					'item_id'           => 'easyq-newsletter',
					'data'              => array(
						array(
							'name'  => __( 'Subscription', 'easyq' ),
							'value' => 'confirmed' === $state ? __( 'Confirmed', 'easyq' ) : __( 'Waiting for confirmation', 'easyq' ),
						),
						array(
							'name'  => __( 'Consent given', 'easyq' ),
							'value' => is_numeric( $consent ) ? gmdate( 'Y-m-d H:i:s', (int) $consent ) . ' UTC' : '-',
						),
					),
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erases the subscription to the newsletter.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_newsletter( string $email ): array {
		$user = get_user_by( 'email', $email );

		return array(
			'items_removed'  => false !== $user && Newsletter::unsubscribe( $user->ID, forget: true ),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Exports the private messages and wall posts a user wrote or received.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page of the export, as core asks for them.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_messages( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( false !== $user ) {
			foreach ( Services::messages()->all_of( $user->ID ) as $message ) {
				$other  = get_userdata( (int) ( $message->from_user_id === $user->ID ? $message->to_user_id : $message->from_user_id ) );
				$data[] = array(
					'group_id'          => 'easyq-messages',
					'group_label'       => __( 'Q&A messages and wall posts', 'easyq' ),
					'group_description' => __( 'Private messages and wall posts you wrote or received.', 'easyq' ),
					'item_id'           => 'easyq-message-' . $message->id,
					'data'              => array(
						array(
							'name'  => __( 'Kind', 'easyq' ),
							'value' => Messages::WALL === $message->type ? __( 'Wall post', 'easyq' ) : __( 'Private message', 'easyq' ),
						),
						array(
							'name'  => $message->from_user_id === $user->ID ? __( 'To', 'easyq' ) : __( 'From', 'easyq' ),
							'value' => false === $other ? __( 'Deleted user', 'easyq' ) : $other->display_name,
						),
						array(
							'name'  => __( 'Text', 'easyq' ),
							'value' => $message->content,
						),
						array(
							'name'  => __( 'Date', 'easyq' ),
							'value' => $message->created,
						),
					),
				);
			}
		}

		return self::page( $data, $page );
	}

	/**
	 * Erases the private messages and wall posts a user wrote or received, and the posts on their wall.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase_messages( string $email ): array {
		$user = get_user_by( 'email', $email );

		return array(
			'items_removed'  => false !== $user && Messages::delete_all_of( $user->ID ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Exports the notices of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page of the export, as core asks for them.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_notices( string $email, int $page = 1 ): array {
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

		return self::page( $data, $page );
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
	 * @param int    $page  Page of the export, as core asks for them.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_updates( string $email, int $page = 1 ): array {
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

		return self::page( $data, $page );
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
	 * @param int    $page  Page of the export, as core asks for them.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export_favorites( string $email, int $page = 1 ): array {
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
			} else {
				// Stored and erased as the others; the title of a question the person may not see stays out.
				/* translators: %d: question number. */
				$rows[] = array( 'question-' . $id, __( 'Question', 'easyq' ), sprintf( __( 'A question that is not shown (number %d)', 'easyq' ), $id ), '' );
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

		return self::page( $data, $page );
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

	/**
	 * Returns a page of export items, as core pages the export of comments
	 * (`wp_comments_personal_data_exporter()`), 500 at a time.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array<string, mixed>> $data All items.
	 * @param int                        $page Page, starting at 1.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	private static function page( array $data, int $page ): array {
		$offset = ( max( 1, $page ) - 1 ) * Post_Data::PER_PAGE;

		return array(
			'data' => array_slice( $data, $offset, Post_Data::PER_PAGE ),
			'done' => count( $data ) <= $offset + Post_Data::PER_PAGE,
		);
	}
}
