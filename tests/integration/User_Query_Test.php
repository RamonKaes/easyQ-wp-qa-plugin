<?php
/**
 * Tests for user lists, titles and statistics.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Points\Points;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;

/**
 * @covers \EasyQ\Users\User_Query
 * @covers \EasyQ\Users\User_Titles
 * @covers \EasyQ\Points\Points
 */
final class User_Query_Test extends Test_Case {

	public function test_lists_users_by_points(): void {
		$top    = $this->user();
		$middle = $this->user();
		$this->ask( $top );
		$this->ask( $top, 'Another question for more points' );
		$this->ask( $middle );

		$found = Services::user_query()->find( 'points', 1, 10 );

		$this->assertSame( array( $top->user_id, $middle->user_id ), $found['ids'] );
		$this->assertSame( 2, $found['total'] );
		$this->assertSame( array( $middle->user_id ), Services::user_query()->find( 'points', 2, 1 )['ids'] );
	}

	public function test_lists_the_newest_users(): void {
		$older = self::factory()->user->create( array( 'user_registered' => '2020-01-01 00:00:00' ) );
		$newer = self::factory()->user->create( array( 'user_registered' => '2026-01-01 00:00:00' ) );

		$ids = Services::user_query()->find( 'newest', 1, 100 )['ids'];

		$this->assertLessThan( array_search( $older, $ids, true ), array_search( $newer, $ids, true ) );
	}

	public function test_lists_users_with_special_levels_highest_first(): void {
		Options::update( 'general', array( 'role_levels' => array( 'editor' => Levels::MODERATOR ) ) );
		$expert = self::factory()->user->create( array( 'display_name' => 'Expert' ) );
		$editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Editor',
			)
		);
		$basic  = self::factory()->user->create();
		Services::user_levels()->set_level( Actor::system(), $expert, Levels::EXPERT );

		$found = Services::user_query()->find( 'special', 1, 10 );

		$this->assertSame( array( $editor, $expert ), $found['ids'] );
		$this->assertNotContains( $basic, $found['ids'] );
	}

	public function test_titles_come_from_levels_or_points(): void {
		Options::update(
			'points',
			array(
				'user_titles' => array(
					100   => 'Newcomer',
					150   => 'Regular',
					'bad' => 'Ignored',
				),
			)
		);
		$user   = $this->user();
		$expert = self::factory()->user->create();
		Services::user_levels()->set_level( Actor::system(), $expert, Levels::EXPERT );

		$this->assertSame( 'Newcomer', Services::user_titles()->title( (int) $user->user_id ) );
		$this->assertSame( 'Regular', Services::user_titles()->points_title( 200 ) );
		$this->assertSame( '', Services::user_titles()->points_title( 10 ) );
		$this->assertSame( 'Expert', Services::user_titles()->title( $expert ) );
	}

	public function test_returns_statistics_and_rank(): void {
		$first  = $this->user();
		$second = $this->user();
		$this->ask( $first );
		$question = $this->ask( $second );
		$this->answer( $first, $question );

		$stats = Services::points()->stats( (int) $first->user_id );

		$this->assertSame( 1, $stats[ Points::QUESTIONS ] );
		$this->assertSame( 1, $stats[ Points::ANSWERS ] );
		$this->assertSame( 160, $stats['points'] );
		$this->assertSame( 1, Services::points()->rank( (int) $first->user_id ) );
		$this->assertSame( 2, Services::points()->rank( (int) $second->user_id ) );
		$this->assertNull( Services::points()->rank( self::factory()->user->create() ) );
		$this->assertSame( 0, Services::points()->stats( 999999 )[ Points::QUESTIONS ] );
	}

	/**
	 * @group ms-required
	 */
	public function test_users_of_other_sites_are_members_once_they_take_part(): void {
		$outsider = self::factory()->user->create_and_get();
		remove_user_from_blog( $outsider->ID, get_current_blog_id() );

		$this->assertFalse( Services::user_query()->is_member( $outsider ) );

		$this->ask( new Actor( $outsider->ID ) );

		$this->assertTrue( Services::user_query()->is_member( $outsider ) );
	}
}
