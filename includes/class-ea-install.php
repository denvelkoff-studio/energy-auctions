<?php
/**
 * Инсталация / деинсталация — създаване на DB таблици, schedule cleanup.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

defined( 'ABSPATH' ) || exit;

/**
 * Install.
 */
class Install {

	/**
	 * Опция с версията на DB схемата.
	 */
	public const DB_VERSION_OPTION = 'ea_db_version';

	/**
	 * Текуща версия на DB схемата. Увеличава се при промяна в таблиците.
	 */
	public const DB_VERSION = '1';

	/**
	 * Връща пълното име на таблицата с офертите.
	 */
	public static function bids_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ea_bids';
	}

	/**
	 * Изпълнява се при активация на плъгина.
	 */
	public static function activate(): void {
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );

		// Flush на rewrite rules — за бъдещи endpoints.
		flush_rewrite_rules();
	}

	/**
	 * Изпълнява се при деактивация — без триене на данни.
	 */
	public static function deactivate(): void {
		// Спираме резервния WP-cron (системният cron остава за hPanel).
		$timestamp = wp_next_scheduled( 'ea_close_auctions_fallback' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ea_close_auctions_fallback' );
		}
		wp_clear_scheduled_hook( 'ea_close_auctions_fallback' );

		flush_rewrite_rules();
	}

	/**
	 * Проверява дали схемата се нуждае от ъпгрейд (извиква се на init).
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Създава DB таблиците чрез dbDelta.
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::bids_table();

		// Таблица с оферти. amount/max_amount като DECIMAL за коректни пари.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			auction_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(19,4) NOT NULL DEFAULT 0,
			max_amount DECIMAL(19,4) NULL DEFAULT NULL,
			is_auto TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY auction_amount (auction_id, amount),
			KEY auction_created (auction_id, created_at),
			KEY user_id (user_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
