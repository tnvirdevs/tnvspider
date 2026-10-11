<?php
/**
 * Custom tables (plan §4), created and upgraded with dbDelta.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Database;

use WST\Config;

/**
 * Owns the plugin's table definitions and the stored schema version.
 */
final class Schema {

	/** Bump when a table definition changes; maybeUpgrade() then re-runs dbDelta. */
	public const VERSION = 1;

	/** Option holding the installed schema version. */
	public const VERSION_OPTION = Config::PREFIX . 'db_version';

	/** Table names without the WordPress prefix. */
	public const TABLES = array( 'strings', 'translations', 'occurrences', 'pages', 'queue', 'usage', 'log' );

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Create a schema manager.
	 *
	 * @param \wpdb $db WordPress database connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Full table name, e.g. wp_wst_strings.
	 *
	 * @param string $table One of self::TABLES.
	 * @throws \InvalidArgumentException For an unknown table.
	 */
	public function table( string $table ): string {
		if ( ! in_array( $table, self::TABLES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown table: ' . esc_html( $table ) );
		}

		return $this->db->prefix . Config::PREFIX . $table;
	}

	/**
	 * Create or update every table and record the schema version.
	 *
	 * WordPress's dbDelta() reports no errors and always runs on the global connection,
	 * so success is checked afterwards by describing every table.
	 *
	 * @return array<string, string> Changes reported by dbDelta (empty when already current).
	 * @throws \RuntimeException When a table is missing after dbDelta.
	 */
	public function install(): array {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = dbDelta( $this->definitions() );

		foreach ( self::TABLES as $table ) {
			$columns = $this->db->get_results( $this->db->prepare( 'DESCRIBE %i', $this->table( $table ) ) );
			if ( array() === $columns ) {
				throw new \RuntimeException( 'Schema install failed for ' . esc_html( $this->table( $table ) ) . ': ' . esc_html( $wpdb->last_error ) );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, true );

		return $changes;
	}

	/**
	 * Drop every plugin table and the schema version (uninstall only).
	 *
	 * @throws \RuntimeException When a table cannot be dropped.
	 */
	public function drop(): void {
		foreach ( self::TABLES as $table ) {
			$sql = $this->db->prepare( 'DROP TABLE IF EXISTS %i', $this->table( $table ) );
			if ( null === $sql || false === $this->db->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Prepared above; uninstall removes our own tables.
				throw new \RuntimeException( 'Could not drop ' . esc_html( $this->table( $table ) ) . ': ' . esc_html( $this->db->last_error ) );
			}
		}
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Install when the stored version is older than self::VERSION.
	 *
	 * @return bool Whether install() ran.
	 */
	public function maybeUpgrade(): bool {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return false;
		}
		$this->install();

		return true;
	}

	/**
	 * CREATE TABLE statements in the exact form dbDelta expects.
	 *
	 * @return list<string>
	 */
	private function definitions(): array {
		$collate = $this->db->get_charset_collate();

		return array(
			"CREATE TABLE {$this->table( 'strings' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
hash char(32) NOT NULL,
kind varchar(24) NOT NULL,
original longtext NOT NULL,
char_count int(10) unsigned NOT NULL,
is_global tinyint(1) NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY hash (hash)
) {$collate};",
			"CREATE TABLE {$this->table( 'translations' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
string_id bigint(20) unsigned NOT NULL,
lang varchar(12) NOT NULL,
translated longtext NOT NULL,
status tinyint(4) NOT NULL,
provider varchar(24) DEFAULT NULL,
flags smallint(5) unsigned NOT NULL DEFAULT 0,
updated_by bigint(20) unsigned DEFAULT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY string_lang (string_id,lang)
) {$collate};",
			"CREATE TABLE {$this->table( 'occurrences' )} (
string_id bigint(20) unsigned NOT NULL,
page_key char(32) NOT NULL,
post_id bigint(20) unsigned DEFAULT NULL,
last_seen datetime NOT NULL,
PRIMARY KEY  (string_id,page_key),
KEY page_key (page_key),
KEY post_id (post_id)
) {$collate};",
			"CREATE TABLE {$this->table( 'pages' )} (
page_key char(32) NOT NULL,
path varchar(255) NOT NULL,
post_id bigint(20) unsigned DEFAULT NULL,
last_seen datetime NOT NULL,
last_scan datetime DEFAULT NULL,
PRIMARY KEY  (page_key),
KEY post_id (post_id)
) {$collate};",
			"CREATE TABLE {$this->table( 'queue' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
string_id bigint(20) unsigned NOT NULL,
lang varchar(12) NOT NULL,
provider varchar(24) NOT NULL,
priority tinyint(4) NOT NULL DEFAULT 5,
state varchar(12) NOT NULL,
attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
next_attempt_at datetime NOT NULL,
locked_until datetime DEFAULT NULL,
last_error text DEFAULT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY string_lang (string_id,lang),
KEY pick (state,next_attempt_at,priority)
) {$collate};",
			"CREATE TABLE {$this->table( 'usage' )} (
provider varchar(24) NOT NULL,
period char(7) NOT NULL,
chars bigint(20) unsigned NOT NULL DEFAULT 0,
requests bigint(20) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (provider,period)
) {$collate};",
			"CREATE TABLE {$this->table( 'log' )} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
level varchar(8) NOT NULL,
source varchar(24) NOT NULL,
message text NOT NULL,
context longtext DEFAULT NULL,
PRIMARY KEY  (id)
) {$collate};",
		);
	}
}
