<?php
/**
 * Layout of the settings tabs.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Admin;

use EasyQ\Settings\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Arranges the fields of each settings tab as core arranges its settings screens:
 * sections with headings, as under Settings → Writing, and related switches and
 * numbers in one row with a short title, as under Settings → Discussion.
 *
 * @since 0.1.0
 *
 * Switches always share a row with a short title, even alone, so that their text
 * stands beside them, as under Settings → Reading.
 */
final class Settings_Layout {

	/**
	 * Returns the sections of a tab. Fields the layout does not name go to the last section.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tab Settings section.
	 * @return array<int, array{title: string, description: string, rows: array<int, string|array{title: string, keys: string[]}>}>
	 */
	public static function sections( string $tab ): array {
		$sections = self::layout( $tab );
		$named    = array();
		foreach ( $sections as $section ) {
			foreach ( $section['rows'] as $row ) {
				$named = array_merge( $named, is_string( $row ) ? array( $row ) : $row['keys'] );
			}
		}

		$fields = Fields::section( $tab );
		$rest   = array_keys( array_filter( $fields, static fn ( array $field ): bool => ! isset( $field['parent'] ) ) );
		$rest   = array_values( array_diff( $rest, $named ) );
		if ( array() !== $rest ) {
			if ( array() === $sections ) {
				$sections[] = self::section( '', array() );
			}
			$last                      = array_key_last( $sections );
			$sections[ $last ]['rows'] = array_merge( $sections[ $last ]['rows'], $rest );
		}

		// Leave out fields that do not exist, e.g. a search choice with a single provider.
		foreach ( $sections as $index => $section ) {
			$rows = array();
			foreach ( $section['rows'] as $row ) {
				if ( is_string( $row ) ) {
					if ( isset( $fields[ $row ] ) ) {
						$rows[] = $row;
					}
					continue;
				}

				$row['keys'] = array_values( array_filter( $row['keys'], static fn ( string $key ): bool => isset( $fields[ $key ] ) ) );
				if ( array() !== $row['keys'] ) {
					$rows[] = $row;
				}
			}
			$sections[ $index ]['rows'] = $rows;
		}

		return array_values( array_filter( $sections, static fn ( array $section ): bool => array() !== $section['rows'] ) );
	}

	/**
	 * Returns a row that groups several settings under one title.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $title Short title of the row.
	 * @param string[] $keys  Settings in the row.
	 * @return array{title: string, keys: string[]}
	 */
	private static function row( string $title, array $keys ): array {
		return array(
			'title' => $title,
			'keys'  => $keys,
		);
	}

	/**
	 * Returns a section.
	 *
	 * @since 0.1.0
	 *
	 * @param string                                                  $title       Heading; empty for none.
	 * @param array<int, string|array{title: string, keys: string[]}> $rows        Settings and rows.
	 * @param string                                                  $description Text below the heading; empty for none.
	 * @return array{title: string, description: string, rows: array<int, string|array{title: string, keys: string[]}>}
	 */
	private static function section( string $title, array $rows, string $description = '' ): array {
		return array(
			'title'       => $title,
			'description' => $description,
			'rows'        => $rows,
		);
	}

	/**
	 * Returns the arranged sections of a tab.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tab Settings section.
	 * @return array<int, array{title: string, description: string, rows: array<int, string|array{title: string, keys: string[]}>}>
	 */
	private static function layout( string $tab ): array {
		return match ( $tab ) {
			'general'     => array(
				self::section( '', array( 'base_slug', 'tags_or_categories', self::row( __( 'Maintenance', 'easyq' ), array( 'read_only' ) ) ) ),
				self::section( __( 'Q&A level of each role', 'easyq' ), array( 'role_levels' ), __( 'Users get the level of their role unless their profile sets a higher one.', 'easyq' ) ),
				self::section( __( 'Data', 'easyq' ), array( self::row( __( 'Uninstalling', 'easyq' ), array( 'delete_data_on_uninstall' ) ) ) ),
			),
			'permissions' => array(
				self::section( __( 'Viewing and posting', 'easyq' ), array( 'permit_view_q_page', 'permit_post_q', 'permit_post_a', 'permit_post_c' ) ),
				self::section( __( 'Voting and flagging', 'easyq' ), array( 'permit_vote_q', 'permit_vote_a', 'permit_vote_c', 'permit_vote_down', 'permit_flag' ) ),
				self::section( __( 'Editing and closing', 'easyq' ), array( 'permit_retag_cat', 'permit_edit_q', 'permit_edit_a', 'permit_edit_c', 'permit_edit_silent', 'permit_close_q', 'permit_select_a' ) ),
				self::section( __( 'Moderation', 'easyq' ), array( 'permit_moderate', 'permit_hide_show', 'permit_delete_hidden', 'permit_anon_view_ips', 'permit_view_voters_flaggers' ) ),
				self::section( __( 'Users', 'easyq' ), array( 'permit_view_new_users_page', 'permit_view_special_users_page', 'permit_post_wall' ) ),
			),
			'users'       => array(
				self::section( '', array( self::row( __( 'Avatars', 'easyq' ), array( 'avatar_allow_upload', 'avatar_store_size' ) ) ) ),
			),
			'posting'     => array(
				self::section(
					__( 'Questions, answers and comments', 'easyq' ),
					array(
						self::row( __( 'Closing questions', 'easyq' ), array( 'allow_close_questions', 'allow_close_own_questions', 'do_close_on_select' ) ),
						self::row( __( 'Answers', 'easyq' ), array( 'allow_self_answer', 'allow_multi_answers', 'follow_on_as' ) ),
						self::row( __( 'Comments', 'easyq' ), array( 'comment_on_qs', 'comment_on_as' ) ),
						self::row( __( 'Authors', 'easyq' ), array( 'allow_anonymous_naming', 'notify_users_default' ) ),
						self::row( __( 'Categories', 'easyq' ), array( 'allow_no_category', 'allow_no_sub_category' ) ),
					)
				),
				self::section(
					__( 'Length and tags', 'easyq' ),
					array(
						self::row( __( 'Question titles', 'easyq' ), array( 'min_len_q_title', 'max_len_q_title' ) ),
						self::row( __( 'Text', 'easyq' ), array( 'min_len_q_content', 'min_len_a_content', 'min_len_c_content' ) ),
						self::row( __( 'Tags', 'easyq' ), array( 'min_num_q_tags', 'max_num_q_tags', 'tag_separator_comma' ) ),
						'block_bad_words',
					)
				),
				self::section(
					__( 'While asking', 'easyq' ),
					array(
						self::row( __( 'Similar questions', 'easyq' ), array( 'do_ask_check_qs', 'match_ask_check_qs', 'page_size_ask_check_qs' ) ),
						self::row( __( 'Tag suggestions', 'easyq' ), array( 'do_example_tags', 'match_example_tags', 'page_size_ask_tags' ) ),
					)
				),
			),
			'viewing'     => array(
				self::section(
					__( 'Posts', 'easyq' ),
					array(
						self::row( __( 'Addresses', 'easyq' ), array( 'q_urls_title_length' ) ),
						self::row( __( 'Links', 'easyq' ), array( 'show_url_links', 'links_in_new_window' ) ),
						self::row( __( 'Shown with posts', 'easyq' ), array( 'show_user_points', 'show_post_update_meta', 'show_compact_numbers', 'show_full_date_days' ) ),
						self::row( __( 'Views', 'easyq' ), array( 'do_count_q_views', 'recalc_hotness_q_view' ) ),
					)
				),
				self::section( __( 'Voting', 'easyq' ), array( self::row( __( 'Votes', 'easyq' ), array( 'voting_on_qs', 'voting_on_as', 'voting_on_cs', 'votes_separated' ) ) ) ),
				self::section(
					__( 'Answers and comments', 'easyq' ),
					array(
						self::row( __( 'Answers', 'easyq' ), array( 'sort_answers_by', 'show_selected_first', 'page_size_q_as' ) ),
						self::row( __( 'Comments', 'easyq' ), array( 'show_fewer_cs_from', 'show_fewer_cs_count' ) ),
					)
				),
				self::section( __( 'Search engines', 'easyq' ), array( self::row( __( 'Sitemaps', 'easyq' ), array( 'sitemap_users' ) ) ) ),
			),
			'lists'       => array(
				self::section( '', array( self::row( __( 'Items per page', 'easyq' ), array( 'page_size_home', 'page_size_qs', 'page_size_activity', 'page_size_tags', 'page_size_users' ) ), 'search_provider' ) ),
				self::section(
					__( 'Hot questions', 'easyq' ),
					array( self::row( __( 'Weights', 'easyq' ), array( 'hot_weight_q_age', 'hot_weight_a_age', 'hot_weight_answers', 'hot_weight_votes', 'hot_weight_views' ) ) ),
					__( 'How much each of these counts towards the hotness of a question.', 'easyq' )
				),
			),
			'feeds'       => array(
				self::section(
					'',
					array(
						self::row( __( 'Feeds', 'easyq' ), array( 'feed_for_questions', 'feed_for_qa', 'feed_for_activity', 'feed_for_hot', 'feed_for_unanswered', 'feed_for_tag_qs', 'feed_per_category', 'feed_for_search' ) ),
						self::row( __( 'Feed items', 'easyq' ), array( 'feed_number_items', 'feed_full_text' ) ),
					)
				),
			),
			'spam'        => array(
				self::section(
					__( 'Users', 'easyq' ),
					array(
						self::row( __( 'Email addresses', 'easyq' ), array( 'confirm_user_emails', 'confirm_user_required' ) ),
						self::row( __( 'New users', 'easyq' ), array( 'moderate_users' ) ),
					)
				),
				self::section(
					__( 'Moderation', 'easyq' ),
					array(
						self::row( __( 'Moderate posts', 'easyq' ), array( 'moderate_anon_post', 'moderate_unapproved', 'moderate_unconfirmed', 'moderate_by_points', 'moderate_points_limit' ) ),
						self::row( __( 'Edited and approved posts', 'easyq' ), array( 'moderate_edited_again', 'moderate_update_time' ) ),
						self::row( __( 'Flags', 'easyq' ), array( 'flagging_of_posts', 'flagging_hide_after' ) ),
					)
				),
				self::section( __( 'IP addresses', 'easyq' ), array( 'block_ips_write', self::row( __( 'Storage', 'easyq' ), array( 'ip_anonymize' ) ) ) ),
				self::section(
					__( 'Rate limits', 'easyq' ),
					array(
						self::row( __( 'Questions', 'easyq' ), array( 'max_rate_ip_qs', 'max_rate_user_qs' ) ),
						self::row( __( 'Answers', 'easyq' ), array( 'max_rate_ip_as', 'max_rate_user_as' ) ),
						self::row( __( 'Comments', 'easyq' ), array( 'max_rate_ip_cs', 'max_rate_user_cs' ) ),
						self::row( __( 'Votes', 'easyq' ), array( 'max_rate_ip_votes', 'max_rate_user_votes' ) ),
						self::row( __( 'Flags', 'easyq' ), array( 'max_rate_ip_flags', 'max_rate_user_flags' ) ),
						self::row( __( 'Uploads', 'easyq' ), array( 'max_rate_ip_uploads', 'max_rate_user_uploads' ) ),
						self::row( __( 'Messages', 'easyq' ), array( 'max_rate_ip_messages', 'max_rate_user_messages' ) ),
						self::row( __( 'Accounts', 'easyq' ), array( 'max_rate_ip_registers', 'max_rate_ip_logins' ) ),
					),
					__( 'How often each IP address or user may do something, per hour; 0 blocks it.', 'easyq' )
				),
			),
			default       => array(),
		};
	}
}
