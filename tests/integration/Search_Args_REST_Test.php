<?php
/**
 * Tests the search arguments of the lists the editor pickers read.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;

/**
 * @covers \EasyQ\REST\Questions_Controller
 * @covers \EasyQ\REST\Users_Controller
 */
final class Search_Args_REST_Test extends REST_Test_Case {

	public function test_questions_beyond_the_first_hundred_are_found(): void {
		$author = $this->user();
		$needle = $this->ask( $author, 'Where is the needle in the haystack?' );
		$hidden = $this->ask( $author, 'A hidden needle somewhere?' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, Post::STATUS_HIDDEN );
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->ask( $author, "Question number $i about widgets?" );
		}

		$first = array_column( $this->data( $this->request( 'GET', '/questions', array( 'per_page' => 100 ) ) ), 'id' );
		$this->assertNotContains( $needle->id, $first, 'The oldest question is not on the first page.' );

		$found = $this->data( $this->request( 'GET', '/questions', array( 'search' => 'needle' ) ) );
		$this->assertSame( array( $needle->id ), array_column( $found, 'id' ) );
	}

	public function test_users_are_found_by_name_among_those_shown(): void {
		$shown = self::factory()->user->create( array( 'display_name' => 'Zebrina Shown' ) );
		$this->take_part( $shown );
		self::factory()->user->create( array( 'display_name' => 'Zebrina Hidden' ) );
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->take_part( self::factory()->user->create( array( 'display_name' => "Other user $i" ) ) );
		}

		$found = $this->data( $this->request( 'GET', '/users', array( 'search' => 'Zebrina' ) ) );
		$this->assertSame( array( $shown ), array_column( $found, 'id' ) );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/users', array( 'search' => 'nobody-has-this' ) ) ) );
	}
}
