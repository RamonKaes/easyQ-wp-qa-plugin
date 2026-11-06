<?php
/**
 * Tests for the extra question field, as Question2Answer's `extra_field_*` settings describe it.
 *
 * @package easyQ
 */

namespace EasyQ\Tests\Integration;

use EasyQ\Blocks\Block_Context;
use EasyQ\Blocks\Render\Forms;
use EasyQ\Frontend\Route;
use EasyQ\Posts\Post;
use EasyQ\Posts\Post_Service;
use EasyQ\Services;
use EasyQ\Settings\Options;

/**
 * @covers \EasyQ\REST\Post_Input
 * @covers \EasyQ\REST\Post_Formatter
 * @covers \EasyQ\REST\Questions_Controller
 * @covers \EasyQ\Blocks\Render\Forms
 * @covers \EasyQ\Blocks\Render\Post_Fields
 */
final class Extra_Field_Test extends REST_Test_Case {

	public function tear_down(): void {
		Block_Context::router()->set_current( null );
		parent::tear_down();
	}

	public function test_the_field_is_off_by_default_and_ignores_submitted_values(): void {
		$this->login();
		$this->assertFalse( Options::get_bool( 'posting', 'extra_field_active' ) );
		$this->assertFalse( Options::get_bool( 'posting', 'extra_field_display' ) );

		$question = $this->question_via_rest( array( 'extra' => 'Debian 13' ) );

		$this->assertSame( '', Post_Service::extra( $question ) );
		$this->assertStringNotContainsString( 'name="extra"', do_blocks( '<!-- wp:easyq/ask-form /-->' ) );
		$this->assertArrayNotHasKey( 'extra', $this->data( $this->request( 'GET', '/questions/' . $question->id ) ) );
	}

	public function test_asking_and_editing_keep_the_value_while_the_field_is_on(): void {
		Options::update( 'posting', array( 'extra_field_active' => true ) );
		$this->login();

		$question = $this->question_via_rest( array( 'extra' => "  Debian 13\n<b>trixie</b> " ) );
		$this->assertSame( 'Debian 13 trixie', Post_Service::extra( $question ) );

		// Only an author who may edit the question reads the raw value; pages do not show it yet.
		$data = $this->data( $this->request( 'GET', '/questions/' . $question->id, array( 'context' => 'edit' ) ) );
		$this->assertSame(
			array(
				'rendered' => '',
				'raw'      => 'Debian 13 trixie',
			),
			$data['extra']
		);

		$this->assertSame( 200, $this->request( 'PUT', '/questions/' . $question->id, array( 'extra' => 'Ubuntu 24.04' ) )->get_status() );
		$this->assertSame( 'Ubuntu 24.04', Post_Service::extra( $question ) );

		// A request without the field keeps the value, as an edit form of Question2Answer would.
		$this->assertSame( 200, $this->request( 'PUT', '/questions/' . $question->id, array( 'title' => 'How do I configure the widget again?' ) )->get_status() );
		$this->assertSame( 'Ubuntu 24.04', Post_Service::extra( $question ) );

		// Once the field is off again, submitted values are ignored and the stored one stays.
		Options::update( 'posting', array( 'extra_field_active' => false ) );
		$this->request( 'PUT', '/questions/' . $question->id, array( 'extra' => 'Fedora' ) );
		$this->assertSame( 'Ubuntu 24.04', Post_Service::extra( $question ) );
	}

	public function test_the_forms_offer_the_field_with_the_prompt_of_the_setting(): void {
		Options::update(
			'posting',
			array(
				'extra_field_active' => true,
				'extra_field_prompt' => 'Which operating system do you use?',
			)
		);
		$author = $this->user();
		wp_set_current_user( (int) $author->user_id );

		$this->assertMatchesRegularExpression(
			'/id="easyq-ask-content".*<label for="easyq-ask-extra">Which operating system do you use\?<\/label>\s*<input[^>]*name="extra"/s',
			do_blocks( '<!-- wp:easyq/ask-form /-->' )
		);

		// The edit form of a question shows the stored value; answers have no extra field.
		$question = $this->ask( $author );
		update_metadata( 'easyq_post', $question->id, Post_Service::EXTRA_META, 'Debian 13' );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="extra"[^>]*value="Debian 13"|<input[^>]*value="Debian 13"[^>]*name="extra"/', Forms::edit_form( $question ) );
		$this->assertStringNotContainsString( 'name="extra"', Forms::edit_form( $this->answer( $this->user(), $question ) ) );

		// A field always needs a label, also without a prompt.
		Options::update( 'posting', array( 'extra_field_prompt' => '' ) );
		$this->assertStringContainsString( '<label for="easyq-ask-extra">Extra information</label>', do_blocks( '<!-- wp:easyq/ask-form /-->' ) );
	}

	public function test_the_block_shows_the_value_only_where_question_pages_show_it(): void {
		$question = $this->ask( $this->user() );
		$answer   = $this->answer( $this->user(), $question );
		update_metadata( 'easyq_post', $question->id, Post_Service::EXTRA_META, 'Debian with walrus drivers' );
		Block_Context::router()->set_current( new Route( 'question', $question ) );
		$extra = static fn ( int $post_id = 0 ): string => do_blocks(
			'<!-- wp:easyq/question' . ( $post_id > 0 ? ' {"questionId":' . $post_id . '}' : '' ) . ' --><!-- wp:easyq/post-extra /--><!-- /wp:easyq/question -->'
		);

		$this->assertStringNotContainsString( 'wp-block-easyq-post-extra', $extra() );
		Options::update( 'posting', array( 'extra_field_active' => true ) );
		$this->assertStringNotContainsString( 'wp-block-easyq-post-extra', $extra() );

		Options::update(
			'posting',
			array(
				'extra_field_display' => true,
				'extra_field_label'   => 'System:',
				'block_bad_words'     => 'walrus',
			)
		);
		$this->assertMatchesRegularExpression( '/<div class="wp-block-easyq-post-extra[^"]*"><span>System:<\/span> Debian with \*+ drivers<\/div>/', $extra() );

		// The REST API shows what the page shows.
		$this->assertSame( array( 'rendered' => 'Debian with ****** drivers' ), $this->data( $this->request( 'GET', '/questions/' . $question->id ) )['extra'] );

		Options::update( 'posting', array( 'extra_field_label' => '' ) );
		$this->assertMatchesRegularExpression( '/<div class="wp-block-easyq-post-extra[^"]*">Debian with \*+ drivers<\/div>/', $extra() );

		// An answer has no extra field, and a question without a value shows nothing.
		$this->assertSame( '', trim( $extra( $answer->id ) ) );
		update_metadata( 'easyq_post', $question->id, Post_Service::EXTRA_META, '' );
		$this->assertStringNotContainsString( 'wp-block-easyq-post-extra', $extra() );
	}

	public function test_lists_load_the_values_of_all_questions_at_once(): void {
		global $wpdb;
		$author = $this->user();
		for ( $i = 0; $i < 4; $i++ ) {
			$question = $this->ask( $author, 'Listed question number ' . $i );
			update_metadata( 'easyq_post', $question->id, Post_Service::EXTRA_META, 'Value ' . $i );
		}
		$queries = function ( int $per_page ) use ( $wpdb ): int {
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$this->request( 'GET', '/questions', array( 'per_page' => $per_page ) );

			return $wpdb->num_queries - $before;
		};

		// The first request of a test loads what stays for the whole request, e.g. the settings.
		$queries( 1 );
		$off = array( $queries( 2 ), $queries( 4 ) );
		Options::update( 'posting', array( 'extra_field_active' => true ) );
		$on = array( $queries( 2 ), $queries( 4 ) );

		// One query more for the values, however long the list.
		$this->assertSame( array( $off[0] + 1, $off[1] + 1 ), $on );
	}

	/**
	 * Asks a question through the REST API as the current user.
	 *
	 * @param array<string, mixed> $params Further parameters.
	 * @return Post
	 */
	private function question_via_rest( array $params ): Post {
		$response = $this->request(
			'POST',
			'/questions',
			$params + array(
				'title'   => 'Which drivers does my printer need?',
				'content' => 'It prints nothing.',
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$question = Services::posts()->find( (int) $this->data( $response )['id'] );
		$this->assertNotNull( $question );

		return $question;
	}
}
