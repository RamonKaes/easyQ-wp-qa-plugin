<?php
/**
 * Block templates of the Q&A pages.
 *
 * @package easyQ
 */

namespace EasyQ\Frontend;

use EasyQ\Blocks\Layouts;

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
		$list = Layouts::question_list();

		return array(
			'easyq-home'           => array(
				'title'       => __( 'Q&A: Home', 'easyq' ),
				'description' => __( 'Start page of the questions and answers.', 'easyq' ),
				'content'     => Layouts::home(),
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
				'content'     => Layouts::question_page(),
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
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/sort-navigation /-->' . "\n" . Layouts::user_list(),
			),
			'easyq-user'           => array(
				'title'       => __( 'Q&A: User profile', 'easyq' ),
				'description' => __( 'The profile of a user.', 'easyq' ),
				'content'     => Layouts::user_page( '<!-- wp:easyq/user-fields /-->' . "\n" . '<!-- wp:easyq/user-stats /-->' ),
			),
			'easyq-user-questions' => array(
				'title'       => __( 'Q&A: Questions of a user', 'easyq' ),
				'description' => __( 'The questions a user asked.', 'easyq' ),
				'content'     => Layouts::user_page( $list ),
			),
			'easyq-user-answers'   => array(
				'title'       => __( 'Q&A: Answers of a user', 'easyq' ),
				'description' => __( 'The answers a user wrote.', 'easyq' ),
				'content'     => Layouts::user_page( $list ),
			),
			'easyq-user-activity'  => array(
				'title'       => __( 'Q&A: Activity of a user', 'easyq' ),
				'description' => __( 'What a user did recently.', 'easyq' ),
				'content'     => Layouts::user_page( $list ),
			),
			'easyq-account'        => array(
				'title'       => __( 'Q&A: Account', 'easyq' ),
				'description' => __( 'Form for users to edit their Q&A profile.', 'easyq' ),
				'content'     => '<!-- wp:easyq/page-title /-->' . "\n" . '<!-- wp:easyq/account /-->',
			),
		);
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
