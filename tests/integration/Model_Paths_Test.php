<?php
/**
 * Tests for paths of users, categories, votes and points no other test runs.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Permits;
use EasyQ\Users\Registration;
use EasyQ\Users\Site_Meta;
use EasyQ\Users\User_Status;

/**
 * @covers \EasyQ\Posts\Post
 * @covers \EasyQ\Users\Permissions
 * @covers \EasyQ\Votes\Votes
 * @covers \EasyQ\Points\Points
 * @covers \EasyQ\Categories\Categories
 * @covers \EasyQ\Users\Registration
 */
final class Model_Paths_Test extends Test_Case {

	public function test_a_post_shown_again_by_a_moderated_author_goes_to_the_queue(): void {
		$this->assertSame( 'A_QUEUED', Post::type_for( Post::ANSWER, Post::STATUS_QUEUED ) );
		$question = $this->ask( $this->user() );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );

		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_QUEUED );

		$this->assertSame( 'Q_QUEUED', $this->post( $question->id )->type );
	}

	public function test_permissions_with_points_still_ask_for_the_confirmation_or_the_approval(): void {
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'permissions' ) );
		Options::update(
			'permissions',
			array(
				'permit_post_q'        => Permits::POINTS_CONFIRMED,
				'permit_post_q_points' => 50,
				'permit_post_a'        => Permits::APPROVED_POINTS,
				'permit_post_a_points' => 50,
			)
		);
		Options::update( 'spam', array( 'moderate_users' => true ) );
		$user = $this->user();
		Services::points()->set_bonus( (int) $user->user_id, 100 );
		update_user_meta( (int) $user->user_id, User_Status::UNCONFIRMED_META, 1 );
		Site_Meta::update( (int) $user->user_id, User_Status::UNAPPROVED_META, 1 );

		// Enough points: the permission becomes the one without points, which still wants its step.
		$this->assertSame( 'confirm', Services::permissions()->error( 'permit_post_q', $user ) );
		$this->assertSame( 'approve', Services::permissions()->error( 'permit_post_a', $user ) );
	}

	public function test_claiming_an_answer_keeps_the_rules_about_answering(): void {
		Options::update(
			'posting',
			array(
				'allow_self_answer'   => false,
				'allow_multi_answers' => true,
			)
		);
		$asker    = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( new Actor( null, 4242 ), $question, 'An answer given before logging in.' );
		$claimer  = new Actor( $asker->user_id, 4242 );

		// The asker may not claim an answer to their own question while self-answers are off.
		$this->assertFalse( Services::permissions()->post_rules( $answer, $claimer, $question )['claimable'] );

		// Nor a second answer while multiple answers are off.
		Options::update(
			'posting',
			array(
				'allow_self_answer'   => true,
				'allow_multi_answers' => false,
			)
		);
		$other   = $this->user();
		$own     = $this->answer( $other, $question, 'An answer of the claimer already.' );
		$claimer = new Actor( $other->user_id, 4242 );
		$this->assertFalse( Services::permissions()->post_rules( $answer, $claimer, $question, array( $own, $answer ) )['claimable'] );
		$this->assertTrue( Services::permissions()->post_rules( $answer, $claimer, $question, array( $answer ) )['claimable'] );
	}

	public function test_a_comment_is_hidden_by_enough_flags(): void {
		Options::update(
			'spam',
			array(
				'flagging_of_posts'   => true,
				'flagging_hide_after' => 2,
			)
		);
		$question = $this->ask( $this->user() );
		$comment  = $this->comment( $this->user(), $question, $question, 'A comment others flag.' );

		Services::votes()->flag( $this->user(), $comment, $question );
		$this->assertFalse( $this->post( $comment->id )->is_hidden() );
		Services::votes()->flag( $this->user(), $this->post( $comment->id ), $question );

		Services::posts()->forget( $comment->id );
		$this->assertTrue( $this->post( $comment->id )->is_hidden() );
	}

	public function test_changed_points_for_votes_on_answers_and_comments_are_recalculated(): void {
		Options::update( 'viewing', array( 'voting_on_cs' => true ) );
		$author   = $this->user();
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $author, $question );
		$comment  = $this->comment( $author, $question, $question, 'A comment others vote on.' );
		Services::votes()->vote( $this->user(), $answer, 1 );
		Services::votes()->vote( $this->user(), $comment, 1 );
		$before = Services::points()->get( (int) $author->user_id );

		Options::update(
			'points',
			array(
				'points_per_a_voted_up' => 12,
				'points_per_c_voted_up' => 7,
			)
		);

		Services::reset();
		$this->assertSame( $before + ( 10 + 7 ) * Options::get_int( 'points', 'points_multiple' ), Services::points()->get( (int) $author->user_id ) );
	}

	public function test_categories_refuse_a_missing_parent_or_target(): void {
		$missing = 999999;
		$created = Services::categories()->create( $missing, 'Orphan' );
		$this->assertInstanceOf( \WP_Error::class, $created );
		$this->assertContains( 'parent', $created->get_error_codes() );

		$id = Services::categories()->create( null, 'To delete' );
		$this->assertIsInt( $id );
		$deleted = Services::categories()->delete( $id, $missing );
		$this->assertInstanceOf( \WP_Error::class, $deleted );
		$this->assertSame( 'reassign', $deleted->get_error_code() );
		$this->assertNotNull( Services::categories()->find( $id ) );
	}

	public function test_an_avatar_the_image_editor_cannot_handle_leaves_no_file(): void {
		Options::update( 'users', array( 'avatar_allow_upload' => true ) );
		$user   = (int) $this->user()->user_id;
		$tmp    = wp_tempnam( 'avatar' );
		$dir    = wp_upload_dir()['path'];
		$files  = static fn (): array => (array) glob( $dir . '/canola*' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $tmp );
		$before = $files();
		// No editor can open the image, as on a host without GD and ImageMagick.
		$none = static fn (): array => array();
		add_filter( 'wp_image_editors', $none, 99 );

		$result = \EasyQ\Users\Avatars::upload(
			$user,
			array(
				'name'     => 'canola.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $tmp,
				'size'     => (int) filesize( $tmp ),
				'error'    => UPLOAD_ERR_OK,
			)
		);
		remove_filter( 'wp_image_editors', $none, 99 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $before, $files(), 'The uploaded original is deleted.' );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_network_sign_up_is_activated_on_the_site_it_came_from(): void {
		$site = self::factory()->blog->create();
		$user = self::factory()->user->create();

		( new Registration() )->activate(
			$user,
			'',
			array(
				'easyq_registration' => array(
					'terms' => true,
					'time'  => 1700000000,
					'site'  => $site,
				),
			)
		);

		$this->assertSame( 1, get_current_blog_id(), 'The site is switched back.' );
		$this->assertSame( '', Site_Meta::get( $user, Registration::TERMS_META ) );
		switch_to_blog( $site );
		$terms = Site_Meta::get( $user, Registration::TERMS_META );
		restore_current_blog();
		$this->assertSame( 1700000000, is_array( $terms ) ? $terms['time'] : null );
	}
}
