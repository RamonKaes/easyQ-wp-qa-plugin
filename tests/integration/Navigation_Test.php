<?php
/**
 * Tests for the Q&A links of the Navigation block.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Routes;
use EasyQ\Settings\Options;
use WP_Block_Type_Registry;

/**
 * @covers \EasyQ\Frontend\Navigation
 */
final class Navigation_Test extends Test_Case {

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		parent::tear_down();
	}

	public function test_offers_q_and_a_pages_as_navigation_link_variations(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'core/navigation-link' );
		$this->assertNotNull( $type );

		$variations = array_column( $type->get_variations(), null, 'name' );

		$this->assertArrayHasKey( 'easyq-ask', $variations );
		$this->assertSame( 'Q&A: Ask a question', $variations['easyq-ask']['title'] );
		$this->assertSame( 'easyq', $variations['easyq-ask']['attributes']['kind'] );
		$this->assertSame( 'ask', $variations['easyq-ask']['attributes']['type'] );
		$this->assertSame( Routes::url( 'ask' ), $variations['easyq-ask']['attributes']['url'] );
		$this->assertArrayHasKey( 'easyq-home', $variations );
		$this->assertArrayHasKey( 'easyq-account', $variations );
	}

	public function test_uses_the_current_address_of_the_page(): void {
		$html = $this->render_link( 'ask', 'http://example.org/old-base/ask/' );

		$this->assertStringContainsString( 'href="' . esc_url( Routes::url( 'ask' ) ) . '"', $html );
		$this->assertStringNotContainsString( 'old-base', $html );

		Options::update( 'general', array( 'base_slug' => 'fragen' ) );
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertStringContainsString( '/fragen/ask/', $this->render_link( 'ask', 'http://example.org/old-base/ask/' ) );
	}

	public function test_marks_the_link_of_the_current_page(): void {
		Block_Context::router()->set_current( new Route( 'ask' ) );

		$this->assertStringContainsString( 'aria-current="page"', $this->render_link( 'ask' ) );
		$this->assertStringContainsString( 'current-menu-item', $this->render_link( 'ask' ) );
		$this->assertStringNotContainsString( 'aria-current', $this->render_link( 'tags' ) );
	}

	public function test_leaves_other_links_alone(): void {
		$html = render_block(
			parse_blocks( '<!-- wp:navigation-link {"label":"Elsewhere","url":"http://example.org/elsewhere/"} /-->' )[0]
		);

		$this->assertStringContainsString( 'href="http://example.org/elsewhere/"', $html );
	}

	/**
	 * Renders a Q&A navigation link.
	 *
	 * @param string $route Route name.
	 * @param string $url   Stored address.
	 * @return string
	 */
	private function render_link( string $route, string $url = 'http://example.org/' ): string {
		$attributes = wp_json_encode(
			array(
				'label' => 'Link',
				'kind'  => 'easyq',
				'type'  => $route,
				'url'   => $url,
			)
		);

		return render_block( parse_blocks( '<!-- wp:navigation-link ' . $attributes . ' /-->' )[0] );
	}
}
