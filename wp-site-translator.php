<?php
/**
 * Plugin Name:       WP Site Translator
 * Description:       Translate the whole site between two languages with queued machine translation and a conflict-proof editor.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.0
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-site-translator
 * Domain Path:       /languages
 *
 * The header above cannot read PHP constants; keep its name, version and text
 * domain in sync with WST\Config.
 *
 * @package WST
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';

\WST\Autoloader::register( __DIR__ . '/src' );
\WST\Plugin::boot( __FILE__ );
