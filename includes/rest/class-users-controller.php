<?php
/**
 * REST endpoints for Q&A user profiles.
 *
 * @package easyQ
 */

namespace EasyQ\Rest;

use EasyQ\Points\Points;
use EasyQ\Services;
use EasyQ\Users\Levels;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the public Q&A profile of users.
 *
 * Routes:
 *
 * - `GET /easyq/v1/users/{id}`
 *
 * Profiles contain only public information: name, avatar, points, level,
 * activity counts and the profile fields the viewer may see.
 */
final class Users_Controller extends Controller {

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
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
	 * Checks whether the request may see the Q&A area.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ): bool|WP_Error {
		return $this->check( 'permit_view_q_page', $this->actor() );
	}

	/**
	 * Returns a profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$user = get_userdata( (int) self::int_param( $request, 'id' ) );
		if ( false === $user ) {
			return Rest_Errors::not_found();
		}

		$viewer = $this->actor();
		$points = Services::points();
		$fields = array();

		foreach ( Services::user_fields()->all() as $field ) {
			$value = Services::user_fields()->value( $user->ID, $field );
			if ( '' !== $value && ( null === $field->permit || Services::permissions()->meets( $field->permit, $viewer ) ) ) {
				$fields[] = array(
					'name'  => $field->name,
					'label' => $field->display_label(),
					'value' => $value,
					'link'  => $field->is_link(),
				);
			}
		}

		$level = Services::user_levels()->level( $user->ID ) ?? Levels::BASIC;

		return new WP_REST_Response(
			array(
				'id'          => $user->ID,
				'name'        => $user->display_name,
				'avatar'      => get_avatar_url( $user->ID, array( 'size' => 192 ) ),
				'url'         => $user->user_url,
				'description' => $user->description,
				'registered'  => mysql_to_rfc3339( $user->user_registered ),
				'level'       => Levels::labels()[ $level ] ?? '',
				'points'      => $points->get( $user->ID ),
				'counts'      => array(
					'questions'        => $points->calculate( $user->ID, Points::QUESTIONS ),
					'answers'          => $points->calculate( $user->ID, Points::ANSWERS ),
					'comments'         => $points->calculate( $user->ID, Points::COMMENTS ),
					'answers_selected' => $points->calculate( $user->ID, Points::ANSWERS_SELECTED ),
					'upvotes_received' => $points->calculate( $user->ID, Points::UPVOTES_RECEIVED ),
				),
				'fields'      => $fields,
			)
		);
	}
}
