<?php
/**
 * The newsletter: subscriptions with double opt-in and sending in batches.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Newsletter;

use EasyQ\Database\DB;
use EasyQ\Frontend\Routes;
use EasyQ\REST\Controller;
use EasyQ\Settings\Options;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Status;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the newsletter of Question2Answer (`app/mailing.php`) to the users who asked for it.
 *
 * Unlike Q2A, which mails every user unless they opt out, users opt in on their account
 * page and confirm by email, as core confirms a new email address. The newsletter goes out
 * in batches by WP-Cron.
 *
 * @since 0.1.0
 */
final class Newsletter {

	/**
	 * User meta: `pending` while the confirmation is open, `confirmed` once subscribed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATE_META = 'easyq_newsletter';

	/**
	 * User meta: hash and time of the open confirmation key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_META = 'easyq_newsletter_key';

	/**
	 * User meta: time of the consent, as proof.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CONSENT_META = 'easyq_newsletter_consent';

	/**
	 * Option with the state of the current sending.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RUN_OPTION = 'easyq_newsletter_run';

	/**
	 * Cron hook of a batch.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'easyq_newsletter_batch';

	/**
	 * Hooks the sending into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * Runs a batch planned by WP-Cron.
	 *
	 * @since 0.1.0
	 */
	public function run_batch(): void {
		$this->send_batch();
	}

	/**
	 * Returns the subscription state of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return string `confirmed`, `pending` or empty.
	 */
	public static function state( int $user_id ): string {
		$state = Site_Meta::get( $user_id, self::STATE_META );

		return is_string( $state ) ? $state : '';
	}

	/**
	 * Asks a user to confirm the subscription by email.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User.
	 * @return true|WP_Error
	 */
	public function subscribe( WP_User $user ): bool|WP_Error {
		if ( ! Options::get_bool( 'newsletter', 'mailing_enabled' ) ) {
			return new WP_Error( 'easyq_newsletter_off', __( 'There is no newsletter.', 'easyq' ), array( 'status' => 404 ) );
		}

		if ( 'confirmed' === self::state( $user->ID ) ) {
			return true;
		}

		// As core stores request keys: only a hash, with the time for the expiry.
		$key = wp_generate_password( 20, false );
		Site_Meta::update(
			$user->ID,
			self::KEY_META,
			array(
				'hash' => wp_hash_password( $key ),
				'time' => time(),
			)
		);
		Site_Meta::update( $user->ID, self::STATE_META, 'pending' );

		$url = Routes::url(
			'unsubscribe',
			null,
			null,
			array(
				'newsletter' => 'confirm',
				'user'       => $user->ID,
				'key'        => $key,
			)
		);

		$site = self::site_name();
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Confirm the newsletter', 'easyq' ), $site );
		/* translators: 1: site name, 2: confirmation address. */
		$body = sprintf( __( "Hello,\n\nyou asked for the newsletter of %1\$s. Please confirm it here:\n\n%2\$s\n\nIf you did not ask for it, ignore this email; you will get no newsletter.\n\n%1\$s", 'easyq' ), $site, $url );

		return wp_mail( $user->user_email, $subject, $body )
			? true
			: new WP_Error( 'easyq_email_error', __( 'The confirmation could not be sent. Please try again later.', 'easyq' ), array( 'status' => 500 ) );
	}

	/**
	 * Confirms a subscription with the key from the email.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key from the email.
	 * @return true|WP_Error
	 */
	public function confirm( int $user_id, string $key ): bool|WP_Error {
		$stored = Site_Meta::get( $user_id, self::KEY_META );
		$hash   = is_array( $stored ) && is_string( $stored['hash'] ?? null ) ? $stored['hash'] : '';
		$time   = is_array( $stored ) && is_int( $stored['time'] ?? null ) ? $stored['time'] : 0;

		/** This filter is documented in wp-includes/user.php */
		$expiry = apply_filters( 'user_request_key_expiration', DAY_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The core filter for the life of confirmation keys.
		$expiry = is_numeric( $expiry ) ? (int) $expiry : DAY_IN_SECONDS;

		if ( '' === $hash || '' === $key || ! wp_check_password( $key, $hash ) || time() > $time + $expiry ) {
			return new WP_Error( 'easyq_invalid_key', __( 'This link is not valid.', 'easyq' ), array( 'status' => 403 ) );
		}

		Site_Meta::delete( $user_id, self::KEY_META );
		Site_Meta::update( $user_id, self::STATE_META, 'confirmed' );
		Site_Meta::update( $user_id, self::CONSENT_META, time() );

		return true;
	}

	/**
	 * Ends the subscription of a user.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether there was a subscription or request.
	 */
	public static function unsubscribe( int $user_id ): bool {
		$had = '' !== self::state( $user_id );
		foreach ( array( self::STATE_META, self::KEY_META, self::CONSENT_META ) as $key ) {
			Site_Meta::delete( $user_id, $key );
		}

		return $had;
	}

	/**
	 * Returns the token of the link to stop the newsletter.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function token( int $user_id ): string {
		return wp_hash( 'easyq_newsletter|' . $user_id );
	}

	/**
	 * Checks the token of a link to stop the newsletter.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $token   Token.
	 * @return bool
	 */
	public static function verify( int $user_id, string $token ): bool {
		return hash_equals( self::token( $user_id ), $token );
	}

	/**
	 * Returns the state of the current sending.
	 *
	 * @since 0.1.0
	 *
	 * @return array{running: bool, done: int, total: int}
	 */
	public static function progress(): array {
		$run = get_option( self::RUN_OPTION );

		return array(
			'running' => is_array( $run ),
			'done'    => is_array( $run ) && is_int( $run['done'] ?? null ) ? $run['done'] : 0,
			'total'   => is_array( $run ) && is_int( $run['total'] ?? null ) ? $run['total'] : 0,
		);
	}

	/**
	 * Starts sending the newsletter, as `qa_mailing_start()`.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public function start(): bool|WP_Error {
		$ready = self::ready();
		if ( $ready instanceof WP_Error ) {
			return $ready;
		}

		if ( ! self::progress()['running'] ) {
			update_option(
				self::RUN_OPTION,
				array(
					'last_user_id' => 0,
					'done'         => 0,
					'total'        => count( self::subscribers( 0, PHP_INT_MAX )['users'] ),
				),
				false
			);
		}

		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}

		return true;
	}

	/**
	 * Stops sending, as `qa_mailing_stop()`.
	 *
	 * @since 0.1.0
	 */
	public static function cancel(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( self::RUN_OPTION );
	}

	/**
	 * Sends the next batch and plans the one after, as `qa_mailing_perform_step()`.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of emails sent.
	 */
	public function send_batch(): int {
		$run = get_option( self::RUN_OPTION );
		if ( ! is_array( $run ) || self::ready() instanceof WP_Error ) {
			self::cancel();

			return 0;
		}

		$per_minute = max( 1, Options::get_int( 'newsletter', 'mailing_per_minute' ) );
		$batch      = self::subscribers( is_int( $run['last_user_id'] ?? null ) ? $run['last_user_id'] : 0, $per_minute );
		if ( null === $batch['last'] ) {
			self::cancel();

			return 0;
		}

		$sent = 0;
		foreach ( $batch['users'] as $user ) {
			$sent += $this->send_to( $user ) ? 1 : 0;
		}

		update_option(
			self::RUN_OPTION,
			array(
				'last_user_id' => $batch['last'],
				'done'         => ( is_int( $run['done'] ?? null ) ? $run['done'] : 0 ) + count( $batch['users'] ),
				'total'        => is_int( $run['total'] ?? null ) ? $run['total'] : 0,
			),
			false
		);
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );

		return $sent;
	}

	/**
	 * Sends the newsletter to one user, with the links to stop it.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user Recipient.
	 * @return bool Whether the email was sent.
	 */
	public function send_to( WP_User $user ): bool {
		$token = self::token( $user->ID );
		$page  = Routes::url(
			'unsubscribe',
			null,
			null,
			array(
				'newsletter' => 'stop',
				'user'       => $user->ID,
				'token'      => $token,
			)
		);
		$click = add_query_arg(
			array(
				'user'  => $user->ID,
				'token' => $token,
			),
			rest_url( Controller::NAMESPACE . '/unsubscribe' )
		);

		$from_email = Options::get_string( 'newsletter', 'mailing_from_email' );
		$from_name  = Options::get_string( 'newsletter', 'mailing_from_name' );
		$charset    = get_option( 'blog_charset' );
		$headers    = 'Content-Type: text/plain; charset="' . ( is_string( $charset ) ? $charset : 'UTF-8' ) . "\"\n"
			. ( is_email( $from_email ) ? 'From: "' . str_replace( '"', '', '' === $from_name ? self::site_name() : $from_name ) . '" <' . $from_email . ">\n" : '' )
			. 'List-Unsubscribe: <' . $click . ">\n"
			. "List-Unsubscribe-Post: List-Unsubscribe=One-Click\n";

		return wp_mail(
			$user->user_email,
			Options::get_string( 'newsletter', 'mailing_subject' ),
			trim( Options::get_string( 'newsletter', 'mailing_body' ) ) . "\n\n\n" . __( 'To stop the newsletter:', 'easyq' ) . ' ' . $page,
			$headers
		);
	}

	/**
	 * Returns the name of the site for plain text, as the option is stored escaped.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private static function site_name(): string {
		$name = get_option( 'blogname' );

		return wp_specialchars_decode( is_string( $name ) ? $name : '', ENT_QUOTES );
	}

	/**
	 * Checks that the newsletter can be sent.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	public static function ready(): bool|WP_Error {
		if ( ! Options::get_bool( 'newsletter', 'mailing_enabled' ) ) {
			return new WP_Error( 'easyq_newsletter_off', __( 'The newsletter is turned off.', 'easyq' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( Options::get_string( 'newsletter', 'mailing_subject' ) ) || '' === trim( Options::get_string( 'newsletter', 'mailing_body' ) ) ) {
			return new WP_Error( 'easyq_newsletter_empty', __( 'Please write the subject and text of the newsletter first.', 'easyq' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * Returns the next users who confirmed the newsletter, without blocked and, while users
	 * are moderated, unapproved users, as `qa_mailing_perform_step()` skips them.
	 *
	 * @since 0.1.0
	 *
	 * @param int $after Last user handled.
	 * @param int $limit Most users.
	 * @return array{users: list<WP_User>, last: int|null} The users, and the last ID looked at; null if none was left.
	 */
	private static function subscribers( int $after, int $limit ): array {
		$wpdb = DB::wpdb();
		$ids  = DB::int_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A batch by ID; WP_User_Query has no "after this ID".
			$wpdb->get_col(
				$wpdb->prepare(
					'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %s AND user_id > %d ORDER BY user_id LIMIT %d',
					$wpdb->usermeta,
					Site_Meta::key( self::STATE_META ),
					'confirmed',
					$after,
					$limit
				)
			)
		);

		$users = array();
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( false === $user || User_Status::is_blocked( $id ) || ( Options::get_bool( 'spam', 'moderate_users' ) && ! User_Status::is_approved( $id ) ) ) {
				continue;
			}
			$users[] = $user;
		}

		return array(
			'users' => $users,
			'last'  => array() === $ids ? null : max( $ids ),
		);
	}
}
