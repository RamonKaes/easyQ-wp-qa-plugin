<?php
/**
 * REST endpoints for Q&A user profiles.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Events\Events;
use EasyQ\Frontend\URLs;
use EasyQ\Limits\Limits;
use EasyQ\Points\Points;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Avatars;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Query;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Lists users and returns and changes Q&A profiles.
 *
 * Routes:
 *
 * - `GET /easyq/v1/users`: users by points, newest users or users with special levels
 * - `GET /easyq/v1/users/{id}`
 * - `GET|POST /easyq/v1/users/me`: the profile of the current user; POST changes
 *   profile fields and the avatar (multipart upload in the field `avatar`)
 *
 * Profiles contain only public information: name, avatar, points, level,
 * activity counts and the profile fields the viewer may see.
 *
 * @since 0.1.0
 */
final class Users_Controller extends Controller {

	/**
	 * Registers the routes.
	 *
	 * @since 0.1.0
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => array(
					'sort' => array(
						'description' => __( 'Users by points, the newest users, or users with special levels.', 'easyq' ),
						'type'        => 'string',
						'enum'        => array_keys( User_Query::SORTS ),
						'default'     => 'points',
					),
				) + $this->pagination_args( Options::get_int( 'lists', 'page_size_users' ) ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_current_item' ),
					'permission_callback' => array( $this, 'current_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_current_item' ),
					'permission_callback' => array( $this, 'update_current_item_permissions_check' ),
					'args'                => array(
						'fields'        => array(
							'description'          => __( 'Values of the profile fields, keyed by field name.', 'easyq' ),
							'type'                 => 'object',
							'additionalProperties' => array( 'type' => 'string' ),
						),
						'remove_avatar' => array(
							'description' => __( 'Whether to remove the uploaded avatar.', 'easyq' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/users/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			)
		);
	}

	/**
	 * Checks whether the request may see the requested user list.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ): bool|WP_Error {
		$sort   = (string) self::string_param( $request, 'sort' );
		$option = User_Query::SORTS[ $sort ] ?? null;

		return $this->check_view( $option ?? 'permit_view_q_page' );
	}

	/**
	 * Lists users.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$per_page = (int) self::int_param( $request, 'per_page' );
		$found    = Services::user_query()->find( (string) self::string_param( $request, 'sort' ), (int) self::int_param( $request, 'page' ), $per_page );

		cache_users( $found['ids'] );

		$items = array();
		foreach ( $found['ids'] as $id ) {
			$user = get_userdata( $id );
			if ( false !== $user ) {
				$items[] = $this->format( $user, details: false );
			}
		}

		return $this->paginated( $items, $found['total'], $per_page );
	}

	/**
	 * Checks whether the request may see the Q&A area.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		return $this->check_view( 'permit_view_q_page' );
	}

	/**
	 * Returns a profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) self::int_param( $request, 'id' ) );
		if ( false === $user || ! Services::user_query()->is_member( $user ) ) {
			return REST_Errors::not_found();
		}

		return new WP_REST_Response( $this->format( $user, details: true ) );
	}

	/**
	 * Checks whether the request comes from a logged-in user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function current_item_permissions_check( $request ): bool|WP_Error {
		return null === $this->actor()->user_id ? REST_Errors::permission( 'login' ) : true;
	}

	/**
	 * Returns the profile of the current user, with all profile fields.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_current_item( $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) $this->actor()->user_id );
		if ( false === $user ) {
			return REST_Errors::not_found();
		}

		return new WP_REST_Response( $this->format( $user, details: true, all_fields: true ) );
	}

	/**
	 * Checks whether the current user may change their profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function update_current_item_permissions_check( $request ): bool|WP_Error {
		$actor = $this->actor();
		if ( null === $actor->user_id ) {
			return REST_Errors::permission( 'login' );
		}

		$writable = $this->check_writable( $actor );
		if ( true !== $writable ) {
			return $writable;
		}

		return $this->check( null, $actor, null, null === $this->avatar_file( $request ) ? null : Limits::UPLOADS );
	}

	/**
	 * Changes the profile fields and the avatar of the current user.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_current_item( $request ): WP_REST_Response|WP_Error {
		$actor   = $this->actor();
		$user_id = (int) $actor->user_id;
		$user    = get_userdata( $user_id );
		if ( false === $user ) {
			return REST_Errors::not_found();
		}

		$file = $this->avatar_file( $request );
		if ( null !== $file ) {
			if ( ! Options::get_bool( 'users', 'avatar_allow_upload' ) ) {
				return REST_Errors::permission( 'avatar' );
			}

			$uploaded = Avatars::upload( $user_id, $file );
			if ( $uploaded instanceof WP_Error ) {
				$errors = new WP_Error();
				$errors->add( 'avatar', $uploaded->get_error_message() );

				return REST_Errors::invalid( $errors );
			}

			Services::limits()->increment( Limits::UPLOADS, $actor );
		} elseif ( self::bool_param( $request, 'remove_avatar' ) ) {
			Avatars::remove( $user_id );
		}

		$values = $request->get_param( 'fields' );
		if ( is_array( $values ) ) {
			foreach ( Services::user_fields()->all() as $field ) {
				if ( isset( $values[ $field->name ] ) && is_string( $values[ $field->name ] ) ) {
					Services::user_fields()->set_value( $user_id, $field, $values[ $field->name ] );
				}
			}
		}

		Events::dispatch( 'u_save', array( 'user_id' => $user_id ), $actor );

		return new WP_REST_Response( $this->format( $user, details: true, all_fields: true ) );
	}

	/**
	 * Returns a profile.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_User $user       User.
	 * @param bool    $details    Whether to include profile fields, rank and activity counts.
	 * @param bool    $all_fields Whether to include all profile fields, also those hidden from the viewer.
	 * @return array<string, mixed>
	 */
	public function format( WP_User $user, bool $details, bool $all_fields = false ): array {
		$level   = Services::user_levels()->level( $user->ID ) ?? Levels::BASIC;
		$profile = array(
			'id'     => $user->ID,
			'name'   => $user->display_name,
			'link'   => URLs::user( $user ),
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 192 ) ),
			'level'  => Levels::labels()[ $level ] ?? '',
			'title'  => Services::user_titles()->title( $user->ID ),
			'points' => Services::points()->get( $user->ID ),
		);

		if ( ! $details ) {
			return $profile;
		}

		$viewer = $this->actor();
		$fields = array();
		foreach ( Services::user_fields()->all() as $field ) {
			$value = Services::user_fields()->value( $user->ID, $field );
			if ( $all_fields || ( '' !== $value && ( null === $field->permit || Services::permissions()->meets( $field->permit, $viewer ) ) ) ) {
				$fields[] = array(
					'name'  => $field->name,
					'label' => $field->display_label(),
					'value' => $value,
					'link'  => $field->is_link(),
				);
			}
		}

		$stats = Services::points()->stats( $user->ID );

		return $profile + array(
			'url'         => $user->user_url,
			'description' => $user->description,
			'registered'  => mysql_to_rfc3339( $user->user_registered ),
			'rank'        => Services::points()->rank( $user->ID ),
			'counts'      => array(
				'questions'          => $stats[ Points::QUESTIONS ],
				'answers'            => $stats[ Points::ANSWERS ],
				'comments'           => $stats[ Points::COMMENTS ],
				'answers_selected'   => $stats[ Points::ANSWERS_SELECTED ],
				'upvotes_received'   => $stats[ Points::UPVOTES_RECEIVED ],
				'downvotes_received' => $stats[ Points::DOWNVOTES_RECEIVED ],
			),
			'fields'      => $fields,
		);
	}

	/**
	 * Checks a permission to view pages; blocked users may still view them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option Permission setting.
	 * @return true|WP_Error
	 */
	private function check_view( string $option ): bool|WP_Error {
		$reason = Services::permissions()->error( $option, $this->actor(), check_blocks: false, maximum_level: true );

		return null === $reason ? true : REST_Errors::permission( $reason );
	}

	/**
	 * Returns the uploaded avatar of a request.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array|null The file entry, or null if no file was sent.
	 * @phpstan-return array{name: non-empty-string, type: non-empty-string, tmp_name: non-empty-string, size: int<0, max>, error: int<0, 8>}|null
	 */
	private function avatar_file( WP_REST_Request $request ): ?array {
		return Avatars::file_entry( $request->get_file_params()['avatar'] ?? null );
	}
}
