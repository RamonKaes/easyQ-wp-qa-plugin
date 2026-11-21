<?php
/**
 * Tests for the export and erasure of posts, votes, flags and profiles.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Database\Schema;
use EasyQ\Events\Activity_Tracker;
use EasyQ\Posts\Post;
use EasyQ\Privacy\Post_Data;
use EasyQ\Privacy\Profile_Data;
use EasyQ\Services;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Users\Avatars;
use EasyQ\Users\Registration;
use EasyQ\Users\Site_Meta;
use EasyQ\Util\IP;

/**
 * @covers \EasyQ\Privacy\Post_Data
 * @covers \EasyQ\Privacy\Profile_Data
 * @covers \EasyQ\Users\User_Events::remove_votes
 */
final class Personal_Data_Test extends Test_Case {

	public function test_registers_posts_votes_and_profiles_with_the_tools_of_wordpress(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		foreach ( array( 'easyq-posts', 'easyq-votes', 'easyq-profile' ) as $key ) {
			$this->assertIsCallable( $exporters[ $key ]['callback'] ?? null, $key );
			$this->assertIsCallable( $erasers[ $key ]['callback'] ?? null, $key );
			$this->assertNotSame( '', $exporters[ $key ]['exporter_friendly_name'] );
			$this->assertNotSame( '', $erasers[ $key ]['eraser_friendly_name'] );
		}
	}

	public function test_the_export_names_close_notes_and_hidden_posts(): void {
		$person   = new Actor( $this->user()->user_id, null, '192.0.2.10' );
		$question = $this->ask( $person, 'A question the person closed' );
		Services::post_service()->close_with_note( $person, $question, 'Answered elsewhere.' );
		$note = Services::posts()->close_note_of( $this->post( $question->id ) );
		$this->assertNotNull( $note );
		Services::post_service()->set_question_status( Actor::system(), $this->post( $question->id ), Post::STATUS_HIDDEN );

		$items = array_column( Post_Data::export_posts( $this->email( $person ) )['data'], 'data', 'item_id' );

		$this->assertSame( 'Question (hidden)', array_column( $items[ "easyq-post-{$question->id}" ], 'value', 'name' )['Kind'] );
		$this->assertSame( 'Reason for closing', array_column( $items[ "easyq-post-{$note->id}" ], 'value', 'name' )['Kind'] );
	}

	public function test_exports_the_posts_a_person_wrote_or_changed_last(): void {
		$person   = new Actor( $this->user()->user_id, null, '192.0.2.10' );
		$other    = new Actor( $this->user()->user_id, null, '198.51.100.20' );
		$email    = $this->email( $person );
		$question = $this->ask( $person, 'A question of the person', 'My question text.' );
		$answer   = $this->answer( $other, $question, 'An answer by someone else.' );
		$comment  = $this->comment( $person, $question, $answer, 'A comment of the person.' );
		Services::posts()->update( $answer->id, array( 'content' => 'Changed by the person.' ), new Actor( $person->user_id, null, '192.0.2.11' ), Post::UPDATE_CONTENT );
		$visitor = Services::post_service()->create_question( new Actor( null, null, '203.0.113.30' ), 'A question without an account', 'Text.', '', array( 'visitor' ), null, $email, 'Pat' );
		$foreign = $this->ask( $other, 'A question of someone else' );

		$export = Post_Data::export_posts( $email );
		$items  = array_column( $export['data'], 'data', 'item_id' );

		$this->assertTrue( $export['done'] );
		$this->assertSame( array( "easyq-post-{$question->id}", "easyq-post-{$answer->id}", "easyq-post-{$comment->id}", "easyq-post-{$visitor}" ), array_keys( $items ) );
		$this->assertArrayNotHasKey( "easyq-post-{$foreign->id}", $items );

		$question_fields = array_column( $items[ "easyq-post-{$question->id}" ], 'value', 'name' );
		$this->assertSame( 'Question', $question_fields['Kind'] );
		$this->assertSame( 'A question of the person', $question_fields['Title'] );
		$this->assertSame( 'My question text.', $question_fields['Text'] );
		$this->assertSame( '192.0.2.10', $question_fields['IP address'] );
		$this->assertStringContainsString( '<a href="', $question_fields['URL'] );

		// Of a post someone else wrote, only the change of the person.
		$answer_fields = array_column( $items[ "easyq-post-{$answer->id}" ], 'value', 'name' );
		$this->assertArrayNotHasKey( 'Text', $answer_fields );
		$this->assertArrayNotHasKey( 'IP address', $answer_fields );
		$this->assertSame( '192.0.2.11', $answer_fields['IP address of the last change'] );

		$visitor_fields = array_column( $items[ "easyq-post-{$visitor}" ], 'value', 'name' );
		$this->assertSame( 'Pat', $visitor_fields['Name'] );
		$this->assertSame( $email, $visitor_fields['Email address for notifications'] );
		$this->assertSame( '203.0.113.30', $visitor_fields['IP address'] );

		$this->assertSame( array(), Post_Data::export_posts( $email, 2 )['data'] );
		$this->assertSame( array(), Post_Data::export_posts( 'nobody@example.org' )['data'] );
		$this->assertSame( array(), Post_Data::export_posts( '' )['data'] );
	}

	public function test_erasing_keeps_the_posts_without_the_person_by_default(): void {
		$person   = new Actor( $this->user()->user_id, null, '192.0.2.10' );
		$other    = new Actor( $this->user()->user_id, null, '198.51.100.20' );
		$email    = $this->email( $person );
		$question = $this->ask( $person, 'A question of the person' );
		$answer   = $this->answer( $other, $question );
		Services::posts()->update( $answer->id, array( 'content' => 'Changed by the person.' ), new Actor( $person->user_id, null, '192.0.2.11' ), Post::UPDATE_CONTENT );
		$visitor = Services::post_service()->create_question( new Actor( null, null, '203.0.113.30' ), 'A question without an account', 'Text.', '', array( 'visitor' ), null, $email, 'Pat' );
		$this->assertGreaterThan( Options::get_int( 'points', 'points_base' ), Services::points()->get( (int) $person->user_id ) );
		foreach ( array( $question->id, $answer->id, $visitor ) as $id ) {
			Services::posts()->find( $id );
		}

		$result = Post_Data::erase_posts( $email );

		$this->assertSame( array( true, false, array(), true ), array( $result['items_removed'], $result['items_retained'], $result['messages'], $result['done'] ) );
		$erased = Services::posts()->find( $question->id );
		$this->assertNotNull( $erased, 'The post stays.' );
		$this->assertSame( array( null, '192.0.2.0' ), array( $erased->user_id, IP::from_binary( $erased->created_ip ) ) );
		$this->assertSame( 'I tried everything.', $erased->content );

		$changed = Services::posts()->find( $answer->id );
		$this->assertSame( array( $other->user_id, null, '192.0.2.0' ), array( $changed?->user_id, $changed?->last_user_id, IP::from_binary( $changed?->last_ip ) ) );
		$this->assertSame( '198.51.100.20', IP::from_binary( $changed?->created_ip ), 'The data of the other author stays.' );

		$anonymous = Services::posts()->find( $visitor );
		$this->assertSame( array( null, null, null, '203.0.113.0' ), array( $anonymous?->author_name, $anonymous?->notify, $anonymous?->cookie_id, IP::from_binary( $anonymous?->created_ip ) ) );

		$this->assertSame( Options::get_int( 'points', 'points_base' ), Services::points()->get( (int) $person->user_id ), 'The points of the posts go with them.' );
		$this->assertSame( array(), Post_Data::export_posts( $email )['data'] );
		$this->assertFalse( Post_Data::erase_posts( $email )['items_removed'] );
	}

	public function test_erasing_can_delete_the_posts_nobody_else_replied_to(): void {
		Options::update( 'general', array( 'erase_posts' => 'delete' ) );
		$person  = $this->user();
		$other   = $this->user();
		$email   = $this->email( $person );
		$alone   = $this->ask( $person, 'A question nobody answered' );
		$own     = $this->ask( $person, 'A question with a comment of the person only' );
		$comment = $this->comment( $person, $own, $own, 'A comment of the person on their question.' );
		$replied = $this->ask( $person, 'A question someone else answered' );
		$answer  = $this->answer( $other, $replied );
		$mine    = $this->answer( $person, $this->ask( $other, 'A question of someone else' ), 'An answer of the person.' );

		$result = Post_Data::erase_posts( $email );

		$this->assertTrue( $result['items_removed'] );
		foreach ( array( $alone->id, $own->id, $comment->id, $mine->id ) as $id ) {
			$this->assertNull( Services::posts()->find( $id ), "Post $id is deleted." );
		}
		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( array( "Q&A post {$replied->id} has replies by others; its author was removed instead." ), $result['messages'] );
		$kept = Services::posts()->find( $replied->id );
		$this->assertSame( array( null, Post::QUESTION ), array( $kept?->user_id, $kept?->type ), 'A question with replies stays visible, without author.' );
		$this->assertNotNull( Services::posts()->find( $answer->id ) );
		$this->assertSame( array(), Post_Data::export_posts( $email )['data'] );
	}

	public function test_the_choice_between_keeping_and_deleting_is_a_setting_of_the_site_owner(): void {
		$field = Fields::section( 'general' )['erase_posts'] ?? null;

		$this->assertIsArray( $field );
		$this->assertSame( 'choice', $field['type'] );
		$this->assertSame( array( 'anonymize', 'delete' ), array_keys( $field['choices'] ) );
		$this->assertTrue( $field['owner'] );
		$this->assertSame( 'anonymize', Options::get_string( 'general', 'erase_posts' ) );
	}

	public function test_exports_and_erases_votes_and_flags_and_recounts(): void {
		$person   = $this->user();
		$author   = $this->user();
		$email    = $this->email( $person );
		$question = $this->ask( $author );
		$answer   = $this->answer( $author, $question );
		$this->assertTrue( Services::votes()->vote( $person, $answer, 1 ) );
		Services::votes()->flag( $person, $question, $question );
		$this->assertTrue( Services::votes()->has_flagged( $question->id, (int) $person->user_id ) );
		$points = Services::points()->get( (int) $author->user_id );

		$export = Post_Data::export_votes( $email );
		$items  = array_column( $export['data'], 'data', 'item_id' );
		$this->assertTrue( $export['done'] );
		$this->assertSame( 'Up', array_column( $items[ "easyq-vote-{$answer->id}" ], 'value', 'name' )['Vote'] );
		$this->assertSame( 'Flagged', array_column( $items[ "easyq-vote-{$question->id}" ], 'value', 'name' )['Flag'] );

		$result = Post_Data::erase_votes( $email );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( 0, Services::posts()->find( $answer->id )?->upvotes );
		$this->assertSame( 0, Services::posts()->find( $question->id )?->flag_count );
		$this->assertLessThan( $points, Services::points()->get( (int) $author->user_id ), 'The author loses the points of the vote.' );
		$this->assertSame( array(), Post_Data::export_votes( $email )['data'] );
		$this->assertFalse( Post_Data::erase_votes( $email )['items_removed'] );
	}

	public function test_exports_and_erases_the_profile(): void {
		$person  = $this->user();
		$user_id = (int) $person->user_id;
		$email   = $this->email( $person );
		$field   = Services::user_fields()->all()[0] ?? null;
		$this->assertNotNull( $field );
		Services::user_fields()->set_value( $user_id, $field, 'Berlin' );
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		Avatars::set( $user_id, $attachment_id );
		update_post_meta( $attachment_id, Avatars::ATTACHMENT_META, $user_id );
		$this->ask( $person );
		$this->assertNotSame( '', Site_Meta::get( $user_id, Activity_Tracker::LAST_WRITE_META ) );
		Site_Meta::update(
			$user_id,
			Registration::TERMS_META,
			array(
				'time' => 1700000000,
				'text' => 'I accept the terms of this site.',
			)
		);

		$fields = array_column( Profile_Data::export( $email )['data'][0]['data'], 'value', 'name' );
		$this->assertSame( '2023-11-14 22:13:20 UTC: I accept the terms of this site.', $fields['Terms accepted on registration'] );
		$this->assertSame( 'Berlin', $fields[ $field->display_label() ] );
		$this->assertStringContainsString( 'canola', $fields['Uploaded avatar'] );
		$this->assertArrayHasKey( 'Points', $fields );
		$this->assertArrayHasKey( 'Last post, vote or message', $fields );

		$result = Profile_Data::erase( $email );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertSame( '', Services::user_fields()->value( $user_id, $field ) );
		$this->assertNull( Avatars::attachment_id( $user_id ) );
		$this->assertNull( get_post( $attachment_id ), 'The uploaded image is deleted.' );
		$this->assertSame( '', Site_Meta::get( $user_id, Activity_Tracker::LAST_WRITE_META ) );
		$this->assertGreaterThan( 0, Services::points()->get( $user_id ), 'Points stay with the account.' );
		$this->assertNotNull( Registration::accepted_terms( $user_id ), 'The proof of consent stays with the account.' );
		$this->assertStringContainsString( 'accepted terms', $result['messages'][0] );
		$this->assertFalse( Profile_Data::erase( $email )['items_removed'] );
		$this->assertSame( array(), Profile_Data::export( 'nobody@example.org' )['data'] );
	}

	public function test_erasing_everything_through_the_tools_of_wordpress_leaves_nothing_to_export(): void {
		$person   = $this->user();
		$email    = $this->email( $person );
		$question = $this->ask( $person );
		Services::votes()->vote( $person, $this->answer( $this->user(), $question ), 1 );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		foreach ( array( 'easyq-posts', 'easyq-votes', 'easyq-profile' ) as $key ) {
			$page = 1;
			do {
				$result = call_user_func( $erasers[ $key ]['callback'], $email, $page++ );
			} while ( ! $result['done'] && $page < 10 );
			$this->assertTrue( $result['done'], $key );
		}

		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d OR last_user_id = %d', Schema::table( 'posts' ), $person->user_id, $person->user_id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', Schema::table( 'uservotes' ), $person->user_id ) ) );
	}

	public function test_exports_and_erases_page_by_page_like_core(): void {
		global $wpdb;

		$person  = $this->user();
		$email   = $this->email( $person );
		$created = gmdate( 'Y-m-d H:i:s' );
		$values  = array();
		for ( $i = 0; $i <= Post_Data::PER_PAGE; $i++ ) {
			$values[] = $wpdb->prepare( "('C', %d, %s, 'A comment.', '')", $person->user_id, $created );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'posts' ) . ' (type, user_id, created, content, format) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.

		$first = Post_Data::export_posts( $email, 1 );
		$this->assertCount( Post_Data::PER_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$second = Post_Data::export_posts( $email, 2 );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );

		$this->assertFalse( Post_Data::erase_posts( $email, 1 )['done'] );
		$this->assertTrue( Post_Data::erase_posts( $email, 2 )['done'], 'Erased posts no longer match, so the next page starts with the rest.' );
		$this->assertSame( array(), Post_Data::export_posts( $email )['data'] );
	}

	public function test_every_tool_finds_the_person_whatever_the_case_of_the_address(): void {
		global $wpdb;

		// An author without an account, and many posts of theirs: more than a page.
		$anonymous = new Actor( null, null, '192.0.2.10' );
		$question  = $this->post( (int) Services::post_service()->create_question( $anonymous, 'A question without an account', 'Text.', '', array(), null, 'Pat@Example.org', 'Pat' ) );
		$created   = gmdate( 'Y-m-d H:i:s' );
		$values    = array();
		for ( $i = 0; $i <= Post_Data::PER_PAGE; $i++ ) {
			$values[] = $wpdb->prepare( "('C', NULL, %s, 'A comment.', '', 'Pat@Example.org', 'Pat')", $created );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'posts' ) . ' (type, user_id, created, content, format, notify, author_name) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.
		// An account, asked for in another case.
		$person = $this->user();
		wp_update_user(
			array(
				'ID'         => (int) $person->user_id,
				'user_email' => 'Kim@Example.org',
			)
		);
		$this->ask( $person );

		$exporters = array_filter( apply_filters( 'wp_privacy_personal_data_exporters', array() ), static fn ( string $key ): bool => str_starts_with( $key, 'easyq-' ), ARRAY_FILTER_USE_KEY );
		$erasers   = array_filter( apply_filters( 'wp_privacy_personal_data_erasers', array() ), static fn ( string $key ): bool => str_starts_with( $key, 'easyq-' ), ARRAY_FILTER_USE_KEY );
		$this->assertNotEmpty( $exporters );
		$this->assertNotEmpty( $erasers );

		foreach ( array( 'pat@example.org', 'KIM@EXAMPLE.ORG' ) as $email ) {
			$this->assertNotSame( array(), call_user_func( $exporters['easyq-posts']['callback'], $email, 1 )['data'], $email );
			foreach ( $erasers as $key => $eraser ) {
				$page = 1;
				do {
					$result = call_user_func( $eraser['callback'], $email, $page++ );
				} while ( ! $result['done'] && $page < 5 );
				$this->assertTrue( $result['done'], "$key ends for $email" );
			}
			// The account stays, as with core, and so does its profile with the base points.
			foreach ( array_diff_key( $exporters, array( 'easyq-profile' => true ) ) as $key => $exporter ) {
				$this->assertSame( array(), call_user_func( $exporter['callback'], $email, 1 )['data'], "$key after erasing $email" );
			}
		}

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE notify = %s OR author_name = %s', Schema::table( 'posts' ), 'Pat@Example.org', 'Pat' ) ) );
		$this->assertNull( $this->post( $question->id )->notify );
	}

	public function test_every_kind_of_data_is_exported_page_by_page(): void {
		global $wpdb;
		$person  = $this->user();
		$email   = $this->email( $person );
		$other   = (int) $this->user()->user_id;
		$created = gmdate( 'Y-m-d H:i:s' );
		$values  = array();
		for ( $i = 0; $i < Post_Data::PER_PAGE + 100; $i++ ) {
			$values[] = $wpdb->prepare( "('private', %d, %d, 'A message.', '', %s)", $person->user_id, $other, $created );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'messages' ) . ' (type, from_user_id, to_user_id, content, format, created) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.
		$values = array();
		for ( $i = 1; $i <= Post_Data::PER_PAGE + 1; $i++ ) {
			$values[] = $wpdb->prepare( "(%d, 'Q', %d)", $person->user_id, $i );
		}
		$wpdb->query( 'INSERT INTO ' . Schema::table( 'userfavorites' ) . ' (user_id, entity_type, entity_id) VALUES ' . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Values prepared above.
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		foreach ( array(
			'easyq-messages'  => 100,
			'easyq-favorites' => 1,
		) as $key => $rest ) {
			$first = call_user_func( $exporters[ $key ]['callback'], $email, 1 );
			$this->assertCount( Post_Data::PER_PAGE, $first['data'], $key );
			$this->assertFalse( $first['done'], $key );
			$second = call_user_func( $exporters[ $key ]['callback'], $email, 2 );
			$this->assertCount( $rest, $second['data'], $key );
			$this->assertTrue( $second['done'], $key );
		}
	}

	public function test_exports_what_is_stored_of_the_person(): void {
		Options::update( 'posting', array( 'extra_field_active' => true ) );
		$person   = $this->user();
		$email    = $this->email( $person );
		$question = $this->post( (int) Services::post_service()->create_question( $person, 'A question with extra information', 'Text.', '', array(), null, null, null, null, false, 'Debian 13' ) );
		$category = Services::categories()->create( null, 'Software' );
		$this->assertIsInt( $category );
		Services::user_levels()->set_category_level( Actor::system(), (int) $person->user_id, $category, \EasyQ\Users\Levels::EXPERT );
		$hidden = $this->ask( $this->user(), 'A followed question that is hidden later' );
		Services::favorites()->set( $person, 'question', $hidden->id, true );
		Services::post_service()->set_question_status( Actor::system(), $hidden, \EasyQ\Posts\Post::STATUS_HIDDEN );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$posts = (string) wp_json_encode( call_user_func( $exporters['easyq-posts']['callback'], $email, 1 ) );
		$this->assertStringContainsString( 'Debian 13', $posts, 'The extra field of the question.' );
		$profile = (string) wp_json_encode( call_user_func( $exporters['easyq-profile']['callback'], $email, 1 ) );
		$this->assertStringContainsString( 'Software', $profile, 'The level in a category.' );
		$favorites = call_user_func( $exporters['easyq-favorites']['callback'], $email, 1 );
		$this->assertCount( 1, $favorites['data'], 'A favorite of a hidden question is stored, so it is exported.' );
		$this->assertStringNotContainsString( 'hidden later', (string) wp_json_encode( $favorites ) );
		unset( $question );
	}

	public function test_erasing_votes_recounts_the_best_vote_of_the_answers(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		$voter    = $this->user();
		Services::votes()->vote( $voter, $answer, 1 );
		$this->assertSame( 1, $this->post( $question->id )->answer_max_votes );

		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		call_user_func( $erasers['easyq-votes']['callback'], $this->email( $voter ), 1 );

		$this->assertSame( 0, $this->post( $question->id )->answer_max_votes );
	}

	/**
	 * Returns the email address of the account of an actor.
	 *
	 * @param Actor $actor Actor with an account.
	 * @return string
	 */
	private function email( Actor $actor ): string {
		return (string) get_userdata( (int) $actor->user_id )->user_email;
	}
}
