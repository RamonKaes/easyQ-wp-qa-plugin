<?php
/**
 * Document title, canonical address, structured data and view counting.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render\Widgets;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Util\IP;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the meta data of Q&A pages and counts question views.
 *
 * Views are counted once per visitor and hour. To tell visitors apart without
 * storing their IP address, a salted hash of the address and the question is
 * kept in a transient for an hour.
 *
 * @since 0.1.0
 */
final class Page_Meta {

	/**
	 * Sets up the meta data.
	 *
	 * @since 0.1.0
	 *
	 * @param Router $router Router of the current request.
	 */
	public function __construct( private readonly Router $router ) {
	}

	/**
	 * Hooks the meta data into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'document_title_parts', array( $this, 'title' ) );
		add_action( 'wp_head', array( $this, 'head' ), 1 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'template_redirect', array( $this, 'count_view' ) );
	}

	/**
	 * Sets the document title of Q&A pages.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function title( array $parts ): array {
		if ( null !== $this->router->current() ) {
			$parts['title'] = Widgets::title_of_page();
		}

		return $parts;
	}

	/**
	 * Prints the canonical address of questions and profiles and the structured data of questions.
	 *
	 * @since 0.1.0
	 */
	public function head(): void {
		$route = $this->router->current();
		if ( null === $route || 'not_found' === $route->name ) {
			return;
		}

		$user = $route->user();
		if ( 'user' === $route->name && null !== $user ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( URLs::user( $user, $route->section ) ) );
			return;
		}

		$question = $route->question();
		if ( null === $question ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( URLs::question( $question ) ) );

		if ( Post::QUESTION === $question->type ) {
			printf( '<script type="application/ld+json">%s</script>' . "\n", wp_json_encode( $this->structured_data( $question ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Keeps search engines away from search results, forms, the account page, empty lists and hidden or queued posts.
	 *
	 * Block templates are rendered before the document head, so the lists of the page are known here.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		$route = $this->router->current();
		if ( null === $route ) {
			return $robots;
		}

		$question = $route->question();
		if ( in_array( $route->name, array( 'search', 'ask', 'account' ), true ) || ( null !== $question && ! $question->is_visible() ) || Lists::main_list_is_empty() ) {
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/**
	 * Counts a view of the question page.
	 *
	 * @since 0.1.0
	 */
	public function count_view(): void {
		$question = $this->router->current()?->question();
		if ( null === $question || ! $question->is_visible() || ( is_user_logged_in() && get_current_user_id() === $question->user_id ) ) {
			return;
		}

		$key = 'easyq_view_' . substr( hash_hmac( 'sha256', $question->id . '|' . (string) IP::current(), wp_salt( 'nonce' ) ), 0, 32 );
		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, 1, HOUR_IN_SECONDS );
		Services::post_service()->record_view( $question );
	}

	/**
	 * Returns the schema.org data of a question page.
	 *
	 * @since 0.1.0
	 *
	 * @param Post $question Question.
	 * @return array<string, mixed>
	 */
	public function structured_data( Post $question ): array {
		$renderer = Services::content_renderer();
		$answers  = array_filter( Services::posts()->answers_of( $question->id ), static fn ( Post $answer ): bool => Post::ANSWER === $answer->type );

		$answer_data = static fn ( Post $answer ): array => array(
			'@type'       => 'Answer',
			'text'        => wp_strip_all_tags( $renderer->html( (string) $answer->content, $answer->format ) ),
			'dateCreated' => mysql_to_rfc3339( $answer->created ),
			'upvoteCount' => $answer->net_votes,
			'url'         => URLs::post( $answer ),
			'author'      => array(
				'@type' => 'Person',
				'name'  => \EasyQ\Blocks\Render::author_name( $answer ),
			),
		);

		$main = array(
			'@type'       => 'Question',
			'name'        => $renderer->text( (string) $question->title ),
			'text'        => wp_strip_all_tags( $renderer->html( (string) $question->content, $question->format ) ),
			'answerCount' => $question->answer_count,
			'upvoteCount' => $question->net_votes,
			'dateCreated' => mysql_to_rfc3339( $question->created ),
			'author'      => array(
				'@type' => 'Person',
				'name'  => \EasyQ\Blocks\Render::author_name( $question ),
			),
		);

		if ( null !== $question->selected_child_id && isset( $answers[ $question->selected_child_id ] ) ) {
			$main['acceptedAnswer'] = $answer_data( $answers[ $question->selected_child_id ] );
		}

		$suggested = array_values( array_diff_key( $answers, array_flip( array_filter( array( $question->selected_child_id ) ) ) ) );
		if ( array() !== $suggested ) {
			$main['suggestedAnswer'] = array_map( $answer_data, $suggested );
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'QAPage',
			'mainEntity' => $main,
		);
	}
}
