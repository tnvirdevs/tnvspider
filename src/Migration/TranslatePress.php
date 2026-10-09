<?php
/**
 * Read-only access to TranslatePress data (plan §13A.4).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Migration;

/**
 * Detects TranslatePress (plugin and data) and reads its settings and
 * dictionary tables. Only SELECT queries run against TranslatePress
 * tables; nothing of TranslatePress is ever written or deleted.
 *
 * Facts checked against the TranslatePress 3.3.7 source: the main plugin
 * file is translatepress-multilingual/index.php and the main class is
 * TRP_Translate_Press; settings live in the trp_settings option
 * (default-language, translation-languages, url-slugs,
 * add-subdirectory-to-default-language); regular strings live in
 * {prefix}trp_dictionary_{default}_{target} (lower-cased locales) with
 * id, original, translated, status (0 none, 1 machine, 2 human),
 * block_type (0 regular, 1 translation block, 2 deprecated), original_id.
 */
final class TranslatePress {

	public const PLUGIN_FILE = 'translatepress-multilingual/index.php';

	public const MAIN_CLASS = 'TRP_Translate_Press';

	public const SETTINGS_OPTION = 'trp_settings';

	public const STATUS_NONE    = 0;
	public const STATUS_MACHINE = 1;
	public const STATUS_HUMAN   = 2;

	public const BLOCK_DEPRECATED = 2;

	/**
	 * Create the reader.
	 *
	 * @param \wpdb $db Database connection.
	 */
	public function __construct( private \wpdb $db ) {
	}

	/**
	 * Whether TranslatePress is active on this site.
	 */
	public static function isActive(): bool {
		$active = get_option( 'active_plugins', array() );

		return ( is_array( $active ) && in_array( self::PLUGIN_FILE, $active, true ) ) || class_exists( self::MAIN_CLASS, false );
	}

	/**
	 * Whether the plugin files are installed (active or not).
	 */
	public static function isInstalled(): bool {
		return is_file( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE );
	}

	/**
	 * Whether there is anything to migrate or to coexist with: the plugin,
	 * its settings or its dictionary tables.
	 */
	public function present(): bool {
		return self::isActive() || self::isInstalled() || null !== $this->settings() || array() !== $this->tableNames();
	}

	/**
	 * TranslatePress language settings, or null when there are none.
	 *
	 * @return array{default: string, languages: list<string>, slugs: array<string, string>, prefix_default: bool}|null
	 */
	public function settings(): ?array {
		$raw = get_option( self::SETTINGS_OPTION );
		if ( ! is_array( $raw ) || ! isset( $raw['default-language'] ) || ! is_string( $raw['default-language'] ) ) {
			return null;
		}
		$languages = array();
		foreach ( (array) ( $raw['translation-languages'] ?? array() ) as $locale ) {
			if ( is_string( $locale ) && '' !== $locale ) {
				$languages[] = $locale;
			}
		}
		$slugs = array();
		foreach ( (array) ( $raw['url-slugs'] ?? array() ) as $locale => $slug ) {
			if ( is_string( $slug ) && '' !== $slug ) {
				$slugs[ (string) $locale ] = $slug;
			}
		}

		return array(
			'default'        => $raw['default-language'],
			'languages'      => array_values( array_unique( $languages ) ),
			'slugs'          => $slugs,
			'prefix_default' => 'yes' === ( $raw['add-subdirectory-to-default-language'] ?? 'no' ),
		);
	}

	/**
	 * Dictionary tables of the configured languages (default → each other
	 * language), with row counts.
	 *
	 * @return list<array{table: string, default: string, target: string, counts: array{total: int, machine: int, manual: int, untranslated: int, deprecated: int}}>
	 */
	public function tables(): array {
		$settings = $this->settings();
		if ( null === $settings ) {
			return array();
		}
		$existing = $this->tableNames();
		$tables   = array();
		foreach ( $settings['languages'] as $target ) {
			if ( $target === $settings['default'] ) {
				continue;
			}
			$name = $this->db->prefix . 'trp_dictionary_' . strtolower( $settings['default'] ) . '_' . strtolower( $target );
			if ( in_array( $name, $existing, true ) ) {
				$tables[] = array(
					'table'   => $name,
					'default' => $settings['default'],
					'target'  => $target,
					'counts'  => $this->counts( $name ),
				);
			}
		}

		return $tables;
	}

	/**
	 * One dictionary table by name, if it is one of tables().
	 *
	 * @param string $name Table name.
	 * @return array{table: string, default: string, target: string, counts: array{total: int, machine: int, manual: int, untranslated: int, deprecated: int}}|null
	 */
	public function table( string $name ): ?array {
		foreach ( $this->tables() as $table ) {
			if ( $table['table'] === $name ) {
				return $table;
			}
		}

		return null;
	}

	/**
	 * Rows of a dictionary table in id order (read-only).
	 *
	 * @param string $table   Table name from tables().
	 * @param int    $afterId Last id read (0 to start).
	 * @param int    $limit   Rows to read.
	 * @return list<array{id: int, original: string, translated: string, status: int, block_type: int}>
	 * @throws \InvalidArgumentException For a table that is not a TranslatePress dictionary.
	 * @throws \RuntimeException On a database error.
	 */
	public function rows( string $table, int $afterId, int $limit ): array {
		if ( null === $this->table( $table ) ) {
			throw new \InvalidArgumentException( 'Not a TranslatePress dictionary table.' );
		}
		$rows = $this->db->get_results(
			$this->db->prepare( 'SELECT id, original, translated, status, block_type FROM %i WHERE id > %d ORDER BY id LIMIT %d', $table, $afterId, max( 1, $limit ) )
		);
		if ( null === $rows || '' !== $this->db->last_error ) {
			throw new \RuntimeException( 'Could not read the TranslatePress table: ' . esc_html( $this->db->last_error ) );
		}

		return array_values(
			array_map(
				static fn( \stdClass $row ): array => array(
					'id'         => (int) $row->id,
					'original'   => (string) $row->original,
					'translated' => (string) ( $row->translated ?? '' ),
					'status'     => (int) $row->status,
					'block_type' => (int) $row->block_type,
				),
				$rows
			)
		);
	}

	/**
	 * Places that use TranslatePress switchers or language shortcodes:
	 * post content, widgets and menu items.
	 *
	 * @return array{posts: list<array{id: int, title: string, type: string, edit: string, found: list<string>}>, widgets: list<array{option: string, found: list<string>}>, menus: list<array{id: int, name: string, edit: string, items: int}>}
	 */
	public function references(): array {
		$shortcodes = array( 'language-switcher', 'trp_language', 'language-include', 'language-exclude' );
		$posts      = array();
		$where      = implode( ' OR ', array_fill( 0, count( $shortcodes ), 'post_content LIKE %s' ) );
		$likes      = array_map( fn( string $code ): string => '%[' . $this->db->esc_like( $code ) . '%', $shortcodes );
		$found      = $this->db->get_results(
			$this->db->prepare(
				"SELECT ID, post_title, post_type, post_content FROM %i WHERE post_status NOT IN ('trash', 'auto-draft', 'inherit') AND post_type NOT IN ('revision', 'nav_menu_item') AND (" . $where . ') ORDER BY ID LIMIT 200', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built above.
				array_merge( array( $this->db->posts ), $likes )
			)
		) ?? array();
		foreach ( $found as $row ) {
			$codes = self::shortcodesIn( (string) $row->post_content, $shortcodes );
			if ( array() !== $codes ) {
				$posts[] = array(
					'id'    => (int) $row->ID,
					'title' => '' === (string) $row->post_title ? '#' . $row->ID : html_entity_decode( (string) $row->post_title, ENT_QUOTES, 'UTF-8' ),
					'type'  => (string) $row->post_type,
					'edit'  => (string) get_edit_post_link( (int) $row->ID, 'raw' ),
					'found' => $codes,
				);
			}
		}

		$widgets = array();
		foreach ( array( 'widget_text', 'widget_custom_html', 'widget_block' ) as $option ) {
			$codes = self::shortcodesIn( (string) wp_json_encode( get_option( $option, array() ) ), $shortcodes );
			if ( array() !== $codes ) {
				$widgets[] = array(
					'option' => $option,
					'found'  => $codes,
				);
			}
		}

		$menus = array();
		$items = $this->db->get_results(
			$this->db->prepare(
				"SELECT p.ID FROM %i p JOIN %i m ON m.post_id = p.ID AND m.meta_key = '_menu_item_object' AND m.meta_value = 'language_switcher' WHERE p.post_type = 'nav_menu_item'",
				$this->db->posts,
				$this->db->postmeta
			)
		) ?? array();
		foreach ( $items as $item ) {
			$terms = wp_get_object_terms( (int) $item->ID, 'nav_menu' );
			foreach ( is_array( $terms ) ? $terms : array() as $menu ) {
				if ( $menu instanceof \WP_Term ) {
					$menus[ $menu->term_id ] = array(
						'id'    => $menu->term_id,
						'name'  => $menu->name,
						'edit'  => admin_url( 'nav-menus.php?action=edit&menu=' . $menu->term_id ),
						'items' => ( $menus[ $menu->term_id ]['items'] ?? 0 ) + 1,
					);
				}
			}
		}

		return array(
			'posts'   => $posts,
			'widgets' => $widgets,
			'menus'   => array_values( $menus ),
		);
	}

	/**
	 * Which of these shortcodes a text uses.
	 *
	 * @param string   $text       Text.
	 * @param string[] $shortcodes Shortcode names.
	 * @phpstan-param list<string> $shortcodes
	 * @return list<string>
	 */
	private static function shortcodesIn( string $text, array $shortcodes ): array {
		$found = array();
		foreach ( $shortcodes as $code ) {
			if ( 1 === preg_match( '/\[' . preg_quote( $code, '/' ) . '[\s\]\/]/', $text ) ) {
				$found[] = $code;
			}
		}

		return $found;
	}

	/**
	 * Names of existing TranslatePress dictionary tables.
	 *
	 * @return list<string>
	 */
	private function tableNames(): array {
		$names = $this->db->get_col( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->db->prefix . 'trp_dictionary_' ) . '%' ) );

		return array_values( array_map( 'strval', $names ) );
	}

	/**
	 * Row counts of a dictionary table.
	 *
	 * @param string $table Table name.
	 * @return array{total: int, machine: int, manual: int, untranslated: int, deprecated: int}
	 */
	private function counts( string $table ): array {
		$row     = $this->db->get_row(
			$this->db->prepare(
				"SELECT COUNT(*) AS total,
				SUM(block_type <> %d AND status = %d AND translated IS NOT NULL AND translated <> '') AS machine,
				SUM(block_type <> %d AND status = %d AND translated IS NOT NULL AND translated <> '') AS manual,
				SUM(block_type = %d) AS deprecated
				FROM %i",
				self::BLOCK_DEPRECATED,
				self::STATUS_MACHINE,
				self::BLOCK_DEPRECATED,
				self::STATUS_HUMAN,
				self::BLOCK_DEPRECATED,
				$table
			)
		);
		$total   = (int) ( $row->total ?? 0 );
		$machine = (int) ( $row->machine ?? 0 );
		$manual  = (int) ( $row->manual ?? 0 );
		$old     = (int) ( $row->deprecated ?? 0 );

		return array(
			'total'        => $total,
			'machine'      => $machine,
			'manual'       => $manual,
			'untranslated' => $total - $machine - $manual - $old,
			'deprecated'   => $old,
		);
	}
}
