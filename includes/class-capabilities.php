<?php
/**
 * Capabilities.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ;

use EasyQ\Posts\Post;
use EasyQ\Users\Levels;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the easyQ capabilities to the Q&A permission rules.
 *
 * The plugin adds no roles and stores no capabilities in the database. Every
 * easyQ capability is a meta capability decided by the permission settings,
 * the user's Q&A level (derived from their roles) and their status, so
 * `current_user_can( 'easyq_edit_post', $post_id )` works anywhere in WordPress.
 *
 * Capabilities that relate to a post take the post ID as argument. Rate limits
 * and blocked IP addresses are not part of capabilities; the REST API checks
 * them for the current request.
 *
 * @since 0.1.0
 */
final class Capabilities {

	/**
	 * Manage the plugin: settings, categories, points, tools.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MANAGE = 'easyq_manage';

	/**
	 * Access the moderation screens.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MODERATE = 'easyq_moderate';

	/**
	 * Capabilities without arguments, mapped to a permission setting.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const GENERAL = array(
		'easyq_view_questions'     => 'permit_view_q_page',
		'easyq_post_question'      => 'permit_post_q',
		'easyq_edit_silently'      => 'permit_edit_silent',
		'easyq_view_ips'           => 'permit_anon_view_ips',
		'easyq_view_voters'        => 'permit_view_voters_flaggers',
		'easyq_post_wall'          => 'permit_post_wall',
		'easyq_view_new_users'     => 'permit_view_new_users_page',
		'easyq_view_special_users' => 'permit_view_special_users_page',
	);

	/**
	 * Capabilities about a post, mapped to the post rule that decides them.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const POST_RULES = array(
		'easyq_post_answer'     => 'answer_button',
		'easyq_post_comment'    => 'commentable',
		'easyq_edit_post'       => 'editable',
		'easyq_retag_question'  => 'retaggable',
		'easyq_select_answer'   => 'answer_selectable',
		'easyq_flag_post'       => 'flag_button',
		'easyq_clear_flags'     => 'flags_clearable',
		'easyq_close_question'  => 'closeable',
		'easyq_reopen_question' => 'reopenable',
		'easyq_moderate_post'   => 'moderatable',
		'easyq_hide_post'       => 'hideable',
		'easyq_reshow_post'     => 'reshowable',
		'easyq_delete_post'     => 'deletable',
	);

	/**
	 * Hooks the capability mapping into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Resolves easyQ capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $caps    Primitive capabilities required so far.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID; 0 for visitors.
	 * @param mixed[]  $args    Further arguments, e.g. a post ID.
	 * @return string[]
	 */
	public function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! str_starts_with( $cap, 'easyq_' ) ) {
			return $caps;
		}

		$allowed = $this->decide( $cap, $user_id, $args );
		if ( null === $allowed ) {
			return $caps;
		}

		return $allowed ? array( 'exist' ) : array( 'do_not_allow' );
	}

	/**
	 * Decides an easyQ capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $cap     Capability.
	 * @param int     $user_id User ID; 0 for visitors.
	 * @param mixed[] $args    Further arguments.
	 * @return bool|null Null for unknown capabilities.
	 */
	private function decide( string $cap, int $user_id, array $args ): ?bool {
		$actor       = new Actor( $user_id > 0 ? $user_id : null );
		$permissions = Services::permissions();

		if ( self::MANAGE === $cap ) {
			return user_can( $user_id, 'manage_options' ) || ( Services::user_levels()->maximum_level( $actor->user_id ) ?? -1 ) >= Levels::ADMIN;
		}

		if ( self::MODERATE === $cap ) {
			return user_can( $user_id, 'manage_options' )
				|| null === $permissions->error( 'permit_moderate', $actor, maximum_level: true )
				|| null === $permissions->error( 'permit_hide_show', $actor, maximum_level: true );
		}

		if ( isset( self::GENERAL[ $cap ] ) ) {
			return null === $permissions->error( self::GENERAL[ $cap ], $actor, maximum_level: true );
		}

		$post = $this->post_argument( $args );

		if ( 'easyq_vote_post' === $cap || 'easyq_vote_down_post' === $cap ) {
			return null !== $post && $this->can_vote( $post, $actor, 'easyq_vote_down_post' === $cap );
		}

		if ( isset( self::POST_RULES[ $cap ] ) ) {
			if ( null === $post ) {
				return false;
			}

			$rules = $permissions->post_rules( $post, $actor, null, array(), Services::posts()->children( $post->id ) );

			return match ( $cap ) {
				'easyq_post_answer' => $rules['answer_button'] && null === $permissions->error( 'permit_post_a', $actor, $post ),
				'easyq_flag_post'   => $rules['flag_button'] && null === $permissions->error( 'permit_flag', $actor, $post ),
				default             => $rules[ self::POST_RULES[ $cap ] ] ?? false,
			};
		}

		return null;
	}

	/**
	 * Checks whether a user may vote on a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor User.
	 * @param bool  $down  Whether the vote is a downvote.
	 * @return bool
	 */
	private function can_vote( Post $post, Actor $actor, bool $down ): bool {
		if ( true !== Services::votes()->can_vote( $post, $actor->user_id ) ) {
			return false;
		}

		$option = match ( $post->base_type() ) {
			Post::QUESTION => 'permit_vote_q',
			Post::ANSWER   => 'permit_vote_a',
			default        => 'permit_vote_c',
		};

		$permissions = Services::permissions();

		return null === $permissions->error( $option, $actor, $post )
			&& ( ! $down || null === $permissions->error( 'permit_vote_down', $actor, $post ) );
	}

	/**
	 * Loads the post passed as first capability argument.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed[] $args Capability arguments.
	 * @return Post|null
	 */
	private function post_argument( array $args ): ?Post {
		$id = $args[0] ?? null;

		if ( $id instanceof Post ) {
			return $id;
		}

		return is_numeric( $id ) ? Services::posts()->find( (int) $id ) : null;
	}
}
