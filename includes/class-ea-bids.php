<?php
/**
 * Слой за достъп до офертите + атомарно записване на оферта.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

use EnergyAuctions\Product\Auction_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Bids.
 */
class Bids {

	/**
	 * Брой минути за anti-sniping (оферта в последните N мин. удължава с N мин.).
	 * Филтрируемо чрез `ea_anti_sniping_minutes`.
	 */
	public static function anti_sniping_minutes(): int {
		return (int) apply_filters( 'ea_anti_sniping_minutes', 5 );
	}

	/**
	 * Връща най-високата оферта за търг (ред от таблицата) или null.
	 *
	 * @param int $auction_id ID на търга.
	 * @return object|null
	 */
	public static function get_highest( int $auction_id ): ?object {
		global $wpdb;
		$table = Install::bids_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE auction_id = %d ORDER BY amount DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$auction_id
			)
		);
		return $row ?: null;
	}

	/**
	 * Връща броя оферти за търг.
	 *
	 * @param int $auction_id ID на търга.
	 */
	public static function get_count( int $auction_id ): int {
		global $wpdb;
		$table = Install::bids_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE auction_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$auction_id
			)
		);
	}

	/**
	 * Връща историята на офертите (най-новите първи).
	 *
	 * @param int $auction_id ID на търга.
	 * @param int $limit      Максимален брой.
	 * @return array<int,object>
	 */
	public static function get_history( int $auction_id, int $limit = 20 ): array {
		global $wpdb;
		$table = Install::bids_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE auction_id = %d ORDER BY amount DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$auction_id,
				$limit
			)
		);
		return $rows ?: array();
	}

	/**
	 * Минималната допустима следваща оферта.
	 *
	 * @param Auction_Product $product Продуктът-търг.
	 * @return float
	 */
	public static function get_min_next_bid( Auction_Product $product ): float {
		$increment = (float) $product->get_ea_bid_increment();
		$highest   = self::get_highest( $product->get_id() );

		if ( $highest ) {
			return (float) $highest->amount + $increment;
		}
		// Първа оферта = стартовата цена.
		return (float) $product->get_ea_start_price();
	}

	/**
	 * Записва оферта атомарно (транзакция + row lock срещу race condition).
	 *
	 * @param Auction_Product $product Продуктът-търг.
	 * @param int             $user_id Потребител.
	 * @param float           $amount  Сума.
	 * @return array{amount:float,is_highest:bool,extended:bool,new_end:string}|WP_Error
	 */
	public static function place_bid( Auction_Product $product, int $user_id, float $amount ) {
		global $wpdb;

		$auction_id = $product->get_id();
		$table      = Install::bids_table();
		$posts      = $wpdb->posts;

		// Транзакция.
		$wpdb->query( 'START TRANSACTION' );

		// Заключваме реда на продукта → серилизира паралелните оферти за този търг.
		// (Дори при нула съществуващи оферти, редът в posts винаги съществува.)
		$locked = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$posts} WHERE ID = %d FOR UPDATE", $auction_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ea_not_found', __( 'Търгът не е намерен.', 'energy-auctions' ) );
		}

		// Четем СВЕЖО състояние под lock-а (директно от postmeta, без обектен кеш).
		$status   = self::fresh_meta( $auction_id, '_ea_status' );
		$end_date = self::fresh_meta( $auction_id, '_ea_end_date' );

		// Търгът трябва да е активен и да не е изтекъл.
		if ( 'active' !== $status || ( '' !== $end_date && Time::to_ts( $end_date ) <= Time::now_ts() ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ea_not_active', __( 'Търгът не е активен.', 'energy-auctions' ) );
		}

		// Текуща най-висока оферта под lock-а.
		$highest = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT amount FROM {$table} WHERE auction_id = %d ORDER BY amount DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$auction_id
			)
		);

		$increment = (float) $product->get_ea_bid_increment();
		$min_next  = $highest ? (float) $highest->amount + $increment : (float) $product->get_ea_start_price();

		// Малък толеранс за грешки в плаваща запетая.
		if ( $amount + 0.00001 < $min_next ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error(
				'ea_too_low',
				sprintf(
					/* translators: %s minimal bid amount */
					__( 'Офертата трябва да е поне %s.', 'energy-auctions' ),
					wc_price( $min_next )
				)
			);
		}

		// Записваме офертата.
		$inserted = $wpdb->insert(
			$table,
			array(
				'auction_id' => $auction_id,
				'user_id'    => $user_id,
				'amount'     => $amount,
				'is_auto'    => 0,
				'created_at' => Time::now_mysql(),
			),
			array( '%d', '%d', '%f', '%d', '%s' )
		);

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'ea_insert_failed', __( 'Грешка при запис на офертата. Опитайте отново.', 'energy-auctions' ) );
		}

		$wpdb->query( 'COMMIT' );

		// --- Anti-sniping: оферта в последните N мин. удължава края с N мин. ---
		$extended = false;
		$new_end  = $end_date;
		$minutes  = self::anti_sniping_minutes();
		if ( $minutes > 0 && '' !== $end_date ) {
			$remaining = Time::to_ts( $end_date ) - Time::now_ts();
			if ( $remaining > 0 && $remaining <= $minutes * MINUTE_IN_SECONDS ) {
				$new_end = Time::add_minutes( Time::now_mysql(), $minutes );
				update_post_meta( $auction_id, '_ea_end_date', $new_end );
				$extended = true;
				/**
				 * След удължаване заради anti-sniping.
				 *
				 * @param int    $auction_id ID на търга.
				 * @param string $new_end    Нов край (Y-m-d H:i:s).
				 */
				do_action( 'ea_auction_extended', $auction_id, $new_end );
			}
		}

		// Кеш на продукта вече е остарял — изчистваме.
		clean_post_cache( $auction_id );

		return array(
			'amount'     => $amount,
			'is_highest' => true,
			'extended'   => $extended,
			'new_end'    => $new_end,
		);
	}

	/**
	 * Чете мета директно от базата (заобикаля обектния кеш) — за четене под lock.
	 *
	 * @param int    $post_id ID.
	 * @param string $key     Мета ключ.
	 * @return string
	 */
	private static function fresh_meta( int $post_id, string $key ): string {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$key
			)
		);
		return null === $value ? '' : (string) $value;
	}

	/**
	 * Маскира потребителско име за публично показване: „Александър“ → „А***р“.
	 *
	 * @param int $user_id ID.
	 * @return string
	 */
	public static function masked_bidder_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'Анонимен', 'energy-auctions' );
		}
		$name = $user->display_name ?: $user->user_login;
		$name = trim( $name );

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		if ( $len <= 1 ) {
			return $name . '***';
		}
		if ( $len === 2 ) {
			$first = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
			return $first . '***';
		}

		$first = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		$last  = function_exists( 'mb_substr' ) ? mb_substr( $name, -1, 1 ) : substr( $name, -1, 1 );
		return $first . '***' . $last;
	}
}
