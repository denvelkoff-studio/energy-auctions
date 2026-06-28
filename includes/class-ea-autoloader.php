<?php
/**
 * Прост autoloader за класовете на плъгина.
 *
 * Класовете в namespace EnergyAuctions\... се мапват към файлове
 * по WordPress конвенцията: class-ea-{name}.php в съответната папка.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader.
 */
class Autoloader {

	/**
	 * Базова директория за класовете.
	 *
	 * @var string
	 */
	private static string $base_dir = '';

	/**
	 * Регистрира autoloader-а.
	 */
	public static function register(): void {
		self::$base_dir = EA_PLUGIN_DIR . 'includes/';
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Зарежда клас по име.
	 *
	 * @param string $class Пълно име на класа (с namespace).
	 */
	public static function autoload( string $class ): void {
		// Интересуват ни само нашите класове.
		if ( 0 !== strpos( $class, 'EnergyAuctions\\' ) ) {
			return;
		}

		// Махаме root namespace-а.
		$relative = substr( $class, strlen( 'EnergyAuctions\\' ) );

		// Под-namespace → под-папка (напр. Admin\Product_Data → admin/).
		$parts      = explode( '\\', $relative );
		$class_name = array_pop( $parts );
		$sub_path   = '';
		if ( ! empty( $parts ) ) {
			$sub_path = strtolower( implode( '/', $parts ) ) . '/';
		}

		// Product_Type → class-ea-product-type.php
		$file_name = 'class-ea-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

		$path = self::$base_dir . $sub_path . $file_name;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
