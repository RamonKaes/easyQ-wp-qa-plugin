<?php
/**
 * Tests for Q&A routes.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Templates;
use EasyQ\Frontend\URLs;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Frontend\Routes
 * @covers \EasyQ\Frontend\Router
 * @covers \EasyQ\Frontend\Route
 * @covers \EasyQ\Frontend\URLs
 * @covers \EasyQ\Frontend\Templates
 */
final class Routing_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Block_Context::router()->add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	public function test_builds_pretty_addresses(): void {
		$this->assertSame( home_url( '/questions/' ), Routes::url( 'home' ) );
		$this->assertSame( home_url( '/questions/tag/block%20themes/' ), Routes::url( 'tag', 'block themes' ) );
		$this->assertSame( home_url( '/questions/categories/a/b/' ), Routes::url( 'category', 'a/b' ) );
		$this->assertSame( home_url( '/questions/user/ada/wall/' ), Routes::url( 'user', 'ada', 'wall' ) );
		$this->assertSame( home_url( '/questions/ask/?follow=3' ), Routes::url( 'ask', null, null, array( 'follow' => 3 ) ) );
	}

	public function test_builds_query_addresses_without_pretty_permalinks(): void {
		$this->set_permalink_structure( '' );

		$this->assertSame( home_url( '/?easyq_route=tag&easyq_arg=php' ), Routes::url( 'tag', 'php' ) );
	}

	public function test_question_addresses_contain_a_shortened_title(): void {
		Options::update( 'viewing', array( 'q_urls_title_length' => 20 ) );
		$question = $this->ask( $this->user(), 'How do I configure the widget settings page?' );

		$this->assertSame( home_url( '/questions/' . $question->id . '/how-do-i-configure/' ), URLs::question( $question ) );
		$this->assertSame( 'question', URLs::slug( '!!!' ) );
	}

	public function test_uses_the_configured_base_slug(): void {
		Options::update( 'general', array( 'base_slug' => 'fragen' ) );

		$this->assertSame( home_url( '/fragen/' ), Routes::url( 'home' ) );
		$this->assertArrayHasKey( '^fragen/?$', Routes::rewrite_rules() );
	}

	public function test_resolves_routes(): void {
		$router   = Block_Context::router();
		$question = $this->ask( $this->user() );
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );
		$child = Services::categories()->create( $category, 'Plugins' );
		$this->assertIsInt( $child );
		$user = self::factory()->user->create_and_get( array( 'user_nicename' => 'ada' ) );

		$this->assertSame( $question->id, $router->resolve( 'question', (string) $question->id, null )->question()?->id );
		$this->assertSame( 'not_found', $router->resolve( 'question', '999999', null )->name );
		$this->assertSame( 'block themes', $router->resolve( 'tag', 'block%20themes', null )->tag() );
		$this->assertSame( $child, $router->resolve( 'category', 'software/plugins', null )->category()?->id );
		$this->assertSame( 'not_found', $router->resolve( 'category', 'plugins', null )->name );
		$this->assertSame( $user->ID, $router->resolve( 'user', 'ada', 'wall' )->user()?->ID );
		$this->assertNull( $router->resolve( 'user', 'ada', 'bogus' )->section );
		$this->assertSame( 'not_found', $router->resolve( 'unknown', null, null )->name );
		$this->assertSame( 'easyq-ask', $router->resolve( 'ask', null, null )->template_slug() );
		$this->assertSame( 'easyq-user-answers', $router->resolve( 'user', 'ada', 'answers' )->template_slug() );
		$this->assertSame( 'easyq-user', $router->resolve( 'user', 'ada', null )->template_slug() );
	}

	public function test_requests_are_routed_without_404(): void {
		$question = $this->ask( $this->user() );

		$this->go_to( URLs::question( $question ) );

		$this->assertSame( 'question', Block_Context::router()->current()?->name );
		$this->assertFalse( is_404() );
		$this->assertSame( array(), $GLOBALS['wp_query']->posts );
	}

	public function test_unknown_addresses_below_the_base_are_not_found(): void {
		$this->go_to( home_url( '/questions/999999/missing/' ) );

		$this->assertSame( 'not_found', Block_Context::router()->current()?->name );
	}

	public function test_routes_without_a_template_are_not_found(): void {
		$this->go_to( Routes::url( 'favorites' ) );
		$templates = new Templates( Block_Context::router() );

		$template = $templates->template_include( 'index.php' );

		$this->assertTrue( is_404() );
		$this->assertSame( get_404_template(), $template );
	}

	public function test_profile_pages_use_their_section_template(): void {
		switch_theme( 'twentytwentyfive' );
		// Block themes get the support for block templates on `after_setup_theme`, which does not run again in tests.
		wp_enable_block_templates();
		self::factory()->user->create( array( 'user_nicename' => 'grace' ) );
		$this->go_to( Routes::url( 'user', 'grace', 'activity' ) );
		$templates = new Templates( Block_Context::router() );

		$template = $templates->template_include( 'index.php' );

		$this->assertFalse( is_404() );
		$this->assertStringContainsString( 'template-canvas.php', $template );
		// Core prefixes the ID of the resolved template with the theme, so only the slug is compared.
		$this->assertStringEndsWith( '//easyq-user-activity', (string) ( $GLOBALS['_wp_current_template_id'] ?? '' ) );
	}

	/**
	 * Opens a Q&A page in the classic theme Twenty Twenty-One and returns the template file.
	 *
	 * @param string $url Address of the page.
	 * @return string
	 */
	private function open_in_classic_theme( string $url ): string {
		switch_theme( 'twentytwentyone' );
		$this->assertFalse( wp_is_block_theme() );
		$this->go_to( $url );

		return ( new Templates( Block_Context::router() ) )->template_include( 'index.php' );
	}

	public function test_classic_themes_show_q_and_a_pages_with_their_page_template(): void {
		wp_set_current_user( $this->administrator() );
		$question = $this->ask( $this->user() );
		$this->go_to( get_permalink( self::factory()->post->create() ) );
		the_post();
		$this->assertNotSame( '', (string) get_edit_post_link(), 'Other posts keep their edit link.' );
		$this->assertSame( '<p>Post</p>', trim( (string) apply_filters( 'the_content', 'Post' ) ) );

		$template = $this->open_in_classic_theme( Routes::url( 'question', $question->id ) );

		$this->assertSame( get_theme_root() . '/twentytwentyone/page.php', $template );
		$this->assertTrue( is_page() );
		$this->assertFalse( is_404() );
		$this->assertTrue( have_posts() );
		the_post();
		$this->assertSame( 'How do I configure the widget?', get_the_title() );
		$content = (string) apply_filters( 'the_content', '' );
		$this->assertStringContainsString( 'wp-block-easyq-post-content', $content );
		// The theme prints the title; the content has no heading of its own and no paragraphs added by `wpautop()`.
		$this->assertStringNotContainsString( '<h1', $content );
		$this->assertSame( Templates::classic_html(), $content );
		$this->assertSame( '', get_edit_post_link() );
		remove_filter( 'get_edit_post_link', array( Templates::class, 'classic_edit_link' ) );
		$this->assertStringContainsString( 'post=0', (string) get_edit_post_link(), 'Without the filter, core links to editing post 0.' );
		$this->assertStringEndsWith( '/includes/frontend/views/no-comments.php', (string) apply_filters( 'comments_template', 'comments.php' ) );
	}

	public function test_classic_page_titles_are_escaped_and_censored(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn' ) );
		$question = $this->ask( $this->user(), 'Darn widgets & <b>bold</b> plans?' );

		$this->open_in_classic_theme( Routes::url( 'question', $question->id ) );

		$this->assertSame( esc_html( '**** widgets & <b>bold</b> plans?' ), $GLOBALS['post']->post_title );
		$this->assertStringContainsString( '**** widgets', wp_get_document_title() );
	}

	public function test_classic_pages_keep_headings_below_the_title(): void {
		$this->ask( $this->user(), 'Which hooks exist?' );

		$this->open_in_classic_theme( Routes::url( 'home' ) );

		$this->assertSame( 'Recent questions', get_the_title( $GLOBALS['post'] ) );
		$this->assertStringNotContainsString( '<h1', Templates::classic_html() );
		$this->assertMatchesRegularExpression( '/<h2 [^>]*><a href="[^"]+">Which hooks exist\?<\/a><\/h2>/', Templates::classic_html() );
	}

	public function test_other_requests_are_left_alone(): void {
		$post_id = self::factory()->post->create();

		$this->go_to( get_permalink( $post_id ) );

		$this->assertNull( Block_Context::router()->current() );
		$this->assertTrue( is_single() );
	}
}
