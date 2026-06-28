<?php
/**
 * Регистрация на WooCommerce product type „auction“.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

use EnergyAuctions\Product\Auction_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Product_Type.
 */
class Product_Type {

	/**
	 * Единствена инстанция.
	 *
	 * @var Product_Type|null
	 */
	private static ?Product_Type $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Product_Type {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Закача hook-овете.
	 */
	private function hooks(): void {
		// Регистрира таксономичния term за product_type след зареждане на WC.
		add_action( 'init', array( $this, 'register_product_type_term' ), 5 );

		// Ъпгрейд на DB схемата при нужда.
		add_action( 'init', array( Install::class, 'maybe_upgrade' ), 6 );

		// Добавя „auction“ в дропдауна за тип продукт в админа.
		add_filter( 'product_type_selector', array( $this, 'add_product_type_selector' ) );

		// Мапва type → клас на продукта.
		add_filter( 'woocommerce_product_class', array( $this, 'map_product_class' ), 10, 2 );
	}

	/**
	 * Регистрира „auction“ като term в таксономията product_type.
	 *
	 * WooCommerce държи типовете като термове; без този term типът няма
	 * да се запази коректно при някои конфигурации.
	 */
	public function register_product_type_term(): void {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			return;
		}
		if ( ! get_term_by( 'slug', Plugin::PRODUCT_TYPE, 'product_type' ) ) {
			wp_insert_term( Plugin::PRODUCT_TYPE, 'product_type' );
		}
	}

	/**
	 * Добавя типа в дропдауна.
	 *
	 * @param array<string,string> $types Налични типове.
	 * @return array<string,string>
	 */
	public function add_product_type_selector( array $types ): array {
		$types[ Plugin::PRODUCT_TYPE ] = __( 'Търг (auction)', 'energy-auctions' );
		return $types;
	}

	/**
	 * Връща нашия клас за продукти от тип „auction“.
	 *
	 * @param string $classname Подразбиращ се клас.
	 * @param string $product_type Тип на продукта.
	 * @return string
	 */
	public function map_product_class( string $classname, string $product_type ): string {
		if ( Plugin::PRODUCT_TYPE === $product_type ) {
			return Auction_Product::class;
		}
		return $classname;
	}
}
