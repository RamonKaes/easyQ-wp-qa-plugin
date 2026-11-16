<?php
/**
 * Document title, canonical address, structured data and view counting.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\Blocks\Lists;
use EasyQ\Blocks\Render\Forms;
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
	 * @param mixed $parts Title parts.
	 * @return mixed string>
	 */
	public function title( mixed $parts ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $parts ) ) {
			return $parts;
		}

		if ( null !== $this->router->current() ) {
			$parts['title'] = Widgets::title_of_page();
		}

		return $parts;
	}

	/**
	 * Prints the canonical address of questions and profiles and the structured data of questions,
	 * unless an SEO plugin does, see the filter `easyq_print_seo_meta`.
	 *
	 * @since 0.1.0
	 */
	public function head(): void {
		$route = $this->router->current();
		if ( null === $route || 'not_found' === $route->name ) {
			return;
		}

		/**
		 * Filters whether easyQ prints the canonical address and the structured data of Q&A pages.
		 *
		 * SEO plugins print their own; by default easyQ leaves them out when Yoast SEO or Rank Math is active.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $print Whether to print them.
		 */
		if ( ! apply_filters( 'easyq_print_seo_meta', ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) ) ) {
			return;
		}

		$user = $route->user();
		if ( 'user' === $route->name && null !== $user ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( URLs::user( $user, $route->section ) ) );
			return;
		}

		$question = $this->router->shown_question();
		if ( null === $question ) {
			return;
		}

		// The page of the answers belongs to the address, as core adds `cpage` (`wp_get_canonical_url()`).
		$page = Lists::requested_page( Lists::ANSWERS_PAGE_VAR );
		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $page > 1 ? add_query_arg( Lists::ANSWERS_PAGE_VAR, $page, URLs::question( $question ) ) : URLs::question( $question ) ) );

		if ( Post::QUESTION === $question->type ) {
			// As core prints inline JSON (`wp_print_speculation_rules()`): with hex flags, so no text closes the element.
			wp_print_inline_script_tag( (string) wp_json_encode( $this->structured_data( $question ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), array( 'type' => 'application/ld+json' ) );
		}
	}

	/**
	 * Keeps search engines away from search results, forms, the account page, empty lists and hidden or queued posts.
	 *
	 * Block templates are rendered before the document head, so the lists of the page are known here.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $robots Robots directives.
	 * @return mixed bool|string>
	 */
	public function robots( mixed $robots ): mixed {
		// Another filter may hand on any type; it is passed on unchanged, as core does.
		if ( ! is_array( $robots ) ) {
			return $robots;
		}

		$route = $this->router->current();
		if ( null === $route ) {
			return $robots;
		}

		$question = $route->question();
		if ( in_array( $route->name, array( 'search', 'ask', 'account', 'unsubscribe' ), true ) || ( null !== $question && ( ! $question->is_visible() || null === $this->router->shown_question() ) ) || Lists::main_list_is_empty() ) {
			$robots['noindex'] = true;
		}

		// Addresses that open a form in place, as core handles `replytocom`.
		if ( Forms::opens_a_form() ) {
			$robots = wp_robots_no_robots( $robots ); // @phpstan-ignore argument.type (Other filters of wp_robots may hand on any keys; core's function only sets two of them.)
		}

		return $robots;
	}

	/**
	 * Counts a view of the question page.
	 *
	 * @since 0.1.0
	 */
	public function count_view(): void {
		$question = $this->router->shown_question();
		if ( null === $question || ! $question->is_visible() || ( is_user_logged_in() && get_current_user_id() === $question->user_id ) ) {
			return;
		}

		if ( ! self::human_probably() ) {
			return;
		}

		// As `qa_db_increment_views()`: a view counts unless the last one of the question came
		// from the same address. One value per question, holding a hash instead of the address.
		$key    = 'easyq_view_' . $question->id;
		$viewer = substr( hash_hmac( 'sha256', (string) IP::current(), wp_salt( 'nonce' ) ), 0, 32 );
		if ( get_transient( $key ) === $viewer ) {
			return;
		}

		set_transient( $key, $viewer, HOUR_IN_SECONDS );
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
			'text'        => self::plain_text( $renderer->html( (string) $answer->content, $answer->format, $answer->base_type() ) ),
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
			'text'        => self::plain_text( $renderer->html( (string) $question->content, $question->format, $question->base_type() ) ),
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

	/**
	 * Tells whether the request probably comes from a person, as `qa_is_human_probably()`:
	 * a browser by its user agent, and no prefetch or prerender.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private static function human_probably(): bool {
		$purpose = '';
		foreach ( array( 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ) as $header ) {
			$purpose .= isset( $_SERVER[ $header ] ) && is_string( $_SERVER[ $header ] ) ? ' ' . sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) : '';
		}
		if ( 1 === preg_match( '/prefetch|prerender|preview/i', $purpose ) ) {
			return false;
		}

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $agent ) {
			return true;
		}

		foreach ( array( 'MSIE', 'Firefox', 'Chrome', 'Safari', 'Opera', 'Gecko', 'MIDP', 'PLAYSTATION', 'Teleca', 'BlackBerry', 'UP.Browser', 'Polaris', 'MAUI_WAP_Browser', 'iPad', 'iPhone', 'iPod' ) as $browser ) {
			if ( str_contains( $agent, $browser ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns HTML as plain text for structured data: without tags and entities.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain_text( string $html ): string {
		return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
