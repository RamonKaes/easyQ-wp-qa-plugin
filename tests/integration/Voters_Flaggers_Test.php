<?php
/**
 * Tests for viewing who voted on and who flagged a post.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Frontend\Route;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use EasyQ\Users\Permits;

/**
 * Question2Answer shows those who may view voters and flaggers who voted and who flagged
 * (`qa-include/plugins/qa-layer-voters-flaggers.php`), by the level in the category of the
 * post (`qa_user_post_permit_error()`). The setting `permit_view_voters_flaggers` does the
 * same here: in the REST API, on the count of votes and in the list of posts.
 *
 * @covers \EasyQ\Votes\Votes
 * @covers \EasyQ\REST\Post_Formatter
 * @covers \EasyQ\Blocks\Render\Post_Fields
 * @covers \EasyQ\Admin\Posts_List_Table
 */
final class Voters_Flaggers_Test extends REST_Test_Case {

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		parent::tear_down();
	}

	/**
	 * Creates a user with a display name.
	 *
	 * @param string $name Display name.
	 * @return Actor
	 */
	private function named( string $name ): Actor {
		$user = $this->user();
		wp_update_user(
			array(
				'ID'           => (int) $user->user_id,
				'display_name' => $name,
			)
		);

		return $user;
	}

	public function test_those_who_may_see_who_voted_and_flagged_and_others_do_not(): void {
		Options::update( 'spam', array( 'flagging_of_posts' => true ) );
		Options::update( 'viewing', array( 'voting_on_qs' => true ) );
		$question = $this->ask( $this->user() );
		Services::votes()->vote( $this->named( 'Ada Up' ), $question, 1 );
		Services::votes()->vote( $this->named( 'Bob Down' ), $question, -1 );
		Services::votes()->flag( $this->named( 'Cy Flag' ), $this->post( $question->id ), $this->post( $question->id ) );

		// The administrators may, by default, as in Question2Answer.
		wp_set_current_user( $this->administrator() );
		$data = $this->data( $this->request( 'GET', '/questions/' . $question->id ) );
		$this->assertSame(
			array(
				'Ada Up'   => 1,
				'Bob Down' => -1,
			),
			array_column( $data['votes']['voters'], 'vote', 'name' )
		);
		$this->assertSame( array( 'Cy Flag' ), array_column( $data['flags']['flaggers'], 'name' ) );

		Block_Context::router()->set_current( new Route( 'question', $this->post( $question->id ) ) );
		$block = do_blocks( '<!-- wp:easyq/post-votes {"display":"separated"} /-->' );
		$this->assertStringContainsString( 'title="Voted up by Ada Up."', $block );
		$this->assertStringContainsString( '<span class="screen-reader-text"> Voted down by Bob Down.</span>', $block );

		// Others see neither.
		wp_set_current_user( (int) $this->user()->user_id );
		$data = $this->data( $this->request( 'GET', '/questions/' . $question->id ) );
		$this->assertNull( $data['votes']['voters'] );
		$this->assertNull( $data['flags']['flaggers'] );
		$this->assertStringNotContainsString( 'Voted up by', do_blocks( '<!-- wp:easyq/post-votes /-->' ) );
	}

	public function test_a_level_in_a_category_shows_the_voters_of_its_posts_only(): void {
		remove_all_filters( 'sanitize_option_' . Options::option_name( 'permissions' ) );
		Options::update( 'permissions', array( 'permit_view_voters_flaggers' => Permits::MODERATORS ) );
		$category = Services::categories()->create( null, 'Cooking' );
		$this->assertIsInt( $category );
		$inside    = $this->ask( $this->user(), 'A question about cooking', 'Text.', array(), $category );
		$outside   = $this->ask( $this->user(), 'A question elsewhere' );
		$moderator = (int) $this->user()->user_id;
		Services::user_levels()->set_category_level( Actor::system(), $moderator, $category, Levels::MODERATOR );
		wp_set_current_user( $moderator );

		$this->assertIsArray( $this->data( $this->request( 'GET', '/questions/' . $inside->id ) )['votes']['voters'] );
		$this->assertNull( $this->data( $this->request( 'GET', '/questions/' . $outside->id ) )['votes']['voters'] );
	}

	public function test_a_list_loads_the_voters_of_all_its_posts_at_once(): void {
		$questions = array( $this->ask( $this->user(), 'First question of the list' ), $this->ask( $this->user(), 'Second question of the list' ), $this->ask( $this->user(), 'Third question of the list' ) );
		foreach ( $questions as $question ) {
			Services::votes()->vote( $this->user(), $question, 1 );
		}
		\EasyQ\Votes\Votes::forget();
		wp_set_current_user( $this->administrator() );
		$queries = 0;
		$count   = static function ( string $query ) use ( &$queries ): string {
			$queries += str_contains( $query, 'vote != 0 OR flag != 0' ) ? 1 : 0;
			return $query;
		};
		add_filter( 'query', $count );

		$listed = $this->data( $this->request( 'GET', '/questions', array( 'per_page' => 10 ) ) );

		remove_filter( 'query', $count );
		$this->assertGreaterThanOrEqual( 3, count( $listed ) );
		$this->assertSame( 1, $queries );
	}

	public function test_the_list_of_posts_names_who_flagged(): void {
		Options::update( 'spam', array( 'flagging_of_posts' => true ) );
		$question = $this->ask( $this->user() );
		Services::votes()->flag( $this->named( 'Cy Flag' ), $question, $question );
		set_current_screen( 'easyq_page_easyq-posts' );
		$content = fn (): string => ( new \EasyQ\Admin\Posts_List_Table( array() ) )->column_content( $this->post( $question->id ) );

		wp_set_current_user( $this->administrator() );
		$this->assertStringContainsString( 'flagged by Cy Flag', $content() );
		// A moderator below the level of the setting, which is the administrators by default.
		wp_set_current_user( (int) $this->user( 'editor' )->user_id );
		$this->assertStringNotContainsString( 'flagged by', $content() );
	}

	public function test_the_privacy_text_names_who_may_see_who_voted(): void {
		$this->assertStringContainsString( 'who cast them is visible only to the administrators of the site.', \EasyQ\Privacy\Policy_Text::content() );

		remove_all_filters( 'sanitize_option_' . Options::option_name( 'permissions' ) );
		Options::update( 'permissions', array( 'permit_view_voters_flaggers' => Permits::MODERATORS ) );
		$this->assertStringContainsString( 'who cast them is visible only to the moderators and administrators of the site.', \EasyQ\Privacy\Policy_Text::content() );
	}
}
