<?php
/**
 * Tests for the user, activity and account blocks.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Actor;
use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Lists;
use EasyQ\Frontend\Flash;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Templates;
use EasyQ\Services;
use EasyQ\Settings\Options;
use EasyQ\Users\Levels;
use WP_User;

/**
 * @covers \EasyQ\Blocks\Render\Users_Render
 * @covers \EasyQ\Blocks\Render\Post_Fields
 * @covers \EasyQ\Blocks\Render\Forms
 * @covers \EasyQ\Blocks\Render\Widgets
 * @covers \EasyQ\Blocks\Render\Query_Resolver
 * @covers \EasyQ\Blocks\Blocks
 * @covers \EasyQ\Frontend\Templates
 */
final class UserBlocksTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Lists::reset();
	}

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		unset( $_GET['sort'], $_GET[ Flash::QUERY_VAR ] );
		parent::tear_down();
	}

	/**
	 * Renders a block template on a route.
	 *
	 * @param string $slug  Template slug.
	 * @param Route  $route Route of the page.
	 * @return string
	 */
	private function render_template( string $slug, Route $route ): string {
		Block_Context::router()->set_current( $route );
		$definitions = ( new Templates( Block_Context::router() ) )->definitions();

		return do_blocks( $definitions[ $slug ]['content'] );
	}

	/**
	 * Creates a user with a display name.
	 *
	 * @param string $name Display name.
	 * @return WP_User
	 */
	private function named_user( string $name ): WP_User {
		return self::factory()->user->create_and_get(
			array(
				'display_name'  => $name,
				'user_nicename' => sanitize_title( $name ),
			)
		);
	}

	public function test_activity_page_shows_what_happened(): void {
		$author   = $this->named_user( 'Grace' );
		$question = $this->ask( new Actor( $author->ID ), 'A question that gets answered' );
		$this->answer( new Actor( $this->named_user( 'Linus' )->ID ), $question );

		$html = $this->render_template( 'easyq-activity', new Route( 'activity' ) );

		$this->assertStringContainsString( 'Recent activity', $html );
		$this->assertStringContainsString( 'A question that gets answered', $html );
		$this->assertMatchesRegularExpression( '#<a href="[^"]+\#easyq-post-\d+">answered</a> <time#', $html );
		$this->assertStringContainsString( 'Linus</a> (', $html );
	}

	public function test_answers_and_comments_pages_list_recent_answers_and_comments(): void {
		$question = $this->ask( $this->user(), 'A question with a comment only' );
		$this->comment( $this->user(), $question, $question );

		$answers = $this->render_template( 'easyq-answers', new Route( 'answers' ) );
		Lists::reset();
		$comments = $this->render_template( 'easyq-comments', new Route( 'comments' ) );

		$this->assertStringNotContainsString( 'A question with a comment only', $answers );
		$this->assertStringContainsString( 'No questions found.', $answers );
		$this->assertStringContainsString( 'A question with a comment only', $comments );
		$this->assertStringContainsString( 'commented', $comments );
	}

	public function test_question_lists_show_who_asked(): void {
		$this->ask( new Actor( $this->named_user( 'Margaret' )->ID ), 'A question in the home list' );

		$html = $this->render_template( 'easyq-home', new Route( 'home' ) );

		$this->assertMatchesRegularExpression( '#asked <time datetime="[^"]+">[^<]+</time> by <a href="[^"]+">Margaret</a>#', $html );
	}

	public function test_profile_page_shows_the_user(): void {
		$user = $this->named_user( 'Ada Lovelace' );
		wp_update_user(
			array(
				'ID'          => $user->ID,
				'description' => 'Wrote the first program.',
			)
		);
		Services::user_fields()->set_value( $user->ID, Services::user_fields()->all()[0], 'London' );
		$this->ask( new Actor( $user->ID ) );

		$html = $this->render_template( 'easyq-user', new Route( 'user', get_userdata( $user->ID ) ) );

		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringContainsString( 'Ada Lovelace', $html );
		$this->assertStringContainsString( 'London', $html );
		$this->assertStringContainsString( 'Wrote the first program.', $html );
		$this->assertStringContainsString( '120 points', $html );
		$this->assertStringContainsString( '(ranked #1)', $html );
		$this->assertStringContainsString( 'Questions (1)', $html );
		$this->assertStringContainsString( '<dt>Member for</dt>', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
	}

	public function test_profile_sections_list_the_posts_of_the_user(): void {
		$user     = $this->named_user( 'Alan' );
		$actor    = new Actor( $user->ID );
		$question = $this->ask( $actor, 'A question asked by Alan' );
		$other    = $this->ask( $this->user(), 'A question answered by Alan' );
		$this->answer( $actor, $other );

		$questions = $this->render_template( 'easyq-user-questions', new Route( 'user', $user, 'questions' ) );
		Lists::reset();
		$answers = $this->render_template( 'easyq-user-answers', new Route( 'user', $user, 'answers' ) );
		Lists::reset();
		$activity = $this->render_template( 'easyq-user-activity', new Route( 'user', $user, 'activity' ) );

		$this->assertStringContainsString( 'Questions by Alan', $questions );
		$this->assertStringContainsString( 'A question asked by Alan', $questions );
		$this->assertStringNotContainsString( 'A question answered by Alan', $questions );
		$this->assertStringContainsString( 'A question answered by Alan', $answers );
		$this->assertStringNotContainsString( 'A question asked by Alan', $answers );
		$this->assertStringContainsString( 'A question asked by Alan', $activity );
		$this->assertStringContainsString( 'A question answered by Alan', $activity );
		$this->assertNotNull( $question );
	}

	public function test_users_page_lists_users_by_points(): void {
		$this->ask( new Actor( $this->named_user( 'Barbara' )->ID ) );

		$html = $this->render_template( 'easyq-users', new Route( 'users' ) );

		$this->assertStringContainsString( 'Top users', $html );
		$this->assertStringContainsString( 'Barbara', $html );
		$this->assertStringContainsString( 'wp-block-post-template', $html );
		$this->assertStringNotContainsString( 'sort=newest', $html );
	}

	public function test_newest_users_need_permission(): void {
		$this->named_user( 'Katherine' );
		$_GET['sort'] = 'newest';

		$visitor = $this->render_template( 'easyq-users', new Route( 'users' ) );

		wp_set_current_user( $this->administrator() );
		Lists::reset();
		$admin = $this->render_template( 'easyq-users', new Route( 'users' ) );

		$this->assertStringNotContainsString( 'Katherine', $visitor );
		$this->assertStringContainsString( 'Log in', $visitor );
		$this->assertStringContainsString( 'Katherine', $admin );
		$this->assertStringContainsString( 'Newest users', $admin );
	}

	public function test_user_titles_and_avatars(): void {
		$user = $this->named_user( 'Hedy' );
		Services::user_levels()->set_level( Actor::system(), $user->ID, Levels::EXPERT );

		Block_Context::router()->set_current( new Route( 'user', $user ) );
		$html = do_blocks( '<!-- wp:easyq/user --><!-- wp:easyq/user-title /--><!-- wp:easyq/user-avatar {"isLink":true} /--><!-- /wp:easyq/user -->' );

		$this->assertStringContainsString( 'Expert', $html );
		$this->assertStringContainsString( 'alt=\'Hedy\'', $html );
	}

	public function test_user_bindings(): void {
		$user = $this->named_user( 'Radia' );

		$html = do_blocks( '<!-- wp:easyq/user {"userId":' . $user->ID . '} --><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"easyq/user","args":{"key":"name"}}}}} --><p></p><!-- /wp:paragraph --><!-- /wp:easyq/user -->' );

		$this->assertStringContainsString( '>Radia</p>', $html );
	}

	public function test_account_page_needs_a_login(): void {
		$html = $this->render_template( 'easyq-account', new Route( 'account' ) );

		$this->assertStringContainsString( 'My account', $html );
		$this->assertStringContainsString( 'Log in', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_account_page_shows_the_profile_form(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		Services::user_fields()->set_value( $user, Services::user_fields()->all()[0], 'Paris' );

		$html = $this->render_template( 'easyq-account', new Route( 'account' ) );

		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'name="field_location"', $html );
		$this->assertStringContainsString( 'value="Paris"', $html );
		$this->assertStringContainsString( 'type="file"', $html );
		$this->assertStringNotContainsString( 'name="remove_avatar"', $html );

		Options::update( 'users', array( 'avatar_allow_upload' => false ) );
		$this->assertStringNotContainsString( 'type="file"', do_blocks( '<!-- wp:easyq/account /-->' ) );
	}

	public function test_success_messages_appear_on_every_page(): void {
		$_GET[ Flash::QUERY_VAR ] = Flash::store( 'success', 'Your profile has been saved.', 'account' );

		$template = \WP_Block_Templates_Registry::get_instance()->get_by_slug( 'easyq-tags' );
		$this->assertNotNull( $template );
		$this->assertStringContainsString( '<!-- wp:easyq/notices /-->', (string) $template->content );

		$html = do_blocks( '<!-- wp:easyq/notices /-->' );

		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'Your profile has been saved.', $html );
	}

	public function test_form_errors_are_shown_once(): void {
		$_GET[ Flash::QUERY_VAR ] = Flash::store( 'error', 'Please check the form.', 'ask' );

		$html = do_blocks( '<!-- wp:easyq/notices /-->' );

		// The form shows the error; the notices block stays an empty, hidden router region.
		$this->assertStringNotContainsString( 'Please check the form.', $html );
		$this->assertStringContainsString( ' hidden></div>', $html );
	}
}
