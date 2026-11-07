<?php
/**
 * Tests for the block settings that override site settings.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render\Forms;
use EasyQ\Blocks\Render\Query_Resolver;
use EasyQ\Frontend\Route;
use EasyQ\Posts\Post;
use EasyQ\Search\Indexed_Post;
use EasyQ\Search\Search_Provider;
use EasyQ\Search\Search_Result;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;

/**
 * @covers \EasyQ\Blocks\Render\Forms
 * @covers \EasyQ\Blocks\Render\Lists_Render
 * @covers \EasyQ\Blocks\Render\Widgets
 * @covers \EasyQ\Blocks\Render\Post_Fields
 * @covers \EasyQ\Blocks\Render\Query_Resolver
 * @covers \EasyQ\Blocks\Render
 */
final class Block_Settings_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();
		Lists::reset();
	}

	public function tear_down(): void {
		unset( $_GET['q'], $_GET[ Forms::ANSWER_VAR ], $_GET[ Forms::COMMENT_VAR ] );
		Block_Context::router()->set_current( null );
		Services::reset();
		Lists::reset();
		parent::tear_down();
	}

	/**
	 * Renders the answers of a question with answer list settings.
	 *
	 * @param Post                 $question   Question.
	 * @param array<string, mixed> $attributes Answer list attributes.
	 * @return string
	 */
	private function answers( Post $question, array $attributes ): string {
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		return do_blocks(
			'<!-- wp:easyq/question --><!-- wp:easyq/answer-list ' . wp_json_encode( $attributes ) . ' --><!-- wp:easyq/answer-template --><!-- wp:easyq/post-content /--><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->'
		);
	}

	public function test_the_answer_list_can_override_order_and_page_size(): void {
		Options::update( 'viewing', array( 'sort_answers_by' => 'created' ) );
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question, 'The older answer here.' );
		$newer = $this->answer( $this->user(), $question, 'The newer, better answer.' );
		Services::votes()->vote( $this->user(), $newer, 1 );

		$site = $this->answers( $question, array() );
		$this->assertLessThan( strpos( $site, 'better answer' ), strpos( $site, 'older answer' ), 'The site setting sorts by date.' );

		$by_votes = $this->answers( $question, array( 'order' => 'votes' ) );
		$this->assertLessThan( strpos( $by_votes, 'older answer' ), strpos( $by_votes, 'better answer' ) );

		$one = $this->answers( $question, array( 'perPage' => 1 ) );
		$this->assertStringContainsString( 'older answer', $one );
		$this->assertStringNotContainsString( 'better answer', $one );
	}

	public function test_the_answer_list_can_turn_off_showing_the_best_answer_first(): void {
		Options::update(
			'viewing',
			array(
				'sort_answers_by'     => 'created',
				'show_selected_first' => true,
			)
		);
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$this->answer( $this->user(), $question, 'The first answer here.' );
		$best = $this->answer( $this->user(), $question, 'The selected best answer.' );
		Services::post_service()->select_answer( $asker, $this->post( $question->id ), $best->id );

		$site = $this->answers( $this->post( $question->id ), array() );
		$this->assertLessThan( strpos( $site, 'first answer' ), strpos( $site, 'best answer' ) );

		$off = $this->answers( $this->post( $question->id ), array( 'selectedFirst' => 'no' ) );
		$this->assertLessThan( strpos( $off, 'best answer' ), strpos( $off, 'first answer' ) );
	}

	public function test_the_comment_list_can_override_when_comments_collapse(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		foreach ( array( 'one', 'two', 'three' ) as $number ) {
			$this->comment( $this->user(), $question, $answer, "Comment number $number here." );
		}
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$html = do_blocks(
			'<!-- wp:easyq/question {"questionId":' . $question->id . '} --><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/comment-list {"showFewerFrom":2,"showFewerCount":1} --><!-- wp:easyq/comment-template --><!-- wp:easyq/post-content /--><!-- /wp:easyq/comment-template --><!-- /wp:easyq/comment-list --><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->'
		);

		$this->assertStringContainsString( 'Show 2 previous comments', $html );
		$this->assertStringContainsString( 'Comment number three here.', $html );
		$this->assertStringNotContainsString( 'Comment number one here.', $html );
		// The link is part of the list, so the layout classes of the block land on the list.
		$this->assertMatchesRegularExpression( '~<ul class="[^"]*wp-block-easyq-comment-template[^"]*is-layout-flow[^"]*"><li><a [^>]*>Show 2 previous comments</a></li><li id="easyq-post-~', $html );
	}

	public function test_two_fixed_lists_on_one_page_are_paginated_separately(): void {
		foreach ( range( 1, 3 ) as $number ) {
			$this->ask( $this->user(), "Question number $number of enough length" );
		}
		Block_Context::router()->set_current( new Route( 'questions' ) );

		$list = static fn ( int $id, string $source ): string => '<!-- wp:easyq/question-query {"queryId":' . $id . ',"query":{"source":"' . $source . '","perPage":1,"inherit":false}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- wp:easyq/query-pagination /--><!-- /wp:easyq/question-query -->';
		$html = do_blocks( $list( 7, 'recent' ) . $list( 8, 'votes' ) );

		// The list IDs are numbers in the block markup; each list gets its own page parameter.
		$this->assertStringContainsString( 'easyq_page_7=2', $html );
		$this->assertStringContainsString( 'easyq_page_8=2', $html );
		$this->assertStringNotContainsString( 'easyq_page_=', $html );
		// Lists without an ID, e.g. patterns placed in a theme file, get one each when they render.
		$plain = static fn ( string $source ): string => '<!-- wp:easyq/question-query {"query":{"source":"' . $source . '","perPage":1,"inherit":false}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- wp:easyq/query-pagination /--><!-- /wp:easyq/question-query -->';
		$html  = do_blocks( $plain( 'recent' ) . $plain( 'votes' ) );
		$this->assertStringContainsString( 'easyq_page_1000=2', $html );
		$this->assertStringContainsString( 'easyq_page_1001=2', $html );
		$this->assertStringNotContainsString( 'easyq_page_=', $html );

		// The pagination is a flex layout of the theme, not a hard-coded class. WordPress 6.7 names the
		// layout class after the block without its namespace, 7.1 with it.
		$this->assertMatchesRegularExpression( '~<nav class="[^"]*\bwp-block-(easyq-)?query-pagination-is-layout-flex\b~', $html );
	}

	public function test_the_date_can_be_relative_or_absolute(): void {
		$question = $this->ask( $this->user() );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$this->assertStringContainsString( ' ago', do_blocks( '<!-- wp:easyq/post-date {"display":"relative"} /-->' ) );
		$this->assertStringNotContainsString( ' ago', do_blocks( '<!-- wp:easyq/post-date {"display":"absolute"} /-->' ) );
	}

	public function test_votes_can_be_shown_separately(): void {
		$question = $this->ask( $this->user() );
		Services::votes()->vote( $this->user(), $question, 1 );
		Block_Context::router()->set_current( new Route( 'question', $this->post( $question->id ) ) );

		$net = do_blocks( '<!-- wp:easyq/post-votes {"display":"net"} /-->' );
		$this->assertStringContainsString( 'data-wp-text="context.net"', $net );
		$this->assertStringNotContainsString( 'context.down', $net );

		$separated = do_blocks( '<!-- wp:easyq/post-votes {"display":"separated"} /-->' );
		$this->assertStringContainsString( 'Upvotes: </span><span data-wp-text="context.up">1<', $separated );
		$this->assertStringContainsString( 'Downvotes: </span><span data-wp-text="context.down">0<', $separated );

		Options::update( 'viewing', array( 'votes_separated' => true ) );
		$this->assertStringContainsString( 'context.up', do_blocks( '<!-- wp:easyq/post-votes /-->' ), 'The site setting applies without a block setting.' );
	}

	public function test_pagination_offers_arrows_labels_and_numbers(): void {
		Lists::store( 'list', array( 1, 2 ), 6, 2, 2, 'easyq_page' );
		$render = static fn ( array $attributes ): string => ( new WP_Block(
			array(
				'blockName'    => 'easyq/query-pagination',
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'easyq/listId' => 'list' )
		) )->render();

		$default = $render( array() );
		$this->assertStringContainsString( 'wp-block-query-pagination-numbers', $default );
		$this->assertStringContainsString( '>Previous</a>', $default );
		$this->assertStringNotContainsString( '-arrow', $default );

		$arrows = $render(
			array(
				'arrow'       => 'chevron',
				'showLabel'   => false,
				'showNumbers' => false,
			)
		);
		$this->assertStringContainsString( 'is-arrow-chevron" aria-hidden="true">«</span><span class="screen-reader-text">Previous</span>', $arrows );
		$this->assertStringContainsString( '<span class="screen-reader-text">Next</span><span class="wp-block-query-pagination-next-arrow is-arrow-chevron" aria-hidden="true">»</span>', $arrows );
		$this->assertStringNotContainsString( 'wp-block-query-pagination-numbers', $arrows );

		$this->assertStringContainsString( '>Previous</a>', $render( array( 'showLabel' => false ) ), 'Without arrows the labels stay visible.' );
	}

	public function test_terms_have_a_prefix_and_suffix(): void {
		$category = (int) Services::categories()->create( null, 'Cooking' );
		$question = $this->ask( $this->user(), 'Terms question', 'x', array( 'pasta' ), $category );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$tags = do_blocks( '<!-- wp:easyq/post-tags {"prefix":"Tags: ","suffix":"."} /-->' );
		$this->assertStringContainsString( '<span class="wp-block-post-terms__prefix">Tags: </span>', $tags );
		$this->assertStringContainsString( '<span class="wp-block-post-terms__suffix">.</span>', $tags );

		$category_html = do_blocks( '<!-- wp:easyq/post-category {"prefix":"In "} /-->' );
		$this->assertStringContainsString( '<span class="wp-block-post-terms__prefix">In </span><a', $category_html );
		$this->assertStringNotContainsString( '__suffix', $category_html );
	}

	public function test_question_lists_can_show_the_questions_of_one_author(): void {
		$author = $this->user();
		$this->ask( $author, 'Asked by the chosen author' );
		$this->ask( $this->user(), 'Asked by someone else' );

		$this->assertSame(
			array(
				'source'  => 'recent',
				'user_id' => (int) $author->user_id,
			),
			Query_Resolver::arguments(
				array(
					'inherit' => false,
					'author'  => $author->user_id,
				)
			)
		);

		$html = do_blocks(
			'<!-- wp:easyq/question-query {"queryId":7,"query":{"inherit":false,"source":"recent","author":' . $author->user_id . '}} --><!-- wp:easyq/question-template --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question-template --><!-- /wp:easyq/question-query -->'
		);
		$this->assertStringContainsString( 'Asked by the chosen author', $html );
		$this->assertStringNotContainsString( 'Asked by someone else', $html );
	}

	public function test_question_lists_take_the_page_size_of_their_source(): void {
		$author = $this->user();
		foreach ( array( 'First', 'Second', 'Third', 'Fourth', 'Fifth' ) as $word ) {
			$this->ask( $author, $word . ' paging question about walruses', 'Text.', array( 'paging' ) );
		}
		Options::update(
			'lists',
			array(
				'page_size_qs'     => 5,
				'page_size_hot_qs' => 1,
				'page_size_una_qs' => 2,
				'page_size_tag_qs' => 3,
				'page_size_search' => 4,
			)
		);
		$count = static fn ( array $query ): int => count( Query_Resolver::questions( $query + array( 'inherit' => false ), 'size' )['ids'] );

		$this->assertSame( 5, $count( array( 'source' => 'recent' ) ) );
		$this->assertSame( 1, $count( array( 'source' => 'hot' ) ) );
		$this->assertSame( 2, $count( array( 'source' => 'unanswered' ) ) );
		$this->assertSame( 2, $count( array( 'source' => 'unselected' ) ) );
		$this->assertSame( 3, $count( array( 'tag' => 'paging' ) ) );
		// A page size of the block still wins.
		$this->assertSame(
			4,
			$count(
				array(
					'source'  => 'hot',
					'perPage' => 4,
				)
			)
		);

		Block_Context::router()->set_current( new Route( 'search' ) );
		$_GET['q'] = 'walruses';
		$this->assertSame( 4, count( Query_Resolver::questions( array( 'inherit' => true ), 'search' )['ids'] ) );
	}

	/**
	 * Renders the answer form of a question for the current user.
	 *
	 * @param Post $question Question.
	 * @return string
	 */
	private function answer_form( Post $question ): string {
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		return do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- /wp:easyq/question -->' );
	}

	public function test_the_answer_form_is_shown_right_away_as_the_setting_says(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker );
		wp_set_current_user( (int) $this->user()->user_id );

		// By default the form is shown while the question has no answers, as in Question2Answer.
		$html = $this->answer_form( $question );
		$this->assertStringContainsString( 'id="easyq-answer-content"', $html );
		$this->assertStringNotContainsString( 'actions.openAnswer', $html );

		$this->answer( $this->user(), $question );
		$question = Services::posts()->find( $question->id );
		$this->assertNotNull( $question );
		$html = $this->answer_form( $question );
		$this->assertStringContainsString( 'data-wp-on--click="actions.openAnswer"', $html );
		$this->assertStringContainsString( 'aria-controls="easyq-answer-body" aria-expanded="false"', $html );
		$this->assertStringContainsString( 'easyq_answer=' . $question->id, $html );
		$this->assertMatchesRegularExpression( '/<div id="easyq-answer-body" hidden[^>]*>.*id="easyq-answer-content"/s', $html );

		// The address of the button opens the form without JavaScript.
		$_GET[ Forms::ANSWER_VAR ] = (string) $question->id;
		$this->assertStringNotContainsString( 'actions.openAnswer', $this->answer_form( $question ) );
		unset( $_GET[ Forms::ANSWER_VAR ] );

		Options::update( 'viewing', array( 'show_a_form_immediate' => 'always' ) );
		$this->assertStringNotContainsString( 'actions.openAnswer', $this->answer_form( $question ) );

		// The author of the question gets the button while there are no answers, too.
		Options::update( 'viewing', array( 'show_a_form_immediate' => 'if_no_as' ) );
		wp_set_current_user( (int) $asker->user_id );
		$unanswered = $this->ask( $asker, 'A question nobody answered yet' );
		$this->assertStringContainsString( 'actions.openAnswer', $this->answer_form( $unanswered ) );

		Options::update( 'viewing', array( 'show_a_form_immediate' => 'never' ) );
		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertStringContainsString( 'actions.openAnswer', $this->answer_form( $unanswered ) );
	}

	public function test_forms_show_the_site_owners_text_above_them(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		wp_set_current_user( (int) $this->user()->user_id );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$forms = static fn (): string => do_blocks( '<!-- wp:easyq/ask-form /-->' )
			. do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/comment-form /--><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->' );

		Options::update(
			'posting',
			array(
				'custom_ask'     => '<p>Search <strong>first</strong>.</p><script>alert(1)</script>',
				'custom_answer'  => '<p>Answer kindly.</p>',
				'custom_comment' => '<p>Comment briefly.</p>',
			)
		);
		$this->assertStringNotContainsString( 'Answer kindly.', $forms() );

		Options::update(
			'posting',
			array(
				'show_custom_ask'     => true,
				'show_custom_answer'  => true,
				'show_custom_comment' => true,
			)
		);
		$_GET[ Forms::ANSWER_VAR ] = (string) $question->id;
		$html                      = $forms();
		$this->assertStringContainsString( '<div><p>Search <strong>first</strong>.</p>alert(1)</div>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertMatchesRegularExpression( '/<div><p>Answer kindly\.<\/p><\/div>.*id="easyq-answer-content"/s', $html );
		$this->assertMatchesRegularExpression( '/<div><p>Comment briefly\.<\/p><\/div>.*id="easyq-comment-' . $answer->id . '-content"/s', $html );
	}

	public function test_the_question_block_shows_only_questions(): void {
		$question = $this->ask( $this->user(), 'The question of the question block' );
		$answer   = $this->answer( $this->user(), $question );
		$block    = static fn ( int $id ): string => do_blocks( '<!-- wp:easyq/question {"questionId":' . $id . '} --><!-- wp:easyq/post-title /--><!-- /wp:easyq/question -->' );

		$this->assertStringContainsString( 'The question of the question block', $block( $question->id ) );
		$this->assertSame( '', trim( $block( $answer->id ) ) );
	}

	public function test_avatars_take_the_sizes_of_the_core_blocks(): void {
		$question = $this->ask( $this->user() );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		// 48 pixels by default, as core's Post Author block.
		$this->assertStringContainsString( "height='48' width='48'", do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-author /--><!-- /wp:easyq/question -->' ) );

		// On the question page the question keeps that, answers show 40 as core's comment template.
		$page = \EasyQ\Blocks\Layouts::question_page();
		$this->assertSame( 1, substr_count( $page, '<!-- wp:easyq/post-author {"fontSize":"small"} /-->' ) );
		$this->assertSame( 1, substr_count( $page, '<!-- wp:easyq/post-author {"avatarSize":40,"fontSize":"small"} /-->' ) );
		$this->assertSame( 1, substr_count( $page, '<!-- wp:easyq/post-extra {"fontSize":"small"} /-->' ) );
	}

	public function test_the_author_shows_their_title_unless_turned_off(): void {
		Options::update( 'points', array( 'user_titles' => array( 0 => 'Walrus keeper' ) ) );
		$question = $this->ask( $this->user() );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$author = static fn ( string $attributes = '' ): string => do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-author ' . $attributes . ' /--><!-- /wp:easyq/question -->' );

		$this->assertStringContainsString( '<span>Walrus keeper</span>', $author() );
		$this->assertStringNotContainsString( 'Walrus keeper', $author( '{"showTitle":false}' ) );

		Options::update( 'viewing', array( 'show_user_titles' => false ) );
		$this->assertStringNotContainsString( 'Walrus keeper', $author() );
	}

	public function test_related_questions_must_match_as_closely_as_the_setting_says(): void {
		$question = $this->ask( $this->user(), 'Feeding the walrus module' );
		$close    = $this->ask( $this->user(), 'A closely related question' );
		$loose    = $this->ask( $this->user(), 'A loosely related question' );

		// A search provider with fixed scores, as the scores of the built-in search depend on all posts.
		$provider = new class( array(
			$close->id => 7.0,
			$loose->id => 3.0,
		) ) implements Search_Provider {
			/**
			 * @param array<int, float> $scores Scores by question ID.
			 */
			public function __construct( private readonly array $scores ) {
			}

			public function id(): string {
				return 'fixed';
			}

			public function label(): string {
				return 'Fixed';
			}

			public function index_post( Indexed_Post $post ): void {
			}

			public function unindex_post( int $post_id ): void {
			}

			public function move_post( int $post_id, ?int $category_id ): void {
			}

			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				$results = array();
				foreach ( $this->scores as $id => $score ) {
					$results[] = new Search_Result( $id, $score, 'Q', $id );
				}

				return $results;
			}
		};
		add_filter( 'easyq_search_providers', static fn ( array $providers ): array => array_merge( $providers, array( $provider ) ) );
		Services::reset();
		Options::update( 'lists', array( 'search_provider' => 'fixed' ) );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$related = static fn (): string => do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/related-questions /--><!-- /wp:easyq/question -->' );

		// The default needs a score of 4, as `qa_match_to_min_score()` gives for 3.
		$html = $related();
		$this->assertStringContainsString( 'A closely related question', $html );
		$this->assertStringNotContainsString( 'A loosely related question', $html );

		Options::update( 'viewing', array( 'match_related_qs' => 5 ) );
		$this->assertStringContainsString( 'A loosely related question', $related() );

		Options::update( 'viewing', array( 'match_related_qs' => 1 ) );
		$this->assertStringNotContainsString( 'related question', $related() );
	}

	public function test_buttons_take_the_font_size_of_their_block(): void {
		$question = $this->ask( $this->user() );
		wp_set_current_user( (int) $this->user()->user_id );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$votes = static fn ( string $attributes ): string => do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-votes ' . $attributes . ' /--><!-- /wp:easyq/question -->' );

		$this->assertMatchesRegularExpression( '/<button type="submit" class="wp-block-button__link wp-element-button has-small-font-size"/', $votes( '{"fontSize":"small"}' ) );
		$this->assertMatchesRegularExpression( '/<button type="submit" class="wp-block-button__link wp-element-button" style="font-size:1\.1rem;"/', $votes( '{"style":{"typography":{"fontSize":"1.1rem"}}}' ) );
		// Without a size the theme's size for buttons applies.
		$html = $votes( '{}' );
		$this->assertStringContainsString( '<button type="submit" class="wp-block-button__link wp-element-button" aria-label', $html );
		$this->assertStringNotContainsString( 'font-size', $html );
	}

	public function test_forms_use_the_markup_of_the_core_comment_form(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		Options::update( 'permissions', array( 'permit_post_a' => \EasyQ\Users\Permits::ALL ) );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$_GET[ Forms::ANSWER_VAR ] = (string) $question->id;

		$html = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form /--><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/comment-form /--><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->' );

		$this->assertStringContainsString( '<p class="comment-form-comment"><label for="easyq-answer-content">', $html );
		$this->assertStringContainsString( '<p class="comment-form-email"><label for="easyq-answer-email">', $html );
		$this->assertStringContainsString( '<p class="comment-form-author"><label for="easyq-answer-name">', $html );
		$this->assertStringContainsString( '<p class="comment-form-cookies-consent"><label><input type="checkbox" name="notify"', $html );
		$this->assertStringContainsString( '<p class="comment-notes"><small>', $html );
		$this->assertStringContainsString( '<p class="form-submit wp-block-button"><button type="submit" class="wp-block-button__link wp-element-button">Answer</button></p>', $html );
		$this->assertMatchesRegularExpression( '/<details class="wp-block-details wp-block-easyq-comment-form[^"]*" id="easyq-comment-form-' . $answer->id . '"[ >]/', $html );
	}

	public function test_the_date_has_a_format_of_its_own(): void {
		$question = $this->ask( $this->user() );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$expected = (string) wp_date( 'd/m/Y', (int) strtotime( $question->created . ' UTC' ) );

		$this->assertStringContainsString( '>' . $expected . '</time>', do_blocks( '<!-- wp:easyq/post-date {"display":"absolute","format":"d/m/Y"} /-->' ) );
		$this->assertStringContainsString( '>' . wp_date( (string) get_option( 'date_format' ), (int) strtotime( $question->created . ' UTC' ) ) . '</time>', do_blocks( '<!-- wp:easyq/post-date {"display":"absolute"} /-->' ), 'Without a format the site format applies.' );
	}

	public function test_links_can_open_in_a_new_tab(): void {
		$author   = $this->user();
		$user     = get_userdata( (int) $author->user_id );
		$question = $this->ask( $author );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$this->assertInstanceOf( \WP_User::class, $user );

		$title = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-title {"isLink":true,"linkTarget":"_blank","rel":"nofollow"} /--><!-- /wp:easyq/question -->' );
		$this->assertMatchesRegularExpression( '~<a href="[^"]+" target="_blank" rel="nofollow">~', $title );
		$this->assertStringNotContainsString( 'target=', do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-title {"isLink":true} /--><!-- /wp:easyq/question -->' ) );

		$author_html = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-author {"isLink":false,"showAvatar":false} /--><!-- /wp:easyq/question -->' );
		$this->assertStringContainsString( '<span>' . $user->display_name . '</span>', $author_html );
		$this->assertStringNotContainsString( '<a ', $author_html );

		Block_Context::router()->set_current( new Route( 'user', $user ) );
		$this->assertStringContainsString( '" target="_blank">' . $user->display_name . '</a>', do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-name {"linkTarget":"_blank"} /--><!-- /wp:easyq/user -->' ) );
		$avatar = do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-avatar {"isLink":true,"linkTarget":"_blank"} /--><!-- /wp:easyq/user -->' );
		$this->assertStringContainsString( 'target="_blank"', $avatar );
		$this->assertStringContainsString( "alt='" . $user->display_name . " (opens in a new tab)'", $avatar );
	}

	public function test_headings_can_be_hidden_or_take_another_level(): void {
		$question = $this->ask( $this->user() );
		$this->answer( $this->user(), $question );

		$this->assertStringContainsString( '<h2>1 answer</h2>', $this->answers( $question, array( 'order' => '' ) ) );
		$this->assertStringContainsString( '<h4>1 answer</h4>', $this->answers( $question, array( 'level' => 4 ) ) );
		$this->assertStringNotContainsString( '1 answer', $this->answers( $question, array( 'showTitle' => false ) ) );

		Options::update( 'permissions', array( 'permit_post_a' => \EasyQ\Users\Permits::ALL ) );
		$_GET[ Forms::ANSWER_VAR ] = (string) $question->id;
		$this->assertStringContainsString( '<h3>Your answer</h3>', do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form {"level":3} /--><!-- /wp:easyq/question -->' ) );
		$this->assertStringNotContainsString( '<h2>Your answer</h2>', do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-form {"showTitle":false} /--><!-- /wp:easyq/question -->' ) );
	}

	public function test_search_fields_have_the_options_of_the_core_search_block(): void {
		$default = do_blocks( '<!-- wp:easyq/search /-->' );
		$this->assertMatchesRegularExpression( '~class="wp-block-search wp-block-search__button-outside wp-block-search__text-button wp-block-easyq-search~', $default );
		$this->assertStringContainsString( '<label class="wp-block-search__label" for=', $default );
		$this->assertStringContainsString( '>Search questions</label>', $default );
		$this->assertStringContainsString( '<button type="submit" class="wp-block-search__button wp-element-button">Search</button>', $default );

		$custom = do_blocks( '<!-- wp:easyq/search {"label":"Find","showLabel":false,"buttonText":"Go","buttonPosition":"button-inside"} /-->' );
		$this->assertStringContainsString( 'wp-block-search__button-inside', $custom );
		$this->assertStringContainsString( '<label class="wp-block-search__label screen-reader-text" for=', $custom );
		$this->assertStringContainsString( '>Find</label>', $custom );
		$this->assertStringContainsString( 'wp-element-button">Go</button>', $custom );

		$ask = do_blocks( '<!-- wp:easyq/ask-box {"buttonPosition":"no-button"} /-->' );
		$this->assertStringContainsString( 'wp-block-search__no-button', $ask );
		$this->assertStringNotContainsString( '<button', $ask );
		$this->assertStringNotContainsString( '__text-button', $ask );
		$this->assertStringContainsString( 'name="title"', $ask );
	}

	public function test_pagination_shows_as_many_page_numbers_as_mid_size_says(): void {
		Lists::store( 'list', array( 1, 2 ), 20, 5, 2, 'easyq_page' );
		$render = static fn ( array $attributes ): string => ( new WP_Block(
			array(
				'blockName'    => 'easyq/query-pagination',
				'attrs'        => $attributes,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'easyq/listId' => 'list' )
		) )->render();

		$number  = static fn ( int $page ): string => sprintf( '<a class="page-numbers" href="?easyq_page=%d">', $page );
		$default = $render( array() );
		$this->assertStringContainsString( $number( 3 ), $default );
		$this->assertStringContainsString( $number( 7 ), $default );
		$this->assertStringNotContainsString( $number( 8 ), $default );

		$narrow = $render( array( 'midSize' => 0 ) );
		$this->assertStringNotContainsString( $number( 4 ), $narrow );
		$this->assertStringContainsString( $number( 10 ), $narrow, 'The first and last page stay.' );
	}

	public function test_categories_can_be_flat_without_empty_ones_or_a_dropdown(): void {
		$software = (int) Services::categories()->create( null, 'Software' );
		$themes   = (int) Services::categories()->create( $software, 'Themes' );
		Services::categories()->create( null, 'Hardware' );
		$this->ask( $this->user(), 'A question about themes', 'x', array(), $themes );

		$nested = do_blocks( '<!-- wp:easyq/categories /-->' );
		$this->assertMatchesRegularExpression( '~>Software</a>[^<]*<span>\(\d+\)</span><ul><li><a [^>]+>Themes</a>~', $nested );
		$this->assertStringContainsString( '>Hardware</a>', $nested );

		$flat = do_blocks( '<!-- wp:easyq/categories {"showHierarchy":false,"showCounts":false} /-->' );
		$this->assertMatchesRegularExpression( '~>Software</a></li><li><a [^>]+>Themes</a></li>~', $flat );

		$filled = do_blocks( '<!-- wp:easyq/categories {"showEmpty":false} /-->' );
		$this->assertStringNotContainsString( 'Hardware', $filled );
		$this->assertStringContainsString( '>Themes</a>', $filled, 'An empty parent of a category with questions stays.' );

		$dropdown = do_blocks( '<!-- wp:easyq/categories {"displayAsDropdown":true,"showCounts":false} /-->' );
		$this->assertStringContainsString( '<input type="hidden" name="easyq_route" value="category" />', $dropdown );
		$this->assertMatchesRegularExpression( '~<select id="[^"]+" name="easyq_arg" required>~', $dropdown );
		$this->assertStringContainsString( '<option value="software/themes">&nbsp;&nbsp;&nbsp;Themes</option>', $dropdown );
		$this->assertStringContainsString( '<button type="submit" class="wp-element-button">Go</button>', $dropdown );
	}

	public function test_rows_choose_what_counts_and_profiles_show(): void {
		$this->ask( $this->user() );
		$counts = do_blocks( '<!-- wp:easyq/activity-count {"rows":["questions"]} /-->' );
		$this->assertStringContainsString( '1 question', $counts );
		$this->assertStringNotContainsString( 'user', $counts );
		$this->assertStringContainsString( ' user</li>', do_blocks( '<!-- wp:easyq/activity-count /-->' ) );

		$id = self::factory()->user->create(
			array(
				'user_url'    => 'https://example.org',
				'description' => 'Keeps walruses.',
			)
		);
		Block_Context::router()->set_current( new Route( 'user', get_userdata( $id ) ) );

		$stats = do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-stats {"rows":["points","comments"]} /--><!-- /wp:easyq/user -->' );
		$this->assertStringContainsString( 'Points', $stats );
		$this->assertStringContainsString( 'Comments', $stats );
		$this->assertStringNotContainsString( 'Member for', $stats );
		$this->assertStringContainsString( 'Member for', do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-stats /--><!-- /wp:easyq/user -->' ) );

		$fields = do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-fields {"rows":["about"]} /--><!-- /wp:easyq/user -->' );
		$this->assertStringContainsString( 'Keeps walruses.', $fields );
		$this->assertStringNotContainsString( 'example.org', $fields );
		$this->assertStringContainsString( 'example.org', do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-fields /--><!-- /wp:easyq/user -->' ) );
		$this->assertStringNotContainsString( '<dl', do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-fields {"rows":[]} /--><!-- /wp:easyq/user -->' ) );
	}

	public function test_comments_have_a_reply_button_that_opens_the_comment_form_of_their_parent(): void {
		$question = $this->ask( $this->user() );
		$answerer = $this->user();
		$answer   = $this->answer( $answerer, $question );
		$this->comment( $this->user(), $question, $answer );
		Options::update( 'permissions', array( 'permit_post_c' => \EasyQ\Users\Permits::ALL ) );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		wp_set_current_user( (int) $answerer->user_id );
		$page = static fn (): string => do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/post-actions /--><!-- wp:easyq/comment-list --><!-- wp:easyq/comment-template --><!-- wp:easyq/post-actions /--><!-- /wp:easyq/comment-template --><!-- /wp:easyq/comment-list --><!-- wp:easyq/comment-form /--><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->' );

		$html = $page();
		$this->assertMatchesRegularExpression( '~<a class="wp-block-button__link wp-element-button" rel="nofollow" href="[^"]*easyq_comment=' . $answer->id . '#easyq-comment-form-' . $answer->id . '" aria-controls="easyq-comment-form-' . $answer->id . '" aria-expanded="false"[^>]*>Reply</a>~', $html );
		$this->assertMatchesRegularExpression( '~<details [^>]*id="easyq-comment-form-' . $answer->id . '"(?![^>]* open)[^>]*>~', $html, 'The form stays closed.' );
		// The addresses of answers have an anchor of their own, which gives way to the anchor of the form.
		$this->assertMatchesRegularExpression( '~href="[^"]*easyq_edit=' . $answer->id . '#easyq-post-' . $answer->id . '"~', $html );
		$this->assertStringNotContainsString( '#easyq-post-' . $answer->id . '#', $html );

		// Without JavaScript the link opens the form, as the Answer button does.
		$_GET[ Forms::COMMENT_VAR ] = (string) $answer->id;
		$this->assertMatchesRegularExpression( '~<details [^>]*id="easyq-comment-form-' . $answer->id . '"[^>]* open[ =>]~', $page() );
		$this->assertTrue( Forms::opens_a_form(), 'The address with the open form is not indexed.' );
		unset( $_GET[ Forms::COMMENT_VAR ] );

		Options::update( 'viewing', array( 'show_c_reply_buttons' => false ) );
		$this->assertStringNotContainsString( '>Reply</a>', $page() );

		Options::update( 'viewing', array( 'show_c_reply_buttons' => true ) );
		Options::update( 'posting', array( 'comment_on_as' => false ) );
		$this->assertStringNotContainsString( '>Reply</a>', $page(), 'Without comments on answers there is nothing to reply with.' );
	}

	public function test_the_tag_field_suggests_tags_only_when_the_setting_is_on(): void {
		wp_set_current_user( (int) $this->user()->user_id );
		Block_Context::router()->set_current( new Route( 'ask' ) );

		$html = do_blocks( '<!-- wp:easyq/ask-form /-->' );
		$this->assertMatchesRegularExpression( '~<input type="text" id="easyq-ask-tags" name="tags" value="" list="easyq-ask-tags-list" autocomplete="off" data-wp-on--input="actions.suggestTags"~', $html );
		$this->assertStringContainsString( '<datalist id="easyq-ask-tags-list">', $html );

		Options::update( 'posting', array( 'do_complete_tags' => false ) );
		$html = do_blocks( '<!-- wp:easyq/ask-form /-->' );
		$this->assertStringContainsString( 'name="tags"', $html );
		$this->assertStringNotContainsString( 'list="', $html );
		$this->assertStringNotContainsString( '<datalist', $html );
	}

	public function test_terms_carry_the_class_of_their_taxonomy_as_in_core(): void {
		$category = (int) Services::categories()->create( null, 'Cooking' );
		$question = $this->ask( $this->user(), 'Terms question', 'x', array( 'pasta' ), $category );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$this->assertMatchesRegularExpression( '~class="[^"]*wp-block-post-terms taxonomy-easyq-tag~', do_blocks( '<!-- wp:easyq/post-tags /-->' ) );
		$this->assertMatchesRegularExpression( '~class="[^"]*wp-block-post-terms taxonomy-easyq-category~', do_blocks( '<!-- wp:easyq/post-category /-->' ) );
	}
}
