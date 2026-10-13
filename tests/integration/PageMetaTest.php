<?php
/**
 * Tests for page meta data and view counting.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Page_Meta;
use EasyQ\Frontend\Route;
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

	public function test_registers_block_templates(): void {
		$this->assertNotNull( get_block_template( 'easyq//easyq-question' ) );
		$this->assertNotNull( get_block_template( 'easyq//easyq-home' ) );
	}
}
