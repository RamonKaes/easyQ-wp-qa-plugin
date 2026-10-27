<?php
/**
 * User events and user deletion.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Schema;
use EasyQ\Events\Events;
use EasyQ\Posts\Post_Repository;
use EasyQ\Points\Points;
use EasyQ\Stats\Counts;
use WP_User;
use EasyQ\Votes\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the WordPress user actions as easyQ events and removes Q&A data of deleted users.
 *
 * Ported from Question2Answer `qa-include/app/users-edit.php` (qa_delete_user).
 * Posts of deleted users stay; they are attributed to the user chosen when
 * deleting, or shown without author.
 *
 * @since 0.1.0
 */
final class User_Events {

	/**
	 * Sets up the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param Post_Repository $posts  Post storage.
	 * @param Points          $points User points.
	 */
	public function __construct(
		private readonly Post_Repository $posts,
		private readonly Points $points
	) {
	}

	/**
	 * Hooks the handler into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'user_register', array( $this, 'registered' ) );
		add_action( 'wp_login', array( $this, 'logged_in' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'logged_out' ) );
		add_action( 'after_password_reset', array( $this, 'password_reset' ) );
		add_action( 'wp_set_password', array( $this, 'password_changed' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'profile_updated' ) );
		add_action( 'delete_user', array( $this, 'deleting' ), 10, 2 );
		add_action( 'deleted_user', array( $this, 'deleted' ) );
		add_action( 'wpmu_delete_user', array( $this, 'deleting_from_network' ) );
	}

	/**
	 * Reports a new user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function registered( int $user_id ): void {
		Events::dispatch( 'u_register', array( 'user_id' => $user_id ), Actor::current() );
	}

	/**
	 * Reports a login.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $login User login.
	 * @param WP_User $user  User.
	 */
	public function logged_in( string $login, WP_User $user ): void {
		Events::dispatch( 'u_login', array( 'user_id' => $user->ID ), new Actor( $user->ID, null, \EasyQ\Util\IP::current() ) );
	}

	/**
	 * Reports a logout.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function logged_out( int $user_id ): void {
		Events::dispatch( 'u_logout', array( 'user_id' => $user_id ), new Actor( $user_id ) );
	}

	/**
	 * Reports a password reset through the emailed link.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User.
	 */
	public function password_reset( WP_User $user ): void {
		Events::dispatch( 'u_reset', array( 'user_id' => $user->ID ), new Actor( $user->ID ) );
	}

	/**
	 * Reports a password change.
	 *
	 * @since 0.1.0
	 *
	 * @param string $password New password (not passed on).
	 * @param int    $user_id  User ID.
	 */
	public function password_changed( string $password, int $user_id ): void {
		Events::dispatch( 'u_password', array( 'user_id' => $user_id ), Actor::current() );
	}

	/**
	 * Reports a profile change: `u_save` by the user, `u_edit` by someone else.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function profile_updated( int $user_id ): void {
		$actor = Actor::current();

		Events::dispatch( $actor->user_id === $user_id ? 'u_save' : 'u_edit', array( 'user_id' => $user_id ), $actor );
	}

	/**
	 * Removes the Q&A data of a user who is being deleted from a single site.
	 *
	 * On multisite, deleting a user from a site only removes them from that site;
	 * their data is removed when they are deleted from the network.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $user_id  User ID.
	 * @param int|null $reassign User who receives the posts, or null.
	 */
	public function deleting( int $user_id, ?int $reassign = null ): void {
		if ( is_multisite() ) {
			return;
		}

		Events::dispatch( 'u_delete_before', array( 'user_id' => $user_id ), Actor::current() );
		$this->remove_data( $user_id, $reassign );
	}

	/**
	 * Reports a deleted user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function deleted( int $user_id ): void {
		if ( is_multisite() ) {
			return;
		}

		Events::dispatch( 'u_delete', array( 'user_id' => $user_id ), Actor::current() );
	}

	/**
	 * Removes the Q&A data of a user on every site of the network.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 */
	public function deleting_from_network( int $user_id ): void {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			if ( \EasyQ\Database\Installer::installed_version() > 0 ) {
				Events::dispatch( 'u_delete_before', array( 'user_id' => $user_id ), Actor::current() );
				$this->remove_data( $user_id, null );
				Events::dispatch( 'u_delete', array( 'user_id' => $user_id ), Actor::current() );
			}
			restore_current_blog();
		}
	}

	/**
	 * Removes the Q&A data of a user on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $user_id  User ID.
	 * @param int|null $reassign User who receives the posts, or null to keep them without author.
	 */
	public function remove_data( int $user_id, ?int $reassign ): void {
		$wpdb  = DB::wpdb();
		$posts = Schema::table( 'posts' );
		$votes = Schema::table( 'uservotes' );

		// Posts the user voted on or flagged need their counts updated afterwards.
		$voted_ids = DB::get_int_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE user_id = %d AND (vote != 0 OR flag != 0)', $votes, $user_id ) );
		$own_ids   = DB::get_int_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d', $posts, $user_id ) );

		if ( null !== $reassign && $reassign > 0 ) {
			DB::query( $wpdb->prepare( 'UPDATE %i SET user_id = %d WHERE user_id = %d', $posts, $reassign, $user_id ) );
		} else {
			DB::query( $wpdb->prepare( 'UPDATE %i SET user_id = NULL WHERE user_id = %d', $posts, $user_id ) );
		}
		DB::query( $wpdb->prepare( 'UPDATE %i SET last_user_id = NULL WHERE last_user_id = %d', $posts, $user_id ) );

		DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', $votes, $user_id ) );
		Votes::forget();
		DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userpoints' ), $user_id ) );
		$this->points->forget( $user_id );
		DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userlevels' ), $user_id ) );
		DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userlimits' ), $user_id ) );
		DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'userevents' ), $user_id ) );
		DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE user_id = %d', Schema::table( 'usernotices' ), $user_id ) );
		DB::query( $wpdb->prepare( "DELETE FROM %i WHERE user_id = %d OR (entity_type = 'U' AND entity_id = %d)", Schema::table( 'userfavorites' ), $user_id, $user_id ) );

		foreach ( array_unique( array_merge( $voted_ids, $own_ids ) ) as $post_id ) {
			$this->posts->forget( $post_id );
		}

		$authors = array();
		foreach ( $voted_ids as $post_id ) {
			$this->posts->recount_votes( $post_id );
			$this->posts->recount_flags( $post_id );

			$post = $this->posts->find( $post_id );
			if ( null !== $post && null !== $post->user_id ) {
				$authors[ $post->user_id ] = true;
			}
		}

		foreach ( array_keys( $authors ) as $author_id ) {
			$this->points->update( $author_id, array( Points::QUESTION_VOTE_POINTS, Points::ANSWER_VOTE_POINTS, Points::COMMENT_VOTE_POINTS, Points::UPVOTES_RECEIVED, Points::DOWNVOTES_RECEIVED ) );
		}

		if ( null !== $reassign && $reassign > 0 ) {
			$this->points->update_all( $reassign );
		}

		Counts::invalidate( Counts::USERS_WITH_POINTS, Counts::FLAGGED );
	}
}
