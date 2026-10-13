<?php
/**
 * Plugin settings storage.
 *
 * @package easyQ
 */

namespace EasyQ\Settings;

use EasyQ\Users\Levels;
use EasyQ\Users\Permits;

defined( 'ABSPATH' ) || exit;

/**
 * Stores settings grouped by section, one option per section.
 *
 * Stored values are merged with the defaults, so new settings work without an
 * upgrade routine. Unknown stored keys are ignored. Setting names follow the
 * Question2Answer option names, see docs/feature-mapping.md.
 */
final class Options {

	/**
	 * Prefix of the option names; the section name is appended.
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'easyq_settings_';

	/**
	 * Returns the default values of all sections.
	 *
	 * Deviations from the Question2Answer defaults are privacy related: anonymous
	 * posting and notifications are off by default.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = array(
			'general'     => array(
				'base_slug'                => 'questions',
				'tags_or_categories'       => 'tc',
				'read_only'                => false,
				'delete_data_on_uninstall' => false,
				// Q&A level of each WordPress role; roles not listed get the basic level.
				'role_levels'              => array(
					'administrator' => Levels::ADMIN,
					'editor'        => Levels::MODERATOR,
				),
			),
			'permissions' => array(
				'permit_view_q_page'                    => Permits::ALL,
				'allow_view_q_bots'                     => true,
				'permit_post_q'                         => Permits::USERS,
				'permit_post_a'                         => Permits::USERS,
				'permit_post_c'                         => Permits::USERS,
				'permit_vote_q'                         => Permits::USERS,
				'permit_vote_a'                         => Permits::USERS,
				'permit_vote_c'                         => Permits::USERS,
				'permit_vote_down'                      => Permits::USERS,
				'permit_retag_cat'                      => Permits::EDITORS,
				'permit_edit_q'                         => Permits::EDITORS,
				'permit_edit_a'                         => Permits::EXPERTS,
				'permit_edit_c'                         => Permits::EDITORS,
				'permit_edit_silent'                    => Permits::MODERATORS,
				'permit_close_q'                        => Permits::EDITORS,
				'permit_select_a'                       => Permits::EXPERTS,
				'permit_anon_view_ips'                  => Permits::EDITORS,
				'permit_view_voters_flaggers'           => Permits::ADMINS,
				'permit_flag'                           => Permits::CONFIRMED,
				'permit_moderate'                       => Permits::EXPERTS,
				'permit_hide_show'                      => Permits::EDITORS,
				'permit_delete_hidden'                  => Permits::MODERATORS,
				'permit_view_new_users_page'            => Permits::EDITORS,
				'permit_view_special_users_page'        => Permits::MODERATORS,
				'permit_post_wall'                      => Permits::CONFIRMED,
				'permit_post_q_points'                  => 0,
				'permit_post_a_points'                  => 0,
				'permit_post_c_points'                  => 0,
				'permit_vote_q_points'                  => 0,
				'permit_vote_a_points'                  => 0,
				'permit_vote_c_points'                  => 0,
				'permit_vote_down_points'               => 0,
				'permit_retag_cat_points'               => 0,
				'permit_edit_q_points'                  => 0,
				'permit_edit_a_points'                  => 0,
				'permit_edit_c_points'                  => 0,
				'permit_edit_silent_points'             => 0,
				'permit_close_q_points'                 => 0,
				'permit_select_a_points'                => 0,
				'permit_anon_view_ips_points'           => 0,
				'permit_view_voters_flaggers_points'    => 0,
				'permit_flag_points'                    => 0,
				'permit_moderate_points'                => 0,
				'permit_hide_show_points'               => 0,
				'permit_delete_hidden_points'           => 0,
				'permit_view_new_users_page_points'     => 0,
				'permit_view_special_users_page_points' => 0,
				'permit_post_wall_points'               => 0,
			),
			'users'       => array(
				'avatar_allow_upload' => true,
				'avatar_store_size'   => 400,
			),
			'posting'     => array(
				'allow_close_questions'     => true,
				'allow_close_own_questions' => true,
				'allow_self_answer'         => true,
				'allow_multi_answers'       => true,
				'follow_on_as'              => true,
				'comment_on_qs'             => false,
				'comment_on_as'             => true,
				'allow_anonymous_naming'    => true,
				'do_close_on_select'        => false,
				'min_len_q_title'           => 12,
				'max_len_q_title'           => 120,
				'min_len_q_content'         => 0,
				'min_len_a_content'         => 12,
				'min_len_c_content'         => 12,
				'min_num_q_tags'            => 0,
				'max_num_q_tags'            => 5,
				'tag_separator_comma'       => false,
				'notify_users_default'      => false,
				'block_bad_words'           => '',
				'do_ask_check_qs'           => false,
				'match_ask_check_qs'        => 3,
				'page_size_ask_check_qs'    => 5,
				'do_example_tags'           => true,
				'match_example_tags'        => 3,
				'do_complete_tags'          => true,
				'page_size_ask_tags'        => 5,
			),
			'viewing'     => array(
				'voting_on_qs'           => true,
				'voting_on_q_page_only'  => false,
				'voting_on_as'           => true,
				'voting_on_cs'           => false,
				'votes_separated'        => false,
				'do_count_q_views'       => true,
				'recalc_hotness_q_view'  => true,
				'show_url_links'         => true,
				'links_in_new_window'    => false,
				'q_urls_title_length'    => 50,
				'show_when_created'      => true,
				'show_full_date_days'    => 7,
				'show_user_points'       => true,
				'show_post_update_meta'  => true,
				'show_view_counts'       => false,
				'show_view_count_q_page' => false,
				'show_compact_numbers'   => true,
				'sort_answers_by'        => 'created',
				'show_selected_first'    => true,
				'page_size_q_as'         => 10,
			),
			'lists'       => array(
				'hot_weight_q_age'   => 100,
				'hot_weight_a_age'   => 100,
				'hot_weight_answers' => 100,
				'hot_weight_votes'   => 100,
				'hot_weight_views'   => 100,
				'search_provider'    => 'basic',
				'page_size_qs'       => 20,
				'page_size_search'   => 10,
				'page_size_tags'     => 30,
				'page_size_users'    => 30,
			),
			'points'      => array(
				'points_base'             => 100,
				'points_multiple'         => 10,
				'points_post_q'           => 2,
				'points_select_a'         => 3,
				'points_per_q_voted_up'   => 1,
				'points_per_q_voted_down' => 1,
				'points_q_voted_max_gain' => 10,
				'points_q_voted_max_loss' => 3,
				'points_post_a'           => 4,
				'points_a_selected'       => 30,
				'points_per_a_voted_up'   => 2,
				'points_per_a_voted_down' => 2,
				'points_a_voted_max_gain' => 20,
				'points_a_voted_max_loss' => 5,
				'points_per_c_voted_up'   => 0,
				'points_per_c_voted_down' => 0,
				'points_c_voted_max_gain' => 10,
				'points_c_voted_max_loss' => 3,
				'points_vote_up_q'        => 1,
				'points_vote_down_q'      => 1,
				'points_vote_up_a'        => 1,
				'points_vote_down_a'      => 1,
			),
			'spam'        => array(
				'confirm_user_emails'    => true,
				'confirm_user_required'  => false,
				'moderate_users'         => false,
				'flagging_of_posts'      => true,
				'flagging_notify_first'  => 1,
				'flagging_notify_every'  => 2,
				'flagging_hide_after'    => 5,
				'moderate_anon_post'     => false,
				'moderate_unapproved'    => false,
				'moderate_unconfirmed'   => false,
				'moderate_by_points'     => false,
				'moderate_points_limit'  => 150,
				'moderate_edited_again'  => false,
				'moderate_notify_admin'  => true,
				'moderate_update_time'   => true,
				'block_ips_write'        => '',
				'max_rate_ip_qs'         => 20,
				'max_rate_user_qs'       => 10,
				'max_rate_ip_as'         => 50,
				'max_rate_user_as'       => 25,
				'max_rate_ip_cs'         => 40,
				'max_rate_user_cs'       => 20,
				'max_rate_ip_votes'      => 600,
				'max_rate_user_votes'    => 300,
				'max_rate_ip_flags'      => 10,
				'max_rate_user_flags'    => 5,
				'max_rate_ip_uploads'    => 20,
				'max_rate_user_uploads'  => 10,
				'max_rate_ip_messages'   => 10,
				'max_rate_user_messages' => 5,
				'max_rate_ip_registers'  => 5,
				'max_rate_ip_logins'     => 20,
				'ip_retention_days'      => 30,
				'ip_anonymize'           => false,
			),
		);

		/**
		 * Filters the default settings.
		 *
		 * @param array<string, array<string, mixed>> $defaults Default values keyed by section and setting.
		 */
		return (array) apply_filters( 'easyq_default_settings', $defaults );
	}

	/**
	 * Returns the names of all sections.
	 *
	 * @return string[]
	 */
	public static function sections(): array {
		return array_keys( self::defaults() );
	}

	/**
	 * Returns the option name of a section.
	 *
	 * @param string $section Section name.
	 * @return string
	 */
	public static function option_name( string $section ): string {
		return self::OPTION_PREFIX . $section;
	}

	/**
	 * Returns all settings of a section, merged with the defaults.
	 *
	 * @param string $section Section name.
	 * @return array<string, mixed>
	 */
	public static function get_section( string $section ): array {
		$values = self::defaults()[ $section ] ?? array();
		$stored = get_option( self::option_name( $section ), array() );

		if ( is_array( $stored ) ) {
			foreach ( array_keys( $values ) as $key ) {
				if ( array_key_exists( $key, $stored ) ) {
					$values[ $key ] = $stored[ $key ];
				}
			}
		}

		return $values;
	}

	/**
	 * Returns a single setting.
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @return mixed The value, or null if the setting does not exist.
	 */
	public static function get( string $section, string $key ): mixed {
		return self::get_section( $section )[ $key ] ?? null;
	}

	/**
	 * Returns a setting as an integer.
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @return int
	 */
	public static function get_int( string $section, string $key ): int {
		$value = self::get( $section, $key );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Returns a setting as a boolean.
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @return bool
	 */
	public static function get_bool( string $section, string $key ): bool {
		return (bool) self::get( $section, $key );
	}

	/**
	 * Returns a setting as a string.
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @return string
	 */
	public static function get_string( string $section, string $key ): string {
		$value = self::get( $section, $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Updates settings of a section; settings not passed keep their value.
	 *
	 * @param string               $section Section name.
	 * @param array<string, mixed> $values  New values keyed by setting name.
	 */
	public static function update( string $section, array $values ): void {
		update_option( self::option_name( $section ), array_merge( self::get_section( $section ), $values ) );
	}

	/**
	 * Adds the options of all sections with their defaults; existing values are kept.
	 */
	public static function install(): void {
		foreach ( self::defaults() as $section => $values ) {
			add_option( self::option_name( $section ), $values, '', true );
		}
	}

	/**
	 * Deletes the options of all sections.
	 */
	public static function delete_all(): void {
		foreach ( self::sections() as $section ) {
			delete_option( self::option_name( $section ) );
		}
	}
}
