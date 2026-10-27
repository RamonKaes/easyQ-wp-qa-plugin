<?php
/**
 * Tests for user levels.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Levels;
use EasyQ\Users\User_Status;

/**
 * @covers \EasyQ\Users\User_Levels
 * @covers \EasyQ\Users\Levels
 */
final class User_Levels_Test extends Test_Case {

	/**
	 * Service under test.
	 *
	 * @var User_Levels
	 */
	private User_Levels $levels;

	public function set_up(): void {
		parent::set_up();
		$this->levels = Services::user_levels();
	}

	public function test_levels_come_from_the_role_mapping(): void {
		$this->assertNull( $this->levels->level( null ) );
		$this->assertSame( Levels::BASIC, $this->levels->level( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ) );
		$this->assertSame( Levels::MODERATOR, $this->levels->level( self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		$this->assertSame( Levels::ADMIN, $this->levels->level( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );

		Options::update( 'general', array( 'role_levels' => array( 'author' => Levels::EXPERT ) ) );

		$this->assertSame( Levels::EXPERT, $this->levels->level( self::factory()->user->create( array( 'role' => 'author' ) ) ) );
		$this->assertSame( Levels::BASIC, $this->levels->level( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
	}

	public function test_invalid_role_mappings_are_ignored(): void {
		Options::update(
			'general',
			array(
				'role_levels' => array(
					'author' => 999,
					7        => Levels::ADMIN,
				),
			)
		);

		$this->assertSame( array(), $this->levels->role_levels() );
	}

	public function test_assigned_levels_only_raise_the_level(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->levels->set_level( Actor::system(), $editor, Levels::EXPERT );

		$this->assertSame( Levels::MODERATOR, $this->levels->level( $editor ) );
		$this->assertSame( Levels::EXPERT, $this->levels->assigned_level( $editor ) );
		$this->assertSame( Levels::EXPERT, $this->event_params( 'u_level' )['level'] );

		$this->levels->set_level( Actor::system(), $editor, null );
		$this->assertNull( $this->levels->assigned_level( $editor ) );
	}

	public function test_invalid_levels_are_not_stored(): void {
		$user = self::factory()->user->create();

		$this->levels->set_level( Actor::system(), $user, 55 );

		$this->assertNull( $this->levels->assigned_level( $user ) );
		$this->assertNotContains( 'u_level', $this->event_names() );
	}

	public function test_approval_raises_basic_users(): void {
		$user = self::factory()->user->create();

		User_Status::approve( Actor::system(), $user );

		$this->assertSame( Levels::APPROVED, $this->levels->level( $user ) );
	}

	public function test_category_levels(): void {
		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Child' );
		$this->assertIsInt( $child );
		$user = self::factory()->user->create();

		$this->levels->set_category_level( Actor::system(), $user, $parent, Levels::EXPERT );

		$this->assertSame( array( $parent => Levels::EXPERT ), $this->levels->category_levels( $user ) );
		$this->assertSame( Levels::EXPERT, $this->levels->level_for_categories( $user, array( $child, $parent ) ) );
		$this->assertSame( Levels::BASIC, $this->levels->level_for_categories( $user, array( $child ) ) );
		$this->assertSame( Levels::EXPERT, $this->levels->maximum_level( $user ) );
		$this->assertContains( $user, $this->levels->special_user_ids() );

		$question = $this->ask( $this->user(), 'Question in the child category', 'x', array(), $child );
		$this->assertSame( Levels::EXPERT, $this->levels->level_for_post( $user, $question ) );

		$this->levels->set_category_level( Actor::system(), $user, $parent, null );
		$this->assertSame( array(), $this->levels->category_levels( $user ) );
	}

	public function test_special_users_include_assigned_experts_only(): void {
		$expert = self::factory()->user->create();
		$basic  = self::factory()->user->create();
		$this->levels->set_level( Actor::system(), $expert, Levels::EXPERT );
		$this->levels->set_level( Actor::system(), $basic, Levels::APPROVED );

		$special = $this->levels->special_user_ids();

		$this->assertContains( $expert, $special );
		$this->assertNotContains( $basic, $special );
	}

	/**
	 * @group ms-required
	 */
	public function test_network_administrators_are_super_administrators(): void {
		$user = self::factory()->user->create();
		grant_super_admin( $user );

		$this->assertSame( Levels::SUPER, $this->levels->level( $user ) );
	}

	/**
	 * Each site of a network has its own Q&A community, so levels and blocks apply to one site only.
	 *
	 * @group ms-required
	 */
	public function test_levels_and_blocks_belong_to_one_site(): void {
		$user  = self::factory()->user->create();
		$other = self::factory()->blog->create();
		$this->levels->set_level( Actor::system(), $user, Levels::MODERATOR );
		User_Status::block( Actor::system(), $user );

		switch_to_blog( $other );
		$level   = $this->levels->level( $user );
		$blocked = User_Status::is_blocked( $user );
		restore_current_blog();

		$this->assertSame( Levels::BASIC, $level );
		$this->assertFalse( $blocked );
		$this->assertSame( Levels::MODERATOR, $this->levels->level( $user ) );
		$this->assertTrue( User_Status::is_blocked( $user ) );
	}

	public function test_labels(): void {
		$this->assertCount( 7, Levels::labels() );
		$this->assertTrue( Levels::is_valid( Levels::EDITOR ) );
		$this->assertFalse( Levels::is_valid( 1 ) );
	}
}
