<?php
/**
 * Language switcher: shortcode, block, menu item and floating (plan §12).
 *
 * @package WST
 */

declare(strict_types=1);

namespace WST\Switcher;

use WST\Assets;
use WST\Config;
use WST\Languages\Current;
use WST\Languages\Language;
use WST\Modes\Resolver;
use WST\Routing\LanguageUrls;
use WST\Settings;

/**
 * Plain links to the same page in each language; on an "off" page the other
 * language's link goes to its home page. No JavaScript, styled with CSS
 * variables. The markup is excluded from translation, and every link
 * carries hreflang so link rewriting leaves it alone.
 */
final class Switcher {

	public const SHORTCODE    = Config::PREFIX . 'switcher';
	public const BLOCK        = 'wst/switcher';
	public const STYLE_HANDLE = 'wst-switcher';
	public const BLOCK_SCRIPT = 'wst-switcher-block';

	/** URL of the menu item that stands for the switcher. */
	public const MENU_URL = '#wst-switcher';

	/**
	 * Create the switcher.
	 *
	 * @param Settings     $settings        Settings.
	 * @param Language     $defaultLanguage Default language.
	 * @param Language     $target          Target language.
	 * @param LanguageUrls $urls            Per-language URLs.
	 * @param Resolver     $modes           Page modes.
	 * @param string       $pluginFile      Main plugin file (for asset URLs).
	 */
	public function __construct(
		private Settings $settings,
		private Language $defaultLanguage,
		private Language $target,
		private LanguageUrls $urls,
		private Resolver $modes,
		private string $pluginFile
	) {
	}

	/**
	 * Register the shortcode, block, menu integration, floating switcher and styles.
	 */
	public function boot(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'init', array( $this, 'registerBlock' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueStyle' ) );
		add_filter( 'wp_nav_menu_objects', array( $this, 'expandMenuItems' ) );
		add_filter( 'nav_menu_link_attributes', array( $this, 'menuLinkAttributes' ), 10, 2 );
		add_action( 'admin_head-nav-menus.php', array( $this, 'addMenuMetaBox' ) );
		if ( $this->settings->flag( 'switcher_floating' ) ) {
			add_action( 'wp_footer', array( $this, 'printFloating' ) );
		}
	}

	/**
	 * Both languages' links for the current request.
	 *
	 * @return list<array{language: Language, url: string, current: bool}>
	 */
	public function links(): array {
		$urls = $this->urls->forCurrentRequest( true );
		if ( null === $urls ) {
			return array();
		}
		if ( $this->modes->currentIsOff() ) {
			// No counterpart: the other language's link goes to its home page.
			$homes = $this->urls->forUri( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) . '/', false );
			$urls  = Current::isTarget()
				? array(
					'default' => $homes['default'] ?? $urls['default'],
					'target'  => $urls['target'],
				)
				: array(
					'default' => $urls['default'],
					'target'  => $homes['target'] ?? $urls['target'],
				);
		}
		$current = Current::isTarget() ? $this->target : $this->defaultLanguage;

		return array(
			array(
				'language' => $this->defaultLanguage,
				'url'      => $urls['default'],
				'current'  => $current->locale() === $this->defaultLanguage->locale(),
			),
			array(
				'language' => $this->target,
				'url'      => $urls['target'],
				'current'  => $current->locale() === $this->target->locale(),
			),
		);
	}

	/**
	 * Visible label of a language for the configured name and switcher styles.
	 *
	 * @param Language $language Language.
	 */
	public function label( Language $language ): string {
		$name = 'english' === $this->settings->choice( 'name_style' ) ? $language->englishName() : $language->nativeName();
		$code = strtoupper( $language->tag( true ) );
		switch ( $this->settings->choice( 'switcher_style' ) ) {
			case 'codes':
				return $code;
			case 'codes_names':
				return $code . ' · ' . $name;
		}

		return $name;
	}

	/**
	 * Switcher markup for the current request (shortcode and block).
	 *
	 * @param mixed $attributes Shortcode or block attributes (unused).
	 */
	public function render( $attributes = array() ): string {
		unset( $attributes );

		return $this->markup( 'inline' );
	}

	/**
	 * Switcher markup.
	 *
	 * @param string $context "inline" or "floating".
	 */
	public function markup( string $context ): string {
		$items = '';
		foreach ( $this->links() as $link ) {
			$language = $link['language'];
			$items   .= sprintf(
				'<li class="wst-switcher__item%s"><a class="wst-switcher__link" href="%s" hreflang="%s" lang="%s"%s>%s</a></li>',
				$link['current'] ? ' is-current' : '',
				esc_url( $link['url'] ),
				esc_attr( $language->tag() ),
				esc_attr( $language->tag() ),
				$link['current'] ? ' aria-current="true"' : '',
				esc_html( $this->label( $language ) )
			);
		}
		if ( '' === $items ) {
			return '';
		}
		$classes = 'wst-switcher wst-switcher--' . $this->settings->choice( 'switcher_theme' );
		if ( 'floating' === $context ) {
			$classes .= ' wst-switcher--floating wst-switcher--' . $this->settings->choice( 'switcher_position' );
		}

		return sprintf(
			'<nav class="%s" data-wst-no-translate aria-label="%s"%s><ul class="wst-switcher__list">%s</ul></nav>',
			esc_attr( $classes ),
			esc_attr__( 'Language', 'wp-site-translator' ),
			$this->styleAttribute( 'floating' === $context ),
			$items
		);
	}

	/**
	 * Floating switcher at the end of the page.
	 */
	public function printFloating(): void {
		echo $this->markup( 'floating' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
	}

	/**
	 * Load the switcher styles on the front end (small; loaded up front so
	 * the switcher never shifts the layout).
	 */
	public function enqueueStyle(): void {
		wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/switcher.css', $this->pluginFile ), array(), Config::VERSION );
	}

	/**
	 * The wst/switcher block: rendered on the server, previewed in the editor.
	 */
	public function registerBlock(): void {
		if ( ! Assets::registerScript( self::BLOCK_SCRIPT, 'switcher-block', $this->pluginFile ) ) {
			return;
		}
		wp_register_style( self::STYLE_HANDLE, plugins_url( 'assets/switcher.css', $this->pluginFile ), array(), Config::VERSION );
		register_block_type(
			dirname( $this->pluginFile ) . '/blocks/switcher',
			array( 'render_callback' => fn(): string => $this->render() )
		);
	}

	/**
	 * Replace "Language switcher" menu items with one link per language.
	 *
	 * @param array<int, object> $items Menu items.
	 * @return array<int, object>
	 */
	public function expandMenuItems( $items ): array {
		$expanded = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_object( $item ) || self::MENU_URL !== self::prop( $item, 'url' ) ) {
				$expanded[] = $item;
				continue;
			}
			$classes = self::prop( $item, 'classes' );
			foreach ( $this->links() as $link ) {
				$clone = clone $item;
				$id    = (int) self::prop( $item, 'ID' ) * 10 + ( $link['current'] ? 1 : 2 );
				foreach (
					array(
						'ID'                  => $id,
						'db_id'               => $id,
						'object_id'           => $id,
						'title'               => $this->label( $link['language'] ),
						'url'                 => $link['url'],
						'classes'             => array_merge( is_array( $classes ) ? $classes : array(), array( 'wst-menu-language', $link['current'] ? 'current-language' : 'other-language' ) ),
						'current'             => false,
						'current_item_parent' => false,
						'wst_language'        => $link['language']->tag(),
						'wst_current'         => $link['current'],
					) as $name => $value
				) {
					self::setProp( $clone, $name, $value );
				}
				$expanded[] = $clone;
			}
		}

		return $expanded;
	}

	/**
	 * A property of a menu item object (WordPress decorates them dynamically).
	 *
	 * @param object $item Menu item.
	 * @param string $name Property.
	 * @return mixed
	 */
	private static function prop( object $item, string $name ) {
		return $item->$name ?? null;
	}

	/**
	 * Set a property of a menu item object.
	 *
	 * @param object $item  Menu item.
	 * @param string $name  Property.
	 * @param mixed  $value Value.
	 */
	private static function setProp( object $item, string $name, $value ): void {
		$item->$name = $value;
	}

	/**
	 * Add hreflang, lang and aria-current to switcher menu links and exclude them from translation.
	 *
	 * @param array<string, string> $atts Link attributes.
	 * @param object                $item Menu item.
	 * @return array<string, string>
	 */
	public function menuLinkAttributes( $atts, $item ): array {
		$atts = (array) $atts;
		if ( isset( $item->wst_language ) && is_string( $item->wst_language ) ) {
			$atts['hreflang'] = $item->wst_language;
			$atts['lang']     = $item->wst_language;
			// Walker_Nav_Menu drops empty attribute values; the Extractor only checks presence.
			$atts['data-wst-no-translate'] = '1';
			if ( true === self::prop( $item, 'wst_current' ) ) {
				$atts['aria-current'] = 'true';
			}
		}

		return $atts;
	}

	/**
	 * "Language switcher" box on Appearance → Menus.
	 */
	public function addMenuMetaBox(): void {
		add_meta_box( 'wst-switcher-menu', __( 'Language switcher', 'wp-site-translator' ), array( $this, 'renderMenuMetaBox' ), 'nav-menus', 'side', 'low' );
	}

	/**
	 * Menu box body: one item the menu editor adds like a custom link.
	 */
	public function renderMenuMetaBox(): void {
		$title = __( 'Language switcher', 'wp-site-translator' );
		?>
		<div id="posttype-wst-switcher" class="posttypediv">
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1"> <?php echo esc_html( $title ); ?>
						</label>
						<input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom">
						<input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php echo esc_attr( $title ); ?>">
						<input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="<?php echo esc_attr( self::MENU_URL ); ?>">
						<input type="hidden" class="menu-item-classes" name="menu-item[-1][menu-item-classes]" value="wst-menu-switcher">
					</li>
				</ul>
			</div>
			<p class="description"><?php esc_html_e( 'Shows one link per language. In block themes, use the Language switcher block instead.', 'wp-site-translator' ); ?></p>
			<p class="button-controls">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'wp-site-translator' ); ?>" name="add-post-type-menu-item" id="submit-posttype-wst-switcher">
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Inline CSS variables: custom colours, and the vertical offset of the
	 * floating switcher (moves it clear of a theme's fixed bar).
	 *
	 * @param bool $floating Whether this is the floating switcher.
	 */
	private function styleAttribute( bool $floating ): string {
		$vars = array();
		if ( 'custom' === $this->settings->choice( 'switcher_theme' ) ) {
			$colors = $this->settings->switcherColors();
			$vars[] = '--wst-switcher-text:' . $colors['text'];
			$vars[] = '--wst-switcher-bg:' . $colors['background'];
			$vars[] = '--wst-switcher-accent:' . $colors['accent'];
		}
		if ( $floating ) {
			$vars[] = '--wst-switcher-offset:' . $this->settings->number( 'switcher_offset' ) . 'px';
		}

		return array() === $vars ? '' : sprintf( ' style="%s"', esc_attr( implode( ';', $vars ) ) );
	}
}
