<?php
/**
 * Block patterns of the Q&A layouts.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers block patterns for typical Q&A layouts, built from easyQ and core blocks.
 *
 * The start page, question page and profile patterns are offered when the matching
 * block template is designed in the Site Editor; the question page and the profile
 * only work there, so they are hidden from the inserter. The compact lists work on
 * any page, e.g. in a sidebar or on the front page of the site.
 *
 * @since 0.1.0
 */
final class Patterns {

	/**
	 * Name of the pattern category.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CATEGORY = 'easyq';

	/**
	 * Hooks the patterns into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_patterns' ) );
	}

	/**
	 * Registers the pattern category and the patterns.
	 *
	 * @since 0.1.0
	 */
	public function register_patterns(): void {
		register_block_pattern_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Questions and answers', 'easyq' ),
				'description' => __( 'Layouts for the Q&A pages and lists of questions and users.', 'easyq' ),
			)
		);

		foreach ( $this->definitions() as $name => $pattern ) {
			register_block_pattern( 'easyq/' . $name, $pattern + array( 'categories' => array( self::CATEGORY ) ) );
		}
	}

	/**
	 * Returns the patterns.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{title: string, description: string, content: string, templateTypes?: string[], blockTypes?: string[], keywords?: string[], inserter?: bool}>
	 *         Pattern properties keyed by name.
	 */
	public function definitions(): array {
		return array(
			'home'             => array(
				'title'         => __( 'Q&A start page', 'easyq' ),
				'description'   => __( 'Page title, a box to ask a question and the recent questions and answers.', 'easyq' ),
				'templateTypes' => array( 'easyq-home' ),
				'content'       => Layouts::home(),
			),
			'question-page'    => array(
				'title'         => __( 'Q&A question page', 'easyq' ),
				'description'   => __( 'A question with votes, answers, comments and the answer form.', 'easyq' ),
				'templateTypes' => array( 'easyq-question' ),
				'inserter'      => false,
				'content'       => Layouts::question_page(),
			),
			'profile'          => array(
				'title'         => __( 'Q&A user profile', 'easyq' ),
				'description'   => __( 'Avatar, name, points, profile sections, profile fields and statistics of a user.', 'easyq' ),
				'templateTypes' => array( 'easyq-user' ),
				'inserter'      => false,
				'content'       => Layouts::user_page( '<!-- wp:easyq/user-fields /-->' . "\n" . '<!-- wp:easyq/user-stats /-->' ),
			),
			'recent-questions' => array(
				'title'       => __( 'Recent questions (compact)', 'easyq' ),
				'description' => __( 'A heading and the five newest questions, one per line, for sidebars and front pages.', 'easyq' ),
				'blockTypes'  => array( 'easyq/question-query' ),
				'keywords'    => array( __( 'questions', 'easyq' ), __( 'latest', 'easyq' ), __( 'sidebar', 'easyq' ) ),
				'content'     => $this->heading( __( 'Recent questions', 'easyq' ) ) . "\n" . Layouts::compact_question_list(
					array(
						'source'  => 'recent',
						'perPage' => 5,
						'inherit' => false,
					),
					101
				),
			),
			'top-users'        => array(
				'title'       => __( 'Top users (compact)', 'easyq' ),
				'description' => __( 'A heading and the five users with the most points, for sidebars.', 'easyq' ),
				'blockTypes'  => array( 'easyq/user-query' ),
				'keywords'    => array( __( 'users', 'easyq' ), __( 'points', 'easyq' ), __( 'sidebar', 'easyq' ) ),
				'content'     => $this->heading( __( 'Top users', 'easyq' ) ) . "\n" . Layouts::compact_user_list(
					array(
						'sort'    => 'points',
						'perPage' => 5,
						'inherit' => false,
					),
					102
				),
			),
		);
	}

	/**
	 * Returns the markup of a core heading.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text, not escaped.
	 * @return string
	 */
	private function heading( string $text ): string {
		return '<!-- wp:heading {"level":2} -->' . "\n" . '<h2 class="wp-block-heading">' . esc_html( $text ) . '</h2>' . "\n" . '<!-- /wp:heading -->';
	}
}
