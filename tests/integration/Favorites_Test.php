<?php
/**
 * Tests for favorites.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Templates;
use EasyQ\Services;

/**
 * @covers \EasyQ\Favorites\Favorites
 * @covers \EasyQ\REST\Favorites_Controller
 */
final class Favorites_Test extends REST_Test_Case {

	/**
	 * Renders the favorites page.
	 *
	 * @return string
	 */
	private function page(): string {
		Lists::reset();
		Block_Context::router()->set_current( new Route( 'favorites' ) );

		return do_blocks( ( new Templates( Block_Context::router() ) )->definitions()['easyq-favorites']['content'] );
	}

	public function test_adds_lists_and_removes_each_type(): void {
		$author   = $this->user();
		$question = $this->ask( $author, 'A question to follow', 'Text.', array( 'followed' ) );
		$category = Services::categories()->create( null, 'Followed category' );
		$this->assertIsInt( $category );
		$fan = $this->user();
		wp_set_current_user( (int) $fan->user_id );

		$targets = array(
			'question' => $question->id,
			'user'     => (int) $author->user_id,
			'tag'      => 'followed',
			'category' => $category,
		);
		foreach ( $targets as $type => $id ) {
			$added = $this->request(
				'POST',
				'/favorites',
				array(
					'type' => $type,
					'id'   => $id,
				)
			);
			$this->assertSame( 200, $added->get_status(), $type );
			$this->assertSame( $id, $this->data( $added )['id'] );
			$this->assertTrue( Services::favorites()->has( (int) $fan->user_id, $type, $id ), $type );

			$listed = $this->data( $this->request( 'GET', '/favorites', array( 'type' => $type ) ) );
			$this->assertSame( array( $id ), array_column( $listed, 'id' ), $type );

			// Adding twice changes nothing.
			$this->request(
				'POST',
				'/favorites',
				array(
					'type' => $type,
					'id'   => $id,
				)
			);
			$this->assertCount( 1, $this->data( $this->request( 'GET', '/favorites', array( 'type' => $type ) ) ), $type );
		}

		$html = $this->page();
		$this->assertStringContainsString( 'A question to follow', $html );
		$this->assertStringContainsString( 'Followed category', $html );
		$this->assertStringContainsString( 'followed', $html );

		foreach ( $targets as $type => $id ) {
			$removed = $this->data(
				$this->request(
					'DELETE',
					'/favorites',
					array(
						'type' => $type,
						'id'   => $id,
					)
				)
			);
			$this->assertTrue( $removed['deleted'], $type );
			$this->assertSame( $id, $removed['previous']['id'], $type );
			$this->assertSame( array(), $this->data( $this->request( 'GET', '/favorites', array( 'type' => $type ) ) ), $type );
		}

		$this->assertStringNotContainsString( 'A question to follow', $this->page() );
		$this->assertStringContainsString( 'No favorite tags yet.', $this->page() );
	}

	public function test_reports_events_as_question2answer(): void {
		$question = $this->ask( $this->user() );
		wp_set_current_user( (int) $this->user()->user_id );

		$this->request(
			'POST',
			'/favorites',
			array(
				'type' => 'question',
				'id'   => $question->id,
			)
		);
		$this->request(
			'DELETE',
			'/favorites',
			array(
				'type' => 'question',
				'id'   => $question->id,
			)
		);

		$this->assertContains( 'q_favorite', $this->event_names() );
		$this->assertContains( 'q_unfavorite', $this->event_names() );
		$this->assertSame( $question->id, $this->event_params( 'q_favorite' )['post_id'] );
	}

	public function test_refuses_visitors_oneself_and_what_does_not_exist(): void {
		$question = $this->ask( $this->user() );
		wp_set_current_user( 0 );
		$params = array(
			'type' => 'question',
			'id'   => $question->id,
		);
		$this->assertSame( 401, $this->request( 'POST', '/favorites', $params )->get_status() );
		$this->assertSame( 401, $this->request( 'GET', '/favorites' )->get_status() );

		$user = $this->user();
		wp_set_current_user( (int) $user->user_id );
		$self = $this->request(
			'POST',
			'/favorites',
			array(
				'type' => 'user',
				'id'   => (int) $user->user_id,
			)
		);
		$this->assertSame( 400, $self->get_status() );

		foreach ( array( 'question', 'user', 'category' ) as $type ) {
			$this->assertSame(
				404,
				$this->request(
					'POST',
					'/favorites',
					array(
						'type' => $type,
						'id'   => 999999,
					)
				)->get_status(),
				$type
			);
		}
		$this->assertSame(
			404,
			$this->request(
				'POST',
				'/favorites',
				array(
					'type' => 'tag',
					'id'   => 'no-such-tag',
				)
			)->get_status()
		);
	}

	public function test_favorites_are_private_to_each_user(): void {
		$question = $this->ask( $this->user(), 'Only one user follows this' );
		$fan      = $this->user();
		wp_set_current_user( (int) $fan->user_id );
		$this->request(
			'POST',
			'/favorites',
			array(
				'type' => 'question',
				'id'   => $question->id,
			)
		);

		wp_set_current_user( (int) $this->user()->user_id );
		$this->assertSame( array(), $this->data( $this->request( 'GET', '/favorites' ) ) );
		$this->assertStringNotContainsString( 'Only one user follows this', $this->page() );
	}

	public function test_hidden_questions_show_no_title_but_can_be_removed(): void {
		$question = $this->ask( $this->user(), 'A question hidden later' );
		$fan      = $this->user();
		wp_set_current_user( (int) $fan->user_id );
		$params = array(
			'type' => 'question',
			'id'   => $question->id,
		);
		$this->request( 'POST', '/favorites', $params );
		\EasyQ\Services::post_service()->set_question_status( \EasyQ\Actor::system(), $this->post( $question->id ), \EasyQ\Posts\Post::STATUS_HIDDEN );

		$this->assertSame( array(), $this->data( $this->request( 'GET', '/favorites' ) ) );
		$this->assertSame( 404, $this->request( 'POST', '/favorites', $params )->get_status() );
		$this->assertStringNotContainsString( 'A question hidden later', $this->page() );
		$this->assertStringNotContainsString( 'A question hidden later', (string) wp_json_encode( \EasyQ\Privacy\Privacy::export_favorites( get_userdata( (int) $fan->user_id )->user_email ) ) );

		$removed = $this->data( $this->request( 'DELETE', '/favorites', $params ) );
		$this->assertTrue( $removed['deleted'] );
		$this->assertArrayNotHasKey( 'title', $removed['previous'] );
		$this->assertSame( array(), Services::favorites()->ids( (int) $fan->user_id, 'question' ) );
	}

	public function test_deleted_entities_leave_the_favorites(): void {
		$question = $this->ask( $this->user() );
		$category = Services::categories()->create( null, 'Gone soon' );
		$this->assertIsInt( $category );
		$fan = $this->user();
		wp_set_current_user( (int) $fan->user_id );
		Services::favorites()->set( $fan, 'category', $category, true );
		Services::favorites()->set( $fan, 'question', $question->id, true );

		Services::categories()->delete( $category );
		$this->assertSame( array(), ( new \EasyQ\Favorites\Favorites( Services::posts(), Services::categories() ) )->ids( (int) $fan->user_id, 'category' ) );

		Services::post_service()->set_question_status( \EasyQ\Actor::system(), $this->post( $question->id ), \EasyQ\Posts\Post::STATUS_HIDDEN );
		Services::post_service()->delete_question( \EasyQ\Actor::system(), $this->post( $question->id ) );
		$this->assertSame( array(), ( new \EasyQ\Favorites\Favorites( Services::posts(), Services::categories() ) )->ids( (int) $fan->user_id, 'question' ) );
	}
}
