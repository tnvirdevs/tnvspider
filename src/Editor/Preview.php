<?php
/**
 * Safe preview markup for the editor frame (plan §11).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Editor;

use WST\Config;
use WST\Html\Lexer;

/**
 * Without "Run page scripts", every executable script and every inline
 * event handler (onclick=…) is removed, so no other plugin's JavaScript runs
 * in the frame; data scripts (JSON, JSON-LD, templates) stay because they
 * never execute. Our preview script is always added: it marks translated
 * text and reports clicks to the editor. Bytes outside the removed parts
 * are kept as they are.
 */
final class Preview {

	/** Script types that execute (an empty type means JavaScript). */
	private const EXECUTABLE_TYPE = '/^$|javascript|ecmascript|module|importmap|jsx|babel/i';

	/**
	 * Create the filter.
	 *
	 * @param string $scriptUrl URL of assets/preview.js.
	 */
	public function __construct( private string $scriptUrl ) {
	}

	/**
	 * Prepare a translated page for the frame.
	 *
	 * @param string $html        Document.
	 * @param bool   $keepScripts Whether the page's own scripts may run.
	 */
	public function prepare( string $html, bool $keepScripts ): string {
		if ( ! $keepScripts ) {
			$html = self::withoutHandlers( self::withoutScripts( $html ) );
		}
		// The page is already rendered (output buffer), so the tag is added directly.
		$tag = sprintf( '<script src="%s" id="wst-preview-js" data-wst-no-translate></script>', esc_url( add_query_arg( 'ver', Config::VERSION, $this->scriptUrl ) ) ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Added to the buffered preview after wp_footer ran.
		$end = strripos( $html, '</body' );

		return false === $end ? $html . $tag : substr( $html, 0, $end ) . $tag . substr( $html, $end );
	}

	/**
	 * Remove executable script elements, contents included.
	 *
	 * @param string $html Document.
	 */
	public static function withoutScripts( string $html ): string {
		$lexer = new Lexer( $html );
		$cuts  = array();
		while ( $lexer->next_token() ) {
			if ( '#tag' !== $lexer->get_token_type() || 'SCRIPT' !== $lexer->get_tag() || $lexer->is_tag_closer() ) {
				continue;
			}
			$type = $lexer->get_attribute( 'type' );
			if ( 1 === preg_match( self::EXECUTABLE_TYPE, is_string( $type ) ? trim( $type ) : '' ) ) {
				$cuts[] = $lexer->tokenSpan();
			}
		}
		foreach ( array_reverse( $cuts ) as [ $start, $length ] ) {
			$html = substr_replace( $html, '', $start, $length );
		}

		return $html;
	}

	/**
	 * Remove inline event handler attributes (onclick, onload, …).
	 *
	 * @param string $html Document.
	 */
	public static function withoutHandlers( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			foreach ( $processor->get_attribute_names_with_prefix( 'on' ) ?? array() as $name ) {
				$processor->remove_attribute( $name );
			}
		}

		return $processor->get_updated_html();
	}
}
