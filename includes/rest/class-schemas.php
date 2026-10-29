<?php
/**
 * JSON schemas of the REST responses other than posts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON schemas of categories, tags, users, profile fields, IP addresses,
 * tool batches, moved questions and similar questions, as core controllers describe their items.
 *
 * Lists use the schema of one item; posts are described by Post_Schema. Each route
 * names its schema in `register_rest_route()`, so `OPTIONS` requests return it.
 *
 * @since 0.1.0
 */
final class Schemas {

	/**
	 * Contexts of most properties.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const VIEW = array( 'view' );

	/**
	 * Contexts of the properties that identify an item, also when it is embedded.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const EMBED = array( 'view', 'embed' );

	/**
	 * Returns the schema of the questions similar to a title and the tags they suggest.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function similar(): array {
		return self::schema(
			'easyq-similar',
			array(
				'questions' => self::property( __( 'Questions similar to the title, best match first.', 'easyq' ), 'array' ) + array( 'items' => Post_Schema::get( 'question' ) ),
				'tags'      => self::property( __( 'Tags of the similar questions, suggested for the new question.', 'easyq' ), 'array' ) + array( 'items' => array( 'type' => 'string' ) ),
			)
		);
	}

	/**
	 * Returns the schema of a category.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function category(): array {
		return self::schema(
			'easyq-category',
			array(
				'id'             => self::property( __( 'Unique identifier of the category.', 'easyq' ), 'integer', self::EMBED ),
				'parent'         => self::property( __( 'Parent category; null for a top-level category.', 'easyq' ), array( 'integer', 'null' ), self::EMBED ),
				'title'          => self::property( __( 'Name of the category.', 'easyq' ), 'string', self::EMBED ),
				'slug'           => self::property( __( 'Slug of the category in addresses.', 'easyq' ), 'string', self::EMBED ),
				'description'    => self::property( __( 'Description of the category.', 'easyq' ), 'string' ),
				'question_count' => self::property( __( 'Number of questions in the category and its subcategories.', 'easyq' ), 'integer' ),
				'position'       => self::property( __( 'Position among the categories with the same parent.', 'easyq' ), 'integer' ),
				'path'           => self::property( __( 'Slugs from the top-level category down to this one.', 'easyq' ), 'array', self::EMBED ) + array( 'items' => array( 'type' => 'string' ) ),
			)
		);
	}

	/**
	 * Returns the schema of the result of moving questions to another category.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function category_reassign(): array {
		return self::schema(
			'easyq-category-reassign',
			array(
				'moved' => self::property( __( 'Number of questions that were moved.', 'easyq' ), 'integer' ),
			)
		);
	}

	/**
	 * Returns the schema of a tag.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function tag(): array {
		return self::schema(
			'easyq-tag',
			array(
				'name'  => self::property( __( 'The tag.', 'easyq' ), 'string', self::EMBED ),
				'count' => self::property( __( 'Number of visible questions with the tag.', 'easyq' ), 'integer', self::EMBED ),
			)
		);
	}

	/**
	 * Returns the schema of a user.
	 *
	 * Lists contain the properties up to `points`; single users and the current user all of them.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function user(): array {
		$count = static fn ( string $description ): array => array(
			'description' => $description,
			'type'        => 'integer',
		);

		return self::schema(
			'easyq-user',
			array(
				'id'          => self::property( __( 'Unique identifier of the user.', 'easyq' ), 'integer', self::EMBED ),
				'name'        => self::property( __( 'Display name of the user.', 'easyq' ), 'string', self::EMBED ),
				'link'        => self::property( __( 'Address of the Q&A profile.', 'easyq' ), 'string', self::EMBED ) + array( 'format' => 'uri' ),
				'avatar'      => self::property( __( 'Address of the avatar image; false without one.', 'easyq' ), array( 'string', 'boolean' ), self::EMBED ),
				'level'       => self::property( __( 'Name of the Q&A level of the user.', 'easyq' ), 'string', self::EMBED ),
				'title'       => self::property( __( 'Title earned with points.', 'easyq' ), 'string', self::EMBED ),
				'points'      => self::property( __( 'Points of the user.', 'easyq' ), 'integer', self::EMBED ),
				'url'         => self::property( __( 'Website of the user.', 'easyq' ), 'string' ),
				'description' => self::property( __( 'Biographical info of the user.', 'easyq' ), 'string' ),
				'registered'  => self::property( __( 'Time of the registration.', 'easyq' ), 'string' ) + array( 'format' => 'date-time' ),
				'rank'        => self::property( __( 'Rank by points; null without points.', 'easyq' ), array( 'integer', 'null' ) ),
				'counts'      => self::property( __( 'Activity of the user.', 'easyq' ), 'object' ) + array(
					'properties' => array(
						'questions'          => $count( __( 'Questions asked.', 'easyq' ) ),
						'answers'            => $count( __( 'Answers written.', 'easyq' ) ),
						'comments'           => $count( __( 'Comments written.', 'easyq' ) ),
						'answers_selected'   => $count( __( 'Answers selected as the best.', 'easyq' ) ),
						'upvotes_received'   => $count( __( 'Up votes received.', 'easyq' ) ),
						'downvotes_received' => $count( __( 'Down votes received.', 'easyq' ) ),
					),
				),
				'fields'      => self::property( __( 'Profile fields the viewer may see; the current user gets all of them.', 'easyq' ), 'array' ) + array(
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'  => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
							'value' => array( 'type' => 'string' ),
							'link'  => array( 'type' => 'boolean' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Returns the schema of a profile field.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function user_field(): array {
		return self::schema(
			'easyq-user-field',
			array(
				'id'          => self::property( __( 'Unique identifier of the field.', 'easyq' ), 'integer', self::EMBED ),
				'name'        => self::property( __( 'Internal name of the field, part of its user meta key.', 'easyq' ), 'string', self::EMBED ),
				'label'       => self::property( __( 'Label of the field.', 'easyq' ), 'string', self::EMBED ),
				'type'        => self::property( __( 'Kind of value.', 'easyq' ), 'string', self::EMBED ) + array( 'enum' => array_keys( User_Fields_Controller::TYPES ) ),
				'permit'      => self::property( __( 'Who may see the value, as a permission threshold.', 'easyq' ), 'integer' ),
				'on_register' => self::property( __( 'Whether the field is on the registration form.', 'easyq' ), 'boolean' ),
				'position'    => self::property( __( 'Position in the list of fields.', 'easyq' ), 'integer' ),
			)
		);
	}

	/**
	 * Returns the schema of an IP address.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function ip(): array {
		$rules = array( 'items' => array( 'type' => 'string' ) );

		return self::schema(
			'easyq-ip',
			array(
				'ip'           => self::property( __( 'The IP address.', 'easyq' ), 'string' ),
				'blocked'      => self::property( __( 'Whether a rule of the block list matches the address.', 'easyq' ), 'boolean' ),
				'rules'        => self::property( __( 'Rules of the block list that match the address.', 'easyq' ), 'array' ) + $rules,
				'can_block'    => self::property( __( 'Whether the current user may block and unblock addresses.', 'easyq' ), 'boolean' ),
				'can_hide_all' => self::property( __( 'Whether the current user may hide all posts of the address.', 'easyq' ), 'boolean' ),
				'removed'      => self::property( __( 'Rules removed by unblocking; only after unblocking.', 'easyq' ), 'array' ) + $rules,
				'hidden'       => self::property( __( 'Number of posts that were hidden; only after hiding all posts.', 'easyq' ), 'integer' ),
			)
		);
	}

	/**
	 * Returns the schema of a batch of a tool.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function tool_batch(): array {
		return self::schema(
			'easyq-tool-batch',
			array(
				'tool'        => self::property( __( 'The tool.', 'easyq' ), 'string' ),
				'step'        => self::property( __( 'Step to run next, or the last step once the tool is complete.', 'easyq' ), 'string' ),
				'step_number' => self::property( __( 'Number of that step, starting at 1.', 'easyq' ), 'integer' ),
				'steps'       => self::property( __( 'Number of steps of the tool.', 'easyq' ), 'integer' ),
				'after'       => self::property( __( 'Last item done; pass it back to go on, 0 at the start of a step.', 'easyq' ), 'integer' ),
				'done'        => self::property( __( 'Items done in the current step.', 'easyq' ), 'integer' ),
				'total'       => self::property( __( 'Items of the current step.', 'easyq' ), 'integer' ),
				'complete'    => self::property( __( 'Whether all steps are done.', 'easyq' ), 'boolean' ),
			)
		);
	}

	/**
	 * Returns a read-only property.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $description Description.
	 * @param string|string[] $type        JSON type.
	 * @param string[]        $context     Contexts that include the property: `view`, and `embed` for the
	 *                                     properties that identify the item when it is embedded.
	 * @return array<string, mixed>
	 */
	private static function property( string $description, string|array $type, array $context = self::VIEW ): array {
		return array(
			'description' => $description,
			'type'        => $type,
			'context'     => $context,
			'readonly'    => true,
		);
	}

	/**
	 * Returns a schema.
	 *
	 * @since 0.1.0
	 *
	 * @param string                              $title      Title of the schema.
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function schema( string $title, array $properties ): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $title,
			'type'       => 'object',
			'properties' => $properties,
		);
	}
}
