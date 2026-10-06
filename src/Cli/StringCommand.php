<?php
/**
 * WP-CLI: wp wst string set|get|list.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Cli;

use WST\Html\InlineMarkup;
use WST\Html\Segment;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Manage original strings and their manual translations.
 */
final class StringCommand {

	private const KINDS = array( Segment::TEXT, Segment::INLINE, Segment::ATTR, Segment::TITLE, Segment::META );

	/**
	 * Create the command.
	 *
	 * @param StringStore $store    String storage.
	 * @param Settings    $settings Plugin settings.
	 */
	public function __construct(
		private StringStore $store,
		private Settings $settings
	) {
	}

	/**
	 * Save a manual translation. Manual translations are never overwritten
	 * by machine translation.
	 *
	 * ## OPTIONS
	 *
	 * <original>
	 * : Original text as it appears on the page. For mixed content, the inner HTML of the element.
	 *
	 * <translation>
	 * : The translation. Inline HTML must keep exactly the original's tags and attributes.
	 *
	 * [--lang=<locale>]
	 * : Target locale. Defaults to the configured target language.
	 *
	 * [--kind=<kind>]
	 * : String kind. "auto" picks inline when the original contains tags, else text.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - text
	 *   - inline
	 *   - attr
	 *   - title
	 *   - meta
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wst string set "Shop now" "এখনই কিনুন"
	 *     wp wst string set 'Read <a href="/story/">our story</a>' '<a href="/story/">আমাদের গল্প</a> পড়ুন'
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Named arguments.
	 * @phpstan-param list<string>  $args
	 */
	public function set( array $args, array $assoc ): void {
		[ $original, $translation ] = $args;
		$lang                       = $this->lang( $assoc );
		$kind                       = $assoc['kind'] ?? 'auto';
		if ( 'auto' === $kind ) {
			$kind = array() === InlineMarkup::signature( $original ) ? Segment::TEXT : Segment::INLINE;
		}
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			\WP_CLI::error( 'Unknown kind: ' . $kind );
		}

		try {
			$id = $this->store->saveManual( $original, $kind, $lang, $translation, 0 );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}

		\WP_CLI::success( sprintf( 'Saved manual %s translation for string #%d (%s).', $kind, $id, $lang ) );
	}

	/**
	 * Print the translation of one string.
	 *
	 * ## OPTIONS
	 *
	 * <original>
	 * : Original text.
	 *
	 * [--lang=<locale>]
	 * : Target locale. Defaults to the configured target language.
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Named arguments.
	 * @phpstan-param list<string>  $args
	 */
	public function get( array $args, array $assoc ): void {
		$lang = $this->lang( $assoc );
		$row  = $this->store->find( $args[0], $lang );
		if ( null === $row ) {
			\WP_CLI::error( 'Unknown string.' );
		}
		if ( null === $row['translated'] ) {
			\WP_CLI::error( sprintf( 'String #%d has no %s translation.', $row['id'], $lang ) );
		}

		\WP_CLI::line( $row['translated'] );
	}

	/**
	 * List strings with their translations.
	 *
	 * ## OPTIONS
	 *
	 * [--lang=<locale>]
	 * : Target locale. Defaults to the configured target language.
	 *
	 * [--status=<status>]
	 * : Only strings with this status.
	 * ---
	 * options:
	 *   - machine
	 *   - manual
	 *   - untranslated
	 * ---
	 *
	 * [--search=<text>]
	 * : Only originals containing this text.
	 *
	 * [--limit=<number>]
	 * : Maximum rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param string[]              $args  Positional arguments.
	 * @param array<string, string> $assoc Named arguments.
	 * @phpstan-param list<string>  $args
	 */
	public function list( array $args, array $assoc ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames -- WP-CLI subcommand name.
		unset( $args );
		$rows = $this->store->search( $this->lang( $assoc ), $assoc['status'] ?? null, $assoc['search'] ?? '', (int) ( $assoc['limit'] ?? 50 ) );

		$items = array_map(
			static fn( array $row ): array => array(
				'id'         => $row['id'],
				'kind'       => $row['kind'],
				'status'     => StringStore::STATUS_MANUAL === $row['status'] ? 'manual' : ( StringStore::STATUS_MACHINE === $row['status'] ? 'machine' : 'untranslated' ),
				'original'   => $row['original'],
				'translated' => $row['translated'] ?? '',
			),
			$rows
		);
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $items, array( 'id', 'kind', 'status', 'original', 'translated' ) );
	}

	/**
	 * Locale from --lang, else the configured target.
	 *
	 * @param array<string, string> $assoc Named arguments.
	 */
	private function lang( array $assoc ): string {
		if ( isset( $assoc['lang'] ) && '' !== $assoc['lang'] ) {
			return $assoc['lang'];
		}
		$target = $this->settings->targetLanguage();
		if ( null === $target ) {
			\WP_CLI::error( 'No target language is configured; pass --lang.' );
		}

		return $target->locale();
	}
}
