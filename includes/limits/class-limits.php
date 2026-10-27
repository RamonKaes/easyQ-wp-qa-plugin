<?php
/**
 * Rate limits.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Limits;

use EasyQ\Actor;
use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Settings\Options;
use EasyQ\Util\IP;

defined( 'ABSPATH' ) || exit;

/**
 * Limits how often users and IP addresses may perform actions per hour.
 *
 * Ported from Question2Answer `qa-include/app/limits.php`, `db/limits.php` and
 * the event module `qa-event-limits.php`.
 *
 * @since 0.1.0
 */
final class Limits {

	/**
	 * Rate limit of asking questions.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUESTIONS = 'Q';

	/**
	 * Rate limit of answering.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ANSWERS = 'A';

	/**
	 * Rate limit of commenting.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMENTS = 'C';

	/**
	 * Rate limit of voting.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VOTES = 'V';

	/**
	 * Rate limit of registrations.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REGISTRATIONS = 'R';

	/**
	 * Rate limit of logins.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LOGINS = 'L';

	/**
	 * Rate limit of uploads.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPLOADS = 'U';

	/**
	 * Rate limit of flagging posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FLAGS = 'F';

	/**
	 * Rate limit of private messages.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MESSAGES = 'M';

	/**
	 * Rate limit of wall posts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WALL_POSTS = 'W';

	/**
	 * Settings with the maximum per user and per IP address for each action.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{0: string|null, 1: string}>
	 */
	private const SETTINGS = array(
		self::QUESTIONS     => array( 'max_rate_user_qs', 'max_rate_ip_qs' ),
		self::ANSWERS       => array( 'max_rate_user_as', 'max_rate_ip_as' ),
		self::COMMENTS      => array( 'max_rate_user_cs', 'max_rate_ip_cs' ),
		self::VOTES         => array( 'max_rate_user_votes', 'max_rate_ip_votes' ),
		self::REGISTRATIONS => array( null, 'max_rate_ip_registers' ),
		self::LOGINS        => array( null, 'max_rate_ip_logins' ),
		self::UPLOADS       => array( 'max_rate_user_uploads', 'max_rate_ip_uploads' ),
		self::FLAGS         => array( 'max_rate_user_flags', 'max_rate_ip_flags' ),
		self::MESSAGES      => array( 'max_rate_user_messages', 'max_rate_ip_messages' ),
		self::WALL_POSTS    => array( 'max_rate_user_messages', 'max_rate_ip_messages' ),
	);

	/**
	 * Events that count towards a limit.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const EVENT_ACTIONS = array(
		'q_queue'     => self::QUESTIONS,
		'q_post'      => self::QUESTIONS,
		'q_claim'     => self::QUESTIONS,
		'a_queue'     => self::ANSWERS,
		'a_post'      => self::ANSWERS,
		'a_claim'     => self::ANSWERS,
		'c_queue'     => self::COMMENTS,
		'c_post'      => self::COMMENTS,
		'c_claim'     => self::COMMENTS,
		'a_to_c'      => self::COMMENTS,
		'q_vote_up'   => self::VOTES,
		'q_vote_down' => self::VOTES,
		'q_vote_nil'  => self::VOTES,
		'a_vote_up'   => self::VOTES,
		'a_vote_down' => self::VOTES,
		'a_vote_nil'  => self::VOTES,
		'c_vote_up'   => self::VOTES,
		'c_vote_down' => self::VOTES,
		'c_vote_nil'  => self::VOTES,
		'q_flag'      => self::FLAGS,
		'a_flag'      => self::FLAGS,
		'c_flag'      => self::FLAGS,
		'u_message'   => self::MESSAGES,
		'u_wall_post' => self::WALL_POSTS,
	);

	/**
	 * Hooks the limit counting into the easyQ events.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'easyq_event', array( $this, 'count_event' ), 10, 3 );
	}

	/**
	 * Counts an event towards its limit.
	 *
	 * Delayed events, e.g. `q_post` after a queued question was approved, were counted when they were first reported.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $event  Event name.
	 * @param array<string, mixed> $params Event parameters.
	 * @param Actor                $actor  Who caused the event.
	 */
	public function count_event( string $event, array $params, Actor $actor ): void {
		if ( isset( $params['delayed'] ) || ! isset( self::EVENT_ACTIONS[ $event ] ) ) {
			return;
		}

		$this->increment( self::EVENT_ACTIONS[ $event ], $actor );
	}

	/**
	 * Returns how many more times an actor may perform an action in the current hour.
	 *
	 * The lower of the user limit and the IP address limit applies.
	 *
	 * @since 0.1.0
	 *
	 * @param string $action One of the class constants.
	 * @param Actor  $actor  The actor.
	 * @return int
	 */
	public function remaining( string $action, Actor $actor ): int {
		if ( ! isset( self::SETTINGS[ $action ] ) ) {
			return 0;
		}

		[ $user_setting, $ip_setting ] = self::SETTINGS[ $action ];

		$period    = self::period();
		$wpdb      = DB::wpdb();
		$remaining = array( Options::get_int( 'spam', $ip_setting ) - $this->used( $wpdb->prepare( 'SELECT period, count FROM %i WHERE ip = %s AND action = %s', Schema::table( 'iplimits' ), (string) $actor->stored_ip(), $action ), $period ) );

		if ( null !== $user_setting ) {
			$user_used   = null === $actor->user_id ? 0 : $this->used( $wpdb->prepare( 'SELECT period, count FROM %i WHERE user_id = %d AND action = %s', Schema::table( 'userlimits' ), $actor->user_id, $action ), $period );
			$remaining[] = Options::get_int( 'spam', $user_setting ) - $user_used;
		}

		return max( 0, min( $remaining ) );
	}

	/**
	 * Records that an actor performed an action.
	 *
	 * @since 0.1.0
	 *
	 * @param string $action One of the class constants.
	 * @param Actor  $actor  The actor.
	 */
	public function increment( string $action, Actor $actor ): void {
		$period = self::period();
		$wpdb   = DB::wpdb();

		if ( null !== $actor->user_id ) {
			DB::query(
				$wpdb->prepare(
					'INSERT INTO %i (user_id, action, period, count) VALUES (%d, %s, %d, 1) ON DUPLICATE KEY UPDATE count = IF(period = %d, count + 1, 1), period = %d',
					Schema::table( 'userlimits' ),
					$actor->user_id,
					$action,
					$period,
					$period,
					$period
				)
			);
		}

		$ip = $actor->stored_ip();
		if ( null !== $ip ) {
			DB::query(
				$wpdb->prepare(
					'INSERT INTO %i (ip, action, period, count) VALUES (%s, %s, %d, 1) ON DUPLICATE KEY UPDATE count = IF(period = %d, count + 1, 1), period = %d',
					Schema::table( 'iplimits' ),
					$ip,
					$action,
					$period,
					$period,
					$period
				)
			);
		}
	}

	/**
	 * Checks whether the IP address of an actor is blocked from writing.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor.
	 * @return bool
	 */
	public function is_ip_blocked( Actor $actor ): bool {
		return IP::is_blocked( $actor->ip, Options::get_string( 'spam', 'block_ips_write' ) );
	}

	/**
	 * Returns the current period: the number of hours since the Unix epoch.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function period(): int {
		return intdiv( time(), HOUR_IN_SECONDS );
	}

	/**
	 * Returns how often an action was performed in the current period.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $sql    Prepared query for the period and count columns.
	 * @param int         $period Current period.
	 * @return int
	 */
	private function used( ?string $sql, int $period ): int {
		$row = DB::get_row( $sql );

		return null !== $row && Row::int( $row, 'period' ) === $period ? Row::int( $row, 'count' ) : 0;
	}
}
