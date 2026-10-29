<?php
/**
 * Moderation screen.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Posts\Moderation_Query;
use EasyQ\REST\Controller;
use EasyQ\REST\Moderation_Controller;
use EasyQ\Services;
use EasyQ\Limits\IP_Blocks;
use WP_Admin_Bar;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Lists questions, answers and comments for moderators, with the posts awaiting
 * approval counted in the menu and the admin bar, and blocks IP addresses.
 *
 * Replaces the Question2Answer pages `admin/moderate`, `admin/flagged`,
 * `admin/hidden` and `ip/{ip}`. Every action goes through the REST API, so the
 * same checks apply as on the site.
 *
 * @since 0.1.0
 */
final class Posts_Screen {

	/**
	 * Page slug of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAGE = 'easyq-posts';

	/**
	 * Actions of the IP address panel.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const IP_ACTIONS = array( 'block', 'unblock', 'hide-all' );

	/**
	 * Hooks the screen into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 10 );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_node' ), 60 );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * Removes the result of an action from the address once it is shown, as core does.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Query arguments.
	 * @return string[]
	 */
	public function removable_query_args( array $args ): array {
		return array_merge( $args, array( 'did', 'done', 'failed' ) );
	}

	/**
	 * Adds the screen to the easyQ menu, with the number of posts awaiting approval.
	 *
	 * @since 0.1.0
	 */
	public function add_menu(): void {
		$count = current_user_can( Capabilities::MODERATE ) ? Services::moderation_query()->count( Actor::current(), 'pending' ) : 0;
		$label = __( 'Posts', 'easyq' );
		if ( $count > 0 ) {
			$label .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$count,
				esc_html( number_format_i18n( $count ) ),
				/* translators: %s: number of posts. */
				esc_html( sprintf( _n( '%s post awaiting approval', '%s posts awaiting approval', $count, 'easyq' ), number_format_i18n( $count ) ) )
			);
		}

		$hook = add_submenu_page( Dashboard_Screen::PAGE, __( 'Q&A Posts', 'easyq' ), $label, Capabilities::MODERATE, self::PAGE, array( $this, 'render' ) );
		if ( false !== $hook ) {
			add_action( 'load-' . $hook, array( $this, 'load' ) );
		}
	}

	/**
	 * Adds the number of posts awaiting approval to the admin bar.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_admin_bar_node( WP_Admin_Bar $admin_bar ): void {
		if ( ! current_user_can( Capabilities::MODERATE ) ) {
			return;
		}

		$count = Services::moderation_query()->count( Actor::current(), 'pending' );
		if ( 0 === $count ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'easyq-pending',
				'title' => sprintf(
					'<span aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>',
					/* translators: %s: number of posts. */
					esc_html( sprintf( __( 'Q&A %s', 'easyq' ), number_format_i18n( $count ) ) ),
					/* translators: %s: number of posts. */
					esc_html( sprintf( _n( '%s Q&A post awaiting approval', '%s Q&A posts awaiting approval', $count, 'easyq' ), number_format_i18n( $count ) ) )
				),
				'href'  => self::url( array( 'view' => 'pending' ) ),
			)
		);
	}

	/**
	 * Returns the address of the screen.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::PAGE ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Returns the address of a row action, with its nonce and the current filters.
	 *
	 * @since 0.1.0
	 *
	 * @param string $action Action.
	 * @param int    $id     Post ID.
	 * @return string
	 */
	public static function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'post'   => $id,
				),
				self::url( self::filter_args( self::filters() ) )
			),
			'easyq-post_' . $id
		);
	}

	/**
	 * Carries out a row, bulk or IP action before the screen is shown, then redirects.
	 *
	 * @since 0.1.0
	 */
	public function load(): void {
		$redirect = $this->handle();
		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Carries out the requested action.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Address to go to afterwards, or null if nothing was requested.
	 */
	public function handle(): ?string {
		$filters = self::filters();
		$back    = self::url( self::filter_args( $filters ) );

		// phpcs:disable WordPress.Security.NonceVerification -- Nonces are checked below, for each kind of action.
		$ip_action = isset( $_POST['easyq_ip_action'] ) && is_string( $_POST['easyq_ip_action'] ) ? sanitize_key( $_POST['easyq_ip_action'] ) : '';
		$action    = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( $_REQUEST['action'] ) : '';
		if ( '' === $action && isset( $_REQUEST['action2'] ) && is_string( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( $_REQUEST['action2'] );
		}
		$ids = isset( $_REQUEST['post'] ) && ( is_array( $_REQUEST['post'] ) || is_string( $_REQUEST['post'] ) ) ? array_values( array_filter( wp_parse_id_list( wp_unslash( $_REQUEST['post'] ) ) ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification

		if ( in_array( $ip_action, self::IP_ACTIONS, true ) && null !== $filters['ip'] ) {
			check_admin_referer( 'easyq-ip_' . $filters['ip'] );
			$response = rest_do_request( new WP_REST_Request( 'POST', '/' . Controller::NAMESPACE . '/ips/' . $filters['ip'] . '/' . $ip_action ) );
			$data     = $response->get_data();
			$hidden   = is_array( $data ) && is_int( $data['hidden'] ?? null ) ? $data['hidden'] : 0;

			return add_query_arg(
				array(
					'did'  => $response->is_error() ? 'failed' : $ip_action,
					'done' => $hidden,
				),
				$back
			);
		}

		if ( ! isset( Posts_List_Table::action_labels()[ $action ] ) || array() === $ids ) {
			return null;
		}

		if ( isset( $_GET['_wpnonce'] ) && 1 === count( $ids ) && ! isset( $_REQUEST['_wp_http_referer'] ) ) {
			check_admin_referer( 'easyq-post_' . $ids[0] );
		} else {
			check_admin_referer( 'bulk-easyq-posts' );
		}

		$done   = 0;
		$failed = 0;
		foreach ( $ids as $id ) {
			$response = rest_do_request( new WP_REST_Request( 'POST', '/' . Controller::NAMESPACE . '/posts/' . $id . '/actions/' . $action ) );
			if ( $response->is_error() ) {
				++$failed;
			} else {
				++$done;
			}
		}

		return add_query_arg(
			array(
				'did'    => $action,
				'done'   => $done,
				'failed' => $failed,
			),
			$back
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$filters = self::filters();
		$table   = new Posts_List_Table( $filters );
		$table->prepare_items();

		echo '<div class="wrap">';
		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Q&A Posts', 'easyq' ) );
		if ( '' !== $filters['search'] ) {
			/* translators: %s: search terms. */
			printf( ' <span class="subtitle">%s</span>', esc_html( sprintf( __( 'Search results for: %s', 'easyq' ), $filters['search'] ) ) );
		}
		echo '<hr class="wp-header-end">';

		$this->render_notice();
		if ( null !== $filters['ip'] ) {
			$this->render_ip( $filters['ip'] );
		}

		$table->views();
		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::PAGE ) );
		foreach ( self::filter_args( $filters ) as $key => $value ) {
			if ( in_array( $key, array( 'view', 'author', 'ip' ), true ) ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $key ), esc_attr( (string) $value ) );
			}
		}
		$table->search_box( __( 'Search Posts', 'easyq' ), 'easyq-post' );
		$table->display();
		echo '</form></div>';
	}

	/**
	 * Renders the result of the last action.
	 *
	 * @since 0.1.0
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only shows the result of an action that was checked before.
		$did    = isset( $_GET['did'] ) && is_string( $_GET['did'] ) ? sanitize_key( $_GET['did'] ) : '';
		$done   = isset( $_GET['done'] ) && is_numeric( $_GET['done'] ) ? (int) $_GET['done'] : 0;
		$failed = isset( $_GET['failed'] ) && is_numeric( $_GET['failed'] ) ? (int) $_GET['failed'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$text = match ( $did ) {
			/* translators: %s: number of posts. */
			'approve'     => _n( '%s post approved.', '%s posts approved.', $done, 'easyq' ),
			/* translators: %s: number of posts. */
			'reject'      => _n( '%s post rejected.', '%s posts rejected.', $done, 'easyq' ),
			/* translators: %s: number of posts. */
			'clear-flags' => _n( 'Flags cleared from %s post.', 'Flags cleared from %s posts.', $done, 'easyq' ),
			/* translators: %s: number of posts. */
			'hide'        => _n( '%s post hidden.', '%s posts hidden.', $done, 'easyq' ),
			/* translators: %s: number of posts. */
			'reshow'      => _n( '%s post shown again.', '%s posts shown again.', $done, 'easyq' ),
			/* translators: %s: number of questions. */
			'reopen'      => _n( '%s question reopened.', '%s questions reopened.', $done, 'easyq' ),
			/* translators: %s: number of posts. */
			'delete'      => _n( '%s post deleted permanently.', '%s posts deleted permanently.', $done, 'easyq' ),
			'block'       => __( 'The IP address is blocked.', 'easyq' ),
			'unblock'     => __( 'The IP address is no longer blocked.', 'easyq' ),
			/* translators: %s: number of posts. */
			'hide-all'    => _n( '%s post from this IP address hidden.', '%s posts from this IP address hidden.', $done, 'easyq' ),
			'failed'      => __( 'The action could not be carried out.', 'easyq' ),
			default       => '',
		};
		if ( '' === $text ) {
			return;
		}

		if ( ( $done > 0 || in_array( $did, array( 'block', 'unblock', 'hide-all' ), true ) ) && 'failed' !== $did ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( $text, number_format_i18n( $done ) ) ) );
		}
		if ( $failed > 0 || 'failed' === $did ) {
			$message = 'failed' === $did
				? $text
				/* translators: %s: number of posts. */
				: sprintf( _n( '%s post could not be changed, for example because it changed in the meantime or you may not do this there.', '%s posts could not be changed, for example because they changed in the meantime or you may not do this there.', $failed, 'easyq' ), number_format_i18n( $failed ) );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Renders the panel of an IP address: whether it is blocked, and buttons to
	 * block or unblock it and to hide its posts.
	 *
	 * Unlike Question2Answer, the host name is not looked up, which would be a
	 * request to a name server.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IP address.
	 */
	private function render_ip( string $ip ): void {
		if ( ! current_user_can( 'easyq_view_ips' ) ) {
			return;
		}

		$actor = Actor::current();
		$rules = ( new IP_Blocks() )->matching_rules( $ip );

		/* translators: %s: IP address. */
		printf( '<h2>%s</h2>', esc_html( sprintf( __( 'IP address %s', 'easyq' ), $ip ) ) );
		printf(
			'<p>%s</p>',
			array() === $rules
				? esc_html__( 'This address is not blocked.', 'easyq' )
				/* translators: %s: blocking rules. */
				: esc_html( sprintf( __( 'This address is blocked by: %s', 'easyq' ), implode( ', ', $rules ) ) )
		);

		if ( ! Moderation_Controller::can_block( $actor ) ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( self::url( array( 'ip' => $ip ) ) ) . '"><p>';
		wp_nonce_field( 'easyq-ip_' . $ip );
		if ( array() === $rules ) {
			printf( '<button type="submit" class="button" name="easyq_ip_action" value="block">%s</button>', esc_html__( 'Block this IP address', 'easyq' ) );
		} else {
			printf( '<button type="submit" class="button" name="easyq_ip_action" value="unblock">%s</button>', esc_html__( 'Unblock this IP address', 'easyq' ) );
			if ( Moderation_Controller::can_hide_all( $actor ) && array() !== ( new IP_Blocks() )->visible_post_ids( $ip ) ) {
				printf( ' <button type="submit" class="button" name="easyq_ip_action" value="hide-all">%s</button>', esc_html__( 'Hide all posts from this IP address', 'easyq' ) );
			}
		}
		echo '</p></form>';
	}

	/**
	 * Returns the filters of the current request.
	 *
	 * @since 0.1.0
	 *
	 * @return array{view: string, type: string, category_id: int|null, user_id: int|null, ip: string|null, search: string, month: string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters only select what to show.
		$get = static fn ( string $key ): string => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$view = $get( 'view' );
		$type = $get( 'type' );
		$ip   = $get( 'ip' );

		return array(
			'view'        => in_array( $view, Moderation_Query::VIEWS, true ) ? $view : 'all',
			'type'        => isset( Moderation_Query::TYPES[ $type ] ) ? $type : '',
			'category_id' => absint( $get( 'category' ) ) > 0 ? absint( $get( 'category' ) ) : null,
			'user_id'     => absint( $get( 'author' ) ) > 0 ? absint( $get( 'author' ) ) : null,
			'ip'          => false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null,
			'search'      => $get( 's' ),
			'month'       => 1 === preg_match( '/^\d{6}$/', $get( 'month' ) ) ? $get( 'month' ) : '',
		);
	}

	/**
	 * Returns the query arguments of filters that are set.
	 *
	 * @since 0.1.0
	 *
	 * @param array{view: string, type: string, category_id: int|null, user_id: int|null, ip: string|null, search: string, month: string} $filters Filters.
	 * @return array<string, int|string>
	 */
	private static function filter_args( array $filters ): array {
		return array_filter(
			array(
				'view'     => 'all' === $filters['view'] ? '' : $filters['view'],
				'type'     => $filters['type'],
				'category' => $filters['category_id'] ?? 0,
				'author'   => $filters['user_id'] ?? 0,
				'ip'       => $filters['ip'] ?? '',
				's'        => $filters['search'],
				'month'    => $filters['month'],
			)
		);
	}
}
