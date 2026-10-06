<?php
/**
 * Front-end render pipeline (plan §6, §6A).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Render;

use WST\Html\Extractor;
use WST\Html\InlineMarkup;
use WST\Html\Replacer;
use WST\Html\Segment;
use WST\Languages\Current;
use WST\Languages\Language;
use WST\Log\Logger;
use WST\Routing\Urls;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Buffers target-language HTML responses and replaces translatable strings
 * with stored translations. Never calls a provider; on any error the
 * original HTML is returned untouched.
 */
final class Pipeline {

	/** Pending strings older than this no longer make a page uncacheable. */
	public const PENDING_MAX_AGE = DAY_IN_SECONDS;

	/**
	 * Context captured when buffering started.
	 *
	 * @var PageContext|null
	 */
	private ?PageContext $context = null;

	/**
	 * Strings of the last processed page still queued for automatic translation.
	 *
	 * @var int
	 */
	private int $pending = 0;

	/**
	 * Create the pipeline.
	 *
	 * @param Settings      $settings Plugin settings.
	 * @param Language      $target   Target language.
	 * @param StringStore   $store    String storage.
	 * @param DiscoveryGate $gate     Discovery rules.
	 * @param Logger        $logger   Plugin log.
	 * @param Urls          $urls     URL helper.
	 */
	public function __construct(
		private Settings $settings,
		private Language $target,
		private StringStore $store,
		private DiscoveryGate $gate,
		private Logger $logger,
		private Urls $urls
	) {
	}

	/**
	 * Register the hooks.
	 */
	public function boot(): void {
		add_action( 'template_redirect', array( $this, 'start' ), 1 );
	}

	/**
	 * Start buffering when this is a target-language front-end HTML page.
	 */
	public function start(): void {
		if ( ! Current::isTarget() || is_feed() || is_robots() || is_trackback() || '' !== (string) get_query_var( 'sitemap' ) ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only parsed.
		$path = $this->urls->unprefixedPath( $uri, $this->target->slug() ) ?? '/';

		$this->context = new PageContext(
			$path,
			is_singular() ? (int) get_queried_object_id() : null,
			$this->gate->allowsRequest( $path )
		);
		ob_start( array( $this, 'finish' ) );
	}

	/**
	 * Output buffer callback: translate the page, send cache headers.
	 *
	 * @param string $html  Buffered output.
	 * @param int    $phase PHP output buffer phase flags.
	 * @return string
	 * @throws \Throwable Re-thrown under WP_DEBUG after logging.
	 */
	public function finish( $html, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		unset( $phase );
		if ( ! is_string( $html ) || '' === $html || null === $this->context || ! $this->isHtmlResponse() ) {
			return $html;
		}
		if ( 200 !== http_response_code() ) {
			$this->context->discoverable = false;
		}

		try {
			$translated = $this->process( $html, $this->context );
		} catch ( \Throwable $e ) {
			$this->logFailure( $e );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				throw $e;
			}

			return $html;
		}

		if ( $this->pending > 0 ) {
			$this->markUncacheable();
		}

		return $translated;
	}

	/**
	 * Translate one document.
	 *
	 * @param string      $html    Document.
	 * @param PageContext $context Page context.
	 */
	public function process( string $html, PageContext $context ): string {
		$this->pending = 0;
		$extractor     = new Extractor();
		$segments      = $extractor->extract( $html );

		$kinds = array();
		foreach ( $segments as $segment ) {
			$kinds[ $segment->text ] = $kinds[ $segment->text ] ?? $segment->kind;
		}
		$lang  = $this->target->locale();
		$found = $this->store->lookup( array_map( 'strval', array_keys( $kinds ) ), $lang );

		$translations = array();
		$untranslated = array();
		foreach ( $found as $text => $entry ) {
			if ( null === $entry['translated'] ) {
				$untranslated[] = $entry['id'];
				continue;
			}
			$translation = $this->checkedTranslation( $text, $kinds[ $text ], $entry['translated'] );
			if ( null !== $translation ) {
				$translations[ $text ] = $translation;
			}
		}

		if ( $context->discoverable ) {
			$untranslated = array_merge( $untranslated, array_values( $this->discover( array_diff_key( $kinds, $found ), $context ) ) );
		}
		$this->pending = $this->store->pendingCount( $untranslated, $lang, self::PENDING_MAX_AGE );

		$html = ( new Replacer() )->apply( $html, $segments, $translations, $this->linkEdits( $extractor->links() ) );

		return $this->setDocumentLanguage( $html );
	}

	/**
	 * Strings of the last processed page still queued for automatic translation.
	 */
	public function pending(): int {
		return $this->pending;
	}

	/**
	 * Record unknown strings within the caps.
	 *
	 * @param array<string, string> $unknown Normalised original => kind.
	 * @param PageContext           $context Page context.
	 * @return array<string, int> Original => string id.
	 */
	private function discover( array $unknown, PageContext $context ): array {
		$maxLength = $this->settings->number( 'max_string_length' );
		$unknown   = array_filter( $unknown, static fn( $kind, $text ): bool => mb_strlen( (string) $text ) <= $maxLength, ARRAY_FILTER_USE_BOTH );
		if ( array() === $unknown ) {
			return array();
		}
		$allowed = $this->gate->take( $context->path, count( $unknown ) );

		return $this->store->recordOnPage( array_slice( $unknown, 0, $allowed, true ), $context->path, $context->postId );
	}

	/**
	 * A stored translation ready for the page, or null when it must not be used.
	 * Inline translations must keep the original markup; their links are
	 * prefixed here because the Replacer skips link edits inside them.
	 *
	 * @param string $original    Normalised original.
	 * @param string $kind        Segment kind on this page.
	 * @param string $translation Stored translation.
	 */
	private function checkedTranslation( string $original, string $kind, string $translation ): ?string {
		if ( Segment::INLINE !== $kind ) {
			return $translation;
		}
		if ( ! InlineMarkup::sameStructure( $original, $translation ) ) {
			$this->logger->warning( 'render', 'Inline translation does not match the original markup; original shown.', array( 'original' => $original ) );

			return null;
		}
		if ( ! $this->settings->flag( 'force_language_links' ) ) {
			return $translation;
		}

		$processor = new \WP_HTML_Tag_Processor( $translation );
		while ( $processor->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) && null === $processor->get_attribute( 'hreflang' ) ) {
				$processor->set_attribute( 'href', $this->urls->addPrefix( $href, $this->target->slug() ) );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Attribute edits that keep internal links in the target language.
	 *
	 * @param list<array{0: int, 1: int, 2: string, 3: string}> $links Links from the Extractor.
	 * @return list<array{0: int, 1: int, 2: string, 3: string}>
	 */
	private function linkEdits( array $links ): array {
		if ( ! $this->settings->flag( 'force_language_links' ) ) {
			return array();
		}
		$edits = array();
		foreach ( $links as [ $start, $length, $attribute, $value ] ) {
			$prefixed = $this->urls->addPrefix( $value, $this->target->slug() );
			if ( $prefixed !== $value ) {
				$edits[] = array( $start, $length, $attribute, $prefixed );
			}
		}

		return $edits;
	}

	/**
	 * Make the html element's lang and dir match the target language, for
	 * themes that do not print language_attributes().
	 *
	 * @param string $html Document.
	 */
	private function setDocumentLanguage( string $html ): string {
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( array( 'tag_name' => 'HTML' ) ) ) {
			return $html;
		}
		$lang = $this->target->tag( $this->settings->flag( 'hreflang_drop_region' ) );
		if ( $lang === $processor->get_attribute( 'lang' ) && $this->target->dir() === $processor->get_attribute( 'dir' ) ) {
			return $html;
		}
		$processor->set_attribute( 'lang', $lang );
		$processor->set_attribute( 'dir', $this->target->dir() );

		return $processor->get_updated_html();
	}

	/**
	 * Whether the response being sent is HTML (or has no content type yet).
	 */
	private function isHtmlResponse(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}

		return true;
	}

	/**
	 * Keep page caches from storing a page that is still being translated.
	 */
	private function markUncacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page-cache plugins' shared contract.
		}
		if ( headers_sent() ) {
			$this->logger->warning( 'render', 'Headers already sent; could not mark a page with pending translations as uncacheable.' );

			return;
		}
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'X-WST-Pending: ' . $this->pending );
	}

	/**
	 * Log a pipeline failure; if the log itself fails, use the PHP error log.
	 *
	 * @param \Throwable $e Failure.
	 */
	private function logFailure( \Throwable $e ): void {
		$context = array(
			'exception' => get_class( $e ),
			'file'      => $e->getFile() . ':' . $e->getLine(),
		);
		try {
			$this->logger->error( 'render', $e->getMessage(), $context );
		} catch ( \Throwable $logError ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last resort when the plugin log is unavailable.
			error_log( 'WP Site Translator render failure: ' . $e->getMessage() . ' (log unavailable: ' . $logError->getMessage() . ')' );
		}
	}
}
