<?php
/**
 * A resolved route.
 *
 * @package easyQ
 */

namespace EasyQ\Frontend;

use EasyQ\Categories\Category;
use EasyQ\Posts\Post;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * The Q&A page a request asks for, with the object it is about.
 */
final class Route {

	/**
	 * Sets up the route.
	 *
	 * @param string                            $name    Route name, e.g. `question`.
	 * @param Post|Category|WP_User|string|null $subject Question, category, user or tag the page is about.
	 * @param string|null                       $section Profile section of user pages.
	 */
	public function __construct(
		public readonly string $name,
		public readonly Post|Category|WP_User|string|null $subject = null,
		public readonly ?string $section = null
	) {
	}

	/**
	 * Returns the route for addresses below the base slug that match nothing.
	 *
	 * @return self
	 */
	public static function not_found(): self {
		return new self( 'not_found' );
	}

	/**
	 * Returns the question of a question page.
	 *
	 * @return Post|null
	 */
	public function question(): ?Post {
		return $this->subject instanceof Post ? $this->subject : null;
	}

	/**
	 * Returns the category of a category page.
	 *
	 * @return Category|null
	 */
	public function category(): ?Category {
		return $this->subject instanceof Category ? $this->subject : null;
	}

	/**
	 * Returns the user of a profile or conversation page.
	 *
	 * @return WP_User|null
	 */
	public function user(): ?WP_User {
		return $this->subject instanceof WP_User ? $this->subject : null;
	}

	/**
	 * Returns the tag of a tag page.
	 *
	 * @return string|null
	 */
	public function tag(): ?string {
		return 'tag' === $this->name && is_string( $this->subject ) ? $this->subject : null;
	}

	/**
	 * Returns the slug of the block template for the route.
	 *
	 * @return string
	 */
	public function template_slug(): string {
		$slug = 'easyq-' . str_replace( '_', '-', $this->name );

		return null === $this->section ? $slug : $slug . '-' . $this->section;
	}
}
