<?php
/**
 * Built-in search engine.
 *
 * @package easyQ
 * @since 0.1.0
 */

namespace EasyQ\Search;

use EasyQ\Database\DB;
use EasyQ\Database\Row;
use EasyQ\Database\Schema;
use EasyQ\Util\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Word index search on the plugin's own tables.
 *
 * Titles, content, tag words and whole tags are indexed per post. A search
 * scores every question by the rarity of the matched words, weighted by where
 * they matched, plus a bonus for hot questions.
 *
 * Ported from Question2Answer `qa-include/plugins/qa-search-basic.php` and the
 * search query in `qa-include/db/selects.php`.
 *
 * @since 0.1.0
 */
final class Basic_Search implements Search_Provider {

	/**
	 * Words that appear in this many posts or more are ignored.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const IGNORED_WORDS_FREQUENCY = 10000;

	/**
	 * Longest indexed word in characters; longer words are truncated.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_WORD_LENGTH = 80;

	/**
	 * Marks where the union of match queries goes in the search query.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UNION_MARKER = '/*easyq-union*/';

	/**
	 * Highest stored count of a word within one post.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_WORD_COUNT = 255;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function id(): string {
		return 'basic';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	public function label(): string {
		return __( 'Built-in search', 'easyq' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Indexed_Post $post Post data to index.
	 */
	public function index_post( Indexed_Post $post ): void {
		$title_words   = array_unique( $this->words( (string) $post->title ) );
		$content_count = array_count_values( $this->words( $post->text ) );
		$tag_words     = array_unique( $this->words( (string) $post->tags ) );
		$whole_tags    = array_unique( array_map( array( $this, 'truncate' ), Text::string_to_tags( $post->tags ) ) );

		$word_ids = $this->word_ids( array_merge( $title_words, array_map( 'strval', array_keys( $content_count ) ), $tag_words, $whole_tags ), create: true );

		$title_ids = $this->pick( $word_ids, $title_words );
		$tag_ids   = $this->pick( $word_ids, $tag_words );
		$whole_ids = $this->pick( $word_ids, $whole_tags );

		$content_ids = array();
		foreach ( $content_count as $word => $count ) {
			if ( isset( $word_ids[ (string) $word ] ) ) {
				$content_ids[ $word_ids[ (string) $word ] ] = min( $count, self::MAX_WORD_COUNT );
			}
		}

		$wpdb = DB::wpdb();

		foreach ( $title_ids as $word_id ) {
			DB::query( $wpdb->prepare( 'INSERT IGNORE INTO %i (post_id, word_id) VALUES (%d, %d)', Schema::table( 'titlewords' ), $post->post_id, $word_id ) );
		}

		foreach ( $content_ids as $word_id => $count ) {
			DB::query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (post_id, word_id, count, type, question_id) VALUES (%d, %d, %d, %s, %d)',
					Schema::table( 'contentwords' ),
					$post->post_id,
					$word_id,
					$count,
					substr( $post->type, 0, 1 ),
					$post->question_id
				)
			);
		}

		foreach ( $tag_ids as $word_id ) {
			DB::query( $wpdb->prepare( 'INSERT IGNORE INTO %i (post_id, word_id) VALUES (%d, %d)', Schema::table( 'tagwords' ), $post->post_id, $word_id ) );
		}

		foreach ( $whole_ids as $word_id ) {
			DB::query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (post_id, word_id, post_created) SELECT id, %d, created FROM %i WHERE id = %d',
					Schema::table( 'posttags' ),
					$word_id,
					Schema::table( 'posts' ),
					$post->post_id
				)
			);
		}

		$this->recount( 'titlewords', 'title_count', $title_ids );
		$this->recount( 'contentwords', 'content_count', array_keys( $content_ids ) );
		$this->recount( 'tagwords', 'tagword_count', $tag_ids );
		$this->recount( 'posttags', 'tag_count', $whole_ids );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function unindex_post( int $post_id ): void {
		$wpdb    = DB::wpdb();
		$indexes = array(
			'titlewords'   => 'title_count',
			'contentwords' => 'content_count',
			'tagwords'     => 'tagword_count',
			'posttags'     => 'tag_count',
		);

		foreach ( $indexes as $index => $count_column ) {
			$table    = Schema::table( $index );
			$word_ids = DB::get_int_col( $wpdb->prepare( 'SELECT word_id FROM %i WHERE post_id = %d', $table, $post_id ) );

			if ( array() !== $word_ids ) {
				DB::query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', $table, $post_id ) );
				$this->recount( $index, $count_column, $word_ids );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * The built-in search ignores categories.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id     Post ID.
	 * @param int|null $category_id New category ID.
	 */
	public function move_post( int $post_id, ?int $category_id ): void {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string   $query   Search query as entered by the user.
	 * @param int      $start   Offset of the first result.
	 * @param int      $count   Maximum number of results.
	 * @param int|null $user_id User searching, or null for visitors.
	 * @return Search_Result[]
	 */
	public function search( string $query, int $start, int $count, ?int $user_id ): array {
		$wpdb        = DB::wpdb();
		$words       = array_values( array_unique( $this->words( $query ) ) );
		$posts       = Schema::table( 'posts' );
		$words_table = Schema::table( 'words' );
		$parts       = array();

		if ( array() !== $words ) {
			// Title matches; duplicate words in a title count once.
			$parts[] = $wpdb->prepare(
				"(SELECT t.post_id AS question_id, LOG(%d / w.title_count) AS score, 'Q' AS match_type, t.post_id AS match_post_id FROM %i AS t JOIN %i AS w ON t.word_id = w.id WHERE w.word IN (" . implode( ',', array_fill( 0, count( $words ), '%s' ) ) . ') AND w.title_count < %d)',
				array_merge( array( self::IGNORED_WORDS_FREQUENCY, Schema::table( 'titlewords' ), $words_table ), $words, array( self::IGNORED_WORDS_FREQUENCY ) )
			);

			// Content matches: repeated words add less and less weight; answers count half, comments and notes a quarter.
			$parts[] = $wpdb->prepare(
				"(SELECT c.question_id, (1 - 1 / (1 + c.count)) * LOG(%d / w.content_count) * (CASE c.type WHEN 'Q' THEN 1.0 WHEN 'A' THEN 0.5 ELSE 0.25 END) AS score, c.type AS match_type, c.post_id AS match_post_id FROM %i AS c JOIN %i AS w ON c.word_id = w.id WHERE w.word IN (" . implode( ',', array_fill( 0, count( $words ), '%s' ) ) . ') AND w.content_count < %d)',
				array_merge( array( self::IGNORED_WORDS_FREQUENCY, Schema::table( 'contentwords' ), $words_table ), $words, array( self::IGNORED_WORDS_FREQUENCY ) )
			);

			// Tag word matches count like two title matches, since tags express intent.
			$parts[] = $wpdb->prepare(
				"(SELECT t.post_id AS question_id, 2 * LOG(%d / w.tagword_count) AS score, 'Q' AS match_type, t.post_id AS match_post_id FROM %i AS t JOIN %i AS w ON t.word_id = w.id WHERE w.word IN (" . implode( ',', array_fill( 0, count( $words ), '%s' ) ) . ') AND w.tagword_count < %d)',
				array_merge( array( self::IGNORED_WORDS_FREQUENCY, Schema::table( 'tagwords' ), $words_table ), $words, array( self::IGNORED_WORDS_FREQUENCY ) )
			);
		}

		// Questions by users whose public name is in the query, or is the whole query.
		$author_ids = $this->author_ids( $words, trim( $query ) );
		if ( array() !== $author_ids ) {
			$parts[] = $wpdb->prepare(
				"(SELECT p.id AS question_id, LOG(%d / GREATEST(u.questions, 1)) AS score, 'Q' AS match_type, p.id AS match_post_id FROM %i AS p JOIN %i AS u ON p.user_id = u.user_id WHERE p.user_id IN (" . implode( ',', array_fill( 0, count( $author_ids ), '%d' ) ) . ") AND p.type = 'Q')",
				array_merge( array( self::IGNORED_WORDS_FREQUENCY, $posts, Schema::table( 'userpoints' ) ), $author_ids )
			);
		}

		$parts = array_filter( $parts, 'is_string' );
		if ( array() === $parts ) {
			return array();
		}

		// The hottest question gets a bonus equal to a unique tag match, the least hot none.
		$hotness = DB::get_row( $wpdb->prepare( "SELECT MIN(hotness) AS min_hotness, MAX(hotness) AS max_hotness FROM %i WHERE type = 'Q'", $posts ) );
		$min     = null === $hotness ? 0.0 : (float) Row::nullable_float( $hotness, 'min_hotness' );
		$range   = ( null === $hotness ? 0.0 : (float) Row::nullable_float( $hotness, 'max_hotness' ) ) - $min;

		$union = implode( ' UNION ALL ', $parts );
		// The union consists of prepared statements; it is inserted after preparing the outer query.
		$sql  = $wpdb->prepare(
			"SELECT x.question_id, SUM(x.score) + IF(%f > 0, 2 * LOG(%d) * (COALESCE(MAX(p.hotness), %f) - %f) / %f, 0) + LOG(x.question_id) / 1000000 AS score, GROUP_CONCAT(CONCAT_WS(':', x.match_type, x.match_post_id, ROUND(x.score, 3))) AS match_parts FROM (/*easyq-union*/) AS x JOIN %i AS p ON p.id = x.question_id WHERE p.type = 'Q' GROUP BY x.question_id ORDER BY score DESC LIMIT %d, %d",
			$range,
			self::IGNORED_WORDS_FREQUENCY,
			$min,
			$min,
			$range > 0 ? $range : 1,
			$posts,
			max( 0, $start ),
			max( 0, $count )
		);
		$rows = null === $sql ? array() : DB::get_results( str_replace( self::UNION_MARKER, $union, $sql ) );

		$results = array();
		foreach ( $rows as $row ) {
			$results[] = $this->to_result( $row );
		}

		return $results;
	}

	/**
	 * Splits text into lowercase index words of limited length.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function words( string $text ): array {
		return array_map( array( $this, 'truncate' ), Text::to_words( $text ) );
	}

	/**
	 * Truncates a word to the longest length the index stores.
	 *
	 * @since 0.1.0
	 *
	 * @param string $word Word.
	 * @return string
	 */
	private function truncate( string $word ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $word, 0, self::MAX_WORD_LENGTH, 'UTF-8' ) : substr( $word, 0, self::MAX_WORD_LENGTH );
	}

	/**
	 * Maps words to their IDs in the words table.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $words  Words.
	 * @param bool     $create Whether to add missing words.
	 * @return array<string, int> IDs keyed by word.
	 */
	public function word_ids( array $words, bool $create ): array {
		$words = array_values( array_unique( array_filter( $words, static fn ( string $word ): bool => '' !== $word ) ) );
		if ( array() === $words ) {
			return array();
		}

		$wpdb  = DB::wpdb();
		$table = Schema::table( 'words' );

		if ( $create ) {
			// The unique key on the word column makes concurrent inserts safe.
			DB::query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One (%s) group per word.
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (word) VALUES ' . implode( ',', array_fill( 0, count( $words ), '(%s)' ) ),
					array_merge( array( $table ), $words )
				)
			);
		}

		$rows = DB::get_results(
			$wpdb->prepare(
				'SELECT id, word FROM %i WHERE word IN (' . implode( ',', array_fill( 0, count( $words ), '%s' ) ) . ')',
				array_merge( array( $table ), $words )
			)
		);

		$ids = array();
		foreach ( $rows as $row ) {
			$ids[ Row::string( $row, 'word' ) ] = Row::int( $row, 'id' );
		}

		return $ids;
	}

	/**
	 * Picks the IDs of some words.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $word_ids IDs keyed by word.
	 * @param string[]           $words    Words to pick.
	 * @return int[]
	 */
	private function pick( array $word_ids, array $words ): array {
		return array_values( array_intersect_key( $word_ids, array_flip( $words ) ) );
	}

	/**
	 * Recalculates how many posts use each word in an index.
	 *
	 * @since 0.1.0
	 *
	 * @param string $index        Index table name without prefix.
	 * @param string $count_column Count column in the words table.
	 * @param int[]  $word_ids     Words to recount.
	 */
	private function recount( string $index, string $count_column, array $word_ids ): void {
		if ( array() === $word_ids ) {
			return;
		}

		$wpdb = DB::wpdb();

		DB::query(
			$wpdb->prepare(
				'UPDATE %i AS x, (SELECT w.id, COUNT(i.word_id) AS total FROM %i AS w LEFT JOIN %i AS i ON i.word_id = w.id WHERE w.id IN (' . implode( ',', array_fill( 0, count( $word_ids ), '%d' ) ) . ') GROUP BY w.id) AS a SET x.%i = a.total WHERE x.id = a.id',
				array_merge( array( Schema::table( 'words' ), Schema::table( 'words' ), Schema::table( $index ) ), $word_ids, array( $count_column ) )
			)
		);
	}

	/**
	 * Finds users whose public name matches query words or the whole query.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $words Query words.
	 * @param string   $query Whole query, for names with spaces.
	 * @return int[] User IDs.
	 */
	private function author_ids( array $words, string $query ): array {
		$names = array_values( array_unique( array_filter( array_merge( $words, array( $query ) ) ) ) );
		if ( array() === $names ) {
			return array();
		}

		$wpdb = DB::wpdb();

		return DB::get_int_col(
			$wpdb->prepare(
				'SELECT ID FROM %i WHERE display_name IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ') OR user_nicename IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')',
				array_merge( array( $wpdb->users ), $names, $names )
			)
		);
	}

	/**
	 * Converts a result row and picks the best matching part.
	 *
	 * @since 0.1.0
	 *
	 * @param object $row Result row with question_id, score and match_parts.
	 * @return Search_Result
	 */
	private function to_result( object $row ): Search_Result {
		$question_id = Row::int( $row, 'question_id' );
		$best_type   = 'Q';
		$best_id     = $question_id;
		$best_score  = null;

		foreach ( explode( ',', Row::string( $row, 'match_parts' ) ) as $part ) {
			$pieces = explode( ':', $part );
			if ( 3 !== count( $pieces ) ) {
				continue;
			}

			if ( null === $best_score || (float) $pieces[2] > $best_score ) {
				$best_score = (float) $pieces[2];
				$best_type  = $pieces[0];
				$best_id    = (int) $pieces[1];
			}
		}

		// The content index stores one character per type; close notes are stored as N.
		$best_type = 'N' === $best_type ? 'NOTE' : $best_type;

		return new Search_Result( $question_id, (float) Row::nullable_float( $row, 'score' ), $best_type, $best_id );
	}
}
