<?php
/**
 * JSON schema of posts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON schema of questions, answers and comments in REST responses.
 *
 * @since 0.1.0
 */
final class Post_Schema {

	/**
	 * Returns the schema of a post type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type `question`, `answer` or `comment`.
	 * @return array<string, mixed>
	 */
	public static function get( string $type ): array {
		$properties = array(
			'id'           => array(
				'description' => __( 'Unique identifier of the post.', 'easyq' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'type'         => array(
				'description' => __( 'Type of the post.', 'easyq' ),
				'type'        => 'string',
				'enum'        => array( 'question', 'answer', 'comment', 'note' ),
				'readonly'    => true,
			),
			'status'       => array(
				'description' => __( 'Visibility of the post.', 'easyq' ),
				'type'        => 'string',
				'enum'        => array( 'normal', 'hidden', 'queued' ),
				'readonly'    => true,
			),
			'parent'       => array(
				'description' => __( 'Parent post.', 'easyq' ),
				'type'        => array( 'integer', 'null' ),
				'readonly'    => true,
			),
			'question'     => array(
				'description' => __( 'Question the post belongs to.', 'easyq' ),
				'type'        => array( 'integer', 'null' ),
				'readonly'    => true,
			),
			'date_gmt'     => array(
				'description' => __( 'Creation time in UTC.', 'easyq' ),
				'type'        => 'string',
				'format'      => 'date-time',
				'readonly'    => true,
			),
			'modified_gmt' => array(
				'description' => __( 'Time of the last change in UTC.', 'easyq' ),
				'type'        => array( 'string', 'null' ),
				'readonly'    => true,
			),
			'author'       => array(
				'description' => __( 'Author of the post.', 'easyq' ),
				'type'        => 'object',
				'readonly'    => true,
				'properties'  => array(
					'id'     => array( 'type' => array( 'integer', 'null' ) ),
					'name'   => array( 'type' => 'string' ),
					'avatar' => array( 'type' => array( 'string', 'boolean' ) ),
					'points' => array( 'type' => array( 'integer', 'null' ) ),
				),
			),
			'content'      => array(
				'description' => __( 'Content of the post.', 'easyq' ),
				'type'        => 'object',
				'properties'  => array(
					'rendered' => array(
						'type'     => 'string',
						'readonly' => true,
					),
					'raw'      => array(
						'type'    => 'string',
						'context' => array( 'edit' ),
					),
					'format'   => array( 'type' => 'string' ),
				),
			),
			'excerpt'      => array(
				'description' => __( 'Shortened plain text of the content.', 'easyq' ),
				'type'        => 'string',
				'readonly'    => true,
			),
			'votes'        => array(
				'description' => __( 'Votes on the post and the vote of the current user.', 'easyq' ),
				'type'        => 'object',
				'readonly'    => true,
				'properties'  => array(
					'up'   => array( 'type' => 'integer' ),
					'down' => array( 'type' => 'integer' ),
					'net'  => array( 'type' => 'integer' ),
					'user' => array( 'type' => 'integer' ),
				),
			),
			'flags'        => array(
				'description' => __( 'Flags; the count is only shown to moderators.', 'easyq' ),
				'type'        => 'object',
				'readonly'    => true,
			),
			'can'          => array(
				'description'          => __( 'Actions the current user can perform on the post.', 'easyq' ),
				'type'                 => 'object',
				'readonly'             => true,
				'additionalProperties' => array( 'type' => 'boolean' ),
			),
		);

		if ( 'question' === $type ) {
			$properties += array(
				'title'           => array(
					'description' => __( 'Title of the question.', 'easyq' ),
					'type'        => 'object',
					'properties'  => array(
						'rendered' => array(
							'type'     => 'string',
							'readonly' => true,
						),
						'raw'      => array(
							'type'    => 'string',
							'context' => array( 'edit' ),
						),
					),
				),
				'tags'            => array(
					'description' => __( 'Tags of the question.', 'easyq' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'category'        => array(
					'description' => __( 'Category of the question.', 'easyq' ),
					'type'        => array( 'object', 'null' ),
				),
				'answer_count'    => array(
					'description' => __( 'Number of visible answers.', 'easyq' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'views'           => array(
					'description' => __( 'Number of views.', 'easyq' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'selected_answer' => array(
					'description' => __( 'Selected best answer.', 'easyq' ),
					'type'        => array( 'integer', 'null' ),
					'readonly'    => true,
				),
				'closed'          => array(
					'description' => __( 'Whether the question is closed.', 'easyq' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'closed_by'       => array(
					'description' => __( 'Duplicate question or close note.', 'easyq' ),
					'type'        => array( 'integer', 'null' ),
					'readonly'    => true,
				),
			);
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'easyq-' . $type,
			'type'       => 'object',
			'properties' => $properties,
		);
	}
}
