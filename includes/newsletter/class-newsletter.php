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
use EasyQ\Mail\Mailer;
use EasyQ\Mail\Recipient;
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
	 * User meta: when the last confirmation email went out, kept when unsubscribing.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SENT_META = 'easyq_newsletter_sent';

	/**
	 * Time between two confirmation emails to a user.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CONFIRMATION_PAUSE = 10 * MINUTE_IN_SECONDS;

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
	 * Checks whether a user may ask for the newsletter now, without sending anything.
	 *
	 * Callers that save more than the subscription check this first, so that a refusal
	 * leaves nothing half saved.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user User.
	 * @return true|WP_Error
	 */
	public function can_subscribe( WP_User $user ): bool|WP_Error {
		if ( ! Options::get_bool( 'newsletter', 'mailing_enabled' ) ) {
			return new WP_Error( 'easyq_newsletter_off', __( 'There is no newsletter.', 'easyq' ), array( 'status' => 404 ) );
		}

		// Not reachable from the plugin: both callers check the state first; the method answers for other callers.
		if ( 'confirmed' === self::state( $user->ID ) ) {
			return true;
		}

		// Turning the newsletter off and on again does not send one email after the other.
		$sent = Site_Meta::get( $user->ID, self::SENT_META );
		if ( is_numeric( $sent ) && time() < (int) $sent + self::CONFIRMATION_PAUSE ) {
			return new WP_Error( 'easyq_limit', __( 'A confirmation email was sent a moment ago. Please check your inbox, or try again in a few minutes.', 'easyq' ), array( 'status' => 429 ) );
		}

		return true;
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
		$allowed = $this->can_subscribe( $user );
		if ( true !== $allowed || 'confirmed' === self::state( $user->ID ) ) {
			return $allowed;
		}

		Site_Meta::update( $user->ID, self::SENT_META, time() );

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

		// In the language of the recipient, as core writes emails to users (`wp_new_user_notification()`).
		switch_to_user_locale( $user->ID );
		$site = self::site_name();
		/* translators: %s: site name. */
		$subject = sprintf( __( '[%s] Confirm the newsletter', 'easyq' ), $site );
		/* translators: 1: site name, 2: confirmation address. */
		$body = sprintf( __( "Hello,\n\nyou asked for the newsletter of %1\$s. Please confirm it here:\n\n%2\$s\n\nIf you did not ask for it, ignore this email; you will get no newsletter.\n\n%1\$s", 'easyq' ), $site, $url );

		$sent = Mailer::send_email( 'newsletter_confirm', new Recipient( $user->user_email, $user->ID ), $subject, $body );
		restore_previous_locale();
		if ( $sent ) {
			return true;
		}

		// Nothing was sent, so nothing waits for a confirmation, and the next request sends again.
		Site_Meta::delete( $user->ID, self::SENT_META );
		Site_Meta::delete( $user->ID, self::KEY_META );
		Site_Meta::delete( $user->ID, self::STATE_META );

		return new WP_Error( 'easyq_email_error', __( 'The confirmation could not be sent. Please try again later.', 'easyq' ), array( 'status' => 500 ) );
	}

	/**
	 * Checks the key from the confirmation email, as `wp_validate_user_request_key()` does for
	 * the requests of the privacy tools: only the hash is stored, and the key expires.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Key from the email.
	 * @return true|WP_Error
	 */
	public static function check_key( int $user_id, string $key ): bool|WP_Error {
		$stored = Site_Meta::get( $user_id, self::KEY_META );
		$hash   = is_array( $stored ) && is_string( $stored['hash'] ?? null ) ? $stored['hash'] : '';
		$time   = is_array( $stored ) && is_int( $stored['time'] ?? null ) ? $stored['time'] : 0;

		/** This filter is documented in wp-includes/user.php */
		$expiry = apply_filters( 'user_request_key_expiration', DAY_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The core filter for the life of confirmation keys.
		$expiry = is_numeric( $expiry ) ? (int) $expiry : DAY_IN_SECONDS;

		if ( '' === $hash || '' === $key || ! wp_check_password( $key, $hash ) || time() > $time + $expiry ) {
			return new WP_Error( 'easyq_invalid_key', __( 'This link is not valid.', 'easyq' ), array( 'status' => 403 ) );
		}

		return true;
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
		$checked = self::check_key( $user_id, $key );
		if ( true !== $checked ) {
			return $checked;
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
	 * @param int  $user_id User ID.
	 * @param bool $forget  Whether to forget the time of the last confirmation email too, as erasing personal data does.
	 * @return bool Whether there was a subscription or request.
	 */
	public static function unsubscribe( int $user_id, bool $forget = false ): bool {
		$had = '' !== self::state( $user_id );
		foreach ( array_merge( array( self::STATE_META, self::KEY_META, self::CONSENT_META ), $forget ? array( self::SENT_META ) : array() ) as $key ) {
			Site_Meta::delete( $user_id, $key );
		}

		return $had;
	}

	/**
	 * Returns the token of the link to stop the newsletter.
	 *
	 * The site is part of it: the salts are shared by the sites of a network, and post and user
	 * IDs repeat or are shared there, so a token of one site must not hold on another.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function token( int $user_id ): string {
		return wp_hash( 'easyq_newsletter|' . get_current_blog_id() . '|' . $user_id );
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
					'total'        => self::count_subscribers(),
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
		// One batch at a time, as `WP_Upgrader::create_lock()` locks: a second one that starts
		// while the first sends returns at once; a lock older than five minutes is stale.
		if ( ! self::lock() ) {
			return 0;
		}

		try {
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

			// The position and the next event are written before sending: a batch that dies sends
			// nobody twice, and the sending goes on with the next one.
			update_option(
				self::RUN_OPTION,
				array(
					'last_user_id' => $batch['last'],
					'done'         => ( is_int( $run['done'] ?? null ) ? $run['done'] : 0 ) + count( $batch['users'] ),
					'total'        => is_int( $run['total'] ?? null ) ? $run['total'] : 0,
				),
				false
			);
			if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_HOOK );
			}

			$sent = 0;
			foreach ( $batch['users'] as $user ) {
				// Cancelled while this batch sends: the rest of the batch gets nothing.
				wp_cache_delete( self::RUN_OPTION, 'options' );
				if ( false === get_option( self::RUN_OPTION ) ) {
					break;
				}
				$sent += $this->send_to( $user ) ? 1 : 0;
			}

			return $sent;
		} finally {
			self::unlock();
		}
	}

	/**
	 * Takes the lock of the batches, as `WP_Upgrader::create_lock()` does.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether this request holds the lock now.
	 */
	private static function lock(): bool {
		$wpdb = DB::wpdb();
		$name = self::RUN_OPTION . '_lock';
		$sql  = $wpdb->prepare( 'INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )', $wpdb->options, $name, (string) time(), 'off' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; an atomic insert, as WP_Upgrader::create_lock().
		if ( null !== $sql && 1 === (int) $wpdb->query( $sql ) ) {
			return true;
		}

		wp_cache_delete( $name, 'options' );
		$since = get_option( $name );
		if ( is_numeric( $since ) && (int) $since > time() - 5 * MINUTE_IN_SECONDS ) {
			return false;
		}

		// The lock of a batch that died.
		update_option( $name, (string) time(), false );

		return true;
	}

	/**
	 * Releases the lock of the batches.
	 *
	 * @since 0.1.0
	 */
	private static function unlock(): void {
		delete_option( self::RUN_OPTION . '_lock' );
	}

	/**
	 * Returns the number of users who will get the newsletter, counted in batches of 500 users
	 * whose data is loaded at once.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	private static function count_subscribers(): int {
		$count = 0;
		$after = 0;
		do {
			$batch  = self::subscribers( $after, 500 );
			$count += count( $batch['users'] );
			$after  = (int) $batch['last'];
		} while ( null !== $batch['last'] );

		return $count;
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

		// The text is the site owner's; what the plugin adds is in the language of the recipient.
		switch_to_user_locale( $user->ID );
		$sent = Mailer::send_email(
			'newsletter',
			new Recipient( $user->user_email, $user->ID ),
			Options::get_string( 'newsletter', 'mailing_subject' ),
			trim( Options::get_string( 'newsletter', 'mailing_body' ) ) . "\n\n\n" . __( 'To stop the newsletter:', 'easyq' ) . ' ' . $page,
			$headers
		);
		restore_previous_locale();

		return $sent;
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

		// The users of a batch are loaded at once, as `cache_users()` does for lists of core.
		cache_users( $ids );
		$users = array();
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			// On a network, only members of the site get its newsletter.
			if ( false === $user || ( is_multisite() && ! is_user_member_of_blog( $id ) ) || User_Status::is_blocked( $id ) || ( Options::get_bool( 'spam', 'moderate_users' ) && ! User_Status::is_approved( $id ) ) ) {
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
