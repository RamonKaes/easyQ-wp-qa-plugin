<?php
/**
 * Q&A additions to the users list.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Services;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Status;
use WP_User_Query;
use EasyQ\Users\Site_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds level and points columns, views for users awaiting approval and blocked
 * users, and bulk actions to approve, block and unblock users.
 *
 * @since 0.1.0
 */
final class Users_Screen {

	/**
	 * Bulk actions of easyQ on the users list.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const BULK_ACTIONS = array( 'easyq_approve', 'easyq_block', 'easyq_unblock' );

	/**
	 * Query argument that selects a view.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VIEW_ARG = 'easyq_status';

	/**
	 * Hooks the additions into the users screen.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'column_value' ), 10, 3 );
		add_filter( 'views_users', array( $this, 'views' ) );
		add_action( 'pre_get_users', array( $this, 'filter_query' ) );
		add_filter( 'bulk_actions-users', array( $this, 'bulk_actions' ) );
		add_action( 'load-users.php', array( $this, 'handle_bulk_request' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Adds the level and points columns.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		// Core prints column labels as HTML.
		$columns['easyq_level']  = esc_html__( 'Q&A level', 'easyq' );
		$columns['easyq_points'] = esc_html__( 'Points', 'easyq' );

		return $columns;
	}

	/**
	 * Returns the value of a Q&A column.
	 *
	 * @since 0.1.0
	 *
	 * @param string $output  Output so far.
	 * @param string $column  Column name.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public function column_value( string $output, string $column, int $user_id ): string {
		if ( 'easyq_points' === $column ) {
			return esc_html( number_format_i18n( Services::points()->get( $user_id ) ) );
		}

		if ( 'easyq_level' !== $column ) {
			return $output;
		}

		$level  = Services::user_levels()->level( $user_id ) ?? Levels::BASIC;
		$labels = array( Levels::labels()[ $level ] ?? (string) $level );

		if ( User_Status::awaits_approval( $user_id ) ) {
			$labels[] = __( 'awaiting approval', 'easyq' );
		}
		if ( User_Status::is_blocked( $user_id ) ) {
			$labels[] = __( 'blocked', 'easyq' );
		}

		return esc_html( implode( ', ', $labels ) );
	}

	/**
	 * Adds the views "Awaiting approval" and "Blocked".
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $views Views as HTML links.
	 * @return array<string, string>
	 */
	public function views( array $views ): array {
		if ( ! current_user_can( Capabilities::MODERATE ) ) {
			return $views;
		}

		$current = $this->current_view();
		$items   = array(
			'unapproved' => array( __( 'Awaiting approval', 'easyq' ), Site_Meta::key( User_Status::UNAPPROVED_META ) ),
			'blocked'    => array( __( 'Blocked', 'easyq' ), Site_Meta::key( User_Status::BLOCKED_META ) ),
		);

		foreach ( $items as $view => [ $label, $meta_key ] ) {
			$count = $this->count_with_meta( $meta_key );
			if ( 0 === $count && $current !== $view ) {
				continue;
			}

			$views[ 'easyq_' . $view ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( self::VIEW_ARG, $view, admin_url( 'users.php' ) ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * Limits the users list to the selected view.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User_Query $query Users query.
	 */
	public function filter_query( WP_User_Query $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'users.php' !== $pagenow || ! current_user_can( Capabilities::MODERATE ) ) {
			return;
		}

		$meta_key = match ( $this->current_view() ) {
			'unapproved' => Site_Meta::key( User_Status::UNAPPROVED_META ),
			'blocked'    => Site_Meta::key( User_Status::BLOCKED_META ),
			default      => null,
		};

		if ( null !== $meta_key ) {
			$query->set( 'meta_key', $meta_key );
			$query->set( 'meta_compare', 'EXISTS' );
		}
	}

	/**
	 * Adds the bulk actions.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		if ( current_user_can( Capabilities::MODERATE ) ) {
			$actions['easyq_approve'] = __( 'Approve for Q&A', 'easyq' );
			$actions['easyq_block']   = __( 'Block in Q&A', 'easyq' );
			$actions['easyq_unblock'] = __( 'Unblock in Q&A', 'easyq' );
		}

		return $actions;
	}

	/**
	 * Carries out a bulk action of easyQ on the users list, on the request the form sends.
	 *
	 * Core runs `handle_bulk_actions-users` only after it redirected the form to an address
	 * without `_wpnonce` (the `default` branch of `wp-admin/users.php`), so the nonce can only be
	 * verified before that, here, as core verifies its own bulk actions with
	 * `check_admin_referer( 'bulk-users' )`.
	 *
	 * @since 0.1.0
	 */
	public function handle_bulk_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The nonce is verified once the action is ours.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' === $action && isset( $_REQUEST['action2'] ) && is_string( $_REQUEST['action2'] ) ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		$user_ids = isset( $_REQUEST['users'] ) && is_array( $_REQUEST['users'] ) ? array_map( static fn ( mixed $id ): int => is_numeric( $id ) ? (int) $id : 0, (array) wp_unslash( $_REQUEST['users'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each ID is cast to an integer.
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return;
		}

		check_admin_referer( 'bulk-users' );
		if ( ! current_user_can( Capabilities::MODERATE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'easyq' ), 403 );
		}

		$back = remove_query_arg( array( 'action', 'action2', 'users', '_wpnonce', '_wp_http_referer', 'easyq_done', 'easyq_count' ), (string) wp_get_referer() );
		wp_safe_redirect(
			add_query_arg(
				array(
					'easyq_done'  => $action,
					'easyq_count' => $this->perform( $action, $user_ids, Actor::current() ),
				),
				'' === $back ? admin_url( 'users.php' ) : $back
			)
		);
		exit;
	}

	/**
	 * Carries out a bulk action on users the actor may edit, never on the actor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $action   Bulk action, one of `BULK_ACTIONS`.
	 * @param int[]  $user_ids Selected users.
	 * @param Actor  $actor    Who acts.
	 * @return int Number of users changed.
	 */
	public function perform( string $action, array $user_ids, Actor $actor ): int {
		$count = 0;
		foreach ( $user_ids as $user_id ) {
			if ( $user_id === $actor->user_id || ! user_can( (int) $actor->user_id, 'edit_user', $user_id ) ) {
				continue;
			}

			match ( $action ) {
				'easyq_approve' => User_Status::approve( $actor, $user_id ),
				'easyq_block'   => User_Status::block( $actor, $user_id ),
				default         => User_Status::unblock( $actor, $user_id ),
			};
			++$count;
		}

		return $count;
	}

	/**
	 * Confirms a bulk action.
	 *
	 * @since 0.1.0
	 */
	public function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only displays the result of a verified action.
		$action = isset( $_GET['easyq_done'] ) && is_string( $_GET['easyq_done'] ) ? sanitize_key( wp_unslash( $_GET['easyq_done'] ) ) : '';
		$count  = isset( $_GET['easyq_count'] ) && is_numeric( $_GET['easyq_count'] ) ? absint( $_GET['easyq_count'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			/* translators: %s: number of users. */
			'easyq_approve' => _n( '%s user approved.', '%s users approved.', $count, 'easyq' ),
			/* translators: %s: number of users. */
			'easyq_block'   => _n( '%s user blocked.', '%s users blocked.', $count, 'easyq' ),
			/* translators: %s: number of users. */
			'easyq_unblock' => _n( '%s user unblocked.', '%s users unblocked.', $count, 'easyq' ),
		);

		if ( ! isset( $messages[ $action ] ) ) {
			return;
		}

		wp_admin_notice(
			esc_html( sprintf( $messages[ $action ], number_format_i18n( $count ) ) ),
			array(
				'type'        => 'success',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Returns the selected view.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Selecting a list view does not change data.
		return isset( $_GET[ self::VIEW_ARG ] ) && is_string( $_GET[ self::VIEW_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::VIEW_ARG ] ) ) : '';
	}

	/**
	 * Counts users with a meta key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $meta_key Meta key.
	 * @return int
	 */
	private function count_with_meta( string $meta_key ): int {
		$query = new WP_User_Query(
			array(
				'meta_key'     => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed by WordPress.
				'meta_compare' => 'EXISTS',
				'count_total'  => true,
				'fields'       => 'ID',
				'number'       => 1,
			)
		);

		return $query->get_total();
	}
}
