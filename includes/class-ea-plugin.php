<?php
/**
 * Главен клас на плъгина — bootstrap и зареждане на модулите.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

use EnergyAuctions\Admin\Product_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin.
 */
final class Plugin {

	/**
	 * Slug на нашия WooCommerce product type.
	 */
	public const PRODUCT_TYPE = 'auction';

	/**
	 * Единствена инстанция.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Дали WooCommerce е активен.
	 *
	 * @var bool
	 */
	private bool $wc_active = false;

	/**
	 * Връща (и създава при нужда) единствената инстанция.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Конструкторът е частен (singleton).
	 */
	private function __construct() {}

	/**
	 * Инициализира плъгина.
	 */
	private function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->wc_active = $this->is_woocommerce_active();

		if ( ! $this->wc_active ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		// Декларираме съвместимост с HPOS (custom order tables).
		add_action(
			'before_woocommerce_init',
			static function () {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EA_PLUGIN_FILE, true );
				}
			}
		);

		$this->init_modules();
	}

	/**
	 * Зарежда модулите на плъгина.
	 */
	private function init_modules(): void {
		// Регистрация на product type-а (frontend + общо).
		Product_Type::instance();

		// Админ функционалност.
		if ( is_admin() ) {
			Product_Data::instance();
		}
	}

	/**
	 * Зарежда преводите.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'energy-auctions',
			false,
			dirname( EA_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Проверява дали WooCommerce е активен.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Admin notice при липсващ WooCommerce.
	 */
	public function notice_missing_woocommerce(): void {
		$message = esc_html__( 'Energy Auctions изисква активен WooCommerce, за да работи.', 'energy-auctions' );
		printf( '<div class="notice notice-error"><p>%s</p></div>', $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Дали WooCommerce е наличен.
	 */
	public function has_woocommerce(): bool {
		return $this->wc_active;
	}
}
