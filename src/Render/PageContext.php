<?php
/**
 * What the render pipeline needs to know about the current page.
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Render;

/**
 * Snapshot of the request taken when output buffering starts.
 */
final class PageContext {

	/**
	 * Create a context.
	 *
	 * @param string   $path         Site path without language prefix.
	 * @param int|null $postId       Queried post for singular views.
	 * @param bool     $discoverable Whether new strings may be recorded (plan §6A).
	 */
	public function __construct(
		public string $path,
		public ?int $postId,
		public bool $discoverable
	) {
	}
}
