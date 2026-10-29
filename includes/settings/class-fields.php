<?php
/**
 * Settings fields.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Settings;

use EasyQ\Services;
use EasyQ\Users\Levels;
use EasyQ\Users\Permits;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the settings an administrator can change: type, label, allowed values.
 *
 * The descriptions drive the settings screen, the sanitizing of saved values and
 * the REST schema. Ranges and choices follow the Question2Answer admin pages. A
 * setting gets a field once the feature behind it works; settings without a field
 * keep their stored value.
 *
 * @since 0.1.0
 *
 * @phpstan-type Field array{type: string, label: string, description?: string, min?: int, max?: int, choices?: array<int|string, string>, parent?: string, owner?: bool, short?: string}
 */
final class Fields {

	/**
	 * Longest user title in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_TITLE_LENGTH = 80;

	/**
	 * Permission settings with the widest and narrowest allowed threshold, and whether
	 * the thresholds with a points minimum are allowed (from Question2Answer).
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{0: int, 1: int, 2: bool}>
	 */
	private const PERMITS = array(
		'permit_view_q_page'             => array( Permits::ALL, Permits::APPROVED, false ),
		'permit_post_q'                  => array( Permits::ALL, Permits::EXPERTS, true ),
		'permit_post_a'                  => array( Permits::ALL, Permits::EXPERTS, true ),
		'permit_post_c'                  => array( Permits::ALL, Permits::EDITORS, true ),
		'permit_vote_q'                  => array( Permits::USERS, Permits::APPROVED_POINTS, true ),
		'permit_vote_a'                  => array( Permits::USERS, Permits::APPROVED_POINTS, true ),
		'permit_vote_c'                  => array( Permits::USERS, Permits::APPROVED_POINTS, true ),
		'permit_vote_down'               => array( Permits::USERS, Permits::EXPERTS, true ),
		'permit_flag'                    => array( Permits::USERS, Permits::EDITORS, true ),
		'permit_retag_cat'               => array( Permits::USERS, Permits::EDITORS, true ),
		'permit_edit_q'                  => array( Permits::USERS, Permits::EDITORS, true ),
		'permit_edit_a'                  => array( Permits::USERS, Permits::EDITORS, true ),
		'permit_edit_c'                  => array( Permits::USERS, Permits::MODERATORS, true ),
		'permit_edit_silent'             => array( Permits::EXPERTS, Permits::ADMINS, true ),
		'permit_close_q'                 => array( Permits::POINTS, Permits::MODERATORS, true ),
		'permit_select_a'                => array( Permits::POINTS, Permits::MODERATORS, true ),
		'permit_anon_view_ips'           => array( Permits::ALL, Permits::MODERATORS, true ),
		'permit_view_voters_flaggers'    => array( Permits::EXPERTS, Permits::SUPERS, true ),
		'permit_moderate'                => array( Permits::POINTS, Permits::MODERATORS, true ),
		'permit_hide_show'               => array( Permits::POINTS, Permits::MODERATORS, true ),
		'permit_delete_hidden'           => array( Permits::EDITORS, Permits::ADMINS, true ),
		'permit_view_new_users_page'     => array( Permits::ALL, Permits::SUPERS, false ),
		'permit_view_special_users_page' => array( Permits::ALL, Permits::SUPERS, false ),
		'permit_post_wall'               => array( Permits::USERS, Permits::APPROVED_POINTS, true ),
	);

	/**
	 * Returns the settings sections that have a tab, with their titles.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array(
			'general'     => __( 'General', 'easyq' ),
			'permissions' => __( 'Permissions', 'easyq' ),
			'users'       => __( 'Users', 'easyq' ),
			'posting'     => __( 'Posting', 'easyq' ),
			'viewing'     => __( 'Viewing', 'easyq' ),
			'lists'       => __( 'Lists', 'easyq' ),
			'feeds'       => __( 'Feeds', 'easyq' ),
			'spam'        => __( 'Spam', 'easyq' ),
		);
	}

	/**
	 * Returns every settings section with a screen, with its title: the tabs of the
	 * settings screen and the points.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public static function sections(): array {
		return self::tabs() + array( 'points' => __( 'Points', 'easyq' ) );
	}

	/**
	 * Returns the fields of a section in display order.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @return array<string, array> Fields keyed by setting name.
	 *
	 * @phpstan-return array<string, Field>
	 */
	public static function section( string $section ): array {
		return match ( $section ) {
			'general'     => self::general(),
			'permissions' => self::permissions(),
			'users'       => self::users(),
			'posting'     => self::posting(),
			'viewing'     => self::viewing(),
			'lists'       => self::lists(),
			'feeds'       => self::feeds(),
			'spam'        => self::spam(),
			'points'      => self::points(),
			default       => array(),
		};
	}

	/**
	 * Sanitizes the values of a section before they are stored.
	 *
	 * Values without a field are kept as passed, values of fields that are invalid
	 * keep their stored value. A field that is not passed keeps its stored value too.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @param mixed  $input   Submitted values keyed by setting name.
	 * @return array<string, mixed>
	 */
	public static function sanitize( string $section, mixed $input ): array {
		$values = Options::get_section( $section );
		if ( ! is_array( $input ) ) {
			return $values;
		}

		$fields = self::section( $section );
		foreach ( $values as $key => $current ) {
			if ( ! array_key_exists( $key, $input ) || ( isset( $fields[ $key ] ) && ! self::may_change( $fields[ $key ] ) ) ) {
				continue;
			}

			$values[ $key ] = isset( $fields[ $key ] ) ? self::sanitize_value( $fields[ $key ], $input[ $key ], $current ) : $input[ $key ];
		}

		return $values;
	}

	/**
	 * Checks whether the current user may change a field.
	 *
	 * Fields of the site owner, such as the Q&A level of each role, need `manage_options`:
	 * with the level of whole roles, users who manage easyQ could otherwise make others
	 * administrators, which Question2Answer does not let administrators do. Without a
	 * logged-in user, as in WP-CLI, every field may change.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $field Field.
	 * @phpstan-param Field $field
	 * @return bool
	 */
	public static function may_change( array $field ): bool {
		return empty( $field['owner'] ) || ! is_user_logged_in() || current_user_can( 'manage_options' );
	}

	/**
	 * Returns the REST schema of a section.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section name.
	 * @return array<string, mixed>
	 */
	public static function schema( string $section ): array {
		$properties = array();

		foreach ( self::section( $section ) as $key => $field ) {
			$property = array( 'description' => $field['label'] );

			switch ( $field['type'] ) {
				case 'bool':
					$property['type'] = 'boolean';
					break;
				case 'int':
					$property['type']    = 'integer';
					$property['minimum'] = $field['min'] ?? 0;
					if ( isset( $field['max'] ) ) {
						$property['maximum'] = $field['max'];
					}
					break;
				case 'choice':
					$choices          = array_keys( $field['choices'] ?? array() );
					$property['type'] = is_int( $choices[0] ?? null ) ? 'integer' : 'string';
					$property['enum'] = $choices;
					break;
				case 'role_levels':
					$property['type']                 = 'object';
					$property['additionalProperties'] = array(
						'type' => 'integer',
						'enum' => array_keys( self::role_level_choices() ),
					);
					break;
				case 'titles':
					// Titles keyed by the points needed. The core settings endpoint forbids other keys
					// already; the schema says so for every other reader too.
					$property['type']                 = 'object';
					$property['patternProperties']    = array(
						'^\\d+$' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => self::MAX_TITLE_LENGTH,
						),
					);
					$property['additionalProperties'] = false;
					break;
				default:
					$property['type'] = 'string';
			}

			$properties[ $key ] = $property;
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}

	/**
	 * Sanitizes a single value.
	 *
	 * @since 0.1.0
	 *
	 * @param array $field   Field.
	 * @param mixed $value   Submitted value.
	 * @param mixed $current Stored value, kept if the submitted one is invalid.
	 * @return mixed
	 *
	 * @phpstan-param Field $field
	 */
	private static function sanitize_value( array $field, mixed $value, mixed $current ): mixed {
		switch ( $field['type'] ) {
			case 'bool':
				return is_bool( $value ) || is_int( $value ) || is_string( $value ) ? rest_sanitize_boolean( $value ) : false;

			case 'int':
				if ( ! is_numeric( $value ) ) {
					return $current;
				}
				$value = max( $field['min'] ?? 0, (int) $value );

				return isset( $field['max'] ) ? min( $field['max'], $value ) : $value;

			case 'choice':
				foreach ( array_keys( $field['choices'] ?? array() ) as $choice ) {
					if ( is_scalar( $value ) && (string) $choice === (string) $value ) {
						return $choice;
					}
				}

				return $current;

			case 'slug':
				$slug = is_string( $value ) ? sanitize_title( $value ) : '';

				return '' === $slug ? $current : $slug;

			case 'lines':
				return is_string( $value ) ? sanitize_textarea_field( $value ) : $current;

			case 'role_levels':
				if ( ! is_array( $value ) ) {
					return $current;
				}

				$levels = array();
				foreach ( array_keys( wp_roles()->get_names() ) as $role ) {
					$level = $value[ $role ] ?? null;
					if ( is_numeric( $level ) && Levels::BASIC !== (int) $level && isset( self::role_level_choices()[ (int) $level ] ) ) {
						$levels[ $role ] = (int) $level;
					}
				}

				return $levels;

			case 'titles':
				return is_array( $value ) ? self::titles( $value ) : $current;

			default:
				return is_string( $value ) ? sanitize_text_field( $value ) : $current;
		}
	}

	/**
	 * Returns the levels a role can grant; the super administrator level comes only with multisite.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	public static function role_level_choices(): array {
		return array_filter( Levels::labels(), static fn ( int $level ): bool => $level < Levels::SUPER, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Returns a yes/no field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $label       Label.
	 * @param string $description Optional description.
	 * @return array
	 *
	 * @phpstan-return Field
	 */
	private static function bool( string $label, string $description = '' ): array {
		$field = array(
			'type'  => 'bool',
			'label' => $label,
		);
		if ( '' !== $description ) {
			$field['description'] = $description;
		}

		return $field;
	}

	/**
	 * Returns a number field.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $label       Label.
	 * @param int      $min         Smallest allowed value.
	 * @param int|null $max         Largest allowed value, or null for no limit.
	 * @param string   $description Optional description.
	 * @return array
	 *
	 * @phpstan-return Field
	 */
	private static function int( string $label, int $min = 0, ?int $max = null, string $description = '' ): array {
		$field = array(
			'type'  => 'int',
			'label' => $label,
			'min'   => $min,
		);
		if ( null !== $max ) {
			$field['max'] = $max;
		}
		if ( '' !== $description ) {
			$field['description'] = $description;
		}

		return $field;
	}

	/**
	 * Returns a selection field.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $label       Label.
	 * @param array<int|string, string> $choices     Allowed values with their names.
	 * @param string                    $description Optional description.
	 * @return array
	 *
	 * @phpstan-return Field
	 */
	private static function choice( string $label, array $choices, string $description = '' ): array {
		$field = array(
			'type'    => 'choice',
			'label'   => $label,
			'choices' => $choices,
		);
		if ( '' !== $description ) {
			$field['description'] = $description;
		}

		return $field;
	}

	/**
	 * Returns the choices of the similarity settings (from Question2Answer).
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, string>
	 */
	private static function match_choices(): array {
		return array(
			5 => __( 'Widest', 'easyq' ),
			4 => __( 'Wider', 'easyq' ),
			3 => __( 'Default', 'easyq' ),
			2 => __( 'Narrower', 'easyq' ),
			1 => __( 'Narrowest', 'easyq' ),
		);
	}

	/**
	 * Returns the fields of the general section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function general(): array {
		return array(
			'base_slug'                => array(
				'type'        => 'slug',
				'label'       => __( 'Address of the Q&A area', 'easyq' ),
				/* translators: %s: example address. */
				'description' => sprintf( __( 'The Q&A pages live below this address, for example %s.', 'easyq' ), home_url( '/questions/' ) ),
			),
			'tags_or_categories'       => self::choice(
				__( 'Organize questions by', 'easyq' ),
				array(
					'tc' => __( 'Tags and categories', 'easyq' ),
					't'  => __( 'Tags', 'easyq' ),
					'c'  => __( 'Categories', 'easyq' ),
					''   => __( 'Neither', 'easyq' ),
				)
			),
			'read_only'                => self::bool(
				__( 'Read-only mode', 'easyq' ),
				__( 'Only users who manage easyQ can post, vote or change anything, for example during maintenance.', 'easyq' )
			),
			'role_levels'              => array(
				'type'        => 'role_levels',
				'label'       => __( 'Q&A level of each role', 'easyq' ),
				'description' => __( 'Users get the level of their role unless their profile sets a higher one.', 'easyq' ),
				'owner'       => true,
			),
			'delete_data_on_uninstall' => array( 'owner' => true ) + self::bool(
				__( 'Delete all Q&A data when the plugin is deleted', 'easyq' ),
				__( 'Removes the questions, answers, comments, votes, settings and user data of easyQ.', 'easyq' )
			),
		);
	}

	/**
	 * Returns the fields of the permissions section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function permissions(): array {
		$labels = array(
			'permit_view_q_page'             => __( 'Viewing questions', 'easyq' ),
			'permit_post_q'                  => __( 'Asking questions', 'easyq' ),
			'permit_post_a'                  => __( 'Answering questions', 'easyq' ),
			'permit_post_c'                  => __( 'Adding comments', 'easyq' ),
			'permit_vote_q'                  => __( 'Voting on questions', 'easyq' ),
			'permit_vote_a'                  => __( 'Voting on answers', 'easyq' ),
			'permit_vote_c'                  => __( 'Voting on comments', 'easyq' ),
			'permit_vote_down'               => __( 'Voting posts down', 'easyq' ),
			'permit_flag'                    => __( 'Flagging posts', 'easyq' ),
			'permit_retag_cat'               => __( 'Changing tags or categories of any question', 'easyq' ),
			'permit_edit_q'                  => __( 'Editing any question', 'easyq' ),
			'permit_edit_a'                  => __( 'Editing any answer', 'easyq' ),
			'permit_edit_c'                  => __( 'Editing any comment', 'easyq' ),
			'permit_edit_silent'             => __( 'Editing posts without showing it', 'easyq' ),
			'permit_close_q'                 => __( 'Closing any question', 'easyq' ),
			'permit_select_a'                => __( 'Selecting the answer of any question', 'easyq' ),
			'permit_anon_view_ips'           => __( 'Viewing IP addresses of anonymous posts', 'easyq' ),
			'permit_view_voters_flaggers'    => __( 'Viewing who voted or flagged', 'easyq' ),
			'permit_moderate'                => __( 'Approving or rejecting posts', 'easyq' ),
			'permit_hide_show'               => __( 'Hiding or showing any post', 'easyq' ),
			'permit_delete_hidden'           => __( 'Deleting hidden posts', 'easyq' ),
			'permit_view_new_users_page'     => __( 'Viewing the list of new users', 'easyq' ),
			'permit_view_special_users_page' => __( 'Viewing the list of special users', 'easyq' ),
			'permit_post_wall'               => __( 'Posting on user walls', 'easyq' ),
		);

		// Shorter names for the narrow title column of the settings screen.
		$shorts = array(
			'permit_retag_cat'               => __( 'Changing tags or categories', 'easyq' ),
			'permit_edit_silent'             => __( 'Editing silently', 'easyq' ),
			'permit_select_a'                => __( 'Selecting best answers', 'easyq' ),
			'permit_anon_view_ips'           => __( 'Viewing IP addresses', 'easyq' ),
			'permit_view_voters_flaggers'    => __( 'Viewing voters and flaggers', 'easyq' ),
			'permit_view_new_users_page'     => __( 'Viewing new users', 'easyq' ),
			'permit_view_special_users_page' => __( 'Viewing special users', 'easyq' ),
		);

		$fields = array();
		foreach ( self::PERMITS as $key => [ $widest, $narrowest, $points ] ) {
			$choices = array_filter(
				Permits::labels(),
				static fn ( int $permit ): bool => $permit <= $widest && $permit >= $narrowest && ( $points || ! Permits::uses_points( $permit ) ),
				ARRAY_FILTER_USE_KEY
			);

			$fields[ $key ] = self::choice( $labels[ $key ], $choices ) + ( isset( $shorts[ $key ] ) ? array( 'short' => $shorts[ $key ] ) : array() );
			if ( $points ) {
				$fields[ $key . '_points' ] = array(
					'type'   => 'int',
					/* translators: %s: name of a permission, e.g. "Asking questions". */
					'label'  => sprintf( __( 'Points needed for: %s', 'easyq' ), $labels[ $key ] ),
					'min'    => 0,
					'parent' => $key,
				);
			}
		}

		return $fields;
	}

	/**
	 * Returns the fields of the points section, in the order of Question2Answer.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function points(): array {
		$fields = array();
		foreach ( array(
			'points_post_q'           => __( 'Posting a question', 'easyq' ),
			'points_select_a'         => __( 'Selecting an answer for your question', 'easyq' ),
			'points_per_q_voted_up'   => __( 'Per up vote on your question', 'easyq' ),
			'points_per_q_voted_down' => __( 'Per down vote on your question', 'easyq' ),
			'points_q_voted_max_gain' => __( 'Limit from up votes on each question', 'easyq' ),
			'points_q_voted_max_loss' => __( 'Limit from down votes on each question', 'easyq' ),
			'points_post_a'           => __( 'Posting an answer', 'easyq' ),
			'points_a_selected'       => __( 'Having your answer selected as the best', 'easyq' ),
			'points_per_a_voted_up'   => __( 'Per up vote on your answer', 'easyq' ),
			'points_per_a_voted_down' => __( 'Per down vote on your answer', 'easyq' ),
			'points_a_voted_max_gain' => __( 'Limit from up votes on each answer', 'easyq' ),
			'points_a_voted_max_loss' => __( 'Limit from down votes on each answer', 'easyq' ),
			'points_per_c_voted_up'   => __( 'Per up vote on your comment', 'easyq' ),
			'points_per_c_voted_down' => __( 'Per down vote on your comment', 'easyq' ),
			'points_c_voted_max_gain' => __( 'Limit from up votes on each comment', 'easyq' ),
			'points_c_voted_max_loss' => __( 'Limit from down votes on each comment', 'easyq' ),
			'points_vote_up_q'        => __( 'Voting up a question', 'easyq' ),
			'points_vote_down_q'      => __( 'Voting down a question', 'easyq' ),
			'points_vote_up_a'        => __( 'Voting up an answer', 'easyq' ),
			'points_vote_down_a'      => __( 'Voting down an answer', 'easyq' ),
		) as $key => $label ) {
			$fields[ $key ] = self::int( $label, 0, 100000 );
		}

		// Multiplying by 0 would take all points away; Question2Answer does not prevent it.
		$fields['points_multiple'] = self::int( __( 'Multiply all points by', 'easyq' ), 1, 1000, __( 'Every value above counts this many times.', 'easyq' ) );
		$fields['points_base']     = self::int( __( 'Add for all users', 'easyq' ), 0, 100000, __( 'Points every user starts with, not multiplied.', 'easyq' ) );
		$fields['user_titles']     = array(
			'type'        => 'titles',
			'label'       => __( 'User titles', 'easyq' ),
			'description' => __( 'Titles that users earn with points, keyed by the points needed.', 'easyq' ),
		);

		return $fields;
	}

	/**
	 * Sanitizes user titles: an object of titles keyed by the points needed, or rows
	 * with `points` and `title` as the form sends them. Rows without a title are
	 * removed; of several titles for the same points, the first one is kept.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $value Submitted titles.
	 * @return array<int, string> Titles keyed by points, the highest first.
	 */
	private static function titles( array $value ): array {
		$titles = array();
		foreach ( $value as $key => $row ) {
			[ $points, $title ] = is_array( $row ) ? array( $row['points'] ?? null, $row['title'] ?? null ) : array( $key, $row );
			$title              = is_string( $title ) ? sanitize_text_field( $title ) : '';
			if ( '' === $title || ! is_numeric( $points ) || (int) $points < 0 ) {
				continue;
			}

			$points = (int) $points;
			if ( isset( $titles[ $points ] ) ) {
				if ( function_exists( 'add_settings_error' ) ) {
					/* translators: %s: number of points. */
					add_settings_error( 'easyq_points', 'easyq-title-points', sprintf( __( 'Only one title can need %s points; the first one was kept.', 'easyq' ), number_format_i18n( $points ) ), 'warning' );
				}
				continue;
			}

			$titles[ $points ] = mb_substr( $title, 0, self::MAX_TITLE_LENGTH );
		}

		krsort( $titles, SORT_NUMERIC );

		return $titles;
	}

	/**
	 * Returns the fields of the users section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function users(): array {
		return array(
			'avatar_allow_upload' => self::bool( __( 'Allow users to upload an avatar', 'easyq' ) ),
			'avatar_store_size'   => self::int( __( 'Largest stored avatar size, in pixels', 'easyq' ), 16, 1000 ),
		);
	}

	/**
	 * Returns the fields of the posting section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function posting(): array {
		return array(
			'allow_close_questions'     => self::bool( __( 'Allow questions to be closed', 'easyq' ) ),
			'allow_close_own_questions' => self::bool( __( 'Allow users to close their own questions', 'easyq' ) ),
			'do_close_on_select'        => self::bool( __( 'Close questions when an answer is selected', 'easyq' ) ),
			'allow_self_answer'         => self::bool( __( 'Allow users to answer their own questions', 'easyq' ) ),
			'allow_multi_answers'       => self::bool( __( 'Allow more than one answer per user to a question', 'easyq' ) ),
			'follow_on_as'              => self::bool( __( 'Allow follow-on questions to answers', 'easyq' ) ),
			'comment_on_qs'             => self::bool( __( 'Allow comments on questions', 'easyq' ) ),
			'comment_on_as'             => self::bool( __( 'Allow comments on answers', 'easyq' ) ),
			'allow_anonymous_naming'    => self::bool( __( 'Allow visitors to give a name with their posts', 'easyq' ) ),
			'notify_users_default'      => self::bool(
				__( 'Preselect email notifications on the post forms', 'easyq' ),
				__( 'Users can still turn them off for each post.', 'easyq' )
			),
			'allow_no_category'         => self::bool(
				__( 'Allow questions without a category', 'easyq' ),
				__( 'Only applies once categories exist.', 'easyq' )
			),
			'allow_no_sub_category'     => self::bool( __( 'Allow questions in a category that has subcategories', 'easyq' ) ),
			'min_len_q_title'           => self::int( __( 'Minimum length of question titles, in characters', 'easyq' ), 1 ),
			'max_len_q_title'           => self::int( __( 'Maximum length of question titles, in characters', 'easyq' ), 1, 800 ),
			'min_len_q_content'         => self::int( __( 'Minimum length of questions, in characters', 'easyq' ) ),
			'min_len_a_content'         => self::int( __( 'Minimum length of answers, in characters', 'easyq' ), 1 ),
			'min_len_c_content'         => self::int( __( 'Minimum length of comments, in characters', 'easyq' ), 1 ),
			'min_num_q_tags'            => self::int( __( 'Minimum number of tags per question', 'easyq' ) ),
			'max_num_q_tags'            => self::int( __( 'Maximum number of tags per question', 'easyq' ), 2 ),
			'tag_separator_comma'       => self::bool( __( 'Separate tags with commas instead of spaces', 'easyq' ) ),
			'block_bad_words'           => array(
				'type'        => 'lines',
				'label'       => __( 'Censored words', 'easyq' ),
				'description' => __( 'Separate words with spaces or commas. Use * as a wildcard, e.g. bad*.', 'easyq' ),
			),
			'do_ask_check_qs'           => self::bool( __( 'Show similar questions while asking', 'easyq' ) ),
			'match_ask_check_qs'        => self::choice( __( 'Similar questions matching', 'easyq' ), self::match_choices() ) + array( 'short' => __( 'how closely they must match', 'easyq' ) ),
			'page_size_ask_check_qs'    => self::int( __( 'Number of similar questions', 'easyq' ), 3, 50 ),
			'do_example_tags'           => self::bool( __( 'Suggest tags from similar questions while asking', 'easyq' ) ),
			'match_example_tags'        => self::choice( __( 'Example tags matching', 'easyq' ), self::match_choices() ) + array( 'short' => __( 'how closely the questions must match', 'easyq' ) ),
			'page_size_ask_tags'        => self::int( __( 'Number of suggested tags', 'easyq' ), 3, 50 ),
		);
	}

	/**
	 * Returns the fields of the viewing section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function viewing(): array {
		return array(
			'q_urls_title_length'   => self::int( __( 'Length of the question title in addresses, in characters', 'easyq' ), 1 ),
			'do_count_q_views'      => self::bool( __( 'Count and show question views', 'easyq' ), __( 'No IP addresses are stored for this.', 'easyq' ) ),
			'recalc_hotness_q_view' => self::bool( __( 'Update the hotness of a question when it is viewed', 'easyq' ) ),
			'voting_on_qs'          => self::bool( __( 'Allow voting on questions', 'easyq' ) ),
			'voting_on_as'          => self::bool( __( 'Allow voting on answers', 'easyq' ) ),
			'voting_on_cs'          => self::bool( __( 'Allow voting on comments', 'easyq' ) ),
			'votes_separated'       => self::bool( __( 'Show up and down votes separately', 'easyq' ) ),
			'show_url_links'        => self::bool( __( 'Turn web addresses in posts into links', 'easyq' ) ),
			'links_in_new_window'   => self::bool( __( 'Open links in posts in a new window', 'easyq' ) ),
			'show_full_date_days'   => self::int( __( 'Show the full date after this many days', 'easyq' ) ),
			'show_user_points'      => self::bool( __( 'Show the points of authors', 'easyq' ) ),
			'show_post_update_meta' => self::bool( __( 'Show when and by whom a post was changed', 'easyq' ) ),
			'show_compact_numbers'  => self::bool( __( 'Show large numbers in short form, e.g. 1.2k', 'easyq' ) ),
			'sort_answers_by'       => self::choice(
				__( 'Sort answers by', 'easyq' ),
				array(
					'created' => __( 'Time', 'easyq' ),
					'votes'   => __( 'Votes', 'easyq' ),
				)
			) + array( 'short' => __( 'decides the order of answers', 'easyq' ) ),
			'show_selected_first'   => self::bool( __( 'Show the selected answer first', 'easyq' ) ),
			'page_size_q_as'        => self::int( __( 'Answers per page', 'easyq' ), 1, 50 ),
			'show_fewer_cs_from'    => self::int( __( 'Shorten comment lists from this many comments', 'easyq' ), 1 ),
			'show_fewer_cs_count'   => self::int( __( 'Comments shown in a shortened list', 'easyq' ), 1 ),
			'sitemap_users'         => self::bool( __( 'List user profiles in the sitemaps', 'easyq' ) ),
		);
	}

	/**
	 * Returns the fields of the lists section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function lists(): array {
		$fields = array(
			'page_size_qs'       => self::int( __( 'Questions per page', 'easyq' ), 1, 50 ) + array( 'short' => __( 'Questions', 'easyq' ) ),
			'page_size_activity' => self::int( __( 'Items per page of the activity list', 'easyq' ), 1, 50 ) + array( 'short' => __( 'Activity', 'easyq' ) ),
			'page_size_tags'     => self::int( __( 'Tags per page', 'easyq' ), 1, 200 ) + array( 'short' => __( 'Tags', 'easyq' ) ),
			'page_size_users'    => self::int( __( 'Users per page', 'easyq' ), 1, 200 ) + array( 'short' => __( 'Users', 'easyq' ) ),
		);

		$providers = array();
		foreach ( Services::search()->providers() as $id => $provider ) {
			$providers[ $id ] = $provider->label();
		}
		// The choice appears once another search provider is registered.
		if ( count( $providers ) > 1 ) {
			$fields['search_provider'] = self::choice( __( 'Search with', 'easyq' ), $providers );
		}

		$hotness = __( 'Weights in percent. A change applies to a question the next time it is answered, voted on or viewed; Recount posts under easyQ → Tools applies it to all questions at once.', 'easyq' );

		return $fields + array(
			'hot_weight_q_age'   => self::int( __( 'Hotness: question is new', 'easyq' ), 0, null, $hotness ) + array( 'short' => __( 'Question is new', 'easyq' ) ),
			'hot_weight_a_age'   => self::int( __( 'Hotness: question has a new answer', 'easyq' ), 0, null, $hotness ) + array( 'short' => __( 'Latest answer is new', 'easyq' ) ),
			'hot_weight_answers' => self::int( __( 'Hotness: question has many answers', 'easyq' ), 0, null, $hotness ) + array( 'short' => __( 'Many answers', 'easyq' ) ),
			'hot_weight_votes'   => self::int( __( 'Hotness: question has many up votes', 'easyq' ), 0, null, $hotness ) + array( 'short' => __( 'Many up votes', 'easyq' ) ),
			'hot_weight_views'   => self::int( __( 'Hotness: question has many views', 'easyq' ), 0, null, $hotness ) + array( 'short' => __( 'Many views', 'easyq' ) ),
		);
	}

	/**
	 * Returns the fields of the feeds section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function feeds(): array {
		return array(
			'feed_for_questions'  => self::bool( __( 'Feed of recent questions', 'easyq' ) ),
			'feed_for_qa'         => self::bool( __( 'Feed of recent questions and answers', 'easyq' ) ),
			'feed_for_activity'   => self::bool( __( 'Feed of recent activity', 'easyq' ) ),
			'feed_for_hot'        => self::bool( __( 'Feed of hot questions', 'easyq' ) ),
			'feed_for_unanswered' => self::bool( __( 'Feed of unanswered questions', 'easyq' ) ),
			'feed_for_tag_qs'     => self::bool( __( 'Feed for each tag', 'easyq' ) ),
			'feed_per_category'   => self::bool( __( 'Feeds for each category', 'easyq' ) ),
			'feed_for_search'     => self::bool( __( 'Feed for search results', 'easyq' ) ),
			'feed_number_items'   => self::int( __( 'Items per feed', 'easyq' ), 1, 50 ),
			'feed_full_text'      => self::bool( __( 'Include the full text of posts', 'easyq' ) ),
		);
	}

	/**
	 * Returns the fields of the spam section.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array>
	 *
	 * @phpstan-return array<string, Field>
	 */
	private static function spam(): array {
		$limits = array(
			'max_rate_ip_qs'         => __( 'Questions per IP address', 'easyq' ),
			'max_rate_user_qs'       => __( 'Questions per user', 'easyq' ),
			'max_rate_ip_as'         => __( 'Answers per IP address', 'easyq' ),
			'max_rate_user_as'       => __( 'Answers per user', 'easyq' ),
			'max_rate_ip_cs'         => __( 'Comments per IP address', 'easyq' ),
			'max_rate_user_cs'       => __( 'Comments per user', 'easyq' ),
			'max_rate_ip_votes'      => __( 'Votes per IP address', 'easyq' ),
			'max_rate_user_votes'    => __( 'Votes per user', 'easyq' ),
			'max_rate_ip_flags'      => __( 'Flags per IP address', 'easyq' ),
			'max_rate_user_flags'    => __( 'Flags per user', 'easyq' ),
			'max_rate_ip_uploads'    => __( 'Uploads per IP address', 'easyq' ),
			'max_rate_user_uploads'  => __( 'Uploads per user', 'easyq' ),
			'max_rate_ip_messages'   => __( 'Messages per IP address', 'easyq' ),
			'max_rate_user_messages' => __( 'Messages per user', 'easyq' ),
			'max_rate_ip_registers'  => __( 'Registrations per IP address', 'easyq' ),
			'max_rate_ip_logins'     => __( 'Login attempts per IP address', 'easyq' ),
		);

		$fields = array(
			'confirm_user_emails'   => self::bool( __( 'Ask users to confirm their email address', 'easyq' ) ),
			'confirm_user_required' => self::bool( __( 'Require a confirmed email address to take part', 'easyq' ) ),
			'moderate_users'        => self::bool( __( 'Approve new users before they take part', 'easyq' ) ),
			'moderate_anon_post'    => self::bool( __( 'Moderate posts by visitors', 'easyq' ) ),
			'moderate_unapproved'   => self::bool( __( 'Moderate posts by users awaiting approval', 'easyq' ) ),
			'moderate_unconfirmed'  => self::bool( __( 'Moderate posts by users with an unconfirmed email address', 'easyq' ) ),
			'moderate_by_points'    => self::bool( __( 'Moderate posts by users with few points', 'easyq' ) ),
			'moderate_points_limit' => self::int( __( 'Points needed to post without moderation', 'easyq' ) ),
			'moderate_edited_again' => self::bool( __( 'Moderate posts again after they are edited', 'easyq' ) ),
			'moderate_update_time'  => self::bool( __( 'Date approved posts at the time of approval', 'easyq' ) ),
			'flagging_of_posts'     => self::bool( __( 'Allow users to flag posts', 'easyq' ) ),
			'flagging_hide_after'   => self::int( __( 'Hide a post after this many flags', 'easyq' ), 2 ),
			'block_ips_write'       => array(
				'type'        => 'lines',
				'label'       => __( 'Blocked IP addresses', 'easyq' ),
				'description' => __( 'These addresses can read but not post or vote. Separate them with commas; use * as a wildcard or a hyphen for a range.', 'easyq' ),
			),
			'ip_anonymize'          => self::bool( __( 'Store IP addresses shortened', 'easyq' ), __( 'Removes the last part of each stored address.', 'easyq' ) ),
		);

		foreach ( $limits as $key => $label ) {
			// The section says that limits count per hour; in their rows, the labels say what they limit.
			$fields[ $key ] = self::int( $label, 'max_rate_ip_logins' === $key ? 1 : 0 ) + array(
				'short' => match ( true ) {
					'max_rate_ip_registers' === $key => __( 'registrations per IP address', 'easyq' ),
					'max_rate_ip_logins' === $key    => __( 'login attempts per IP address', 'easyq' ),
					str_contains( $key, '_ip_' )     => __( 'per IP address', 'easyq' ),
					default                          => __( 'per user', 'easyq' ),
				},
			);
		}

		return $fields;
	}
}
