<?php
/**
 * REST endpoints for moderation lists and IP addresses.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Limits\IP_Blocks;
use EasyQ\Posts\Moderation_Query;
use EasyQ\Services;
use EasyQ\Users\Levels;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists posts for moderators, and shows, blocks and unblocks IP addresses.
 *
 * Routes:
 *
 * - `GET /easyq/v1/posts` with `view` and filters
 * - `GET /easyq/v1/ips/{ip}`
 * - `POST /easyq/v1/ips/{ip}/block`, `/unblock` and `/hide-all`
 *
 * Moderation actions on single posts are in Actions_Controller.
 *
 * @since 0.1.0
 */
final class Moderation_Controller extends Controller {

	/**
	 * Pattern of an IP address in a route.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const IP_PATTERN = '(?P<ip>[0-9A-Fa-f:\.]+)';

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_posts' ),
				'permission_callback' => array( $this, 'list_permissions_check' ),
				'args'                => array(
					'view'     => array(
						'description' => __( 'All posts, or those awaiting approval, flagged, hidden or closed.', 'easyq' ),
						'type'        => 'string',
						'enum'        => Moderation_Query::VIEWS,
						'default'     => 'all',
					),
					'type'     => array(
						'description' => __( 'Only questions, answers or comments.', 'easyq' ),
						'type'        => 'string',
						'enum'        => array_keys( Moderation_Query::TYPES ),
					),
					'category' => array(
						'description' => __( 'Only posts in this category and its subcategories.', 'easyq' ),
						'type'        => 'integer',
					),
					'author'   => array(
						'description' => __( 'Only posts by this user.', 'easyq' ),
						'type'        => 'integer',
					),
					'ip'       => array(
						'description' => __( 'Only posts created from this IP address.', 'easyq' ),
						'type'        => 'string',
					),
					'search'   => array(
						'description' => __( 'Only posts whose title or text contains this.', 'easyq' ),
						'type'        => 'string',
					),
					'month'    => array(
						'description' => __( 'Only posts created in this month, as YYYYMM.', 'easyq' ),
						'type'        => 'string',
						'pattern'     => '^\d{6}$',
					),
				) + $this->pagination_args( 20 ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ips/' . self::IP_PATTERN,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_ip' ),
				'permission_callback' => array( $this, 'ip_permissions_check' ),
			)
		);

		foreach ( array( 'block', 'unblock', 'hide-all' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/ips/' . self::IP_PATTERN . '/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'hide-all' === $action ? 'hide_all' : $action ),
					'permission_callback' => array( $this, 'hide-all' === $action ? 'hide_all_permissions_check' : 'block_permissions_check' ),
				)
			);
		}
	}

	/**
	 * Checks whether the current user may list posts: moderators, and users who may
	 * see IP addresses when the list is limited to one address.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function list_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( Capabilities::MODERATE ) || ( $request->has_param( 'ip' ) && current_user_can( 'easyq_view_ips' ) ) ) {
			return true;
		}

		return REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Lists posts.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_posts( $request ): WP_REST_Response {
		$actor    = $this->actor();
		$per_page = self::int_param( $request, 'per_page' ) ?? 20;
		$result   = Services::moderation_query()->find(
			$actor,
			array(
				'view'        => self::string_param( $request, 'view' ),
				'type'        => self::string_param( $request, 'type' ),
				'category_id' => self::int_param( $request, 'category' ),
				'user_id'     => self::int_param( $request, 'author' ),
				'ip'          => self::string_param( $request, 'ip' ),
				'search'      => self::string_param( $request, 'search' ),
				'month'       => self::string_param( $request, 'month' ),
				'page'        => self::int_param( $request, 'page' ),
				'per_page'    => $per_page,
			)
		);

		return $this->paginated(
			array_map( fn ( $post ): array => $this->formatter()->format( $post, $actor ), $result['posts'] ),
			$result['total'],
			$per_page
		);
	}

	/**
	 * Checks whether the current user may see IP addresses.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function ip_permissions_check( $request ): bool|WP_Error {
		if ( current_user_can( 'easyq_view_ips' ) ) {
			return true;
		}

		return REST_Errors::permission( is_user_logged_in() ? 'level' : 'login' );
	}

	/**
	 * Checks whether the current user may block and unblock IP addresses: moderators
	 * in any category, as in Question2Answer.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function block_permissions_check( $request ): bool|WP_Error {
		$allowed = $this->ip_permissions_check( $request );
		if ( true === $allowed && ! self::can_block( $this->actor() ) ) {
			return REST_Errors::permission( 'level' );
		}

		return $allowed;
	}

	/**
	 * Checks whether the current user may hide all posts from an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function hide_all_permissions_check( $request ): bool|WP_Error {
		$allowed = $this->block_permissions_check( $request );
		if ( true === $allowed && ! self::can_hide_all( $this->actor() ) ) {
			return REST_Errors::permission( 'level' );
		}

		return $allowed;
	}

	/**
	 * Describes an IP address: whether it is blocked and by which rules.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_ip( $request ): WP_REST_Response|WP_Error {
		$ip = self::ip( $request );

		return null === $ip ? REST_Errors::not_found() : new WP_REST_Response( $this->describe( $ip ) );
	}

	/**
	 * Blocks an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function block( $request ): WP_REST_Response|WP_Error {
		$ip = self::ip( $request );
		if ( null === $ip ) {
			return REST_Errors::not_found();
		}

		( new IP_Blocks() )->block( $this->actor(), $ip );

		return new WP_REST_Response( $this->describe( $ip ) );
	}

	/**
	 * Unblocks an IP address by removing every rule that matches it.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function unblock( $request ): WP_REST_Response|WP_Error {
		$ip = self::ip( $request );
		if ( null === $ip ) {
			return REST_Errors::not_found();
		}

		$removed = ( new IP_Blocks() )->unblock( $this->actor(), $ip );

		return new WP_REST_Response( $this->describe( $ip ) + array( 'removed' => $removed ) );
	}

	/**
	 * Hides the visible posts from an IP address that the current user may hide.
	 *
	 * Each post goes through the `hide` action, with its checks.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function hide_all( $request ): WP_REST_Response|WP_Error {
		$ip = self::ip( $request );
		if ( null === $ip ) {
			return REST_Errors::not_found();
		}

		$hidden = 0;
		foreach ( ( new IP_Blocks() )->visible_post_ids( $ip ) as $post_id ) {
			$post = Services::posts()->find( $post_id );
			// Hiding a question earlier in the loop does not hide its answers, so each post is checked when it comes.
			if ( null === $post || ! $post->is_visible() ) {
				continue;
			}

			$response = rest_do_request( new WP_REST_Request( 'POST', '/' . self::NAMESPACE . '/posts/' . $post_id . '/actions/hide' ) );
			if ( ! $response->is_error() ) {
				++$hidden;
			}
		}

		return new WP_REST_Response( $this->describe( $ip ) + array( 'hidden' => $hidden ) );
	}

	/**
	 * Checks whether an actor may block IP addresses: moderators in any category.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor.
	 * @return bool
	 */
	public static function can_block( Actor $actor ): bool {
		return null !== $actor->user_id && user_can( $actor->user_id, 'easyq_view_ips' )
			&& ( Services::user_levels()->maximum_level( $actor->user_id ) ?? -1 ) >= Levels::MODERATOR;
	}

	/**
	 * Checks whether an actor may hide all posts from an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor.
	 * @return bool
	 */
	public static function can_hide_all( Actor $actor ): bool {
		return self::can_block( $actor ) && null === Services::permissions()->error( 'permit_hide_show', $actor, maximum_level: true );
	}

	/**
	 * Returns the IP address of the route, or null if it is not one.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return string|null
	 */
	private static function ip( WP_REST_Request $request ): ?string {
		$ip = self::string_param( $request, 'ip' );

		return null !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	/**
	 * Describes an IP address for the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param string $ip IP address.
	 * @return array<string, mixed>
	 */
	private function describe( string $ip ): array {
		$rules = ( new IP_Blocks() )->matching_rules( $ip );
		$actor = $this->actor();

		return array(
			'ip'           => $ip,
			'blocked'      => array() !== $rules,
			'rules'        => $rules,
			'can_block'    => self::can_block( $actor ),
			'can_hide_all' => self::can_hide_all( $actor ),
		);
	}
}
