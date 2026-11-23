<?php
/**
 * Permission checks.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Users;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Limits\Limits;
use EasyQ\Points\Points;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Service;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Votes\Votes;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an actor may perform an action, and if not, why.
 *
 * Errors are the Question2Answer reasons, so the interface can explain them:
 *
 * - `login`: visitors must log in.
 * - `level`: the user's level is too low.
 * - `userblock`: the user is blocked.
 * - `ipblock`: the IP address is blocked.
 * - `confirm`: the user must confirm their email address.
 * - `approve`: the user must be approved.
 * - `limit`: the user or IP address reached the rate limit.
 *
 * Ported from Question2Answer `qa-include/app/users.php` and the post rules in
 * `pages/question-view.php`.
 *
 * @since 0.1.0
 */
final class Permissions {

	/**
	 * Sets up the service.
	 *
	 * @since 0.1.0
	 *
	 * @param User_Levels $levels User levels.
	 * @param Points      $points User points.
	 * @param Limits      $limits Rate limits.
	 * @param Votes       $votes  Votes and flags.
	 */
	public function __construct(
		private readonly User_Levels $levels,
		private readonly Points $points,
		private readonly Limits $limits,
		private readonly Votes $votes
	) {
	}

	/**
	 * Checks whether an actor may perform an action.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $option        Permission setting, e.g. `permit_post_q`; null for actions anybody may perform.
	 * @param Actor       $actor         The actor.
	 * @param Post|null   $post          Post the action relates to; category levels of the user apply.
	 * @param string|null $limit_action  Rate limit to check, see the Limits constants.
	 * @param bool        $check_blocks  Whether blocked users and IP addresses are refused.
	 * @param bool        $maximum_level Whether to use the user's highest level in any category, e.g. for admin screens.
	 * @param int[]       $category_ids  Categories the action relates to, if there is no post; their category levels apply.
	 * @return string|null The reason the action is not allowed, or null if it is allowed.
	 */
	public function error( ?string $option, Actor $actor, ?Post $post = null, ?string $limit_action = null, bool $check_blocks = true, bool $maximum_level = false, array $category_ids = array() ): ?string {
		$user_id = $actor->user_id;
		$level   = match ( true ) {
			null !== $post             => $this->levels->level_for_post( $user_id, $post ),
			$maximum_level             => $this->levels->maximum_level( $user_id ),
			array() !== $category_ids => $this->levels->level_for_categories( $user_id, $category_ids ),
			default                    => $this->levels->level( $user_id ),
		};

		$permit = null === $option ? Permits::ALL : Options::get_int( 'permissions', $option );

		if ( null !== $user_id && null !== $option && Permits::uses_points( $permit ) ) {
			if ( $this->points->get( $user_id ) >= Options::get_int( 'permissions', $option . '_points' ) ) {
				$permit = match ( $permit ) {
					Permits::APPROVED_POINTS  => Permits::APPROVED,
					Permits::POINTS_CONFIRMED => Permits::CONFIRMED,
					default                   => Permits::USERS,
				};
			} else {
				// A generic answer, so users are not tempted to collect points just for this.
				$permit = Permits::EXPERTS;
			}
		}

		$error = $this->permit_error( $permit, $user_id, $level, $check_blocks );

		if ( null === $error && $check_blocks && $this->limits->is_ip_blocked( $actor ) ) {
			$error = 'ipblock';
		}

		if ( null === $error && null !== $user_id && Options::get_bool( 'spam', 'confirm_user_emails' ) && Options::get_bool( 'spam', 'confirm_user_required' ) && ! User_Status::is_confirmed( $user_id ) ) {
			$error = 'confirm';
		}

		if ( null === $error && null !== $limit_action && $this->limits->remaining( $limit_action, $actor ) <= 0 ) {
			$error = 'limit';
		}

		return $error;
	}

	/**
	 * Checks whether an actor may perform an action.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $option Permission setting, e.g. `permit_post_q`.
	 * @param Actor       $actor  The actor.
	 * @param Post|null   $post   Post the action relates to.
	 * @return bool
	 */
	public function allows( ?string $option, Actor $actor, ?Post $post = null ): bool {
		return null === $this->error( $option, $actor, $post );
	}

	/**
	 * Returns where an actor may perform an action: everywhere, or only in some categories.
	 *
	 * A post is in scope if its category or one of the categories above it is listed,
	 * which matches the category levels that apply to a post.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option Permission setting, e.g. `permit_moderate`.
	 * @param Actor  $actor  The actor.
	 * @return int[]|null Null if the action is allowed everywhere; otherwise the categories where it is allowed, possibly none.
	 */
	public function category_scope( string $option, Actor $actor ): ?array {
		if ( null === $this->error( $option, $actor ) ) {
			return null;
		}

		$category_ids = array();
		foreach ( array_keys( null === $actor->user_id ? array() : $this->levels->category_levels( $actor->user_id ) ) as $category_id ) {
			if ( null === $this->error( $option, $actor, category_ids: array( $category_id ) ) ) {
				$category_ids[] = $category_id;
			}
		}

		return $category_ids;
	}

	/**
	 * Checks whether an actor meets a threshold directly, e.g. the visibility of a profile field.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $permit Threshold, see Permits.
	 * @param Actor $actor  The actor.
	 * @return bool
	 */
	public function meets( int $permit, Actor $actor ): bool {
		return null === $this->permit_error( $permit, $actor->user_id, $this->levels->level( $actor->user_id ), check_blocks: false );
	}

	/**
	 * Returns why a new post of an actor needs moderation.
	 *
	 * Experts and users who may moderate posts themselves are never moderated.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Author.
	 * @return string|null `approve`, `confirm`, `points` or `login`, or null if the post can be published right away.
	 */
	public function moderation_reason( Actor $actor ): ?string {
		$user_id = $actor->user_id;
		$level   = $this->levels->level( $user_id );

		if ( ( $level ?? Levels::BASIC ) >= Levels::EXPERT || null === $this->error( 'permit_moderate', $actor ) ) {
			return null;
		}

		if ( null === $user_id ) {
			return Options::get_bool( 'spam', 'moderate_anon_post' ) ? 'login' : null;
		}

		if ( Options::get_bool( 'spam', 'moderate_users' ) && Options::get_bool( 'spam', 'moderate_unapproved' ) && User_Status::awaits_approval( $user_id ) ) {
			return 'approve';
		}

		if ( Options::get_bool( 'spam', 'confirm_user_emails' ) && Options::get_bool( 'spam', 'moderate_unconfirmed' ) && ! User_Status::is_confirmed( $user_id ) ) {
			return 'confirm';
		}

		if ( Options::get_bool( 'spam', 'moderate_by_points' ) && $this->points->get( $user_id ) < Options::get_int( 'spam', 'moderate_points_limit' ) ) {
			return 'points';
		}

		return null;
	}

	/**
	 * Tells whether the read-only mode keeps a user from changing anything.
	 *
	 * As in the maintenance mode of Question2Answer (`qa-include/app/page.php`), only those who
	 * manage the Q&A area may change anything while it is on; unlike there, everybody may read.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $user_id User ID, null for a visitor.
	 * @return bool
	 */
	public function is_read_only_for( ?int $user_id ): bool {
		return Options::get_bool( 'general', 'read_only' ) && ( null === $user_id || ! user_can( $user_id, Capabilities::MANAGE ) );
	}

	/**
	 * Checks whether a post was written by the actor.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor The actor.
	 * @return bool
	 */
	public function is_by( Post $post, Actor $actor ): bool {
		// As `qa_post_is_by_user()`: as soon as one side has an account, the accounts decide; a
		// post written before logging in is the user's once claimed. The cookie counts between visitors.
		if ( null !== $post->user_id || null !== $actor->user_id ) {
			return $post->user_id === $actor->user_id;
		}

		return null !== $post->cookie_id && $post->cookie_id === $actor->cookie_id;
	}

	/**
	 * Returns why a viewer may not view a post, as the REST API refuses it: the permission to
	 * view questions, checked against the category of the post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor Viewer.
	 * @return string|null Reason code, or null if allowed.
	 */
	public function view_error( Post $post, Actor $actor ): ?string {
		return $this->error( 'permit_view_q_page', $actor, $post, check_blocks: false );
	}

	/**
	 * Tells whether a viewer may see a post anywhere outside the editing screens: on its page,
	 * in a block, a title or a link, as the REST API would return it.
	 *
	 * @since 0.1.0
	 *
	 * @param Post  $post  Post.
	 * @param Actor $actor Viewer.
	 * @return bool
	 */
	public function shows( Post $post, Actor $actor ): bool {
		return $this->post_rules( $post, $actor )['viewable'] && null === $this->view_error( $post, $actor );
	}

	/**
	 * Determines which actions an actor may perform on a post.
	 *
	 * Rules ending in `button` mean the action is offered, possibly with a hint
	 * that the user must log in or confirm first; the others mean the action is
	 * possible right away.
	 *
	 * @since 0.1.0
	 *
	 * @param Post      $post        The post.
	 * @param Actor     $actor       The actor.
	 * @param Post|null $parent_post Parent of the post (the question of an answer, the question or answer of a comment), or null for questions.
	 * @param Post[]    $siblings    Other posts with the same parent.
	 * @param Post[]    $children    Posts whose parent is this post.
	 * @return array<string, bool>
	 */
	public function post_rules( Post $post, Actor $actor, ?Post $parent_post = null, array $siblings = array(), array $children = array() ): array {
		$is_by_user = $this->is_by( $post, $actor );
		$closed     = Post::QUESTION === $post->base_type() && Post_Service::is_closed( $post );
		$hidden     = $post->is_hidden();
		$queued     = $post->is_queued();
		$base_type  = $post->base_type();
		$user_flag  = null !== $actor->user_id && $this->votes->has_flagged( $post->id, $actor->user_id );

		$error = fn ( ?string $option ): ?string => $this->error( $option, $actor, $post );
		// The author needs no permission to change or hide their own post, but must not be
		// blocked, as `qa_page_q_permit_edit()` checks with the option null for them.
		$own = $is_by_user ? $error( null ) : null;

		$post_q = $error( 'permit_post_q' );
		$post_a = $error( 'permit_post_a' );
		$post_c = $error( 'permit_post_c' );
		$edit   = $error(
			match ( $base_type ) {
			Post::QUESTION => 'permit_edit_q',
			Post::ANSWER   => 'permit_edit_a',
			default        => 'permit_edit_c',
			}
		);
		$retag     = $error( 'permit_retag_cat' );
		$flag      = $error( 'permit_flag' );
		$hide_show = $error( 'permit_hide_show' );
		$hide_self = $is_by_user ? $own : $hide_show;
		$close     = $error( $is_by_user && Options::get_bool( 'posting', 'allow_close_own_questions' ) ? null : 'permit_close_q' );
		$moderate  = $error( 'permit_moderate' );

		$using             = Options::get_string( 'general', 'tags_or_categories' );
		$button_errors     = array( 'login', 'level', 'approve' );
		$rules             = array();
		$rules['by_user']  = $is_by_user;
		$rules['closed']   = $closed;
		$author_last       = null === $post->last_user_id || $post->last_user_id === $post->user_id;
		$rules['viewable'] = $hidden ? null === $hide_self : ( $queued ? ( $is_by_user || null === $moderate ) : true );

		$rules['answer_button'] = Post::QUESTION === $post->type && 'level' !== $post_a && ! $closed
			&& ( Options::get_bool( 'posting', 'allow_self_answer' ) || ! $is_by_user );

		$rules['comment_button'] = in_array( $post->type, array( Post::QUESTION, Post::ANSWER ), true ) && 'level' !== $post_c
			&& Options::get_bool( 'posting', Post::QUESTION === $post->type ? 'comment_on_qs' : 'comment_on_as' );
		$rules['commentable']    = $rules['comment_button'] && null === $post_c;

		// A Reply button on a comment opens the comment form of its parent, as Q2A offers it in `qa_page_q_comment_view()`.
		$rules['reply_button'] = Post::COMMENT === $post->type && null !== $parent_post && Options::get_bool( 'viewing', 'show_c_reply_buttons' )
			&& in_array( $parent_post->type, array( Post::QUESTION, Post::ANSWER ), true )
			&& 'level' !== $this->error( 'permit_post_c', $actor, $parent_post )
			&& Options::get_bool( 'posting', Post::QUESTION === $parent_post->type ? 'comment_on_qs' : 'comment_on_as' );

		$rules['edit_button'] = ! $hidden && ! $closed && ( $is_by_user || ( ! in_array( $edit, $button_errors, true ) && ! $queued ) );
		$rules['editable']    = $rules['edit_button'] && null === ( $is_by_user ? $own : $edit );

		$rules['retag_button'] = Post::QUESTION === $base_type && '' !== $using && ! $hidden
			&& ( $is_by_user || ! in_array( $retag, $button_errors, true ) );
		$rules['retaggable']   = $rules['retag_button'] && null === ( $is_by_user ? $own : $retag );

		$rules['answer_selectable'] = Post::QUESTION === $post->type && null === $error( $is_by_user ? null : 'permit_select_a' );

		$rules['flag_button']     = Options::get_bool( 'spam', 'flagging_of_posts' ) && ! $is_by_user && ! $hidden && ! $queued
			&& ! $user_flag && ! in_array( $flag, $button_errors, true );
		$rules['flag_to_hide']    = $rules['flag_button'] && null === $flag && $post->flag_count + 1 >= Options::get_int( 'spam', 'flagging_hide_after' );
		$rules['unflaggable']     = $user_flag && ! $hidden;
		$rules['flags_clearable'] = $post->flag_count >= ( $user_flag ? 2 : 1 ) && null === $hide_show;

		// Questions closed or hidden by someone else need the general permission to reopen or reshow.
		$not_closed_by_other = ! ( $closed && null !== $post->closed_by_id && ! $author_last );
		$not_hidden_by_other = ! ( $hidden && ! $author_last );

		$rules['closeable']  = Options::get_bool( 'posting', 'allow_close_questions' ) && Post::QUESTION === $post->type && ! $closed && null === $close;
		$rules['reopenable'] = $closed && null === $close && ! $hidden
			&& ( $not_closed_by_other || null === $error( 'permit_close_q' ) );

		$rules['moderatable']    = $queued && null === $moderate;
		$rules['hideable']       = ! $hidden && ( $is_by_user || ! $queued ) && null === $hide_self
			&& ( $not_closed_by_other || null === $hide_show );
		$rules['reshow_at_once'] = $hidden && null === $hide_show;
		$rules['reshowable']     = $hidden && null === $hide_self
			&& ( $rules['reshow_at_once'] || ( $not_hidden_by_other && 0 === $post->flag_count ) );

		// A question other questions are closed as duplicates of stays, as Question2Answer passes
		// the duplicates to its rules (`qa-include/pages/question.php`); only a hidden post asks.
		$rules['deletable'] = $hidden && null === $error( 'permit_delete_hidden' )
			&& ! ( Post::QUESTION === $base_type && Services::posts()->has_duplicates( $post->id ) );

		$rules['claimable'] = null === $post->user_id && null !== $actor->user_id && null !== $post->cookie_id && $post->cookie_id === $actor->cookie_id
			&& null === match ( $base_type ) {
				Post::QUESTION => $post_q,
				Post::ANSWER   => $post_a,
				default        => $post_c,
			};

		$rules['followable'] = Post::ANSWER === $post->type && Options::get_bool( 'posting', 'follow_on_as' );

		// Who voted on and who flagged the post, as `qa-layer-voters-flaggers.php` shows them where the
		// level of the actor in the category of the post allows it (`qa_user_post_permit_error()`).
		$rules['voters_viewable'] = null === $error( 'permit_view_voters_flaggers' );

		// Claiming must not break the rules about answering own questions and answering twice.
		if ( $rules['claimable'] && Post::ANSWER === $base_type ) {
			if ( ! Options::get_bool( 'posting', 'allow_self_answer' ) && null !== $parent_post && $this->is_by( $parent_post, $actor ) ) {
				$rules['claimable'] = false;
			}

			if ( ! Options::get_bool( 'posting', 'allow_multi_answers' ) ) {
				foreach ( $siblings as $sibling ) {
					if ( $sibling->parent_id === $post->parent_id && Post::ANSWER === $sibling->base_type() && $this->is_by( $sibling, $actor ) ) {
						$rules['claimable'] = false;
					}
				}
			}
		}

		foreach ( $children as $child ) {
			if ( $child->parent_id === $post->id ) {
				$rules['deletable'] = false;

				if ( Post::ANSWER === $child->base_type() && $this->is_by( $child, $actor ) ) {
					if ( ! Options::get_bool( 'posting', 'allow_multi_answers' ) ) {
						$rules['answer_button'] = false;
					}
					if ( ! Options::get_bool( 'posting', 'allow_self_answer' ) ) {
						$rules['claimable'] = false;
					}
				}
			}
		}

		return $rules;
	}

	/**
	 * Checks a threshold against a user's level and status.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $permit       Threshold, see Permits.
	 * @param int|null $user_id      User ID, or null for visitors.
	 * @param int|null $level        User level, or null for visitors.
	 * @param bool     $check_blocks Whether blocked users are refused.
	 * @return string|null
	 */
	private function permit_error( int $permit, ?int $user_id, ?int $level, bool $check_blocks ): ?string {
		if ( null === $user_id ) {
			return $permit < Permits::ALL ? 'login' : null;
		}

		$level ??= Levels::BASIC;

		$level_error = ( $permit <= Permits::SUPERS && $level < Levels::SUPER )
			|| ( $permit <= Permits::ADMINS && $level < Levels::ADMIN )
			|| ( $permit <= Permits::MODERATORS && $level < Levels::MODERATOR )
			|| ( $permit <= Permits::EDITORS && $level < Levels::EDITOR )
			|| ( $permit <= Permits::EXPERTS && $level < Levels::EXPERT );

		if ( $level_error ) {
			return 'level';
		}

		if ( $check_blocks && User_Status::is_blocked( $user_id ) ) {
			return 'userblock';
		}

		if ( $permit >= Permits::USERS ) {
			return null;
		}

		if ( $permit >= Permits::CONFIRMED ) {
			// Approved users and above need no confirmation.
			if ( Options::get_bool( 'spam', 'confirm_user_emails' ) && $level < Levels::APPROVED && ! User_Status::is_confirmed( $user_id ) ) {
				return 'confirm';
			}
		} elseif ( $permit >= Permits::APPROVED ) {
			if ( Options::get_bool( 'spam', 'moderate_users' ) && $level < Levels::APPROVED && User_Status::awaits_approval( $user_id ) ) {
				return 'approve';
			}
		}

		return null;
	}
}
