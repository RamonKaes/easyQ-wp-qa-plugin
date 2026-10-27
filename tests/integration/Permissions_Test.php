<?php
/**
 * Tests for the permission checks.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Limits\Limits;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\Permits;
use EasyQ\Users\User_Status;
use EasyQ\Users\Site_Meta;

/**
 * @covers \EasyQ\Users\Permissions
 */
final class Permissions_Test extends Test_Case {

	/**
	 * Creates an actor of a kind.
	 *
	 * @param string $kind Kind of user.
	 * @return Actor
	 */
	private function actor( string $kind ): Actor {
		if ( 'visitor' === $kind ) {
			return new Actor();
		}

		$role  = in_array( $kind, array( 'editor_role', 'admin_role' ), true ) ? ( 'editor_role' === $kind ? 'editor' : 'administrator' ) : 'subscriber';
		$actor = $this->user( $role );
		$id    = (int) $actor->user_id;

		match ( $kind ) {
			'unconfirmed' => User_Status::unconfirm( $id ),
			'unapproved'  => Site_Meta::update( $id, User_Status::UNAPPROVED_META, 1 ),
			'approved'    => User_Status::approve( Actor::system(), $id ),
			'expert'      => Services::user_levels()->set_level( Actor::system(), $id, Levels::EXPERT ),
			'moderator'   => Services::user_levels()->set_level( Actor::system(), $id, Levels::MODERATOR ),
			'blocked'     => User_Status::block( Actor::system(), $id ),
			default       => null,
		};

		return $actor;
	}

	/**
	 * @dataProvider provide_matrix
	 *
	 * @param int         $permit   Threshold.
	 * @param string      $kind     Kind of user.
	 * @param string|null $expected Expected error.
	 */
	public function test_permission_matrix( int $permit, string $kind, ?string $expected ): void {
		Options::update(
			'spam',
			array(
				'confirm_user_emails' => true,
				'moderate_users'      => true,
			)
		);
		Options::update( 'permissions', array( 'permit_post_q' => $permit ) );

		$this->assertSame( $expected, Services::permissions()->error( 'permit_post_q', $this->actor( $kind ) ) );
	}

	/**
	 * Provides thresholds, kinds of users and the expected result.
	 *
	 * @return array<string, array{int, string, string|null}>
	 */
	public static function provide_matrix(): array {
		$cases = array(
			array( Permits::ALL, 'visitor', null ),
			array( Permits::USERS, 'visitor', 'login' ),
			array( Permits::USERS, 'basic', null ),
			array( Permits::USERS, 'unconfirmed', null ),
			array( Permits::USERS, 'blocked', 'userblock' ),
			array( Permits::CONFIRMED, 'basic', null ),
			array( Permits::CONFIRMED, 'unconfirmed', 'confirm' ),
			array( Permits::CONFIRMED, 'approved', null ),
			array( Permits::APPROVED, 'basic', null ),
			array( Permits::APPROVED, 'unapproved', 'approve' ),
			array( Permits::APPROVED, 'approved', null ),
			array( Permits::EXPERTS, 'basic', 'level' ),
			array( Permits::EXPERTS, 'expert', null ),
			array( Permits::EDITORS, 'expert', 'level' ),
			array( Permits::EDITORS, 'editor_role', null ),
			array( Permits::MODERATORS, 'editor_role', null ),
			array( Permits::MODERATORS, 'moderator', null ),
			array( Permits::ADMINS, 'moderator', 'level' ),
			array( Permits::ADMINS, 'admin_role', null ),
			array( Permits::SUPERS, 'admin_role', 'level' ),
		);

		$named = array();
		foreach ( $cases as [ $permit, $kind, $expected ] ) {
			$named[ "$permit for $kind" ] = array( $permit, $kind, $expected );
		}

		return $named;
	}

	public function test_points_thresholds(): void {
		Options::update(
			'permissions',
			array(
				'permit_post_a'        => Permits::POINTS,
				'permit_post_a_points' => 150,
			)
		);
		$poor = $this->user();
		$rich = $this->user();
		Services::points()->set_bonus( (int) $rich->user_id, 50 );

		$this->assertSame( 'level', Services::permissions()->error( 'permit_post_a', $poor ) );
		$this->assertNull( Services::permissions()->error( 'permit_post_a', $rich ) );
	}

	public function test_category_levels_apply_to_posts_in_the_category_tree(): void {
		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Child' );
		$this->assertIsInt( $child );
		$expert = $this->user();
		Services::user_levels()->set_category_level( Actor::system(), (int) $expert->user_id, $parent, Levels::EDITOR );

		$inside  = $this->ask( $this->user(), 'Question in the child category', 'x', array(), $child );
		$outside = $this->ask( $this->user(), 'Question without category here', 'x' );

		$this->assertNull( Services::permissions()->error( 'permit_edit_q', $expert, $inside ) );
		$this->assertSame( 'level', Services::permissions()->error( 'permit_edit_q', $expert, $outside ) );
		$this->assertTrue( Services::permissions()->post_rules( $inside, $expert )['editable'] );
	}

	public function test_blocked_ip_addresses_and_rate_limits(): void {
		Options::update( 'spam', array( 'block_ips_write' => '192.0.2.1' ) );
		$this->assertSame( 'ipblock', Services::permissions()->error( 'permit_post_q', $this->user() ) );

		Options::update(
			'spam',
			array(
				'block_ips_write'  => '',
				'max_rate_user_qs' => 1,
			)
		);
		$actor = $this->user();
		Services::limits()->increment( Limits::QUESTIONS, $actor );

		$this->assertSame( 'limit', Services::permissions()->error( 'permit_post_q', $actor, null, Limits::QUESTIONS ) );
		$this->assertNull( Services::permissions()->error( 'permit_post_q', $actor ) );
	}

	public function test_required_confirmation_blocks_everything(): void {
		Options::update( 'spam', array( 'confirm_user_required' => true ) );
		$actor = $this->user();
		User_Status::unconfirm( (int) $actor->user_id );

		$this->assertSame( 'confirm', Services::permissions()->error( 'permit_vote_q', $actor ) );
	}

	public function test_moderation_reasons(): void {
		Options::update(
			'spam',
			array(
				'moderate_anon_post'    => true,
				'moderate_users'        => true,
				'moderate_unapproved'   => true,
				'moderate_unconfirmed'  => true,
				'moderate_by_points'    => true,
				'moderate_points_limit' => 101,
			)
		);
		$permissions = Services::permissions();

		$this->assertSame( 'login', $permissions->moderation_reason( new Actor() ) );
		$this->assertSame( 'approve', $permissions->moderation_reason( $this->actor( 'unapproved' ) ) );
		$this->assertSame( 'confirm', $permissions->moderation_reason( $this->actor( 'unconfirmed' ) ) );
		$this->assertSame( 'points', $permissions->moderation_reason( $this->actor( 'basic' ) ) );
		$this->assertNull( $permissions->moderation_reason( $this->actor( 'expert' ) ) );
		$this->assertNull( $permissions->moderation_reason( $this->actor( 'editor_role' ) ) );
	}

	public function test_threshold_check_for_profile_fields(): void {
		$this->assertTrue( Services::permissions()->meets( Permits::ALL, new Actor() ) );
		$this->assertFalse( Services::permissions()->meets( Permits::EDITORS, $this->user() ) );
		$this->assertTrue( Services::permissions()->meets( Permits::EDITORS, $this->user( 'editor' ) ) );
	}

	public function test_post_rules_for_answers_and_claims(): void {
		Options::update(
			'posting',
			array(
				'allow_self_answer'   => false,
				'allow_multi_answers' => false,
			)
		);
		$asker    = $this->user();
		$answerer = $this->user();
		$question = $this->ask( $asker );
		$answer   = $this->answer( $answerer, $question );
		$children = Services::posts()->children( $question->id );

		$permissions = Services::permissions();
		$this->assertFalse( $permissions->post_rules( $question, $asker, null, array(), $children )['answer_button'] );
		$this->assertFalse( $permissions->post_rules( $question, $answerer, null, array(), $children )['answer_button'] );
		$this->assertTrue( $permissions->post_rules( $question, $this->user(), null, array(), $children )['answer_button'] );
		$this->assertFalse( $permissions->post_rules( $question, $this->user( 'editor' ), null, array(), $children )['deletable'] );
		$this->assertTrue( $permissions->post_rules( $answer, $answerer )['by_user'] );
	}

	public function test_anonymous_posts_can_be_claimed_with_the_same_cookie(): void {
		$question = $this->post( Services::post_service()->create_question( new Actor( null, 777 ), 'Anonymous question title', 'Text.', '', array() ) );
		$user_id  = self::factory()->user->create();

		$this->assertTrue( Services::permissions()->post_rules( $question, new Actor( $user_id, 777 ) )['claimable'] );
		$this->assertFalse( Services::permissions()->post_rules( $question, new Actor( $user_id, 778 ) )['claimable'] );
	}

	public function test_questions_closed_by_others_need_the_close_permission_to_reopen(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		Services::post_service()->close_with_note( $this->user( 'editor' ), $question, 'Closed by the editor.' );
		$closed = $this->post( $question->id );

		$this->assertFalse( Services::permissions()->post_rules( $closed, $author )['reopenable'] );
		$this->assertTrue( Services::permissions()->post_rules( $closed, $this->user( 'editor' ) )['reopenable'] );
	}

	public function test_hidden_posts_are_viewable_by_their_author_and_moderators(): void {
		$author   = $this->user();
		$question = $this->ask( $author );
		Services::post_service()->set_question_status( Actor::system(), $question, Post::STATUS_HIDDEN );
		$hidden = $this->post( $question->id );

		$this->assertTrue( Services::permissions()->post_rules( $hidden, $author )['viewable'] );
		$this->assertTrue( Services::permissions()->post_rules( $hidden, $this->user( 'editor' ) )['viewable'] );
		$this->assertFalse( Services::permissions()->post_rules( $hidden, $this->user() )['viewable'] );
		$this->assertFalse( Services::permissions()->post_rules( $hidden, $author )['reshow_at_once'] );
	}
}
