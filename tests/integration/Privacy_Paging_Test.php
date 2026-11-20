<?php
/**
 * Tests that the personal data tools work a page at a time.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Database\Schema;
use EasyQ\Privacy\Post_Data;
use EasyQ\Privacy\Privacy;
use EasyQ\Services;

/**
 * Core pages its exporters and erasers (`wp_comments_personal_data_exporter()`,
 * `wp_comments_personal_data_eraser()`): a request handles one page, so a very active
 * member neither exhausts the memory nor leaves counts wrong when a request breaks off.
 *
 * @covers \EasyQ\Privacy\Post_Data
 * @covers \EasyQ\Privacy\Privacy
 * @covers \EasyQ\Users\User_Events
 */
final class Privacy_Paging_Test extends Test_Case {

	/**
	 * Queries seen while recording.
	 *
	 * @var string[]
	 */
	private array $log = array();

	/**
	 * Remembers a query.
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public function remember( string $query ): string {
		$this->log[] = $query;

		return $query;
	}

	public function test_votes_are_erased_a_page_at_a_time_with_right_counts(): void {
		global $wpdb;
		$voter    = $this->user();
		$author   = $this->user();
		$question = $this->ask( $author );
		$posts    = array();
		for ( $i = 0; $i <= Post_Data::VOTES_PER_PAGE; $i++ ) {
			$posts[] = $this->answer( $author, $question, "Answer number $i to vote on here." );
		}
		foreach ( $posts as $post ) {
			Services::votes()->vote( $voter, $post, 1 );
		}
		$email = get_userdata( (int) $voter->user_id )->user_email;
		$rows  = static fn (): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::table( 'uservotes' ), $voter->user_id ) );

		$first = Post_Data::erase_votes( $email );
		$this->assertFalse( $first['done'], 'One page, then the next request.' );
		$this->assertTrue( $first['items_removed'] );
		$this->assertSame( 1, $rows() );
		// The posts of the first page are recounted already.
		Services::posts()->forget( $posts[0]->id );
		$this->assertSame( 0, $this->post( $posts[0]->id )->net_votes );
		$this->assertSame( 1, $this->post( end( $posts )->id )->net_votes );

		$second = Post_Data::erase_votes( $email );
		$this->assertTrue( $second['done'] );
		$this->assertSame( 0, $rows() );
		$this->assertSame( 0, $this->post( end( $posts )->id )->net_votes );
	}

	public function test_messages_are_exported_with_one_page_loaded(): void {
		global $wpdb;
		$user  = get_userdata( (int) $this->user()->user_id );
		$other = (int) $this->user()->user_id;
		$this->assertInstanceOf( \WP_User::class, $user );
		$values = array();
		for ( $i = 0; $i <= Post_Data::PER_PAGE; $i++ ) {
			$values[] = $wpdb->prepare( "('private', %d, %d, %s, '', %s)", $user->ID, $other, "Message $i", gmdate( 'Y-m-d H:i:s', 1700000000 + $i ) );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'messages' ) . ' (type, from_user_id, to_user_id, content, format, created) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The values are prepared above.

		$first = Privacy::export_messages( $user->user_email, 1 );
		$this->assertCount( Post_Data::PER_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );

		$this->log = array();
		add_filter( 'query', array( $this, 'remember' ) );
		$second = Privacy::export_messages( $user->user_email, 2 );
		remove_filter( 'query', array( $this, 'remember' ) );

		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );
		$loads = array_values( array_filter( $this->log, static fn ( string $query ): bool => str_contains( $query, Schema::table( 'messages' ) ) ) );
		$this->assertCount( 1, $loads );
		$this->assertStringContainsString( 'LIMIT 500 OFFSET 500', $loads[0], 'Only the page is loaded.' );
	}

	public function test_favorites_are_exported_with_the_posts_of_one_page_loaded(): void {
		global $wpdb;
		$user   = get_userdata( (int) $this->user()->user_id );
		$author = $this->user();
		$this->assertInstanceOf( \WP_User::class, $user );
		$question = $this->ask( $author );
		$base     = $question->id;
		// Favorites of questions that are not there any more export as "not shown"; one real one.
		$values = array();
		for ( $i = 0; $i <= Post_Data::PER_PAGE; $i++ ) {
			$values[] = $wpdb->prepare( "(%d, 'Q', %d, 0)", $user->ID, 0 === $i ? $base : $base + 100000 + $i );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'userfavorites' ) . ' (user_id, entity_type, entity_id, no_user_events) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The values are prepared above.
		Services::reset();
		wp_cache_flush();

		$this->log = array();
		add_filter( 'query', array( $this, 'remember' ) );
		$first = Privacy::export_favorites( $user->user_email, 1 );
		remove_filter( 'query', array( $this, 'remember' ) );

		$this->assertCount( Post_Data::PER_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertTrue( Privacy::export_favorites( $user->user_email, 2 )['done'] );
		$post_loads = array_filter( $this->log, static fn ( string $query ): bool => str_contains( $query, 'FROM `' . Schema::table( 'posts' ) . '`' ) );
		$this->assertLessThanOrEqual( 2, count( $post_loads ), 'The questions of the page are loaded at once.' );
	}
}
