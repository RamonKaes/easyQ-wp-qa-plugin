<?php
/**
 * Tests for the block patterns.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Patterns;
use WP_Block_Pattern_Categories_Registry;
use WP_Block_Patterns_Registry;
use WP_Block_Type_Registry;

/**
 * @covers \EasyQ\Blocks\Patterns
 * @covers \EasyQ\Blocks\Layouts
 */
final class PatternsTest extends TestCase {

	public function test_registers_the_category_and_the_patterns(): void {
		$this->assertTrue( WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( Patterns::CATEGORY ) );

		$registry = WP_Block_Patterns_Registry::get_instance();
		foreach ( array( 'home', 'question-page', 'profile', 'recent-questions', 'top-users' ) as $name ) {
			$this->assertTrue( $registry->is_registered( 'easyq/' . $name ), $name );
			$this->assertContains( Patterns::CATEGORY, $registry->get_registered( 'easyq/' . $name )['categories'] ?? array(), $name );
		}
	}

	public function test_offers_page_layouts_for_their_templates(): void {
		$registry = WP_Block_Patterns_Registry::get_instance();

		$this->assertSame( array( 'easyq-home' ), $registry->get_registered( 'easyq/home' )['templateTypes'] ?? null );
		$this->assertSame( array( 'easyq-question' ), $registry->get_registered( 'easyq/question-page' )['templateTypes'] ?? null );
		$this->assertFalse( $registry->get_registered( 'easyq/question-page' )['inserter'] ?? true, 'The question page only works in its template.' );
		$this->assertFalse( $registry->get_registered( 'easyq/profile' )['inserter'] ?? true, 'The profile only works in its template.' );
		$this->assertTrue( $registry->get_registered( 'easyq/recent-questions' )['inserter'] ?? true );
	}

	public function test_patterns_consist_of_registered_blocks(): void {
		foreach ( ( new Patterns() )->definitions() as $name => $pattern ) {
			$names = array();
			$walk  = static function ( array $blocks ) use ( &$walk, &$names ): void {
				foreach ( $blocks as $block ) {
					if ( null !== $block['blockName'] ) {
						$names[] = $block['blockName'];
					}
					$walk( $block['innerBlocks'] );
				}
			};
			$walk( parse_blocks( $pattern['content'] ) );

			$this->assertNotSame( array(), $names, $name );
			foreach ( array_unique( $names ) as $block_name ) {
				$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( $block_name ), "$name uses $block_name" );
			}
		}
	}

	public function test_compact_lists_show_recent_questions_and_top_users(): void {
		$author = $this->user();
		$this->ask( $author, 'Question for the compact list' );

		$questions = do_blocks( ( new Patterns() )->definitions()['recent-questions']['content'] );
		$users     = do_blocks( ( new Patterns() )->definitions()['top-users']['content'] );

		$this->assertStringContainsString( 'Recent questions', $questions );
		$this->assertStringContainsString( 'Question for the compact list', $questions );
		$this->assertStringContainsString( 'Top users', $users );
		$this->assertStringContainsString( (string) get_userdata( (int) $author->user_id )?->display_name, $users );
	}
}
