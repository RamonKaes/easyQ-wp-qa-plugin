<?php
/**
 * REST representation of posts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Posts\Content_Renderer;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Service;
use EasyQ\Services;
use EasyQ\Util\IP;
use EasyQ\Util\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Converts posts into the arrays returned by the REST API.
 *
 * @since 0.1.0
 */
final class Post_Formatter {

	/**
	 * Names of the post types in responses.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const TYPE_NAMES = array(
		Post::QUESTION => 'question',
		Post::ANSWER   => 'answer',
		Post::COMMENT  => 'comment',
		Post::NOTE     => 'note',
	);

	/**
	 * Sets up the formatter.
	 *
	 * @since 0.1.0
	 *
	 * @param Content_Renderer $renderer Content output.
	 */
	public function __construct( private readonly Content_Renderer $renderer ) {
	}

	/**
	 * Returns the name of a post type in responses.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return string
	 */
	public static function type_name( Post $post ): string {
		return self::TYPE_NAMES[ $post->base_type() ] ?? 'note';
	}

	/**
	 * Converts a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post   $post     Post.
	 * @param Actor  $viewer   Who requests the post.
	 * @param string $context  `view` or `edit`; `edit` adds the raw content if the viewer may edit it.
	 * @param Post[] $children Children of the post, used for the rules about deleting and answering.
	 * @return array<string, mixed>
	 */
	public function format( Post $post, Actor $viewer, string $context = 'view', array $children = array() ): array {
		$permissions = Services::permissions();
		$base_type   = $post->base_type();
		$rules       = $permissions->post_rules( $post, $viewer, null, array(), $children );
		$question_id = Post::QUESTION === $base_type ? $post->id : Services::post_service()->question_of( $post )?->id;
		$user_vote   = null === $viewer->user_id ? 0 : Services::votes()->vote_of( $post->id, $viewer->user_id );
		$moderator   = null !== $viewer->user_id && user_can( $viewer->user_id, Capabilities::MODERATE );

		$data = array(
			'id'           => $post->id,
			'type'         => self::type_name( $post ),
			'status'       => $post->status(),
			'parent'       => $post->parent_id,
			'question'     => $question_id,
			'date_gmt'     => mysql_to_rfc3339( $post->created ),
			'modified_gmt' => null === $post->updated ? null : mysql_to_rfc3339( $post->updated ),
			'author'       => $this->author( $post ),
			'content'      => array(
				'rendered' => $this->renderer->html( (string) $post->content, $post->format ),
				'format'   => $post->format,
			),
			'excerpt'      => $this->renderer->text( Text::shorten_line( $post->text(), 200 ) ),
			'votes'        => array(
				'up'   => $post->upvotes,
				'down' => $post->downvotes,
				'net'  => $post->net_votes,
				'user' => $user_vote,
			),
			'flags'        => array(
				'count' => $moderator ? $post->flag_count : null,
				'user'  => $rules['unflaggable'],
			),
			'can'          => array(
				'answer'      => $rules['answer_button'],
				'comment'     => $rules['commentable'],
				'edit'        => $rules['editable'],
				'retag'       => $rules['retaggable'],
				'select'      => $rules['answer_selectable'],
				'vote'        => null !== $viewer->user_id && user_can( $viewer->user_id, 'easyq_vote_post', $post ),
				'vote_down'   => null !== $viewer->user_id && user_can( $viewer->user_id, 'easyq_vote_down_post', $post ),
				'flag'        => $rules['flag_button'],
				'unflag'      => $rules['unflaggable'],
				'clear_flags' => $rules['flags_clearable'],
				'close'       => $rules['closeable'],
				'reopen'      => $rules['reopenable'],
				'moderate'    => $rules['moderatable'],
				'hide'        => $rules['hideable'],
				'reshow'      => $rules['reshowable'],
				'delete'      => $rules['deletable'],
				'claim'       => $rules['claimable'],
				'follow'      => $rules['followable'],
			),
		);

		if ( Post::QUESTION === $base_type ) {
			$data['title']           = array( 'rendered' => $this->renderer->text( (string) $post->title ) );
			$data['tags']            = $post->tag_list();
			$data['category']        = $this->category( $post->category_id );
			$data['answer_count']    = $post->answer_count;
			$data['views']           = $post->views;
			$data['selected_answer'] = $post->selected_child_id;
			$data['closed']          = Post_Service::is_closed( $post );
			$data['closed_by']       = $post->closed_by_id;
		}

		if ( 'edit' === $context && $rules['editable'] ) {
			$data['content']['raw'] = (string) $post->content;
			if ( Post::QUESTION === $base_type ) {
				$data['title']['raw'] = (string) $post->title;
			}
		}

		if ( null === $post->user_id && null !== $viewer->user_id && user_can( $viewer->user_id, 'easyq_view_ips' ) ) {
			$data['ip'] = IP::from_binary( $post->created_ip );
		}

		return $data;
	}

	/**
	 * Describes the author of a post.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $post Post.
	 * @return array<string, mixed>
	 */
	private function author( Post $post ): array {
		$user = null === $post->user_id ? false : get_userdata( $post->user_id );

		if ( false === $user ) {
			return array(
				'id'     => null,
				'name'   => '' === (string) $post->author_name ? __( 'Anonymous', 'easyq' ) : (string) $post->author_name,
				'avatar' => get_avatar_url( 0, array( 'size' => 96 ) ),
				'points' => null,
			);
		}

		return array(
			'id'     => $user->ID,
			'name'   => $user->display_name,
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'points' => Services::points()->get( $user->ID ),
		);
	}

	/**
	 * Describes a category.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $category_id Category ID.
	 * @return array<string, mixed>|null
	 */
	private function category( ?int $category_id ): ?array {
		$category = null === $category_id ? null : Services::categories()->find( $category_id );
		if ( null === $category ) {
			return null;
		}

		return array(
			'id'    => $category->id,
			'title' => $category->title,
			'slug'  => $category->slug,
			'path'  => array_map( static fn ( $ancestor ): int => $ancestor->id, Services::categories()->path( $category->id ) ),
		);
	}
}
