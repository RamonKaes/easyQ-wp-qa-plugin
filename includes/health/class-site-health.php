<?php
/**
 * Site Health tests and information.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Health;

use EasyQ\Admin\Settings_Screen;
use EasyQ\Database\Installer;
use EasyQ\Database\Schema;
use EasyQ\Frontend\Routes;
use EasyQ\Services;
use EasyQ\Settings\Fields;
use EasyQ\Settings\Options;
use EasyQ\Stats\Counts;
use EasyQ\Stats\Site_Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the tests of easyQ to Tools → Site Health and a section to its Info tab.
 *
 * Core already reports missed and late scheduled events, those of easyQ included, so
 * there is no test of its own for them.
 *
 * @since 0.1.0
 */
final class Site_Health {

	/**
	 * Action of the request that creates or updates the tables, see repair_tables().
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REPAIR_ACTION = 'easyq_repair_tables';

	/**
	 * Settings shown on the Info tab, by section.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string[]>
	 */
	private const SETTINGS = array(
		'general' => array( 'read_only', 'erase_posts' ),
		'users'   => array( 'show_register_terms' ),
		'spam'    => array( 'ip_retention_days', 'ip_anonymize' ),
	);

	/**
	 * Hooks into Site Health. Its weekly check runs the tests in a scheduled event, so they are
	 * added outside the admin too.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_information' ) );
		add_action( 'admin_post_' . self::REPAIR_ACTION, array( $this, 'repair_tables' ) );
	}

	/**
	 * Creates or updates the tables of the current site, as the action of the database test
	 * offers it, and returns to Site Health.
	 *
	 * @since 0.1.0
	 */
	public function repair_tables(): void {
		check_admin_referer( self::REPAIR_ACTION );
		// Who may activate plugins, which installs the tables as well.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'easyq' ), 403 );
		}

		Installer::install_site();

		wp_safe_redirect( admin_url( 'site-health.php' ) );
		exit;
	}

	/**
	 * Adds the tests of easyQ.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tests Tests by type, `direct` and `async`.
	 * @return mixed
	 */
	public function add_tests( mixed $tests ): mixed {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$direct                     = is_array( $tests['direct'] ?? null ) ? $tests['direct'] : array();
		$direct['easyq_database']   = array(
			'label' => __( 'Q&A database tables', 'easyq' ),
			'test'  => array( $this, 'test_database' ),
		);
		$direct['easyq_permalinks'] = array(
			'label' => __( 'Q&A addresses', 'easyq' ),
			'test'  => array( $this, 'test_permalinks' ),
		);
		$direct['easyq_read_only']  = array(
			'label' => __( 'Q&A read-only mode', 'easyq' ),
			'test'  => array( $this, 'test_read_only' ),
		);
		$tests['direct']            = $direct;

		return $tests;
	}

	/**
	 * Tests whether the tables of easyQ exist and match the version of the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Test result.
	 */
	public function test_database(): array {
		$result = self::result(
			'easyq_database',
			__( 'The Q&A database tables are up to date', 'easyq' ),
			esc_html__( 'easyQ keeps questions, answers, votes and points in tables of its own. They match this version of the plugin.', 'easyq' )
		);

		$changes = Schema::pending_changes();
		if ( Installer::installed_version() >= Installer::DB_VERSION && array() === $changes ) {
			return $result;
		}

		// The dry run of dbDelta() reports what it would do; the list says what is missing instead.
		$list = '';
		foreach ( $changes as $change ) {
			$list .= '<li>' . esc_html( self::describe_change( $change ) ) . '</li>';
		}

		$result['status']      = 'critical';
		$result['label']       = __( 'The Q&A database tables are missing or out of date', 'easyq' );
		$result['description'] = sprintf(
			'<p>%s</p>',
			esc_html__( 'Some tables of easyQ are missing or differ from what this version of the plugin expects, so questions and answers may fail to show or to save.', 'easyq' )
		) . ( '' === $list ? '' : '<ul>' . $list . '</ul>' );
		// The tables of this site, also on a network, where activating the plugin for the network
		// installs the current site only and every other on its next request.
		$result['actions'] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::REPAIR_ACTION ), self::REPAIR_ACTION ) ),
			esc_html__( 'Create or update the tables of easyQ for this site', 'easyq' )
		);

		return $result;
	}

	/**
	 * Tests whether the Q&A pages have readable addresses, which needs pretty permalinks.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Test result.
	 */
	public function test_permalinks(): array {
		$address = '<code>' . esc_html( Routes::url( 'home' ) ) . '</code>';
		$result  = self::result(
			'easyq_permalinks',
			__( 'Q&A pages have readable addresses', 'easyq' ),
			/* translators: %s: address of the Q&A start page. */
			sprintf( esc_html__( 'The Q&A pages start at %s.', 'easyq' ), $address )
		);

		if ( Routes::pretty() ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'Q&A pages have addresses with query parameters', 'easyq' );
		$result['description'] = sprintf(
			'<p>%s</p>',
			/* translators: %s: address of the Q&A start page. */
			sprintf( esc_html__( 'With plain permalinks the Q&A pages work, but their addresses, such as %s, are harder to read and to share. Another permalink structure gives questions addresses like those of posts.', 'easyq' ), $address )
		);
		$result['actions'] = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Change the permalink structure', 'easyq' )
		);

		return $result;
	}

	/**
	 * Tests whether the Q&A area is in read-only mode, which is meant for maintenance only.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Test result.
	 */
	public function test_read_only(): array {
		$result = self::result(
			'easyq_read_only',
			__( 'The Q&A area accepts new posts', 'easyq' ),
			esc_html__( 'Users can ask, answer, comment and vote as their levels allow.', 'easyq' )
		);

		if ( ! Options::get_bool( 'general', 'read_only' ) ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'The Q&A area is in read-only mode', 'easyq' );
		$result['description'] = sprintf(
			'<p>%s</p>',
			esc_html__( 'Only users who manage easyQ can post, vote or change anything. Turn the read-only mode off once the maintenance is done.', 'easyq' )
		);
		$result['actions']     = sprintf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url(
				add_query_arg(
					array(
						'page' => Settings_Screen::PAGE,
						'tab'  => 'general',
					),
					admin_url( 'admin.php' )
				) . '#easyq-general-read-only'
			),
			esc_html__( 'Review the read-only mode', 'easyq' )
		);

		return $result;
	}

	/**
	 * Adds a section about easyQ to the Info tab.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $info Sections of the Info tab.
	 * @return mixed
	 */
	public function add_information( mixed $info ): mixed {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$size     = Site_Stats::tables_size();
		$provider = Services::search()->provider();
		$fields   = array(
			'version'           => array(
				'label' => __( 'Version', 'easyq' ),
				'value' => \EasyQ\VERSION,
			),
			'database_version'  => array(
				'label' => __( 'Database version', 'easyq' ),
				/* translators: 1: installed version of the tables, 2: version the plugin expects. */
				'value' => sprintf( __( '%1$d (expected: %2$d)', 'easyq' ), Installer::installed_version(), Installer::DB_VERSION ),
				'debug' => Installer::installed_version() . ' (' . Installer::DB_VERSION . ')',
			),
			'tables_size'       => array(
				'label' => __( 'Size of the Q&A tables', 'easyq' ),
				'value' => (string) size_format( $size, 1 ),
				'debug' => $size,
			),
			'questions'         => array(
				'label' => __( 'Questions', 'easyq' ),
				'value' => Counts::get( Counts::QUESTIONS ),
			),
			'answers'           => array(
				'label' => __( 'Answers', 'easyq' ),
				'value' => Counts::get( Counts::ANSWERS ),
			),
			'comments'          => array(
				'label' => __( 'Comments', 'easyq' ),
				'value' => Counts::get( Counts::COMMENTS ),
			),
			'users_with_points' => array(
				'label' => __( 'Users with points', 'easyq' ),
				'value' => Counts::get( Counts::USERS_WITH_POINTS ),
			),
			'address'           => array(
				'label'   => __( 'Q&A address', 'easyq' ),
				'value'   => Routes::url( 'home' ),
				// As core marks `home_url` and `site_url`: not copied with the site information.
				'private' => true,
			),
			'search'            => array(
				'label' => __( 'Search', 'easyq' ),
				'value' => $provider->label(),
				'debug' => $provider->id(),
			),
		);

		foreach ( self::SETTINGS as $section => $keys ) {
			$definitions = Fields::section( $section );
			foreach ( $keys as $key ) {
				$fields[ $key ] = self::setting( $definitions[ $key ]['label'] ?? $key, $definitions[ $key ]['choices'] ?? array(), Options::get( $section, $key ) );
			}
		}

		$info['easyq'] = array(
			'label'  => __( 'easyQ Questions and Answers', 'easyq' ),
			'fields' => $fields,
		);

		return $info;
	}

	/**
	 * Returns a test result that passed, in the form of the tests of core.
	 *
	 * @since 0.1.0
	 *
	 * @param string $test        Test name.
	 * @param string $label       Label of the result.
	 * @param string $description Description, HTML.
	 * @return array<string, mixed>
	 */
	private static function result( string $test, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Q&A', 'easyq' ),
				'color' => 'blue',
			),
			'description' => '<p>' . $description . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}

	/**
	 * Returns the field of a setting for the Info tab.
	 *
	 * @since 0.1.0
	 *
	 * @param string                    $label   Label of the setting.
	 * @param array<int|string, string> $choices Labels of the values, for settings with choices.
	 * @param mixed                     $value   Stored value.
	 * @return array{label: string, value: string, debug: string}
	 */
	private static function setting( string $label, array $choices, mixed $value ): array {
		$debug = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_scalar( $value ) ? (string) $value : '' );

		return array(
			'label' => $label,
			'value' => match ( true ) {
				is_bool( $value )                                   => $value ? __( 'Yes', 'easyq' ) : __( 'No', 'easyq' ),
				is_string( $value ) && isset( $choices[ $value ] ) => $choices[ $value ],
				default                                             => $debug,
			},
			'debug' => $debug,
		);
	}

	/**
	 * Says what a change of the dry run of `dbDelta()` means: what is missing or differs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $change A message of `dbDelta()`, such as "Created table wp_easyq_posts".
	 * @return string
	 */
	private static function describe_change( string $change ): string {
		$patterns = array(
			/* translators: %s: name of a database table. */
			'/^Created table (.+)$/'                     => __( 'Missing table: %s', 'easyq' ),
			/* translators: %s: column of a database table. */
			'/^Added column (.+)$/'                      => __( 'Missing column: %s', 'easyq' ),
			/* translators: %s: index of a database table. */
			'/^Added index (.+)$/'                       => __( 'Missing index: %s', 'easyq' ),
			/* translators: %s: column of a database table. */
			'/^Changed type of (.+?) from .*$/'          => __( 'Column of another type: %s', 'easyq' ),
			/* translators: %s: column of a database table. */
			'/^Changed default value of (.+?) from .*$/' => __( 'Column with another default value: %s', 'easyq' ),
		);
		foreach ( $patterns as $pattern => $text ) {
			if ( 1 === preg_match( $pattern, $change, $match ) ) {
				return sprintf( $text, $match[1] );
			}
		}

		return $change;
	}
}
