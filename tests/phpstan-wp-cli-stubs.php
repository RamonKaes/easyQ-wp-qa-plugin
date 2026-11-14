<?php
/**
 * Declarations of the WP-CLI API that easyQ uses, for static analysis only.
 *
 * Scanned by PHPStan (`scanFiles`), never loaded. The signatures follow WP-CLI 2.12:
 * `php/class-wp-cli.php`, `php/utils.php`, `php/utils-wp.php` and `php-cli-tools`.
 *
 * @package easyQ
 */

// phpcs:disable

namespace {
	class WP_CLI {
		/**
		 * @param string                 $name
		 * @param callable|object|string $callable
		 * @param array<string, mixed>   $args
		 */
		public static function add_command( $name, $callable, $args = array() ): bool {}

		/**
		 * @param string $message
		 */
		public static function log( $message ): void {}

		/**
		 * @param string $message
		 */
		public static function success( $message ): void {}

		/**
		 * @param string|\WP_Error|\Throwable $message
		 * @param bool|int                    $exit
		 * @return ($exit is false ? null : never)
		 */
		public static function error( $message, $exit = true ) {}

		/**
		 * Exits unless the answer is yes or `--yes` was given.
		 *
		 * @param string                    $question
		 * @param array<string, bool|string> $assoc_args
		 */
		public static function confirm( $question, $assoc_args = array() ): void {}
	}
}

namespace WP_CLI {
	/**
	 * Takes every call through __call(); the progress bar calls are named for analysis.
	 *
	 * @method void tick( int $increment = 1, ?string $msg = null )
	 * @method void finish()
	 */
	class NoOp {
		/**
		 * @param string       $method
		 * @param array<mixed> $args
		 */
		public function __call( $method, $args ): void {}
	}
}

namespace WP_CLI\Utils {
	/**
	 * @param array<string, bool|string> $assoc_args
	 * @param string                     $flag
	 * @param mixed                      $default
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {}

	/**
	 * @param string $message
	 * @param int    $count
	 * @param int    $interval
	 * @return \cli\progress\Bar|\WP_CLI\NoOp
	 */
	function make_progress_bar( $message, $count, $interval = 100 ) {}

	function wp_clear_object_cache(): void {}
}

namespace cli\progress {
	class Bar {
		/**
		 * @param int         $increment
		 * @param string|null $msg
		 */
		public function tick( $increment = 1, $msg = null ): void {}

		public function finish(): void {}
	}
}
