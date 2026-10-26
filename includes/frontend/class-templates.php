<?php
/**
 * Block templates of the Q&A pages.
 *
 * @package easyQ
 */

namespace EasyQ\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a block template for every Q&A page and uses it for Q&A requests.
 *
 * The templates consist of core blocks and easyQ blocks and use the theme's
 * header and footer template parts. Site owners can change them in the Site
 * Editor; themes can provide their own `templates/easyq-*.html` files.
 */
final class Templates {

	/**
	 * Sets up the templates.
	 *
	 * @param Router $router Router of the current request.
	 */
	public function __construct( private readonly Router $router ) {
	}

	/**
	 * Hooks the templates into WordPress.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_templates' ) );
		add_filter( 'template_include', array( $this, 'template_include' ), 20 );
	}

	/**
	 * Registers the block templates.
	 */
	public function register_templates(): void {
		foreach ( $this->definitions() as $slug => $definition ) {
			register_block_template(
				'easyq//' . $slug,
				array(
					'title'       => $definition['title'],
					'description' => $definition['description'],
					'content'     => $this->wrap( $definition['content'] ),
				)
			);
		}
	}

	/**
	 * Uses the block template of the route for Q&A requests.
	 *
	 * @param string $template Template file chosen by WordPress.
	 * @return string
	 */
	public function template_include( string $template ): string {
		$route = $this->router->current();
		if ( null === $route ) {
			return $template;
		}

		if ( 'not_found' === $route->name || ! isset( $this->definitions()[ $route->template_slug() ] ) ) {
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			status_header( 404 );
			nocache_headers();

			return get_404_template();
		}

		if ( ! wp_is_block_theme() ) {
			return $template;
		}

		$slug = $route->template_slug();

		return locate_block_template( $template, $slug, array( $slug ) );
	}

	/**
	 * Returns the templates with title, description and content.
	 *
	 * @return array<string, array{title: string, description: string, content: string}>
	 */
	public function definitions(): array {
		$list = $this->question_list();

		return array(
			'easyq-home'           => array(
				'title'       => __( 'Q&A: Home', 'easyq' ),
				'description' => __( 'Start page of the questions and answers.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/ask-box /-->' . "\n" . '<!-- wp:easyq/sort-navigation /-->' . "\n" . $list,
			),
			'easyq-hot'            => array(
				'title'       => __( 'Q&A: Hot questions', 'easyq' ),
				'description' => __( 'Questions sorted by recent activity, answers and votes.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/sort-navigation /-->' . "\n" . $list,
			),
			'easyq-unanswered'     => array(
				'title'       => __( 'Q&A: Unanswered questions', 'easyq' ),
				'description' => __( 'Questions without answers.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . $list,
			),
			'easyq-tag'            => array(
				'title'       => __( 'Q&A: Tag', 'easyq' ),
				'description' => __( 'Questions with a tag.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . $list,
			),
			'easyq-category'       => array(
				'title'       => __( 'Q&A: Category', 'easyq' ),
				'description' => __( 'Questions in a category.', 'easyq' ),
				'content'     => '<!-- wp:easyq/breadcrumbs /-->' . "\n" . '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/categories {"showChildrenOfCurrent":true} /-->' . "\n" . $list,
			),
			'easyq-search'         => array(
				'title'       => __( 'Q&A: Search results', 'easyq' ),
				'description' => __( 'Questions found by a search.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/search /-->' . "\n" . $list,
			),
			'easyq-question'       => array(
				'title'       => __( 'Q&A: Question', 'easyq' ),
				'description' => __( 'A question with its answers and comments.', 'easyq' ),
				'content'     => $this->question_page(),
			),
			'easyq-ask'            => array(
				'title'       => __( 'Q&A: Ask a question', 'easyq' ),
				'description' => __( 'Form for new questions.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/ask-form /-->',
			),
			'easyq-tags'           => array(
				'title'       => __( 'Q&A: Tags', 'easyq' ),
				'description' => __( 'All tags by popularity.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/tags /-->',
			),
			'easyq-categories'     => array(
				'title'       => __( 'Q&A: Categories', 'easyq' ),
				'description' => __( 'All categories.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/categories /-->',
			),
			'easyq-activity'       => array(
				'title'       => __( 'Q&A: Recent activity', 'easyq' ),
				'description' => __( 'Recently asked, answered, commented on and changed questions.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . $list,
			),
			'easyq-answers'        => array(
				'title'       => __( 'Q&A: Recent answers', 'easyq' ),
				'description' => __( 'Recently answered questions.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . $list,
			),
			'easyq-comments'       => array(
				'title'       => __( 'Q&A: Recent comments', 'easyq' ),
				'description' => __( 'Recently commented questions and answers.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . $list,
			),
			'easyq-users'          => array(
				'title'       => __( 'Q&A: Users', 'easyq' ),
				'description' => __( 'Users by points, newest users and users with special levels.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/sort-navigation /-->' . "\n" . $this->user_list(),
			),
			'easyq-user'           => array(
				'title'       => __( 'Q&A: User profile', 'easyq' ),
				'description' => __( 'The profile of a user.', 'easyq' ),
				'content'     => $this->user_page( '<!-- wp:easyq/user-fields /-->' . "\n" . '<!-- wp:easyq/user-stats /-->' ),
			),
			'easyq-user-questions' => array(
				'title'       => __( 'Q&A: Questions of a user', 'easyq' ),
				'description' => __( 'The questions a user asked.', 'easyq' ),
				'content'     => $this->user_page( $list ),
			),
			'easyq-user-answers'   => array(
				'title'       => __( 'Q&A: Answers of a user', 'easyq' ),
				'description' => __( 'The answers a user wrote.', 'easyq' ),
				'content'     => $this->user_page( $list ),
			),
			'easyq-user-activity'  => array(
				'title'       => __( 'Q&A: Activity of a user', 'easyq' ),
				'description' => __( 'What a user did recently.', 'easyq' ),
				'content'     => $this->user_page( $list ),
			),
			'easyq-account'        => array(
				'title'       => __( 'Q&A: Account', 'easyq' ),
				'description' => __( 'Form for users to edit their Q&A profile.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/account /-->',
			),
		);
	}

	/**
	 * Returns the markup of a user list.
	 *
	 * @return string
	 */
	private function user_list(): string {
		return '<!-- wp:easyq/user-query {"queryId":1,"query":{"inherit":true}} -->
<!-- wp:easyq/user-template {"layout":{"type":"grid","columnCount":3}} -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-avatar {"avatarSize":48} /-->
<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-name /-->
<!-- wp:easyq/user-points /-->
<!-- wp:easyq/user-title /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:easyq/user-template -->
<!-- wp:easyq/query-pagination /-->
<!-- wp:easyq/query-no-results ' . wp_json_encode( array( 'message' => __( 'No users found.', 'easyq' ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ' /-->
<!-- /wp:easyq/user-query -->';
	}

	/**
	 * Returns the markup of a profile page.
	 *
	 * @param string $content Blocks below the profile header and navigation.
	 * @return string
	 */
	private function user_page( string $content ): string {
		return '<!-- wp:easyq/user -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"center"}} -->
<div class="wp-block-group"><!-- wp:easyq/user-avatar {"avatarSize":96} /-->
<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->
<div class="wp-block-group"><!-- wp:easyq/page-title /-->
<!-- wp:easyq/user-title /-->
<!-- wp:easyq/user-points {"showRank":true} /--></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- wp:easyq/user-navigation /-->
' . $content . '
<!-- /wp:easyq/user -->';
	}

	/**
	 * Returns the markup of a question list.
	 *
	 * @return string
	 */
	private function question_list(): string {
		return '<!-- wp:easyq/question-query {"queryId":1,"query":{"inherit":true}} -->
<!-- wp:easyq/question-template -->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-votes {"layout":{"type":"flex","orientation":"vertical"}} /-->
<!-- wp:easyq/post-answer-count /-->
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-title {"isLink":true} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-tags /-->
<!-- wp:easyq/post-category /-->
<!-- wp:easyq/post-activity /--></div>
<!-- /wp:group --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
<!-- /wp:easyq/question-template -->
<!-- wp:easyq/query-pagination /-->
<!-- wp:easyq/query-no-results /-->
<!-- /wp:easyq/question-query -->';
	}

	/**
	 * Returns the markup of the question page.
	 *
	 * @return string
	 */
	private function question_page(): string {
		$post_body = '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap","verticalAlignment":"top"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-votes {"layout":{"type":"flex","orientation":"vertical"}} /-->
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-content /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-actions /-->
<!-- wp:easyq/post-author /--></div>
<!-- /wp:group -->
<!-- wp:easyq/comment-list -->
<!-- wp:easyq/comment-template -->
<!-- wp:easyq/post-content /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-author {"showAvatar":false} /-->
<!-- wp:easyq/post-date /-->
<!-- wp:easyq/post-actions /--></div>
<!-- /wp:group -->
<!-- /wp:easyq/comment-template -->
<!-- wp:easyq/comment-form /-->
<!-- /wp:easyq/comment-list --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->';

		return '<!-- wp:easyq/question -->
<!-- wp:easyq/breadcrumbs /-->
<!-- wp:easyq/post-title {"level":1} /-->
<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group"><!-- wp:easyq/post-status /-->
<!-- wp:easyq/post-tags /-->
<!-- wp:easyq/post-category /-->
<!-- wp:easyq/post-view-count /--></div>
<!-- /wp:group -->
' . $post_body . '
<!-- wp:easyq/answer-list {"queryId":2} -->
<!-- wp:easyq/answer-template -->
' . $post_body . '
<!-- /wp:easyq/answer-template -->
<!-- wp:easyq/query-pagination /-->
<!-- /wp:easyq/answer-list -->
<!-- wp:easyq/answer-form /-->
<!-- wp:easyq/related-questions /-->
<!-- /wp:easyq/question -->';
	}

	/**
	 * Wraps page content with the theme's header and footer.
	 *
	 * @param string $content Block markup of the page.
	 * @return string
	 */
	private function wrap( string $content ): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} -->
<main class="wp-block-group"><!-- wp:easyq/notices /-->
' . $content . '</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}
}
