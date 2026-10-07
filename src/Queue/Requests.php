<?php
/**
 * Explicit machine-translation requests (plan §8 priorities, §9 table).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Providers\Selector;
use WST\Settings;
use WST\Storage\StringStore;

/**
 * Queues strings a user asked to translate, whatever the page mode (except
 * "off", which is not translated at all): the editor's strings at priority
 * 1, "translate this page now" at priority 2. Manual translations are never
 * sent. Never calls a provider itself.
 */
final class Requests {

	/**
	 * Create the service.
	 *
	 * @param Settings    $settings  Settings.
	 * @param StringStore $store     Strings.
	 * @param Queue       $queue     Queue.
	 * @param Selector    $selector  Provider availability.
	 * @param Scheduler   $scheduler Cron runner.
	 * @param Resolver    $modes     Page modes.
	 */
	public function __construct(
		private Settings $settings,
		private StringStore $store,
		private Queue $queue,
		private Selector $selector,
		private Scheduler $scheduler,
		private Resolver $modes
	) {
	}

	/**
	 * Queue chosen strings (editor), skipping manual translations.
	 *
	 * @param int[]    $stringIds String ids.
	 * @phpstan-param list<int> $stringIds
	 * @param Language $target    Target language.
	 * @param string   $pageMode  Mode of the editor's page, '' when unknown.
	 * @return array{queued: int, skipped: int, provider: string}
	 * @throws RequestRefused When no provider can translate now, or machine translation is off for manual pages.
	 */
	public function strings( array $stringIds, Language $target, string $pageMode = '' ): array {
		$this->refuseOnManual( $pageMode );
		$ids     = $this->store->existingIds( array_values( array_unique( $stringIds ) ) );
		$send    = array_values( array_diff( $ids, $this->store->manualIds( $ids, $target->locale() ) ) );
		$skipped = count( $stringIds ) - count( $send );

		return array(
			'queued'   => count( $send ),
			'skipped'  => $skipped,
			'provider' => $this->enqueue( $send, $target, Queue::PRIORITY_EDITOR ),
		);
	}

	/**
	 * Queue every untranslated string of a post ("translate this page now").
	 * Works on manual pages; refused on "off" pages.
	 *
	 * @param int      $postId Post id.
	 * @param Language $target Target language.
	 * @return array{queued: int, skipped: int, provider: string}
	 * @throws RequestRefused When the page is off, was never seen, or no provider can translate now.
	 */
	public function post( int $postId, Language $target ): array {
		$mode = $this->modes->resolvePost( $postId )['mode'];
		if ( Settings::MODE_OFF === $mode ) {
			throw new RequestRefused( 'This page is set to "off": it is not translated.' );
		}
		$this->refuseOnManual( $mode );
		if ( 0 === $this->store->postCoverage( $postId, $target->locale() )['total'] ) {
			throw new RequestRefused( 'No strings are known for this page yet. Visit or scan its translated version first.' );
		}
		$ids = $this->store->untranslatedOnPost( $postId, $target->locale() );

		return array(
			'queued'   => count( $ids ),
			'skipped'  => 0,
			'provider' => $this->enqueue( $ids, $target, Queue::PRIORITY_PAGE_NOW ),
		);
	}

	/**
	 * Refuse explicit machine translation on manual pages when the owner
	 * turned "Allow machine translation in the editor on manual pages" off.
	 *
	 * @param string $mode Page mode, '' when unknown.
	 * @throws RequestRefused When refused.
	 */
	private function refuseOnManual( string $mode ): void {
		if ( Settings::MODE_MANUAL === $mode && ! $this->settings->flag( 'editor_mt_on_manual' ) ) {
			throw new RequestRefused( 'Machine translation is turned off for manual pages (Translator → Translation).' );
		}
	}

	/**
	 * Queue ids for the active provider.
	 *
	 * @param int[]    $ids      String ids.
	 * @phpstan-param list<int> $ids
	 * @param Language $target   Target language.
	 * @param int      $priority Queue priority.
	 * @return string Provider id.
	 * @throws RequestRefused When no provider can translate now.
	 */
	private function enqueue( array $ids, Language $target, int $priority ): string {
		$period = Usage::period();
		$active = $this->selector->active( $this->settings->defaultLanguage(), $target, $period );
		if ( null === $active ) {
			$primary = $this->settings->provider();
			throw new RequestRefused( esc_html( '' === $primary ? 'No translation provider is selected.' : 'No provider can translate now: ' . $this->selector->problem( $primary, $this->settings->defaultLanguage(), $target, $period ) ) );
		}
		if ( array() !== $ids ) {
			$this->queue->enqueue( $ids, $target->locale(), $active['id'], $priority, time() );
			$this->scheduler->ensure();
		}

		return $active['id'];
	}
}
