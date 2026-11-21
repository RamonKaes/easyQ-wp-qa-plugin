<?php
/**
 * Tests the markup the blocks render against what core renders.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Layouts;
use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render;
use EasyQ\Database\Schema;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Route;
use EasyQ\Posts\Post;
use EasyQ\Search\Indexed_Post;
use EasyQ\Search\Search_Provider;
use EasyQ\Search\Search_Result;
use EasyQ\Services;
use EasyQ\Settings\Options;
use WP_Block;
use WP_Block_Type_Registry;

/**
 * @coversNothing
 */
final class Rendering_Test extends Test_Case {

	/**
	 * Renders every block of the plugin from the registry twice on one page, with a question.
	 *
	 * @param Post $question Question.
	 * @return array<string, string> HTML by block name.
	 */
	private function every_block_twice( Post $question ): array {
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$html = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( ! str_starts_with( $name, 'easyq/' ) ) {
				continue;
			}
			$html[ $name ] = '';
			for ( $copy = 1; $copy <= 2; $copy++ ) {
				Lists::reset();
				$inner          = parse_blocks( '<!-- wp:easyq/post-title /--><!-- wp:easyq/post-votes /-->' );
				$html[ $name ] .= ( new WP_Block(
					array(
						'blockName'    => $name,
						'attrs'        => array( 'questionId' => $question->id ),
						'innerBlocks'  => $inner,
						'innerHTML'    => '',
						'innerContent' => array( null, null ),
					),
					array( 'easyq/postId' => $question->id )
				) )->render();
			}
		}

		return $html;
	}

	public function test_blocks_with_directives_declare_interactivity_and_give_unique_ids(): void {
		$author = $this->user();
		wp_set_current_user( (int) $author->user_id );
		$question = $this->ask( $author );
		$this->answer( $this->user(), $question );
		$html     = $this->every_block_twice( $question );
		$page     = implode( '', $html );
		$registry = WP_Block_Type_Registry::get_instance();

		// Each block alone, without inner blocks, so the region is its own; as an administrator
		// with comments on and the question in edit mode, so that every form and action shows.
		\EasyQ\Settings\Options::update( 'posting', array( 'comment_on_qs' => true ) );
		wp_set_current_user( $this->administrator() );
		$_GET[ \EasyQ\Blocks\Render\Forms::EDIT_VAR ] = (string) $question->id;
		foreach ( array_keys( $html ) as $name ) {
			Lists::reset();
			$markup = ( new WP_Block(
				array(
					'blockName'    => $name,
					'attrs'        => array( 'questionId' => $question->id ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
				array( 'easyq/postId' => $question->id )
			) )->render();
			// Any directive, not only the region: the handbook asks every block with directives to say so.
			if ( 1 === preg_match( '/\sdata-wp-[a-z]/', $markup ) ) {
				$this->assertTrue( ! empty( $registry->get_registered( $name )->supports['interactivity'] ), "$name prints directives and declares supports.interactivity" );
			}
		}
		unset( $_GET[ \EasyQ\Blocks\Render\Forms::EDIT_VAR ] );

		preg_match_all( '/\sid="([^"]+)"/', $page, $ids );
		$counts = array_count_values( $ids[1] );
		$this->assertNotEmpty( $counts );
		$this->assertSame( array(), array_keys( array_filter( $counts, static fn ( int $count ): bool => $count > 1 ) ), 'Every ID once on the page.' );
		preg_match_all( '/\sfor="([^"]+)"/', $page, $labels );
		$this->assertSame( array(), array_values( array_diff( $labels[1], $ids[1] ) ), 'Every label points to a field.' );
	}

	public function test_the_server_renders_the_pressed_vote(): void {
		$voter    = $this->user();
		$question = $this->ask( $this->user() );
		Services::votes()->vote( $voter, $question, 1 );
		wp_set_current_user( (int) $voter->user_id );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$html = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/post-votes /--><!-- /wp:easyq/question -->' );

		$this->assertSame( 1, substr_count( $html, 'aria-pressed="true"' ), $html );
		$this->assertSame( 1, substr_count( $html, 'aria-pressed="false"' ) );
	}

	public function test_links_in_html_are_marked_as_core_marks_them(): void {
		$renderer = Services::content_renderer();
		$html     = $renderer->html( '<p><a href="https://example.org/" rel="author">Link</a></p>', 'html', Post::ANSWER );
		$this->assertStringContainsString( 'rel="author nofollow ugc"', $html );
		$this->assertStringNotContainsString( 'target=', $html );

		Options::update( 'viewing', array( 'links_in_new_window' => true ) );
		$html = $renderer->html( '<p><a href="https://example.org/">Link</a></p>', 'html', Post::COMMENT );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="nofollow ugc noopener"', $html );
	}

	public function test_censoring_keeps_entities_and_finds_words_split_by_tags(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn amp' ) );
		$renderer = Services::content_renderer();

		$this->assertSame( '<p>Tom &amp; Jerry **** it</p>', $renderer->html( '<p>Tom &amp; Jerry darn it</p>', 'html' ) );
		$this->assertSame( '<p>**<b></b>** and <em>****</em></p>', $renderer->html( '<p>da<b></b>rn and <em>darn</em></p>', 'html' ) );
		$this->assertSame( '<p>dar</p><p>n ****</p>', $renderer->html( '<p>dar</p><p>n darn</p>', 'html' ), 'Words do not continue across paragraphs.' );
		$this->assertSame( '<p>Größe ****</p>', $renderer->html( '<p>Größe darn</p>', 'html' ) );
	}

	public function test_structured_data_is_plain_text_that_closes_no_element(): void {
		global $wpdb;
		$question = $this->ask( $this->user(), 'It\'s a question & more', "It's text & more." );
		// A title that comes in without the REST API, e.g. an import.
		$wpdb->update( Schema::table( 'posts' ), array( 'title' => '</script><script>alert(1)</script>' ), array( 'id' => $question->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_flush();
		Block_Context::router()->set_current( new Route( 'question', $this->post( $question->id ) ) );

		ob_start();
		( new Page_Meta( Block_Context::router() ) )->head();
		$head = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert', $head );
		$this->assertSame( 1, preg_match( '~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $head, $json ), $head );
		$data = json_decode( trim( $json[1] ), true );
		$this->assertSame( "It's text & more.", $data['mainEntity']['text'] );
		$this->assertSame( '</script><script>alert(1)</script>', $data['mainEntity']['name'] );
	}

	public function test_comment_lists_never_announce_a_negative_number(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		foreach ( range( 1, 3 ) as $i ) {
			$this->comment( $this->user(), $question, $answer, "Comment number $i here." );
		}
		Options::update(
			'viewing',
			array(
				'show_fewer_cs_from'  => 1,
				'show_fewer_cs_count' => 5,
			)
		);
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$html = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/answer-list --><!-- wp:easyq/answer-template --><!-- wp:easyq/comment-list --><!-- wp:easyq/comment-template --><!-- wp:easyq/post-content /--><!-- /wp:easyq/comment-template --><!-- /wp:easyq/comment-list --><!-- /wp:easyq/answer-template --><!-- /wp:easyq/answer-list --><!-- /wp:easyq/question -->' );

		$this->assertStringNotContainsString( 'previous comment', $html );
		$this->assertSame( 3, substr_count( $html, 'here.' ) );
	}

	public function test_layouts_serialize_attributes_as_core(): void {
		$attributes = array(
			'message' => 'a -- "b" \\ c <!-- d --> & e',
			'number'  => 3,
		);

		$this->assertSame( serialize_block_attributes( $attributes ), Layouts::json( $attributes ) );
	}

	public function test_searches_of_another_provider_show_only_what_the_user_may_see(): void {
		$author = $this->user();
		$hidden = $this->ask( $author, 'A hidden question about widgets' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, Post::STATUS_HIDDEN );
		$visible = $this->ask( $author, 'A visible question about widgets' );
		$current = $this->ask( $author, 'The question of the page' );
		$ids     = array( $hidden->id, $visible->id );
		add_filter(
			'easyq_search_providers',
			static fn ( array $providers ): array => array_merge(
				$providers,
				array(
					new class( $ids ) implements Search_Provider {
						/**
						 * @param int[] $ids Questions it finds.
						 */
						public function __construct( private array $ids ) {
						}
						public function id(): string {
							return 'everything';
						}
						public function label(): string {
							return 'Everything';
						}
						public function index_post( Indexed_Post $post ): void {
						}
						public function unindex_post( int $post_id ): void {
						}
						public function move_post( int $post_id, ?int $category_id ): void {
						}
						public function search( string $query, int $start, int $count, ?int $user_id ): array {
							return array_map( static fn ( int $id ): Search_Result => new Search_Result( $id, 100.0, 'Q', $id ), $this->ids );
						}
					},
				)
			)
		);
		Services::reset();
		Options::update( 'lists', array( 'search_provider' => 'everything' ) );

		$found = array_map( static fn ( Search_Result $result ): int => $result->question_id, Services::search()->search( 'widgets', 0, 10 ) );
		$this->assertSame( array( $visible->id ), $found );

		Block_Context::router()->set_current( new Route( 'question', $current ) );
		$related = do_blocks( '<!-- wp:easyq/question --><!-- wp:easyq/related-questions /--><!-- /wp:easyq/question -->' );
		$this->assertStringContainsString( 'A visible question', $related );
		$this->assertStringNotContainsString( 'A hidden question', $related );

		wp_set_current_user( $this->administrator() );
		$this->assertContains( $hidden->id, array_map( static fn ( Search_Result $result ): int => $result->question_id, Services::search()->search( 'widgets', 0, 10, get_current_user_id() ) ), 'Moderators find hidden questions.' );
	}

	public function test_unique_ids_restart_on_a_new_page(): void {
		$this->assertSame( 'easyq-x', Render::unique_id( 'easyq-x' ) );
		$this->assertSame( 'easyq-x-2', Render::unique_id( 'easyq-x' ) );
		Render::reset_ids();
		$this->assertSame( 'easyq-x', Render::unique_id( 'easyq-x' ) );
	}
}
