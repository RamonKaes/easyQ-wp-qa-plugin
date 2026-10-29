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
use EasyQ\Blocks\Render\Forms;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\Templates;
use EasyQ\Frontend\URLs;
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

	/**
	 * Rule of core's comment form styles that gives text fields a border and the font of the page.
	 */
	private const COMMENT_FORM_FIELD_RULE = '/:where\(\.wp-block-post-comments-form\)?\s?textarea\)?[^{]*\{[^}]*border[^}]*#949494[^}]*font-family:\s*inherit/';

	public function set_up(): void {
		parent::set_up();
		Lists::reset();
	}

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		unset( $_GET['sort'], $_GET[ Flash::QUERY_VAR ], $_GET['easyq_edit'], $_GET['easyq_close'] );
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

		$html = $this->render( $definitions['easyq-questions']['content'], new Route( 'questions' ) );

		$this->assertStringContainsString( 'Third question in the list', $html );
		$this->assertStringContainsString( 'Second question in the list', $html );
		$this->assertStringNotContainsString( 'First question in the list', $html );
		$this->assertStringContainsString( 'wp-block-query-pagination', $html );
		$this->assertStringContainsString( 'easyq_page=2', $html );
		$this->assertStringContainsString( 'Recent questions', $html );
	}

	public function test_start_page_lists_recent_questions_and_answers_as_in_q2a(): void {
		Options::update( 'lists', array( 'page_size_home' => 2 ) );
		$author   = $this->user();
		$answered = $this->ask( $author, 'Asked first and answered last' );
		$newest   = $this->ask( $author, 'Asked second' );
		$this->ask( $author, 'Asked last but commented on' );
		$this->answer( $this->user(), $answered );
		// Comments do not count on the start page, unlike the activity page.
		$this->comment( $this->user(), $newest, $newest );
		$definitions = ( new Templates( Block_Context::router() ) )->definitions();

		$html = $this->render( $definitions['easyq-home']['content'], new Route( 'home' ) );

		$this->assertStringContainsString( '>Recent questions and answers</h1>', $html );
		$this->assertStringNotContainsString( 'wp-block-easyq-sort-navigation', $html );
		// Newest event first, one item per question, as many as `page_size_home`, without further pages.
		$this->assertLessThan( (int) strpos( $html, 'Asked last but commented on' ), (int) strpos( $html, 'Asked first and answered last' ) );
		$this->assertStringContainsString( 'Asked last but commented on', $html );
		$this->assertStringNotContainsString( 'Asked second', $html );
		$this->assertSame( 1, substr_count( $html, 'Asked first and answered last' ) );
		$this->assertStringNotContainsString( 'wp-block-query-pagination', $html );

		$activity = $this->render( $definitions['easyq-activity']['content'], new Route( 'activity' ) );
		$this->assertStringContainsString( 'Asked second', $activity, 'The activity page counts the comment.' );
	}

	public function test_questions_page_has_the_titles_of_q2a(): void {
		$titles = array(
			''        => 'Recent questions',
			'hot'     => 'Hot questions',
			'votes'   => 'Highest voted questions',
			'answers' => 'Most answered questions',
			'views'   => 'Most viewed questions',
		);
		foreach ( $titles as $sort => $title ) {
			$_GET['sort'] = $sort;
			$this->assertStringContainsString( ">$title</h1>", $this->render( '<!-- wp:easyq/page-title /-->', new Route( 'questions' ) ), $sort );
		}
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

		$html = $this->render( '<!-- wp:easyq/question-query {"queryId":3,"query":{"inherit":true}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- /wp:easyq/question-query -->', new Route( 'questions' ) );

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

	/**
	 * Counts the buttons that are not inside a buttons block.
	 *
	 * @param string $html Rendered HTML.
	 * @return int
	 */
	private function loose_buttons( string $html ): int {
		$document = new \DOMDocument();
		$document->loadHTML( '<!DOCTYPE html><meta charset="utf-8">' . $html, LIBXML_NOERROR );
		$has = static fn ( string $name ): string => "contains(concat(' ', normalize-space(@class), ' '), ' $name ')";

		return (int) ( new \DOMXPath( $document ) )->evaluate( 'count(//*[' . $has( 'wp-block-button' ) . '][not(ancestor::*[' . $has( 'wp-block-buttons' ) . '])])' );
	}

	public function test_buttons_sit_in_a_buttons_block_as_in_core(): void {
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question );
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-votes /--><!-- wp:easyq/post-actions /--><!-- wp:easyq/answer-form /--><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/post-votes /--><!-- wp:easyq/post-actions /--><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->', new Route( 'question', $question ) );

		// The votes and actions of the question and its answer.
		$this->assertSame( 2, substr_count( $html, 'aria-label="Vote up"' ) );
		$this->assertSame( 2, substr_count( $html, 'value="hide"' ) );
		$this->assertSame( 0, $this->loose_buttons( $html ) );
		// The check finds a button outside a buttons block.
		$this->assertSame( 1, $this->loose_buttons( '<div class="wp-block-group"><form class="wp-block-button is-style-outline"><button class="wp-block-button__link">▲</button></form></div>' ) );
	}

	public function test_post_actions_follow_the_permissions(): void {
		$question = $this->ask( $this->user() );
		$markup   = '<!-- wp:easyq/question --><!-- wp:easyq/post-actions /--><!-- /wp:easyq/question -->';

		$this->assertStringNotContainsString( 'value="hide"', $this->render( $markup, new Route( 'question', $question ) ) );

		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$html = $this->render( $markup, new Route( 'question', $question ) );

		$this->assertStringContainsString( 'value="hide"', $html );
		$this->assertStringContainsString( 'easyq_edit=' . $question->id, $html );
		// Closing opens the question page in the state `close`, as in Question2Answer.
		$this->assertStringContainsString( 'easyq_close=' . $question->id . '#easyq-close-' . $question->id . '">Close</a>', $html );
		// Links that open a form in place are `nofollow`, like core's reply links of comments.
		$this->assertMatchesRegularExpression( '/rel="nofollow" href="[^"]*easyq_edit=' . $question->id . '[^"]*">Edit<\/a>/', $html );
		$this->assertMatchesRegularExpression( '/rel="nofollow" href="[^"]*easyq_close=' . $question->id . '[^"]*">Close<\/a>/', $html );
		$this->assertStringNotContainsString( 'name="note"', $html );
	}

	public function test_close_form_appears_below_the_question_when_requested(): void {
		$question = $this->ask( $this->user(), 'Question to close', 'The question text.' );
		$markup   = '<!-- wp:easyq/question --><!-- wp:easyq/post-content /--><!-- /wp:easyq/question -->';
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );

		$this->assertStringNotContainsString( 'name="note"', $this->render( $markup, new Route( 'question', $question ) ) );

		// The request address as in the browser; the cancel link is built from it.
		$this->go_to( add_query_arg( 'easyq_close', $question->id, URLs::question( $question ) ) );
		$this->assertTrue( Forms::closing( $question ) );
		$html   = $this->render( $markup, new Route( 'question', $question ) );
		$cancel = $this->cancel_link( $html );

		$this->assertStringContainsString( 'The question text.', $html );
		$this->assertLessThan( (int) strpos( $html, 'id="easyq-close-' . $question->id . '"' ), (int) strpos( $html, 'The question text.' ) );
		$this->assertStringContainsString( '<h2 id="easyq-close-' . $question->id . '-title">Close this question</h2>', $html );
		$this->assertStringContainsString( 'name="easyq_action" value="close"', $html );
		$this->assertStringContainsString( 'name="note"', $html );
		$this->assertStringContainsString( 'name="duplicate_of"', $html );
		$this->assertStringContainsString( '>Close question</button>', $html );
		$this->assertStringContainsString( (string) wp_parse_url( URLs::question( $question ), PHP_URL_PATH ), $cancel );
		$this->assertStringNotContainsString( 'easyq_close=', $cancel );
		// Back to the question, as core's link to cancel a reply goes back to the comment form.
		$this->assertStringEndsWith( '#easyq-post-' . $question->id, $cancel );

		// Not for users who may not close it, and not for another question.
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertStringNotContainsString( 'name="note"', $this->render( $markup, new Route( 'question', $question ) ) );
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$_GET['easyq_close'] = (string) ( $question->id + 1 );
		$this->assertStringNotContainsString( 'name="note"', $this->render( $markup, new Route( 'question', $question ) ) );
	}

	/**
	 * Returns the address of the cancel link of a form.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	private function cancel_link( string $html ): string {
		$this->assertSame( 1, preg_match( '/<a href="([^"]*)">Cancel<\/a>/', $html, $match ) );

		return html_entity_decode( $match[1] );
	}

	public function test_edit_form_replaces_the_content_for_editors(): void {
		$author             = $this->user();
		$question           = $this->ask( $author );
		$_GET['easyq_edit'] = (string) $question->id;

		$markup = '<!-- wp:easyq/question --><!-- wp:easyq/post-content /--><!-- /wp:easyq/question -->';
		$this->assertStringNotContainsString( 'name="easyq_form" value="edit"', $this->render( $markup, new Route( 'question', $question ) ) );

		wp_set_current_user( (int) $author->user_id );
		$html = $this->render( $markup, new Route( 'question', $question ) );
		$this->assertStringContainsString( 'name="easyq_form" value="edit"', $html );
		$this->assertStringEndsWith( '#easyq-post-' . $question->id, $this->cancel_link( $html ) );
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

	public function test_ask_form_follows_the_category_settings(): void {
		$parent = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Themes' );
		$this->assertIsInt( $child );
		wp_set_current_user( (int) $this->user()->user_id );

		$html = $this->render( '<!-- wp:easyq/ask-form /-->', new Route( 'ask' ) );
		$this->assertStringContainsString( '<select id="easyq-ask-category" name="category" required>', $html );
		$this->assertMatchesRegularExpression( '/<option value="' . $parent . '"\s+disabled=\'disabled\'>Software<\/option>/', $html );
		$this->assertStringContainsString( '<option value="' . $child . '">— Themes</option>', $html );

		Options::update(
			'posting',
			array(
				'allow_no_category'     => true,
				'allow_no_sub_category' => true,
			)
		);
		$html = $this->render( '<!-- wp:easyq/ask-form /-->', new Route( 'ask' ) );
		$this->assertStringContainsString( '<select id="easyq-ask-category" name="category">', $html );
		$this->assertStringContainsString( '<option value="' . $parent . '">Software</option>', $html );
	}

	/**
	 * Returns the forms with fields to fill in, each with the class of its parent element.
	 *
	 * @param string $html Rendered HTML.
	 * @return array<int, array{form: string, parent: string, breaks: int}>
	 */
	private function field_forms( string $html ): array {
		$document = new \DOMDocument();
		$document->loadHTML( '<!DOCTYPE html><meta charset="utf-8">' . $html, LIBXML_NOERROR );
		$xpath = new \DOMXPath( $document );
		$forms = array();
		$nodes = $xpath->query( '//form[.//textarea or .//input[not(@type="hidden") and not(@type="checkbox") and not(@type="submit")]]' );
		$this->assertInstanceOf( \DOMNodeList::class, $nodes );
		foreach ( $nodes as $form ) {
			$this->assertInstanceOf( \DOMElement::class, $form );
			$parents = $xpath->query( '..', $form );
			$parent  = false === $parents ? null : $parents->item( 0 );
			$forms[] = array(
				'form'   => $form->getAttribute( 'class' ),
				'parent' => $parent instanceof \DOMElement ? $parent->getAttribute( 'class' ) : '',
				// Line breaks between a label and its text field.
				'breaks' => (int) $xpath->evaluate( 'count(.//label/following-sibling::*[1][self::br][following-sibling::*[1][self::textarea or self::input]])', $form ),
			);
		}

		return $forms;
	}

	public function test_forms_take_on_the_markup_and_styles_of_the_comment_form(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		Services::categories()->create( null, 'Software' );
		Options::update( 'posting', array( 'comment_on_qs' => true ) );

		/*
		 * Core registers the style of the comment form block when it registers the block on `init`, and only
		 * while block assets load separately: in block themes, and since WordPress 7.1 in all themes. The test
		 * site of 6.7 starts with a classic theme, so this repeats that step with separate block assets.
		 */
		add_filter( 'should_load_separate_core_block_assets', '__return_true' );
		$block_json       = wp_normalize_path( ABSPATH . WPINC . '/blocks/post-comments-form/block.json' );
		$metadata         = (array) wp_json_file_decode( $block_json, array( 'associative' => true ) );
		$metadata['file'] = $block_json;
		$this->assertSame( 'wp-block-post-comments-form', register_block_style_handle( $metadata, 'style', 0 ) );
		$this->assertTrue( wp_style_is( 'wp-block-post-comments-form', 'registered' ) );
		wp_dequeue_style( 'wp-block-post-comments-form' );
		$this->assertFalse( wp_style_is( 'wp-block-post-comments-form' ) );

		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$html               = $this->render( '<!-- wp:easyq/ask-form /-->', new Route( 'ask' ) )
			. $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-actions /--><!-- wp:easyq/comment-form /--><!-- wp:easyq/answer-form /--><!-- /wp:easyq/question -->', new Route( 'question', $question ) )
			. do_blocks( '<!-- wp:easyq/account /-->' );
		$_GET['easyq_edit'] = (string) $question->id;
		$html              .= $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-content /--><!-- /wp:easyq/question -->', new Route( 'question', $question ) );
		unset( $_GET['easyq_edit'] );
		$_GET['easyq_close'] = (string) $question->id;
		$html               .= $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-content /--><!-- /wp:easyq/question -->', new Route( 'question', $question ) );

		$this->assertTrue( wp_style_is( 'wp-block-post-comments-form' ) );
		$forms = $this->field_forms( $html );
		// Ask, close, comment, answer, account and edit.
		$this->assertCount( 6, $forms );
		foreach ( $forms as $form ) {
			$this->assertSame( 'comment-form', $form['form'] );
			$this->assertContains( 'wp-block-post-comments-form', explode( ' ', $form['parent'] ) );
			$this->assertSame( 0, $form['breaks'] );
		}
		$this->assertStringContainsString( 'class="wp-block-post-comments-form wp-block-easyq-ask-form"', $html );

		// The check finds forms without the markup.
		$this->assertSame(
			array(
				array(
					'form'   => '',
					'parent' => '',
					'breaks' => 1,
				),
			),
			$this->field_forms( '<form method="post"><p><label for="x">X</label><br /><textarea id="x"></textarea></p></form>' )
		);
	}

	public function test_forms_get_the_comment_form_styles_from_the_block_library_otherwise(): void {
		// Without separate block assets core loads all block styles in one stylesheet.
		add_filter( 'should_load_separate_core_block_assets', '__return_false' );
		$this->assertFalse( wp_should_load_separate_core_block_assets() );

		$library = (string) file_get_contents( ABSPATH . WPINC . '/css/dist/block-library/style.css' );

		$this->assertStringContainsString( '.wp-block-post-comments-form .comment-form textarea', $library );
		// Border and font of the fields; WordPress 6.7 writes the selector as `:where(.wp-block-post-comments-form) textarea`.
		$this->assertMatchesRegularExpression( self::COMMENT_FORM_FIELD_RULE, $library );
		$this->assertDoesNotMatchRegularExpression( self::COMMENT_FORM_FIELD_RULE, '.wp-block-search__input{border:1px solid #949494;font-family:inherit}' );
	}

	public function test_category_errors_belong_to_the_select(): void {
		Services::categories()->create( null, 'Software' );
		wp_set_current_user( (int) $this->user()->user_id );
		$_GET[ Flash::QUERY_VAR ] = Flash::store( 'error', 'Please check the form.', 'ask', array( 'category' => 'Please select a category.' ) );

		$html = $this->render( '<!-- wp:easyq/ask-form /-->', new Route( 'ask' ) );

		$this->assertStringContainsString( '<select id="easyq-ask-category" name="category" required aria-invalid="true" aria-describedby="easyq-ask-category-error">', $html );
		$this->assertStringContainsString( '<strong id="easyq-ask-category-error" role="alert">Please select a category.</strong>', $html );
	}

	public function test_closed_questions_have_no_answer_form(): void {
		$question = $this->ask( $this->user() );
		Services::post_service()->close_with_note( Actor::system(), $question, 'Closed.' );
		wp_set_current_user( (int) $this->user()->user_id );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- wp:easyq/post-status /--><!-- /wp:easyq/question -->', new Route( 'question', $this->post( $question->id ) ) );

		$this->assertStringNotContainsString( 'easyq-answer-form', $html );
		$this->assertStringContainsString( 'Closed: Closed.', $html );
	}

	public function test_closing_notes_censor_block_words(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn' ) );
		$question = $this->ask( $this->user() );
		Services::post_service()->close_with_note( Actor::system(), $question, 'Darn spam.' );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-status /--><!-- /wp:easyq/question -->', new Route( 'question', $this->post( $question->id ) ) );

		$this->assertStringContainsString( 'Closed: **** spam.', $html );
	}

	public function test_duplicate_notes_censor_the_original_title(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn' ) );
		$original = $this->ask( $this->user(), 'Darn widget settings' );
		$question = $this->ask( $this->user() );
		Services::post_service()->close_as_duplicate( Actor::system(), $question, $original->id );

		$html = $this->render( '<!-- wp:easyq/question --><!-- wp:easyq/post-status /--><!-- /wp:easyq/question -->', new Route( 'question', $this->post( $question->id ) ) );

		$this->assertStringContainsString( '**** widget settings</a>', $html );
	}

	public function test_page_titles_navigation_and_lists(): void {
		$this->ask( $this->user(), 'Tagged question title', 'x', array( 'php' ) );
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );

		$this->assertStringContainsString( 'Questions tagged “php”', $this->render( '<!-- wp:easyq/page-title /-->', new Route( 'tag', 'php' ) ) );
		$sorts = $this->render( '<!-- wp:easyq/sort-navigation /-->', new Route( 'questions' ) );
		$this->assertStringContainsString( 'aria-current="page">Recent questions', $sorts );
		$this->assertStringContainsString( 'href="' . esc_url( add_query_arg( 'sort', 'votes', Routes::url( 'questions' ) ) ) . '"', $sorts );
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
