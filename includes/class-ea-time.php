<?php
/**
 * Помощни функции за дати/часове (магазинът е BG/EET).
 *
 * Всички дати на търговете се пазят като MySQL datetime в ЛОКАЛНАТА
 * часова зона на сайта (WP setting). Сравненията стават спрямо
 * локалното „сега“, за да са консистентни.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

defined( 'ABSPATH' ) || exit;

/**
 * Time.
 */
class Time {

	/**
	 * Текущ локален datetime (Y-m-d H:i:s).
	 */
	public static function now_mysql(): string {
		return current_time( 'mysql' );
	}

	/**
	 * Текущ абсолютен UNIX timestamp.
	 *
	 * ВАЖНО: връщаме истински UNIX timestamp (time()), за да е консистентно
	 * с to_ts(), който също дава абсолютен timestamp спрямо wp_timezone().
	 * Така сравненията на дати са коректни независимо от часовата зона.
	 */
	public static function now_ts(): int {
		return time();
	}

	/**
	 * Превръща локален MySQL datetime в UNIX timestamp спрямо WP часовата зона.
	 *
	 * @param string $datetime Y-m-d H:i:s (локално).
	 * @return int 0 при невалиден вход.
	 */
	public static function to_ts( string $datetime ): int {
		$datetime = trim( $datetime );
		if ( '' === $datetime ) {
			return 0;
		}
		try {
			$dt = new \DateTimeImmutable( $datetime, wp_timezone() );
			return $dt->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Форматира локален datetime за показване според WP формат.
	 *
	 * @param string $datetime Y-m-d H:i:s (локално).
	 * @return string
	 */
	public static function format( string $datetime ): string {
		$ts = self::to_ts( $datetime );
		if ( ! $ts ) {
			return '';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Връща ISO 8601 (с offset) за JS countdown.
	 *
	 * @param string $datetime Y-m-d H:i:s (локално).
	 * @return string
	 */
	public static function to_iso( string $datetime ): string {
		$ts = self::to_ts( $datetime );
		if ( ! $ts ) {
			return '';
		}
		return wp_date( 'c', $ts );
	}

	/**
	 * Добавя минути към локален MySQL datetime.
	 *
	 * @param string $datetime Y-m-d H:i:s.
	 * @param int    $minutes  Брой минути.
	 * @return string Нов Y-m-d H:i:s.
	 */
	public static function add_minutes( string $datetime, int $minutes ): string {
		$ts = self::to_ts( $datetime );
		if ( ! $ts ) {
			$ts = self::now_ts();
		}
		$ts += $minutes * MINUTE_IN_SECONDS;
		return wp_date( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Добавя дни към локален MySQL datetime.
	 *
	 * @param string $datetime Y-m-d H:i:s.
	 * @param int    $days     Брой дни.
	 * @return string Нов Y-m-d H:i:s.
	 */
	public static function add_days( string $datetime, int $days ): string {
		$ts = self::to_ts( $datetime );
		if ( ! $ts ) {
			$ts = self::now_ts();
		}
		$ts += $days * DAY_IN_SECONDS;
		return wp_date( 'Y-m-d H:i:s', $ts );
	}
}
