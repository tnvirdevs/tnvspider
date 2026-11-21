<?php
/**
 * Automatic queueing of strings found on visited pages.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Queue;

use WST\Languages\Language;
use WST\Providers\Selector;
use WST\Settings;

/**
 * Queues untranslated strings of auto-mode pages for the active provider
 * (visitor priority). Without a usable provider nothing is queued, so the
 * page stays cacheable (plan §6A). Never calls a provider.
 */
final class AutoQueue {

	/**
	 * Active provider per target locale for this request ('' = none).
	 *
	 * @var array<string, string>
	 */
	private array $active = array();

	/**
	 * Create the auto-queue.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Selector  $selector  Provider availability.
	 * @param Queue     $queue     Queue.
	 * @param Scheduler $scheduler Cron runner.
	 */
	public function __construct(
		private Settings $settings,
		private Selector $selector,
		private Queue $queue,
		private Scheduler $scheduler
	) {
	}

	/**
	 * Id of the provider that would translate into $target now, or '' when
	 * none can (none selected, paused, out of budget, pair unsupported).
	 *
	 * @param Language $target Target language.
	 */
	public function provider( Language $target ): string {
		$lang = $target->locale();
		if ( ! isset( $this->active[ $lang ] ) ) {
			$active                = $this->selector->active( $this->settings->defaultLanguage(), $target, Usage::period() );
			$this->active[ $lang ] = null === $active ? '' : $active['id'];
		}

		return $this->active[ $lang ];
	}

	/**
	 * Queue strings at visitor priority. Rows already queued keep their state.
	 *
	 * @param int[]    $stringIds String ids.
	 * @phpstan-param list<int> $stringIds
	 * @param Language $target    Target language.
	 * @return bool Whether a provider will translate them.
	 */
	public function queue( array $stringIds, Language $target ): bool {
		$provider = $this->provider( $target );
		if ( '' === $provider ) {
			return false;
		}
		if ( array() !== $stringIds ) {
			$this->queue->enqueue( $stringIds, $target->locale(), $provider, Queue::PRIORITY_VISITOR, time() );
			$this->scheduler->ensure();
		}

		return true;
	}
}
