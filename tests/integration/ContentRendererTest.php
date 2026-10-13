<?php
/**
 * Tests for the content output.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Posts\Content_Renderer;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\Posts\Content_Renderer
 */
final class ContentRendererTest extends TestCase {

	public function test_escapes_plain_text_and_links_addresses(): void {
		$html = ( new Content_Renderer() )->html( "<b>bold</b>\n\nSee https://example.com", '' );

		$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt;', $html );
		$this->assertStringContainsString( '<a href="https://example.com" rel="nofollow ugc">', $html );
		$this->assertSame( 2, substr_count( $html, '<p>' ) );
	}

	public function test_opens_links_in_new_windows_when_configured(): void {
		Options::update( 'viewing', array( 'links_in_new_window' => true ) );

		$this->assertStringContainsString( 'target="_blank"', ( new Content_Renderer() )->html( 'https://example.com', '' ) );

		Options::update( 'viewing', array( 'show_url_links' => false ) );
		$this->assertStringNotContainsString( '<a ', ( new Content_Renderer() )->html( 'https://example.com', '' ) );
	}

	public function test_filters_html(): void {
		$html = ( new Content_Renderer() )->html( '<p onclick="x()">Text<script>bad()</script></p>', 'html' );

		$this->assertSame( '<p>Textbad()</p>', $html );
	}

	public function test_censors_block_words_outside_of_tags(): void {
		Options::update( 'posting', array( 'block_bad_words' => 'darn, class' ) );
		$renderer = new Content_Renderer();

		$this->assertSame( '<p class="x">**** it</p>', $renderer->html( '<p class="x">Darn it</p>', 'html' ) );
		$this->assertSame( 'A **** title', $renderer->text( 'A darn title' ) );
	}
}
