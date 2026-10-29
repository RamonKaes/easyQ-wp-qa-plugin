<?php
/**
 * RSS feeds of the Q&A lists.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Feeds;

use EasyQ\Actor;
use EasyQ\Categories\Category;
use EasyQ\Frontend\Route;
use EasyQ\Frontend\Router;
use EasyQ\Frontend\Routes;
use EasyQ\Frontend\URLs;
use EasyQ\Posts\Activity_Item;
use EasyQ\Posts\Post;
use EasyQ\Services;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the RSS 2.0 feeds of Question2Answer: recent, hot and unanswered questions,
 * answers, comments, questions and answers, all activity, tags and search results.
 *
 * Addresses are `/{base}/feed/{type}/{argument}`, e.g. `/questions/feed/questions/cooking`
 * for a category or `/questions/feed/tag/pasta`. Feeds contain only what visitors may see,
 * and no author names.
 *
 * Ported from Question2Answer `qa-feed.php`.
 *
 * @since 0.1.0
 */
final class Feeds {

	/**
	 * Name of the feed, as in `?feed=easyq`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FEED = 'easyq';

	/**
	 * Feed types and the setting that enables them.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public const TYPES = array(
		'questions'  => 'feed_for_questions',
		'hot'        => 'feed_for_hot',
		'unanswered' => 'feed_for_unanswered',
		'answers'    => 'feed_for_activity',
		'comments'   => 'feed_for_activity',
		'activity'   => 'feed_for_activity',
		'qa'         => 'feed_for_qa',
		'tag'        => 'feed_for_tag_qs',
		'search'     => 'feed_for_search',
	);

	/**
	 * Feed types that need an argument: the tag or the search terms.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const WITH_ARGUMENT = array( 'tag', 'search' );

	/**
	 * Kinds of activity of the activity feed types, see Activity_Query::KINDS.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string[]>
	 */
	private const ACTIVITY = array(
		'answers'  => array( 'answers' ),
		'comments' => array( 'comments' ),
		'qa'       => array( 'questions', 'answers' ),
		'activity' => array( 'questions', 'answers', 'comments', 'edits' ),
	);

	/**
	 * Sets up the feeds.
	 *
	 * @since 0.1.0
	 *
	 * @param Router $router Router of the current request.
	 */
	public function __construct( private readonly Router $router ) {
	}

	/**
	 * Hooks the feeds into WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_feed' ) );
		add_action( 'wp_head', array( $this, 'discovery_link' ), 2 );
		add_action( 'parse_request', array( $this, 'ignore_conditional_get' ), 20 );
		add_filter( 'wp_headers', array( $this, 'headers' ) );
	}

	/**
	 * Keeps WordPress from answering Q&A feed requests with "304 Not Modified".
	 *
	 * WordPress compares the client's cache headers with the last change of its own
	 * posts and comments, which says nothing about questions and answers. Runs after
	 * the router resolved the route.
	 *
	 * @since 0.1.0
	 */
	public function ignore_conditional_get(): void {
		if ( 'feed' === $this->router->current()?->name ) {
			unset( $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		}
	}

	/**
	 * Removes the cache headers WordPress derives from its own posts from Q&A feeds.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|false> $headers HTTP headers.
	 * @return array<string, string|false>
	 */
	public function headers( array $headers ): array {
		if ( 'feed' === $this->router->current()?->name ) {
			unset( $headers['ETag'] );
			$headers['Last-Modified'] = false;
		}

		return $headers;
	}

	/**
	 * Registers the feed.
	 *
	 * @since 0.1.0
	 */
	public function add_feed(): void {
		add_feed( self::FEED, array( $this, 'render' ) );
	}

	/**
	 * Returns the address of a feed.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $type     Feed type, see TYPES.
	 * @param string|null $argument Tag, search terms or category path (slugs joined by `/`).
	 * @return string
	 */
	public static function url( string $type, ?string $argument = null ): string {
		return Routes::url( 'feed', $type, null === $argument || '' === $argument ? null : $argument );
	}

	/**
	 * Returns the feed that belongs to a Q&A page.
	 *
	 * @since 0.1.0
	 *
	 * @param Route  $route Route of the page.
	 * @param string $query Search terms of a search page.
	 * @param string $sort  Sort order of the questions page.
	 * @return array{0: string, 1: string|null}|null Feed type and argument, or null if the page has no feed.
	 */
	public static function for_route( Route $route, string $query = '', string $sort = '' ): ?array {
		$category = $route->category();

		$feed = match ( $route->name ) {
			'home'       => array( 'qa', null ),
			// Like Question2Answer, only the recent and the hot questions have a feed.
			'questions'  => match ( $sort ) {
				'', 'recent' => array( 'questions', null ),
				'hot'        => array( 'hot', null ),
				default      => null,
			},
			'hot'        => array( 'hot', null ),
			'unanswered' => array( 'unanswered', null ),
			'activity'   => array( 'activity', null ),
			'answers'    => array( 'answers', null ),
			'comments'   => array( 'comments', null ),
			'tag'        => array( 'tag', $route->tag() ),
			'category'   => null === $category ? null : array( 'questions', implode( '/', $category->path_slugs() ) ),
			'search'     => '' === $query ? null : array( 'search', $query ),
			default      => null,
		};

		return null !== $feed && self::enabled( $feed[0], 'category' === $route->name ) ? $feed : null;
	}

	/**
	 * Checks whether a feed type is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type        Feed type.
	 * @param bool   $in_category Whether the feed is limited to a category.
	 * @return bool
	 */
	public static function enabled( string $type, bool $in_category = false ): bool {
		$option = self::TYPES[ $type ] ?? null;
		if ( null === $option || ! Options::get_bool( 'feeds', $option ) ) {
			return false;
		}

		return ! $in_category || ( ! in_array( $type, self::WITH_ARGUMENT, true ) && Options::get_bool( 'feeds', 'feed_per_category' ) );
	}

	/**
	 * Prints the autodiscovery link of the feed of the current Q&A page.
	 *
	 * @since 0.1.0
	 */
	public function discovery_link(): void {
		$route = $this->router->current();
		if ( null === $route ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the search terms of the page.
		$query = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the sort order of the page.
		$sort = isset( $_GET['sort'] ) && is_string( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : '';
		$feed = self::for_route( $route, $query, $sort );
		if ( null === $feed ) {
			return;
		}

		$channel = $this->channel( $feed[0], $feed[1], new Actor() );
		if ( null === $channel ) {
			return;
		}

		printf(
			'<link rel="alternate" type="%s" title="%s" href="%s" />' . "\n",
			esc_attr( feed_content_type( 'rss2' ) ),
			esc_attr( $channel['title'] ),
			esc_url( self::url( $feed[0], $feed[1] ) )
		);
	}

	/**
	 * Returns title, address and items of a feed.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $type     Feed type, see TYPES.
	 * @param string|null $argument Tag, search terms or category path.
	 * @param Actor       $viewer   Who reads the feed; feeds are built for visitors.
	 * @return array{title: string, link: string, items: list<array{title: string, link: string, description: string|null, category: string|null, date: string}>}|null
	 *         Null if the feed does not exist, is disabled or the viewer may not see the Q&A area.
	 */
	public function channel( string $type, ?string $argument, Actor $viewer ): ?array {
		$argument = null === $argument ? '' : trim( $argument );
		$category = null;

		if ( in_array( $type, self::WITH_ARGUMENT, true ) ) {
			if ( '' === $argument ) {
				return null;
			}
		} elseif ( '' !== $argument ) {
			$category = Services::categories()->find_by_slugs( array_map( 'sanitize_title', explode( '/', $argument ) ) );
			if ( null === $category ) {
				return null;
			}
		}

		if ( ! self::enabled( $type, null !== $category ) || null !== Services::permissions()->error( 'permit_view_q_page', $viewer, check_blocks: false ) ) {
			return null;
		}

		$count = max( 1, min( 100, Options::get_int( 'feeds', 'feed_number_items' ) ) );

		return array(
			'title' => $this->title( $type, $argument, $category ),
			'link'  => $this->link( $type, $argument, $category ),
			'items' => match ( $type ) {
				'questions', 'hot', 'unanswered' => $this->question_items( array( 'source' => 'questions' === $type ? 'recent' : $type ), $category, $count ),
				'tag'    => $this->question_items( array( 'tag' => $argument ), null, $count ),
				'search' => $this->search_items( $argument, $count ),
				default  => $this->activity_items( self::ACTIVITY[ $type ] ?? array(), $category, $count ),
			},
		);
	}

	/**
	 * Prints the feed of the current request.
	 *
	 * @since 0.1.0
	 */
	public function render(): void {
		$route   = $this->router->current();
		$channel = null !== $route && 'feed' === $route->name && is_string( $route->subject )
			? $this->channel( $route->subject, $route->section, new Actor() )
			: null;

		if ( null === $channel ) {
			status_header( 404 );
			if ( ! headers_sent() ) {
				nocache_headers();
				header( 'Content-Type: text/plain; charset=' . get_bloginfo( 'charset' ) );
			}
			echo esc_html__( 'This feed does not exist.', 'easyq' );
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . feed_content_type( 'rss2' ) . '; charset=' . get_bloginfo( 'charset' ), true );
		}
		echo $this->xml( $channel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped with esc_xml() in xml().
	}

	/**
	 * Returns the RSS 2.0 document of a feed.
	 *
	 * @since 0.1.0
	 *
	 * @param array{title: string, link: string, items: list<array{title: string, link: string, description: string|null, category: string|null, date: string}>} $channel Feed, see channel().
	 * @return string
	 */
	public function xml( array $channel ): string {
		$site  = get_bloginfo( 'name' );
		$lines = array(
			'<?xml version="1.0" encoding="' . esc_xml( get_bloginfo( 'charset' ) ) . '"?>',
			'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
			'<channel>',
			'<title>' . esc_xml( '' === $site ? $channel['title'] : $site . ' – ' . $channel['title'] ) . '</title>',
			'<link>' . esc_xml( $channel['link'] ) . '</link>',
			'<atom:link href="' . esc_xml( get_self_link() ) . '" rel="self" type="application/rss+xml" />',
			'<description>' . esc_xml( get_bloginfo( 'description' ) ) . '</description>',
			'<language>' . esc_xml( get_bloginfo( 'language' ) ) . '</language>',
		);

		foreach ( $channel['items'] as $item ) {
			$lines[] = '<item>';
			$lines[] = '<title>' . esc_xml( $item['title'] ) . '</title>';
			$lines[] = '<link>' . esc_xml( $item['link'] ) . '</link>';
			if ( null !== $item['description'] ) {
				$lines[] = '<description>' . esc_xml( $item['description'] ) . '</description>';
			}
			if ( null !== $item['category'] ) {
				$lines[] = '<category>' . esc_xml( $item['category'] ) . '</category>';
			}
			$lines[] = '<guid isPermaLink="true">' . esc_xml( $item['link'] ) . '</guid>';
			$time    = strtotime( $item['date'] . ' UTC' );
			if ( false !== $time ) {
				$lines[] = '<pubDate>' . esc_xml( gmdate( DATE_RSS, $time ) ) . '</pubDate>';
			}
			$lines[] = '</item>';
		}

		$lines[] = '</channel>';
		$lines[] = '</rss>';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns the title of a feed, without the site name.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $type     Feed type.
	 * @param string        $argument Tag or search terms.
	 * @param Category|null $category Category.
	 * @return string
	 */
	private function title( string $type, string $argument, ?Category $category ): string {
		$title = match ( $type ) {
			'hot'        => __( 'Hot questions', 'easyq' ),
			'unanswered' => __( 'Questions without answers', 'easyq' ),
			'answers'    => __( 'Recent answers', 'easyq' ),
			'comments'   => __( 'Recent comments', 'easyq' ),
			'qa'         => __( 'Recent questions and answers', 'easyq' ),
			'activity'   => __( 'Recent activity', 'easyq' ),
			/* translators: %s: tag. */
			'tag'        => sprintf( __( 'Questions tagged “%s”', 'easyq' ), $argument ),
			/* translators: %s: search terms. */
			'search'     => sprintf( __( 'Results for “%s”', 'easyq' ), $argument ),
			default      => __( 'Recent questions', 'easyq' ),
		};

		/* translators: 1: feed title, e.g. "Recent questions", 2: category. */
		return null === $category ? $title : sprintf( __( '%1$s in %2$s', 'easyq' ), $title, $category->title );
	}

	/**
	 * Returns the address of the Q&A page a feed belongs to.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $type     Feed type.
	 * @param string        $argument Tag or search terms.
	 * @param Category|null $category Category.
	 * @return string
	 */
	private function link( string $type, string $argument, ?Category $category ): string {
		if ( null !== $category && in_array( $type, array( 'questions', 'hot', 'unanswered' ), true ) ) {
			return URLs::category( $category );
		}

		return match ( $type ) {
			'hot', 'unanswered', 'answers', 'comments', 'activity', 'questions' => Routes::url( $type ),
			'tag'    => Routes::url( 'tag', $argument ),
			'search' => Routes::url( 'search', null, null, array( 'q' => $argument ) ),
			default  => Routes::url( 'home' ),
		};
	}

	/**
	 * Returns questions as feed items.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args     Question query arguments.
	 * @param Category|null        $category Category.
	 * @param int                  $count    Number of items.
	 * @return list<array{title: string, link: string, description: string|null, category: string|null, date: string}>
	 */
	private function question_items( array $args, ?Category $category, int $count ): array {
		if ( null !== $category ) {
			$args['category_id'] = $category->id;
		}

		$found = Services::question_query()->find( $args + array( 'per_page' => $count ) );

		return array_values( array_map( fn ( Post $question ): array => $this->item( $question, $question, null ), $found['posts'] ) );
	}

	/**
	 * Returns activity as feed items, one per question, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]      $kinds    Kinds of activity.
	 * @param Category|null $category Category.
	 * @param int           $count    Number of items.
	 * @return list<array{title: string, link: string, description: string|null, category: string|null, date: string}>
	 */
	private function activity_items( array $kinds, ?Category $category, int $count ): array {
		$query = Services::activity_query();
		$items = $query->recent( $kinds, null === $category ? array() : array( 'category_id' => $category->id ), $count );
		$posts = $query->posts_of( $items );

		$result = array();
		foreach ( $items as $item ) {
			$question = $posts[ $item->question_id ] ?? null;
			$post     = $posts[ $item->post_id ] ?? null;
			if ( null !== $question && null !== $post && $question->is_visible() && $post->is_visible() ) {
				$result[] = $this->item( $question, $post, $item );
			}
		}

		return $result;
	}

	/**
	 * Returns search results as feed items, by relevance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $query Search terms.
	 * @param int    $count Number of items.
	 * @return list<array{title: string, link: string, description: string|null, category: string|null, date: string}>
	 */
	private function search_items( string $query, int $count ): array {
		$result = array();
		foreach ( Services::search()->search( $query, 0, $count ) as $found ) {
			$question = Services::posts()->find( $found->question_id );
			if ( null !== $question && Post::QUESTION === $question->type && $question->is_visible() ) {
				$result[] = $this->item( $question, $question, null );
			}
		}

		return $result;
	}

	/**
	 * Returns a feed item.
	 *
	 * @since 0.1.0
	 *
	 * @param Post               $question Question.
	 * @param Post               $post     Question, answer or comment the item is about.
	 * @param Activity_Item|null $activity What happened, for activity feeds.
	 * @return array{title: string, link: string, description: string|null, category: string|null, date: string}
	 */
	private function item( Post $question, Post $post, ?Activity_Item $activity ): array {
		$renderer = Services::content_renderer();
		$title    = $renderer->text( (string) $question->title );

		// A new question needs no label; everything else says what happened, e.g. "answered".
		if ( null !== $activity && ( Post::QUESTION !== $activity->base_type || null !== $activity->update_type ) ) {
			/* translators: 1: question title, 2: what happened, e.g. "answered". */
			$title = sprintf( __( '%1$s (%2$s)', 'easyq' ), $title, $activity->label( $post ) );
		}

		$category = null === $question->category_id ? null : Services::categories()->find( $question->category_id );

		return array(
			'title'       => $title,
			'link'        => $post->id === $question->id ? URLs::question( $question ) : URLs::post( $post ),
			'description' => Options::get_bool( 'feeds', 'feed_full_text' ) ? $renderer->html( (string) $post->content, $post->format ) : null,
			'category'    => $category?->title,
			'date'        => null === $activity ? $question->created : $activity->time,
		);
	}
}
