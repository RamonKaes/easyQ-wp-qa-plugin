<?php
/**
 * Tests for page meta data and view counting.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Urls;
use EasyQ\Services;

/**
 * @covers \EasyQ\Frontend\Page_Meta
 * @covers \EasyQ\Frontend\Templates
 */
final class PageMetaTest extends TestCase {

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		parent::tear_down();
	}

	public function test_sets_titles_and_robots(): void {
		$meta = new Page_Meta( Block_Context::router() );
		Block_Context::router()->set_current( new Route( 'search' ) );

		$this->assertSame( 'Search', $meta->title( array( 'title' => 'x' ) )['title'] );
		$this->assertTrue( $meta->robots( array() )['noindex'] );

		Block_Context::router()->set_current( null );
		$this->assertSame( array( 'title' => 'x' ), $meta->title( array( 'title' => 'x' ) ) );
	}

	public function test_describes_questions_as_qa_pages(): void {
		$asker    = $this->user();
		$question = $this->ask( $asker, 'Structured data question' );
		$accepted = $this->answer( $this->user(), $question, 'The accepted answer text.' );
		$this->answer( $this->user(), $question, 'Another suggested answer.' );
		Services::post_service()->select_answer( $asker, $question, $accepted->id );

		$data = ( new Page_Meta( Block_Context::router() ) )->structured_data( $this->post( $question->id ) );

		$this->assertSame( 'QAPage', $data['@type'] );
		$this->assertSame( 'Structured data question', $data['mainEntity']['name'] );
		$this->assertSame( 2, $data['mainEntity']['answerCount'] );
		$this->assertStringContainsString( 'accepted answer', $data['mainEntity']['acceptedAnswer']['text'] );
		$this->assertCount( 1, $data['mainEntity']['suggestedAnswer'] );
	}

	public function test_counts_each_visitor_once_per_hour(): void {
		$question = $this->ask( $this->user() );
		$meta     = new Page_Meta( Block_Context::router() );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		$meta->count_view();
		$meta->count_view();

		$this->assertSame( 1, $this->post( $question->id )->views );
	}

	public function test_prints_canonical_address_and_structured_data(): void {
		$question = $this->ask( $this->user() );
		Block_Context::router()->set_current( new Route( 'question', $question ) );

		ob_start();
		( new Page_Meta( Block_Context::router() ) )->head();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'rel="canonical"', $html );
		$this->assertStringContainsString( 'application/ld+json', $html );
	}

	public function test_keeps_the_account_page_out_of_search_engines(): void {
		$meta = new Page_Meta( Block_Context::router() );

		Block_Context::router()->set_current( new Route( 'account' ) );
		$this->assertTrue( $meta->robots( array() )['noindex'] );

		Block_Context::router()->set_current( new Route( 'users' ) );
		$this->assertArrayNotHasKey( 'noindex', $meta->robots( array() ) );
	}

	public function test_keeps_empty_lists_out_of_search_engines(): void {
		$meta = new Page_Meta( Block_Context::router() );
		Block_Context::router()->set_current( new Route( 'unanswered' ) );

		Lists::store( '1', array(), 0, 1, 20, Lists::MAIN_PAGE_VAR );
		$this->assertTrue( $meta->robots( array() )['noindex'] );

		Lists::reset();
		Lists::store( '1', array( 5 ), 1, 1, 20, Lists::MAIN_PAGE_VAR );
		Lists::store( '2', array(), 0, 1, 20, 'easyq_answers' );
		$this->assertArrayNotHasKey( 'noindex', $meta->robots( array() ), 'Only the main list counts.' );

		Lists::reset();
	}

	public function test_prints_the_canonical_address_of_profile_sections(): void {
		$user = get_userdata( (int) $this->user()->user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		Block_Context::router()->set_current( new Route( 'user', $user, 'answers' ) );

		ob_start();
		( new Page_Meta( Block_Context::router() ) )->head();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( sprintf( '<link rel="canonical" href="%s">', esc_url( Urls::user( $user, 'answers' ) ) ), $html );
		$this->assertStringNotContainsString( 'application/ld+json', $html );
	}

	public function test_registers_block_templates(): void {
		$this->assertNotNull( get_block_template( 'easyq//easyq-question' ) );
		$this->assertNotNull( get_block_template( 'easyq//easyq-home' ) );
		$this->assertNotNull( get_block_template( 'easyq//easyq-user' ) );
		$this->assertNotNull( get_block_template( 'easyq//easyq-account' ) );
	}
}
