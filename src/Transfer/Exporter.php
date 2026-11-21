<?php
/**
 * CSV export of translations (plan §13A.3).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Transfer;

use WST\Languages\Language;
use WST\Storage\StringStore;

/**
 * Streams every string of a filter (all, untranslated, machine, manual or
 * one page) with its translation into the target language, in chunks, so
 * large sites do not need the whole file in memory. Download through
 * admin-post.php with a nonce; needs manage_options.
 */
final class Exporter {

	public const ACTION = 'wst_export';

	public const FILTERS = array( 'all', 'untranslated', 'machine', 'manual', 'page' );

	/** Rows read per query. */
	public const CHUNK = 500;

	/**
	 * Create the exporter.
	 *
	 * @param StringStore $store  Strings.
	 * @param Language    $target Target language.
	 */
	public function __construct( private StringStore $store, private Language $target ) {
	}

	/**
	 * Register the download handler.
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	/**
	 * Send the file (admin-post.php?action=wst_export&filter=…&path=…).
	 *
	 * @throws \RuntimeException When the output stream cannot be opened.
	 */
	public function download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export translations.', 'wp-site-translator' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked above.
		$filter = isset( $_GET['filter'] ) && is_string( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
		$path   = isset( $_GET['path'] ) && is_string( $_GET['path'] ) ? sanitize_text_field( wp_unslash( $_GET['path'] ) ) : '';
		// phpcs:enable
		try {
			$pageKey = $this->pageKey( $filter, $path );
		} catch ( \InvalidArgumentException $e ) {
			wp_die( esc_html( $e->getMessage() ), '', array( 'response' => 400 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->fileName( $filter ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}
		$out = fopen( 'php://output', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams the download.
		if ( false === $out ) {
			throw new \RuntimeException( 'Could not open the output stream.' );
		}
		$this->write( $out, 'page' === $filter ? 'all' : $filter, $pageKey, 'flush' );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streams the download.
		exit;
	}

	/**
	 * Page key for the "one page" filter, null for the others.
	 *
	 * @param string $filter Filter.
	 * @param string $path   Page path for the page filter.
	 * @throws \InvalidArgumentException For an unknown filter or page.
	 */
	public function pageKey( string $filter, string $path ): ?string {
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Messages are escaped where they are printed (wp_die in download()).
		if ( ! in_array( $filter, self::FILTERS, true ) ) {
			throw new \InvalidArgumentException( __( 'Unknown export filter.', 'wp-site-translator' ) );
		}
		if ( 'page' !== $filter ) {
			return null;
		}
		if ( ! str_starts_with( $path, '/' ) || null === $this->store->pageTimes( $path )['last_seen'] ) {
			throw new \InvalidArgumentException( __( 'No strings are recorded for this page. Give its path (for example /about/) or scan it in the translation editor first.', 'wp-site-translator' ) );
		}

		// phpcs:enable

		return StringStore::pageKey( $path );
	}

	/**
	 * Write the CSV file.
	 *
	 * @param resource      $out        Stream.
	 * @param string        $filter     all, untranslated, machine or manual.
	 * @param string|null   $pageKey    Only strings of this page.
	 * @param callable|null $afterChunk Called after each chunk (flushes the download).
	 * @return int Rows written, without the header.
	 */
	public function write( $out, string $filter, ?string $pageKey, ?callable $afterChunk = null ): int {
		fwrite( $out, Csv::BOM ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Stream, not a file.
		Csv::writeRow( $out, array_merge( Csv::COLUMNS, array( Csv::PAGES_COLUMN ) ) );
		$lang    = $this->target->locale();
		$afterId = 0;
		$count   = 0;
		do {
			$rows = $this->store->exportRows( $lang, $filter, $pageKey, $afterId, self::CHUNK );
			$full = count( $rows ) === self::CHUNK;
			foreach ( $rows as $row ) {
				Csv::writeRow(
					$out,
					array(
						$row['original'],
						$row['translated'] ?? '',
						self::statusName( $row['status'] ),
						$row['kind'],
						$lang,
						implode( Csv::PAGES_SEPARATOR, $row['pages'] ),
					)
				);
				$afterId = $row['id'];
				++$count;
			}
			if ( null !== $afterChunk ) {
				$afterChunk();
			}
		} while ( $full );

		return $count;
	}

	/**
	 * Status column value.
	 *
	 * @param int|null $status Stored status.
	 */
	public static function statusName( ?int $status ): string {
		if ( StringStore::STATUS_MANUAL === $status ) {
			return 'manual';
		}

		return StringStore::STATUS_MACHINE === $status ? 'machine' : '';
	}

	/**
	 * Download file name.
	 *
	 * @param string $filter Filter.
	 */
	private function fileName( string $filter ): string {
		return sanitize_file_name( 'translations-' . $this->target->slug() . '-' . $filter . '-' . gmdate( 'Y-m-d' ) . '.csv' );
	}
}
