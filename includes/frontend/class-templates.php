<?php
/**
 * Block templates of the Q&A pages.
 *
 * @package easyQ
 * @since 0.1.0
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
 *
 * @since 0.1.0
 */
final class Templates {

	/**
	 * Rendered Q&A page for classic themes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private static string $classic_html = '';

	/**
	 * Page that stands for the Q&A page in the main query of classic themes.
	 *
	 * @since 0.1.0
	 *
	 * @var \WP_Post|null
	 */
	private static ?\WP_Post $classic_page = null;

	/**
	 * Sets up the templates.
	 *
	 * @since 0.1.0
	 *
	 * @param Router $router Router of the current request.
	 */
	public function __construct( private readonly Router $router ) {
	}

	/**
	 * Hooks the templates into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_templates' ) );
		add_filter( 'template_include', array( $this, 'template_include' ), 20 );
	}

	/**
	 * Registers the block templates.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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

		$slug = $route->template_slug();

		if ( ! wp_is_block_theme() ) {
			return $this->classic_template( $slug );
		}

		return locate_block_template( $template, $slug, array( $slug ) );
	}

	/**
	 * Renders the block template of a Q&A page for a classic theme and returns the file that prints it.
	 *
	 * Classic themes cannot load block templates, so the blocks of the template are
	 * rendered here, before the document head. They are printed as the content of a
	 * page that the theme shows with its own page template, so that the page has the
	 * theme's containers, widths and sidebars. Themes can instead provide their own
	 * `easyq/classic.php`, which prints classic_html() itself.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug Template slug, e.g. `easyq-question`.
	 * @return string Template file.
	 */
	private function classic_template( string $slug ): string {
		$content = '<!-- wp:easyq/notices /-->' . "\n" . $this->definitions()[ $slug ]['content'];

		/**
		 * Filters the block markup of a Q&A page in classic themes.
		 *
		 * Block themes edit the templates in the Site Editor instead.
		 *
		 * @since 0.1.0
		 *
		 * @param string $content Block markup.
		 * @param string $slug    Template slug, e.g. `easyq-question`.
		 */
		$content = (string) apply_filters( 'easyq_classic_template_content', $content, $slug );

		$theme_file = locate_template( array( 'easyq/classic.php' ) );
		if ( '' !== $theme_file ) {
			self::$classic_html = do_blocks( $content );

			return $theme_file;
		}

		// The theme's page template prints the title, so the blocks leave out theirs.
		add_filter( 'render_block_easyq/page-title', array( self::class, 'without_main_heading' ), 10, 2 );
		add_filter( 'render_block_easyq/post-title', array( self::class, 'without_main_heading' ), 10, 2 );
		self::$classic_html = do_blocks( $content );
		remove_filter( 'render_block_easyq/page-title', array( self::class, 'without_main_heading' ) );
		remove_filter( 'render_block_easyq/post-title', array( self::class, 'without_main_heading' ) );

		$this->set_up_classic_page();
		foreach ( array( get_page_template(), get_singular_template(), get_index_template() ) as $file ) {
			if ( '' !== $file ) {
				return $file;
			}
		}

		return \EasyQ\PLUGIN_DIR . '/includes/frontend/views/classic.php';
	}

	/**
	 * Makes the main query hold one page whose content is the rendered Q&A page.
	 *
	 * The page has the title of the Q&A page, which the theme prints as it prints
	 * page titles. It has no ID and no comments; its content is put in last, after
	 * the content filters, so that they do not change the rendered blocks.
	 *
	 * @since 0.1.0
	 */
	private function set_up_classic_page(): void {
		global $wp_query, $post;

		self::$classic_page = new \WP_Post(
			(object) array(
				'ID'             => 0,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				// Post titles are printed without escaping.
				'post_title'     => esc_html( \EasyQ\Blocks\Render\Widgets::title_of_page() ),
				'post_content'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'comment_count'  => 0,
				'filter'         => 'raw',
			)
		);

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->posts             = array( self::$classic_page );
			$wp_query->post              = self::$classic_page;
			$wp_query->post_count        = 1;
			$wp_query->found_posts       = 1;
			$wp_query->max_num_pages     = 1;
			$wp_query->current_post      = -1;
			$wp_query->queried_object    = self::$classic_page;
			$wp_query->queried_object_id = 0;
			$wp_query->is_page           = true;
			$wp_query->is_singular       = true;
			$wp_query->is_home           = false;
			$wp_query->is_archive        = false;
			$wp_query->is_404            = false;
		}
		$post = self::$classic_page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The theme's page template reads the page from the global.

		add_filter( 'the_content', array( self::class, 'classic_content' ), PHP_INT_MAX );
		add_filter( 'comments_template', array( self::class, 'classic_comments' ) );
		add_filter( 'get_edit_post_link', array( self::class, 'classic_edit_link' ), 10, 2 );
	}

	/**
	 * Leaves out the main heading of a Q&A page, a title block of level 1.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $html  Rendered block.
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	public static function without_main_heading( string $html, array $block ): string {
		$attributes = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$type       = \WP_Block_Type_Registry::get_instance()->get_registered( is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '' );
		$schema     = $type?->attributes['level'] ?? null;
		$default    = is_array( $schema ) ? ( $schema['default'] ?? null ) : null;
		$level      = $attributes['level'] ?? $default;

		return is_numeric( $level ) && 1 === (int) $level ? '' : $html;
	}

	/**
	 * Returns the rendered Q&A page as content of the page that stands for it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Content after the content filters.
	 * @return string
	 */
	public static function classic_content( string $content ): string {
		return self::is_classic_page( get_post() ) ? self::$classic_html : $content;
	}

	/**
	 * Keeps the theme's comments template away from the page that stands for a Q&A page.
	 *
	 * Comment queries for post ID 0 are not limited to a post, so the theme would list
	 * the comments of the whole site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $template Comments template file.
	 * @return string
	 */
	public static function classic_comments( string $template ): string {
		return self::is_classic_page( get_post() ) ? \EasyQ\PLUGIN_DIR . '/includes/frontend/views/no-comments.php' : $template;
	}

	/**
	 * Removes the edit link of the page that stands for a Q&A page.
	 *
	 * `get_post( 0 )` returns the global post, so without this the theme and the
	 * toolbar would link to editing a post with ID 0.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $link    Edit link.
	 * @param int         $post_id Post ID.
	 * @return string|null
	 */
	public static function classic_edit_link( ?string $link, int $post_id ): ?string {
		return 0 === $post_id && self::is_classic_page( get_post() ) ? '' : $link;
	}

	/**
	 * Whether a post is the page that stands for the Q&A page.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post|null $post Post.
	 * @return bool
	 */
	private static function is_classic_page( ?\WP_Post $post ): bool {
		return null !== self::$classic_page && null !== $post && 0 === $post->ID && 'page' === $post->post_type;
	}

	/**
	 * Returns the rendered Q&A page for classic themes, see classic_template().
	 *
	 * @since 0.1.0
	 *
	 * @return string HTML.
	 */
	public static function classic_html(): string {
		return self::$classic_html;
	}

	/**
	 * Returns the templates with title, description and content.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{title: string, description: string, content: string}>
	 */
	public function definitions(): array {
		$list = Layouts::question_list();

		return array(
			'easyq-home'           => array(
				'title'       => __( 'Q&A: Home', 'easyq' ),
				'description' => __( 'Start page with the recent questions and answers.', 'easyq' ),
				'content'     => Layouts::home(),
			),
			'easyq-questions'      => array(
				'title'       => __( 'Q&A: Questions', 'easyq' ),
				'description' => __( 'All questions, sorted by date, hotness, votes, answers or views.', 'easyq' ),
				'content'     => Layouts::questions(),
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
	 * @since 0.1.0
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
