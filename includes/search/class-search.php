<?php
/**
 * Search service.
 *
 * @package easyQ
 */

namespace EasyQ\Search;

use EasyQ\Posts\Post;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps all search providers informed about posts and runs searches.
 */
final class Search {

	/**
	 * Nesting depth of suspend() calls.
	 *
	 * @var int
	 */
	private int $suspended = 0;

	/**
	 * Registered providers, built on first use.
	 *
	 * @var array<string, Search_Provider>|null
	 */
	private ?array $providers = null;

	/**
	 * Returns all registered search providers.
	 *
	 * @return array<string, Search_Provider> Providers keyed by ID.
	 */
	public function providers(): array {
		if ( null === $this->providers ) {
			/**
			 * Filters the search providers.
			 *
			 * Add an instance of a class implementing EasyQ\Search\Search_Provider to offer another search engine.
			 *
			 * @param Search_Provider[] $providers Providers; the built-in search is always kept.
			 */
			$filtered = apply_filters( 'easyq_search_providers', array( new Basic_Search() ) );

			$this->providers = array();
			foreach ( is_array( $filtered ) ? $filtered : array() as $provider ) {
				if ( $provider instanceof Search_Provider ) {
					$this->providers[ $provider->id() ] = $provider;
				}
			}

			if ( ! isset( $this->providers['basic'] ) ) {
				$this->providers = array( 'basic' => new Basic_Search() ) + $this->providers;
			}
		}

		return $this->providers;
	}

	/**
	 * Returns the provider that answers searches.
	 *
	 * @return Search_Provider
	 */
	public function provider(): Search_Provider {
		$providers = $this->providers();

		return $providers[ Options::get_string( 'lists', 'search_provider' ) ] ?? $providers['basic'];
	}

	/**
	 * Stops indexing until resume() is called, e.g. during batch operations that reindex afterwards.
	 */
	public function suspend(): void {
		++$this->suspended;
	}

	/**
	 * Resumes indexing after suspend().
	 */
	public function resume(): void {
		$this->suspended = max( 0, $this->suspended - 1 );
	}

	/**
	 * Adds a post to the index of every provider.
	 *
	 * @param Post     $post        The post.
	 * @param int      $question_id ID of the question the post belongs to.
	 * @param int|null $category_id Category of the question.
	 */
	public function index( Post $post, int $question_id, ?int $category_id ): void {
		if ( $this->suspended > 0 ) {
			return;
		}

		$data = new Indexed_Post(
			$post->id,
			$post->base_type(),
			$question_id,
			$post->parent_id,
			Post::QUESTION === $post->base_type() ? $post->title : null,
			$post->text(),
			Post::QUESTION === $post->base_type() ? $post->tags : null,
			$category_id
		);

		foreach ( $this->providers() as $provider ) {
			$provider->index_post( $data );
		}
	}

	/**
	 * Removes a post from the index of every provider.
	 *
	 * @param int $post_id Post ID.
	 */
	public function unindex( int $post_id ): void {
		if ( $this->suspended > 0 ) {
			return;
		}

		foreach ( $this->providers() as $provider ) {
			$provider->unindex_post( $post_id );
		}
	}

	/**
	 * Tells every provider that posts moved to another category.
	 *
	 * @param int[]    $post_ids    Post IDs.
	 * @param int|null $category_id New category ID.
	 */
	public function move( array $post_ids, ?int $category_id ): void {
		foreach ( $this->providers() as $provider ) {
			foreach ( $post_ids as $post_id ) {
				$provider->move_post( $post_id, $category_id );
			}
		}
	}

	/**
	 * Searches questions with the selected provider.
	 *
	 * @param string   $query   Search query.
	 * @param int      $start   Offset of the first result.
	 * @param int      $count   Maximum number of results.
	 * @param int|null $user_id User searching, or null for visitors.
	 * @return Search_Result[]
	 */
	public function search( string $query, int $start, int $count, ?int $user_id = null ): array {
		return $this->provider()->search( $query, $start, $count, $user_id );
	}
}
