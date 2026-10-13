<?php
/**
 * Database schema.
 *
 * @package easyQ
 */

namespace easyQ\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Defines, installs and removes the custom tables.
 *
 * The schema follows the Question2Answer 1.8.8 data model with these changes:
 *
 * - Users live in the WordPress user tables; user IDs are `bigint(20) unsigned`.
 * - Column names use snake_case.
 * - Meta tables use the layout of the WordPress metadata API.
 * - No foreign keys (WordPress core does not use them); dependent rows are
 *   removed in code.
 * - IP address columns are nullable so they can be purged after the retention
 *   period. The `lastviewip` column is dropped; views are counted without IPs.
 * - Q2A's `NOTE` post type and the `users`, `userlogins`, `userprofile`,
 *   `usermetas`, `options`, `blobs`, `pages`, `widgets` and `cache` tables are
 *   replaced by WordPress features.
 *
 * Definitions are written in the format dbDelta() expects: one column per line,
 * lowercase types, named keys and two spaces after `PRIMARY KEY`.
 */
final class Schema {

	/**
	 * Prefix of every easyQ table, appended to the WordPress table prefix.
	 *
	 * @var string
	 */
	public const TABLE_PREFIX = 'easyq_';

	/**
	 * Meta types registered with the WordPress metadata API.
	 *
	 * Each type `easyq_x` uses the table `easyq_xmeta` with the object ID column `easyq_x_id`.
	 *
	 * @var string[]
	 */
	public const META_TYPES = array( 'easyq_post', 'easyq_category', 'easyq_tag' );

	/**
	 * Returns the full name of an easyQ table for the current site.
	 *
	 * @param string $name Table name without prefixes, e.g. `posts`.
	 * @return string
	 */
	public static function table( string $name ): string {
		$wpdb = Db::wpdb();

		return $wpdb->prefix . self::TABLE_PREFIX . $name;
	}

	/**
	 * Returns the full names of all easyQ tables for a table prefix.
	 *
	 * @param string $prefix WordPress table prefix of a site.
	 * @return string[]
	 */
	public static function table_names( string $prefix ): array {
		return array_keys( self::definitions( $prefix, '' ) );
	}

	/**
	 * Returns the CREATE TABLE statements of all tables.
	 *
	 * @param string $prefix          WordPress table prefix of the site.
	 * @param string $charset_collate Charset and collation clause, see wpdb::get_charset_collate().
	 * @return array<string, string> Statements keyed by full table name.
	 */
	public static function definitions( string $prefix, string $charset_collate ): array {
		$columns = array(
			'posts'         => "id bigint(20) unsigned NOT NULL auto_increment,
type varchar(20) NOT NULL default '',
parent_id bigint(20) unsigned default NULL,
category_id bigint(20) unsigned default NULL,
category_path_1 bigint(20) unsigned default NULL,
category_path_2 bigint(20) unsigned default NULL,
category_path_3 bigint(20) unsigned default NULL,
answer_count smallint(5) unsigned NOT NULL default '0',
answer_max_votes smallint(5) unsigned NOT NULL default '0',
selected_child_id bigint(20) unsigned default NULL,
closed_by_id bigint(20) unsigned default NULL,
user_id bigint(20) unsigned default NULL,
cookie_id bigint(20) unsigned default NULL,
created_ip varbinary(16) default NULL,
last_user_id bigint(20) unsigned default NULL,
last_ip varbinary(16) default NULL,
upvotes smallint(5) unsigned NOT NULL default '0',
downvotes smallint(5) unsigned NOT NULL default '0',
net_votes smallint(6) NOT NULL default '0',
views int(10) unsigned NOT NULL default '0',
hotness float default NULL,
flag_count tinyint(3) unsigned NOT NULL default '0',
format varchar(20) NOT NULL default '',
created datetime NOT NULL,
updated datetime default NULL,
update_type char(1) default NULL,
title text,
content longtext,
tags text,
author_name varchar(40) default NULL,
notify varchar(100) default NULL,
PRIMARY KEY  (id),
KEY type_created (type,created),
KEY type_answer_count (type,answer_count,created),
KEY type_net_votes (type,net_votes,created),
KEY type_views (type,views,created),
KEY type_hotness (type,hotness),
KEY type_answer_max_votes (type,answer_max_votes,created),
KEY parent_id (parent_id,type),
KEY user_id (user_id,type,created),
KEY selected_child_id (selected_child_id,type,created),
KEY closed_by_id (closed_by_id),
KEY category_path_1 (category_path_1,type,created),
KEY category_path_2 (category_path_2,type,created),
KEY category_path_3 (category_path_3,type,created),
KEY category_id (category_id,type,created),
KEY created_ip (created_ip,created),
KEY updated (updated,type),
KEY flag_count (flag_count,created,type),
KEY category_path_1_updated (category_path_1,updated,type),
KEY category_path_2_updated (category_path_2,updated,type),
KEY category_path_3_updated (category_path_3,updated,type),
KEY category_id_updated (category_id,updated,type),
KEY last_user_id (last_user_id,updated,type),
KEY last_ip (last_ip,updated,type)",

			'categories'    => "id bigint(20) unsigned NOT NULL auto_increment,
parent_id bigint(20) unsigned default NULL,
title varchar(80) NOT NULL default '',
slug varchar(200) NOT NULL default '',
description varchar(800) NOT NULL default '',
question_count int(10) unsigned NOT NULL default '0',
position smallint(5) unsigned NOT NULL default '0',
backpath varchar(804) NOT NULL default '',
PRIMARY KEY  (id),
UNIQUE KEY parent_slug (parent_id,slug(191)),
KEY parent_position (parent_id,position),
KEY backpath (backpath(191))",

			'words'         => "id bigint(20) unsigned NOT NULL auto_increment,
word varchar(80) NOT NULL default '',
title_count int(10) unsigned NOT NULL default '0',
content_count int(10) unsigned NOT NULL default '0',
tagword_count int(10) unsigned NOT NULL default '0',
tag_count int(10) unsigned NOT NULL default '0',
PRIMARY KEY  (id),
KEY word (word),
KEY tag_count (tag_count)",

			'titlewords'    => 'post_id bigint(20) unsigned NOT NULL,
word_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (post_id,word_id),
KEY word_id (word_id)',

			'contentwords'  => "post_id bigint(20) unsigned NOT NULL,
word_id bigint(20) unsigned NOT NULL,
count tinyint(3) unsigned NOT NULL default '0',
type char(1) NOT NULL default '',
question_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (post_id,word_id),
KEY word_id (word_id)",

			'tagwords'      => 'post_id bigint(20) unsigned NOT NULL,
word_id bigint(20) unsigned NOT NULL,
PRIMARY KEY  (post_id,word_id),
KEY word_id (word_id)',

			'posttags'      => 'post_id bigint(20) unsigned NOT NULL,
word_id bigint(20) unsigned NOT NULL,
post_created datetime NOT NULL,
PRIMARY KEY  (post_id,word_id),
KEY word_id (word_id,post_created)',

			'uservotes'     => "post_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
vote tinyint(4) NOT NULL default '0',
flag tinyint(4) NOT NULL default '0',
vote_created datetime default NULL,
vote_updated datetime default NULL,
PRIMARY KEY  (user_id,post_id),
KEY post_id (post_id),
KEY voted (vote_created,vote_updated)",

			'userpoints'    => "user_id bigint(20) unsigned NOT NULL,
points int(11) NOT NULL default '0',
questions mediumint(9) NOT NULL default '0',
answers mediumint(9) NOT NULL default '0',
comments mediumint(9) NOT NULL default '0',
answers_selected_by_user mediumint(9) NOT NULL default '0',
answers_selected mediumint(9) NOT NULL default '0',
question_upvotes mediumint(9) NOT NULL default '0',
question_downvotes mediumint(9) NOT NULL default '0',
answer_upvotes mediumint(9) NOT NULL default '0',
answer_downvotes mediumint(9) NOT NULL default '0',
comment_upvotes mediumint(9) NOT NULL default '0',
comment_downvotes mediumint(9) NOT NULL default '0',
question_vote_points int(11) NOT NULL default '0',
answer_vote_points int(11) NOT NULL default '0',
comment_vote_points int(11) NOT NULL default '0',
upvotes_received int(11) NOT NULL default '0',
downvotes_received int(11) NOT NULL default '0',
bonus int(11) NOT NULL default '0',
PRIMARY KEY  (user_id),
KEY points (points)",

			'userlevels'    => "user_id bigint(20) unsigned NOT NULL,
entity_type char(1) NOT NULL default '',
entity_id bigint(20) unsigned NOT NULL,
level tinyint(3) unsigned default NULL,
PRIMARY KEY  (user_id,entity_type,entity_id),
KEY entity (entity_type,entity_id)",

			'userfields'    => "id bigint(20) unsigned NOT NULL auto_increment,
name varchar(40) NOT NULL default '',
label varchar(40) default NULL,
position smallint(5) unsigned NOT NULL default '0',
flags tinyint(3) unsigned NOT NULL default '0',
permit tinyint(3) unsigned default NULL,
PRIMARY KEY  (id),
KEY position (position)",

			'userfavorites' => "user_id bigint(20) unsigned NOT NULL,
entity_type char(1) NOT NULL default '',
entity_id bigint(20) unsigned NOT NULL,
no_user_events tinyint(3) unsigned NOT NULL default '0',
PRIMARY KEY  (user_id,entity_type,entity_id),
KEY user_events (user_id,no_user_events),
KEY entity (entity_type,entity_id,no_user_events)",

			'usernotices'   => "id bigint(20) unsigned NOT NULL auto_increment,
user_id bigint(20) unsigned NOT NULL,
content text NOT NULL,
format varchar(20) NOT NULL default '',
tags varchar(200) default NULL,
created datetime NOT NULL,
PRIMARY KEY  (id),
KEY user_created (user_id,created)",

			'userevents'    => "id bigint(20) unsigned NOT NULL auto_increment,
user_id bigint(20) unsigned NOT NULL,
entity_type char(1) NOT NULL default '',
entity_id bigint(20) unsigned NOT NULL default '0',
question_id bigint(20) unsigned NOT NULL,
last_post_id bigint(20) unsigned NOT NULL,
update_type char(1) default NULL,
last_user_id bigint(20) unsigned default NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
KEY user_updated (user_id,updated),
KEY question_user (question_id,user_id)",

			'sharedevents'  => "id bigint(20) unsigned NOT NULL auto_increment,
entity_type char(1) NOT NULL default '',
entity_id bigint(20) unsigned NOT NULL,
question_id bigint(20) unsigned NOT NULL,
last_post_id bigint(20) unsigned NOT NULL,
update_type char(1) default NULL,
last_user_id bigint(20) unsigned default NULL,
updated datetime NOT NULL,
PRIMARY KEY  (id),
KEY entity_updated (entity_type,entity_id,updated),
KEY question_entity (question_id,entity_type,entity_id)",

			'messages'      => "id bigint(20) unsigned NOT NULL auto_increment,
type varchar(20) NOT NULL default 'private',
from_user_id bigint(20) unsigned default NULL,
to_user_id bigint(20) unsigned default NULL,
from_hidden tinyint(1) unsigned NOT NULL default '0',
to_hidden tinyint(1) unsigned NOT NULL default '0',
content text NOT NULL,
format varchar(20) NOT NULL default '',
created datetime NOT NULL,
PRIMARY KEY  (id),
KEY type_users (type,from_user_id,to_user_id,created),
KEY to_user (to_user_id,type,created),
KEY from_hidden (from_hidden),
KEY to_hidden (to_hidden)",

			'userlimits'    => "user_id bigint(20) unsigned NOT NULL,
action char(1) NOT NULL default '',
period int(10) unsigned NOT NULL default '0',
count smallint(5) unsigned NOT NULL default '0',
PRIMARY KEY  (user_id,action)",

			'iplimits'      => "ip varbinary(16) NOT NULL,
action char(1) NOT NULL default '',
period int(10) unsigned NOT NULL default '0',
count smallint(5) unsigned NOT NULL default '0',
PRIMARY KEY  (ip,action)",

			'cookies'       => 'id bigint(20) unsigned NOT NULL,
created datetime NOT NULL,
created_ip varbinary(16) default NULL,
written datetime default NULL,
written_ip varbinary(16) default NULL,
PRIMARY KEY  (id)',
		);

		foreach ( self::META_TYPES as $meta_type ) {
			$object_column                               = $meta_type . '_id';
			$columns[ substr( $meta_type, 6 ) . 'meta' ] = "meta_id bigint(20) unsigned NOT NULL auto_increment,
$object_column bigint(20) unsigned NOT NULL default '0',
meta_key varchar(255) default NULL,
meta_value longtext,
PRIMARY KEY  (meta_id),
KEY $object_column ($object_column),
KEY meta_key (meta_key(191))";
		}

		$definitions = array();
		foreach ( $columns as $name => $body ) {
			$table                 = $prefix . self::TABLE_PREFIX . $name;
			$definitions[ $table ] = trim( "CREATE TABLE $table (\n$body\n) $charset_collate" ) . ';';
		}

		return $definitions;
	}

	/**
	 * Creates or updates all tables of the current site.
	 *
	 * @return string[] Messages describing the changes dbDelta() made.
	 */
	public static function install(): array {
		$wpdb = Db::wpdb();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( array_values( self::definitions( $wpdb->prefix, $wpdb->get_charset_collate() ) ) );
	}

	/**
	 * Lists the changes dbDelta() would make to the tables of the current site.
	 *
	 * An empty result means the installed schema matches the definitions.
	 *
	 * @return string[]
	 */
	public static function pending_changes(): array {
		$wpdb = Db::wpdb();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		return dbDelta( array_values( self::definitions( $wpdb->prefix, $wpdb->get_charset_collate() ) ), false );
	}

	/**
	 * Drops all tables of the current site.
	 */
	public static function drop(): void {
		$wpdb = Db::wpdb();

		foreach ( self::table_names( $wpdb->prefix ) as $table ) {
			$sql = $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table );
			if ( null !== $sql ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; removing the plugin's own tables is a schema change by design.
				$wpdb->query( $sql );
			}
		}
	}

	/**
	 * Registers the meta tables on the wpdb object so the metadata API finds them.
	 *
	 * Must run again whenever the current site changes on multisite.
	 */
	public static function register_meta_tables(): void {
		$wpdb = Db::wpdb();

		foreach ( self::META_TYPES as $meta_type ) {
			$property          = $meta_type . 'meta';
			$wpdb->{$property} = $wpdb->prefix . $property;
		}
	}
}
