<?php
/**
 * Деинсталация на Energy Auctions.
 *
 * Консервативно: НЕ трием таблицата с оферти по подразбиране (данни за
 * продажби). За пълно изтриване дефинирайте в wp-config.php:
 *   define( 'EA_REMOVE_ALL_DATA', true );
 *
 * @package EnergyAuctions
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Винаги махаме служебните опции.
delete_option( 'ea_db_version' );

// Пълно изтриване само при изричен флаг.
if ( defined( 'EA_REMOVE_ALL_DATA' ) && EA_REMOVE_ALL_DATA ) {
	$table = $wpdb->prefix . 'ea_bids';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
}
