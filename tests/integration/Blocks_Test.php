<?php
/**
 * Tests for the block output.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Templates;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Blocks\Blocks
 * @covers \EasyQ\Blocks\Renderer
 * @covers \EasyQ\Blocks\Render
 * @covers \EasyQ\Blocks\Render\Lists_Render
 * @covers \EasyQ\Blocks\Render\Post_Fields
 * @covers \EasyQ\Blocks\Render\Forms
 * @covers \EasyQ\Blocks\Render\Widgets
 * @covers \EasyQ\Blocks\Render\Query_Resolver
 */
final class Blocks_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();
		Lists::reset();
	}

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		unset( $_GET['sort'], $_GET[ Flash::QUERY_VAR ], $_GET['easyq_edit'] );
		parent::tear_down();
	}

	/**
	 * Renders block markup on a route.
	 *
	 * @param string     $markup Block markup.
	 * @param Route|null $route  Route of the page.
	 * @return string
	 */
	private function render( string $markup, ?Route $route = null ): string {
		Block_Context::router()->set_current( $route );

		return do_blocks( $markup );
	}

	public function test_registers_all_blocks(): void {
		$names = array_filter( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ), static fn ( string $name ): bool => str_starts_with( $name, 'easyq/' ) );

		$this->assertCount( 46, $names );
		$this->assertNotNull( get_block_bindings_source( 'easyq/post' ) );
	}

	public function test_renders_the_question_page(): void {
		$question = $this->ask( $this->user(), 'A question for the page', 'The <b>details</b>.' );
		$this->answer( $this->user(), $question, 'An answer for the page.' );
		$definitions = ( new Templates( Block_Context::router() ) )->definitions();

		$html = $this->render( $definitions['easyq-question']['content'], new Route( 'question', $question ) );

		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringContainsString( 'A question for the page', $html );
		$this->assertStringContainsString( 'The &lt;b&gt;details&lt;/b&gt;.', $html );
		$this->assertStringContainsString( 'An answer for the page.', $html );
		$this->assertStringContainsString( '1 answer', $html );
		$this->assertStringContainsString( 'id="easyq-post-' . $question->id . '"', $html );
		$this->assertStringContainsString( 'wp-block-post-template', $html );
	}

	public function test_hidden_questions_render_nothing_for_visitors(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question -->', new Route( 'question', $this->post( $question->id ) ) );

		$this->assertSame( '', trim( $html ) );
	}

	public function test_lists_questions_with_pagination(): void {
		Options::update( 'lists', array( 'page_size_qs' => 2 ) );
		$author = $this->user();
		foreach ( array( 'First', 'Second', 'Third' ) as $word ) {
			$this->ask( $author, "$word question in the list" );
		}
		$definitions = ( new Templates( Block_Context::router() ) )->definitions();

		$html = $this->render( $definitions['easyq-home']['content'], new Route( 'home' ) );

		$this->assertStringContainsString( 'Third question in the list', $html );
		$this->assertStringContainsString( 'Second question in the list', $html );
		$this->assertStringNotContainsString( 'First question in the list', $html );
		$this->assertStringContainsString( 'wp-block-query-pagination', $html );
		$this->assertStringContainsString( 'easyq_page=2', $html );
		$this->assertStringContainsString( 'Recent questions', $html );
	}

	public function test_shows_a_message_for_empty_lists(): void {
		$html = $this->render( '<!-- wp:easyq/question-query {"queryId":7,"query":{"inherit":true}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- wp:easyq/query-no-results /--><!-- /wp:easyq/question-query -->', new Route( 'home' ) );

		$this->assertStringContainsString( 'No questions found.', $html );
	}

	public function test_lists_follow_the_sort_parameter(): void {
		$author = $this->user();
		$low    = $this->ask( $author, 'Question with few votes' );
		$high   = $this->ask( $author, 'Question with many votes' );
		Services::votes()->vote( $this->user(), $low, 1 );
		$_GET['sort'] = 'votes';

		$html = $this->render( '<!-- wp:easyq/question-query {"queryId":3,"query":{"inherit":true}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- /wp:easyq/question-query -->', new Route( 'home' ) );

		$this->assertLessThan( strpos( $html, 'many votes' ), strpos( $html, 'few votes' ) );
		$this->assertNotFalse( strpos( $html, (string) $high->title ) );
	}

	public function test_fixed_queries_ignore_the_page(): void {
		$tagged = $this->ask( $this->user(), 'Question tagged for the block', 'x', array( 'fixed' ) );
		$this->ask( $this->user(), 'Question without the tag' );

		$html = $this->render( '<!-- wp:easyq/question-query {"queryId":4,"query":{"inherit":false,"tag":"fixed"}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- /wp:easyq/question-query -->', new Route( 'home' ) );

		$this->assertStringContainsString( (string) $tagged->title, $html );
		$this->assertStringNotContainsString( 'without the tag', $html );
	}

	public function test_vote_buttons_and_interactivity(): void {
		$question = $this->ask( $this->user() );
		wp_set_current_user( (int) $this->user()->user_id );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-votes /--><!-- /wp:easyq/question -->', new Route( 'question', $question ) );

		$this->assertStringContainsString( 'data-wp-interactive="easyq"', $html );
		$this->assertStringContainsString( 'aria-label="Vote up"', $html );
		$this->assertStringContainsString( 'name="easyq_form" value="vote"', $html );
	}

	public function test_post_actions_follow_the_permissions(): void {
		$question = $this->ask( $this->user() );
		$markup   = '<!-- wp:easyq/question --><!-- wp:easyq/post-actions /--><!-- /wp:easyq/question -->';

		$this->assertStringNotContainsString( 'value="hide"', $this->render( $markup, new Route( 'question', $question ) ) );

		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$html = $this->render( $markup, new Route( 'question', $question ) );

		$this->assertStringContainsString( 'value="hide"', $html );
		$this->assertStringContainsString( 'easyq_edit=' . $question->id, $html );
		$this->assertStringContainsString( '<details', $html );
	}

	public function test_edit_form_replaces_the_content_for_editors(): void {
		$author             = $this->user();
		$question           = $this->ask( $author );
		$_GET['easyq_edit'] = (string) $question->id;

		$markup = '<!-- wp:easyq/question --><!-- wp:easyq/post-content /--><!-- /wp:easyq/question -->';
		$this->assertStringNotContainsString( 'name="easyq_form" value="edit"', $this->render( $markup, new Route( 'question', $question ) ) );

		wp_set_current_user( (int) $author->user_id );
		$this->assertStringContainsString( 'name="easyq_form" value="edit"', $this->render( $markup, new Route( 'question', $question ) ) );
	}

	public function test_forms_ask_visitors_to_log_in(): void {
		$question = $this->ask( $this->user() );

		$this->assertStringContainsString( 'Log in', $this->render( '<!-- wp:easyq/ask-form /-->', new Route( 'ask' ) ) );
		$this->assertStringContainsString( 'Log in', $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- /wp:easyq/question -->', new Route( 'question', $question ) ) );

		wp_set_current_user( (int) $this->user()->user_id );
		$html = $this->render( '<!-- wp:easyq/ask-form /-->', new Route( 'ask' ) );
		$this->assertStringContainsString( 'name="title"', $html );
		$this->assertStringContainsString( 'name="tags"', $html );
	}

	public function test_closed_questions_have_no_answer_form(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->close_with_note( Actor::system(), $question, 'Closed.' );
		wp_set_current_user( (int) $this->user()->user_id );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- wp:easyq/post-status /--><!-- /wp:easyq/question -->', new Route( 'question', $this->post( $question->id ) ) );

		$this->assertStringNotContainsString( 'easyq-answer-form', $html );
		$this->assertStringContainsString( 'Closed: Closed.', $html );
	}

	public function test_page_titles_navigation_and_lists(): void {
		$this->ask( $this->user(), 'Tagged question title', 'x', array( 'php' ) );
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );

		$this->assertStringContainsString( 'Questions tagged “php”', $this->render( '<!-- wp:easyq/page-title /-->', new Route( 'tag', 'php' ) ) );
		$this->assertStringContainsString( 'aria-current="page">Recent questions', $this->render( '<!-- wp:easyq/sort-navigation /-->', new Route( 'home' ) ) );
		$this->assertStringContainsString( 'rel="tag">php</a>', $this->render( '<!-- wp:easyq/tags /-->' ) );
		$this->assertStringContainsString( 'Software', $this->render( '<!-- wp:easyq/categories /-->' ) );
		$this->assertStringContainsString( '1 question', $this->render( '<!-- wp:easyq/activity-count /-->' ) );
		$this->assertStringContainsString( 'name="q"', $this->render( '<!-- wp:easyq/search /-->' ) );
	}

	public function test_notices_show_the_message_of_a_submission(): void {
		$_GET[ Flash::QUERY_VAR ] = Flash::store( 'success', 'All done.' );

		$this->assertStringContainsString( 'All done.', $this->render( '<!-- wp:easyq/notices /-->' ) );
	}

	public function test_core_blocks_can_show_post_data_through_bindings(): void {
		$question = $this->ask( $this->user(), 'Bound question title' );

		$html = $this->render(
			'<!-- wp:easyq/question --><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/post","args":{"key":"title"}}}}} --><p></p><!-- /wp:paragraph --><!-- /wp:easyq/question -->',
			new Route( 'question', $question )
		);

		$this->assertMatchesRegularExpression( '#<p[^>]*>Bound question title</p>#', $html );
	}

	public function test_related_questions(): void {
		$question = $this->ask( $this->user(), 'Configure the walrus module', 'x', array( 'walrus' ) );
		$related  = $this->ask( $this->user(), 'Another walrus module question', 'x', array( 'walrus' ) );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/related-questions /--><!-- /wp:easyq/question -->', new Route( 'question', $question ) );

		$this->assertStringContainsString( (string) $related->title, $html );
		$this->assertSame( 1, substr_count( $html, (string) $question->title ) === 0 ? 1 : 0 );
	}
}
