<?php
/**
 * Tests for the block settings that override site settings.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render\Query_Resolver;
use EasyQ\Frontend\Route;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;

/**
 * @covers \EasyQ\Blocks\Render\Lists_Render
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
		Block_Context::router()->set_current( null );
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
			'<!-- wp:easyq/question {"questionId":' . $answer->id . '} --><!-- wp:easyq/comment-list {"showFewerFrom":2,"showFewerCount":1} --><!-- wp:easyq/comment-template --><!-- wp:easyq/post-content /--><!-- /wp:easyq/comment-template --><!-- /wp:easyq/comment-list --><!-- /wp:easyq/question -->'
		);

		$this->assertStringContainsString( 'Show 2 previous comments', $html );
		$this->assertStringContainsString( 'Comment number three here.', $html );
		$this->assertStringNotContainsString( 'Comment number one here.', $html );
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
}
