<?php
/**
 * Tests for the cases the services refuse or leave alone.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Events\Events;
use EasyQ\Limits\IP_Blocks;
use EasyQ\Search\Search;
use EasyQ\Search\Search_Provider;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\User_Field;
use EasyQ\Users\User_Fields;
use EasyQ\Users\User_Status;

/**
 * What does not exist, is already so, or is too long: each service says so or changes
 * nothing, and sends no event for a change that did not happen (F-213).
 *
 * @covers \EasyQ\Users\User_Status
 * @covers \EasyQ\Users\User_Fields
 * @covers \EasyQ\Users\User_Levels
 * @covers \EasyQ\Users\User_Query
 * @covers \EasyQ\Users\Permissions
 * @covers \EasyQ\Votes\Votes
 * @covers \EasyQ\Limits\IP_Blocks
 * @covers \EasyQ\Limits\Limits
 * @covers \EasyQ\Points\Points
 * @covers \EasyQ\Events\Events
 * @covers \EasyQ\Capabilities
 * @covers \EasyQ\Search\Search
 */
final class Service_Edges_Test extends Test_Case {

	public function test_a_status_that_is_already_so_sends_no_event(): void {
		$user = (int) $this->user()->user_id;
		User_Status::confirm( Actor::system(), $user );
		User_Status::approve( Actor::system(), $user );
		User_Status::block( Actor::system(), $user );
		User_Status::unblock( Actor::system(), $user );
		$before = count( $this->event_names() );

		User_Status::confirm( Actor::system(), $user );
		User_Status::approve( Actor::system(), $user );
		User_Status::unblock( Actor::system(), $user );

		$this->assertSame( $before, count( $this->event_names() ) );
	}

	public function test_fields_that_do_not_exist_are_left_alone_and_long_input_is_cut_or_refused(): void {
		$fields = Services::user_fields();
		$ids    = array_map( static fn ( User_Field $field ): int => $field->id, $fields->all() );

		$positions = array_map( static fn ( User_Field $field ): int => $field->position, $fields->all() );
		$fields->move( 99999, 1 );
		$fields->delete( 99999 );
		$this->assertSame( $ids, array_map( static fn ( User_Field $field ): int => $field->id, $fields->all() ) );
		$this->assertSame( $positions, array_map( static fn ( User_Field $field ): int => $field->position, $fields->all() ), 'No place is kept for a field that does not exist.' );

		$user  = (int) $this->user()->user_id;
		$field = $fields->all()[0];
		$fields->set_value( $user, $field, str_repeat( 'v', User_Fields::MAX_VALUE_LENGTH + 5 ) );
		$this->assertSame( User_Fields::MAX_VALUE_LENGTH, mb_strlen( $fields->value( $user, $field ) ) );

		$long = $fields->create( str_repeat( 'n', User_Fields::MAX_NAME_LENGTH + 1 ), 'Long name', 0, null );
		$this->assertInstanceOf( \WP_Error::class, $long );
		$this->assertSame( array( 'The name can have at most ' . User_Fields::MAX_NAME_LENGTH . ' characters.' ), $long->get_error_messages( 'name' ) );
	}

	public function test_users_who_do_not_exist_have_no_level_and_are_not_listed(): void {
		$user = (int) $this->user()->user_id;
		$this->take_part( $user );

		$this->assertNull( Services::user_levels()->level( 99999 ) );
		$this->assertSame( array( $user ), Services::user_query()->shown_ids( array( 99999, $user ), null ) );
		$this->assertSame( array( $user ), Services::user_query()->shown_ids( array( 99999, $user ), $this->administrator() ), 'Also for those who may list users.' );
	}

	public function test_visitors_cannot_flag_and_taking_back_a_flag_never_set_changes_nothing(): void {
		$question = $this->ask( $this->user() );

		$refused = Services::votes()->flag( new Actor( null, null, '192.0.2.1' ), $question, $question );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'login_required', $refused->get_error_code() );

		$user   = $this->user();
		$before = count( $this->event_names() );
		Services::votes()->unflag( $user, $question );
		$this->assertSame( $before, count( $this->event_names() ) );
		$this->assertSame( 0, $this->post( $question->id )->flag_count );
	}

	public function test_unknown_names_count_nothing(): void {
		$user = (int) $this->user()->user_id;

		$this->assertSame( 0, Services::limits()->remaining( 'no_such_action', new Actor( $user ) ) );
		$this->assertSame( 0, Services::points()->calculate( $user, 'no_such_column' ) );
		$this->assertSame( array(), ( new IP_Blocks() )->unblock( Actor::system(), '192.0.2.200' ) );
		$this->assertNotContains( 'ip_unblock', $this->event_names(), 'Nothing was unblocked.' );
	}

	public function test_an_event_not_listed_is_reported_as_core_reports_wrong_use(): void {
		$this->setExpectedIncorrectUsage( Events::class . '::dispatch' );

		Events::dispatch( 'no_such_event', array(), Actor::system() );
	}

	public function test_capabilities_of_posts_need_a_post_and_votes_on_comments_their_setting(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$comment  = $this->comment( $this->user(), $question, $answer );
		$voter    = (int) $this->user()->user_id;

		$this->assertFalse( user_can( $voter, 'easyq_post_answer' ), 'Without the post it is about.' );
		$this->assertTrue( Services::permissions()->allows( 'permit_post_q', new Actor( $voter ) ) );

		Options::update( 'viewing', array( 'voting_on_cs' => true ) );
		Options::update(
			'permissions',
			array(
				'permit_vote_c'        => \EasyQ\Users\Permits::APPROVED_POINTS,
				'permit_vote_c_points' => 100000,
			)
		);
		$this->assertFalse( user_can( $voter, 'easyq_vote_post', $comment->id ), 'Voting on comments has its own permission.' );
		Options::update( 'permissions', array( 'permit_vote_c' => \EasyQ\Users\Permits::USERS ) );
		$this->assertTrue( user_can( $voter, 'easyq_vote_post', $comment->id ) );
	}

	public function test_the_built_in_search_stays_when_a_filter_leaves_it_out(): void {
		$other = new class() implements Search_Provider {
			// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- A stand-in provider.
			public function id(): string {
				return 'other';
			}
			public function label(): string {
				return 'Other';
			}
			public function index_post( \EasyQ\Search\Indexed_Post $post ): void {}
			public function unindex_post( int $post_id ): void {}
			public function move_post( int $post_id, ?int $category_id ): void {}
			public function search( string $query, int $start, int $count, ?int $user_id ): array {
				return array();
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.Missing
		};
		add_filter( 'easyq_search_providers', static fn (): array => array( $other ) );

		$this->assertSame( array( 'basic', 'other' ), array_keys( ( new Search() )->providers() ) );
	}

	public function test_logging_out_is_an_event_as_in_question2answer(): void {
		$user = (int) $this->user()->user_id;

		do_action( 'wp_logout', $user );

		$this->assertSame( array( 'user_id' => $user ), $this->event_params( 'u_logout' ) );
	}
}
