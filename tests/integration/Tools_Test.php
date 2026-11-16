<?php
/**
 * Tests for the maintenance tools.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Schema;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Stats\Counts;
use EasyQ\Tools\Tools;

/**
 * @covers \EasyQ\Tools\Tools
 * @covers \EasyQ\Posts\Post_Service::reindex_question
 */
final class Tools_Test extends Test_Case {

	/**
	 * Runs a tool to the end and returns the number of calls it took.
	 *
	 * @param string $tool Tool.
	 * @return int
	 */
	private function run_tool( string $tool ): int {
		$tools = new Tools();
		$state = array(
			'step'  => '',
			'after' => 0,
			'total' => 0,
			'done'  => 0,
			'run'   => '',
		);

		for ( $calls = 1; $calls <= 100; $calls++ ) {
			$state = $tools->run( Actor::user( $this->administrator() ), $tool, $state['step'], $state['after'], 0 === $state['after'] ? null : $state['total'], $state['done'], $state['run'] );
			$this->assertIsArray( $state );
			if ( $state['complete'] ) {
				return $calls;
			}
		}

		$this->fail( "The tool $tool did not finish." );
	}

	/**
	 * Changes columns of a post directly, as a broken or old database would have them.
	 *
	 * @param int                  $id      Post ID.
	 * @param array<string, mixed> $columns Columns.
	 */
	private function corrupt( int $id, array $columns ): void {
		global $wpdb;

		$wpdb->update( Schema::table( 'posts' ), $columns, array( 'id' => $id ) );
		Services::posts()->forget( $id );
	}

	public function test_recounts_posts_and_their_hotness(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		Services::votes()->vote( $this->user(), $answer, 1 );
		Services::votes()->flag( $this->user(), $question, $question );
		$this->corrupt(
			$question->id,
			array(
				'answer_count' => 9,
				'flag_count'   => 0,
				'hotness'      => null,
			)
		);
		$this->corrupt(
			$answer->id,
			array(
				'upvotes'   => 0,
				'net_votes' => 0,
			)
		);
		update_option( Counts::OPTION, array( Counts::QUESTIONS => 42 ) );

		$this->run_tool( 'recount_posts' );

		$question = $this->post( $question->id );
		$this->assertSame( 1, $question->answer_count );
		$this->assertSame( 1, $question->answer_max_votes );
		$this->assertSame( 1, $question->flag_count );
		$this->assertNotNull( $question->hotness );
		$this->assertSame( 1, $this->post( $answer->id )->upvotes );
		$this->assertSame( 1, Counts::get( Counts::QUESTIONS ) );
	}

	public function test_rebuilds_the_search_index(): void {
		global $wpdb;

		$visible = $this->ask( $this->user(), 'Configuring the zeppelin dashboard' );
		$hidden  = $this->ask( $this->user(), 'Hidden zeppelin question' );
		Services::post_service()->set_question_status( Actor::system(), $hidden, Post::STATUS_HIDDEN );
		$this->assertSame( array( $visible->id ), $this->search_ids( 'zeppelin' ) );

		foreach ( array( 'titlewords', 'contentwords', 'tagwords', 'posttags' ) as $index ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( $index ) ) );
		}
		$this->assertSame( array(), $this->search_ids( 'zeppelin' ) );

		$this->run_tool( 'reindex' );

		$this->assertSame( array( $visible->id ), $this->search_ids( 'zeppelin' ) );
		// Search results leave hidden questions out anyway, so the index itself is checked.
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', Schema::table( 'titlewords' ), $hidden->id ) ) );
		$this->assertNotSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id = %d', Schema::table( 'titlewords' ), $visible->id ) ) );
	}

	public function test_recalculates_points_in_batches(): void {
		global $wpdb;

		$authors = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$authors[] = $this->user();
			$this->ask( end( $authors ), 'Question number ' . $i . ' for points' );
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET points = 1, questions = 0', Schema::table( 'userpoints' ) ) );
		Services::points()->forget( (int) $authors[0]->user_id );

		$this->run_tool( 'recalc_points' );

		foreach ( $authors as $author ) {
			Services::points()->forget( (int) $author->user_id );
			$this->assertSame( 120, Services::points()->get( (int) $author->user_id ) );
		}
	}

	public function test_recalculates_categories(): void {
		global $wpdb;

		$parent = Services::categories()->create( null, 'Parent' );
		$this->assertIsInt( $parent );
		$child = Services::categories()->create( $parent, 'Child' );
		$this->assertIsInt( $child );
		$question = $this->ask( $this->user(), 'A question in the child', 'x', array(), $child );

		$this->corrupt(
			$question->id,
			array(
				'category_path_1' => null,
				'category_path_2' => null,
			)
		);
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET question_count = 7, backpath = 'wrong'", Schema::table( 'categories' ) ) );
		Services::categories()->forget();

		$this->run_tool( 'recalc_categories' );

		$this->assertSame( array( $parent, $child, null ), $this->post( $question->id )->category_path );
		$this->assertSame( 1, Services::categories()->find( $parent )?->question_count );
		$this->assertSame( 'child/parent', Services::categories()->find( $child )?->backpath );
	}

	public function test_deletes_hidden_posts_without_dependents(): void {
		$admin = Actor::system();
		$posts = Services::post_service();

		$alone = $this->ask( $this->user(), 'A hidden question alone' );
		$posts->set_question_status( $admin, $alone, Post::STATUS_HIDDEN );

		// A hidden answer with a hidden comment goes, comment first; its question stays visible.
		$question = $this->ask( $this->user(), 'A visible question here' );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );
		$posts->set_comment_status( $admin, $comment, $question, $answer, Post::STATUS_HIDDEN );
		$posts->set_answer_status( $admin, $this->post( $answer->id ), $question, Post::STATUS_HIDDEN );

		// A hidden question with a visible answer stays.
		$kept = $this->ask( $this->user(), 'A hidden question with an answer' );
		$this->answer( $this->user(), $kept );
		$posts->set_question_status( $admin, $this->post( $kept->id ), Post::STATUS_HIDDEN );

		$this->run_tool( 'delete_hidden' );

		$this->assertNull( Services::posts()->find( $alone->id ) );
		$this->assertNull( Services::posts()->find( $comment->id ) );
		$this->assertNull( Services::posts()->find( $answer->id ) );
		$this->assertNotNull( Services::posts()->find( $question->id ) );
		$this->assertNotNull( Services::posts()->find( $kept->id ) );
	}

	public function test_steps_report_their_progress(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->ask( $this->user(), 'Question number ' . $i . ' for progress' );
		}
		$tools = new Tools();
		$actor = Actor::user( $this->administrator() );

		$first = $tools->run( $actor, 'reindex' );
		$this->assertSame(
			array(
				'tool'        => 'reindex',
				'step'        => 'questions',
				'step_number' => 1,
				'steps'       => 1,
				'done'        => 3,
				'total'       => 3,
				'complete'    => false,
			),
			array_diff_key(
				(array) $first,
				array(
					'after' => true,
					'run'   => true,
				)
			)
		);
		$last = $tools->run( $actor, 'reindex', 'questions', (int) ( $first['after'] ?? 0 ), 3, 3, (string) ( $first['run'] ?? '' ) );
		$this->assertTrue( $last['complete'] ?? false );

		$this->assertNull( $tools->run( $actor, 'no_such_tool' ) );
		$this->assertNull( $tools->run( $actor, 'reindex', 'no_such_step' ) );
		$this->assertSame( 'counts', $tools->run( $actor, 'recalc_points', 'users', PHP_INT_MAX )['step'] ?? null );
	}

	public function test_each_step_is_counted_once(): void {
		global $wpdb;
		$created = gmdate( 'Y-m-d H:i:s' );
		$values  = array();
		for ( $i = 0; $i < 1200; $i++ ) {
			$values[] = $wpdb->prepare( "('C', %s, 'A comment.', '')", $created );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'posts' ) . ' (type, created, content, format) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.
		$counts = 0;
		$record = static function ( string $query ) use ( &$counts ): string {
			$counts += str_starts_with( $query, 'SELECT COUNT(*) FROM' ) && str_contains( $query, 'FIND_IN_SET' ) ? 1 : 0;
			return $query;
		};
		add_filter( 'query', $record );
		$calls = $this->run_tool( 'recount_posts' );
		remove_filter( 'query', $record );

		$steps = count( array_filter( Tools::STEPS['recount_posts'] ) );
		$this->assertGreaterThan( $steps + 2, $calls, 'Several batches per step.' );
		$this->assertLessThanOrEqual( $steps, $counts, "$counts counts in $calls batches" );
	}

	public function test_a_second_run_waits_for_the_first(): void {
		$this->ask( $this->user(), 'A question to reindex' );
		$tools = new Tools();
		$actor = Actor::user( $this->administrator() );
		$first = $tools->run( $actor, 'reindex' );
		$this->assertIsArray( $first );
		$this->assertFalse( $first['complete'] );

		$second = $tools->run( $actor, 'refill_events' );
		$this->assertWPError( $second );
		$this->assertSame( 409, $second->get_error_data()['status'] ?? null );

		$this->assertIsArray( $tools->run( $actor, 'reindex', 'questions', $first['after'], $first['total'], $first['done'], $first['run'] ), 'The run itself goes on.' );
		$this->assertIsArray( $tools->run( $actor, 'refill_events' ), 'After the end of the first run another may start.' );

		// A run that stopped without a batch for five minutes is over.
		update_option( Tools::RUN_OPTION, 'gone|' . ( time() - HOUR_IN_SECONDS ), false );
		$this->assertIsArray( $tools->run( $actor, 'reindex' ) );
	}

	public function test_the_command_line_offers_every_tool(): void {
		$doc = (string) ( new \ReflectionMethod( \EasyQ\CLI\Command::class, 'recalc' ) )->getDocComment();
		$this->assertSame( 1, preg_match( '/options:\s*\n((?:\s*\*\s+- [a-z-]+\n)+)/', $doc, $list ) );
		preg_match_all( '/- ([a-z-]+)/', $list[1], $options );

		$this->assertSame( array_map( static fn ( string $tool ): string => str_replace( '_', '-', $tool ), array_keys( Tools::STEPS ) ), $options[1] );
	}
}
