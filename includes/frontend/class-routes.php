<?php
/**
 * Q&A routes.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Frontend;

use EasyQ\Feeds\Feeds;
use EasyQ\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the Q&A pages below the base slug and builds their addresses.
 *
 * With pretty permalinks the pages live at `/{base}/…`, e.g. `/questions/42/my-question`;
 * without, query parameters are used, e.g. `/?easyq_route=question&easyq_id=42`.
 *
 * @since 0.1.0
 */
final class Routes {

	/**
	 * Query variable with the route name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUTE_VAR = 'easyq_route';

	/**
	 * Query variable with a route argument, e.g. a question ID, tag or user name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ARG_VAR = 'easyq_arg';

	/**
	 * Query variable with a second route argument, e.g. a profile section or the argument of a feed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SECTION_VAR = 'easyq_section';

	/**
	 * Routes without arguments and their path below the base slug.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	public const SIMPLE = array(
		'ask'         => 'ask',
		'hot'         => 'hot',
		'unanswered'  => 'unanswered',
		'activity'    => 'activity',
		'answers'     => 'answers',
		'comments'    => 'comments',
		'tags'        => 'tags',
		'categories'  => 'categories',
		'users'       => 'users',
		'search'      => 'search',
		'account'     => 'account',
		'favorites'   => 'favorites',
		'updates'     => 'updates',
		'messages'    => 'messages',
		'feedback'    => 'feedback',
		'unsubscribe' => 'unsubscribe',
	);

	/**
	 * Sections of user profiles.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	public const USER_SECTIONS = array( 'questions', 'answers', 'activity', 'wall' );

	/**
	 * Returns the base slug of the Q&A area.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function base(): string {
		$base = trim( Options::get_string( 'general', 'base_slug' ), '/' );

		return '' === $base ? 'questions' : $base;
	}

	/**
	 * Returns the rewrite rules of the Q&A area.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Query strings keyed by regular expression.
	 */
	public static function rewrite_rules(): array {
		$base   = preg_quote( self::base(), '#' );
		$route  = self::ROUTE_VAR;
		$arg    = self::ARG_VAR;
		$simple = implode( '|', array_map( static fn ( string $path ): string => preg_quote( $path, '#' ), self::SIMPLE ) );
		$feeds  = implode( '|', array_keys( Feeds::TYPES ) );

		return array(
			"^{$base}/?$"                            => "index.php?{$route}=home",
			"^{$base}/([0-9]+)(?:/[^/]*)?/?$"        => "index.php?{$route}=question&{$arg}=\$matches[1]",
			"^{$base}/({$simple})/?$"                => "index.php?{$route}=\$matches[1]",
			"^{$base}/tag/([^/]+)/?$"                => "index.php?{$route}=tag&{$arg}=\$matches[1]",
			"^{$base}/categories/(.+?)/?$"           => "index.php?{$route}=category&{$arg}=\$matches[1]",
			"^{$base}/user/([^/]+)/(" . implode( '|', self::USER_SECTIONS ) . ')/?$' => "index.php?{$route}=user&{$arg}=\$matches[1]&" . self::SECTION_VAR . '=$matches[2]',
			"^{$base}/user/([^/]+)/?$"               => "index.php?{$route}=user&{$arg}=\$matches[1]",
			"^{$base}/message/([^/]+)/?$"            => "index.php?{$route}=message&{$arg}=\$matches[1]",
			"^{$base}/feed/({$feeds})(?:/(.+?))?/?$" => 'index.php?feed=' . Feeds::FEED . "&{$route}=feed&{$arg}=\$matches[1]&" . self::SECTION_VAR . '=$matches[2]',
		);
	}

	/**
	 * Checks whether the site uses pretty permalinks.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function pretty(): bool {
		$structure = get_option( 'permalink_structure' );

		return is_string( $structure ) && '' !== $structure;
	}

	/**
	 * Returns the address of a route.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $route   Route name, e.g. `home`, `question` or `tag`.
	 * @param string|int|null      $arg     Route argument.
	 * @param string|null          $section Second argument, e.g. a profile section.
	 * @param array<string, mixed> $query   Further query parameters.
	 * @return string
	 */
	public static function url( string $route, string|int|null $arg = null, ?string $section = null, array $query = array() ): string {
		if ( ! self::pretty() ) {
			$params = 'feed' === $route ? array( 'feed' => Feeds::FEED ) : array();
			$params = $params + array( self::ROUTE_VAR => $route );
			if ( null !== $arg ) {
				$params[ self::ARG_VAR ] = (string) $arg;
			}
			if ( null !== $section ) {
				$params[ self::SECTION_VAR ] = $section;
			}

			return add_query_arg( array_merge( $params, $query ), home_url( '/' ) );
		}

		$path = match ( $route ) {
			'home'     => '',
			'question' => (string) $arg,
			'tag'      => 'tag/' . rawurlencode( (string) $arg ),
			'category' => 'categories/' . implode( '/', array_map( 'rawurlencode', explode( '/', (string) $arg ) ) ),
			'user'     => 'user/' . rawurlencode( (string) $arg ) . ( null === $section ? '' : '/' . $section ),
			'message'  => 'message/' . rawurlencode( (string) $arg ),
			'feed'     => 'feed/' . rawurlencode( (string) $arg ) . ( null === $section ? '' : '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $section ) ) ) ),
			default    => self::SIMPLE[ $route ] ?? '',
		};

		$url = home_url( user_trailingslashit( self::base() . ( '' === $path ? '' : '/' . $path ) ) );

		return array() === $query ? $url : add_query_arg( $query, $url );
	}
}
