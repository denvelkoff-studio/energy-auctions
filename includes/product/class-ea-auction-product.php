<?php
/**
 * WooCommerce продуктов клас за тип „auction“.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions\Product;

use EnergyAuctions\Plugin;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Auction_Product.
 *
 * Разширява WC_Product, за да наследи checkout/плащане/акаунти наготово.
 */
class Auction_Product extends WC_Product {

	/**
	 * Връща типа на продукта.
	 */
	public function get_type(): string {
		return Plugin::PRODUCT_TYPE;
	}

	/* --------------------------------------------------------------------- *
	 * Getters за мета-полетата на търга.
	 * --------------------------------------------------------------------- */

	/**
	 * Стартова цена.
	 */
	public function get_ea_start_price( string $context = 'view' ): string {
		return (string) $this->get_meta( '_ea_start_price', true, $context );
	}

	/**
	 * Стъпка на наддаване.
	 */
	public function get_ea_bid_increment( string $context = 'view' ): string {
		return (string) $this->get_meta( '_ea_bid_increment', true, $context );
	}

	/**
	 * Скрит резерв (минимална цена за продажба).
	 */
	public function get_ea_reserve_price( string $context = 'view' ): string {
		return (string) $this->get_meta( '_ea_reserve_price', true, $context );
	}

	/**
	 * Цена „купи сега“ (опционална).
	 */
	public function get_ea_buy_now_price( string $context = 'view' ): string {
		return (string) $this->get_meta( '_ea_buy_now_price', true, $context );
	}

	/**
	 * Начало на търга (MySQL datetime, локална часова зона на сайта).
	 */
	public function get_ea_start_date( string $context = 'view' ): string {
		return (string) $this->get_meta( '_ea_start_date', true, $context );
	}

	/**
	 * Край на търга (MySQL datetime, локална часова зона на сайта).
	 */
	public function get_ea_end_date( string $context = 'view' ): string {
		return (string) $this->get_meta( '_ea_end_date', true, $context );
	}

	/**
	 * Статус на търга: scheduled|active|ended|sold|unsold.
	 */
	public function get_ea_status( string $context = 'view' ): string {
		$status = (string) $this->get_meta( '_ea_status', true, $context );
		return '' === $status ? 'scheduled' : $status;
	}

	/* --------------------------------------------------------------------- *
	 * Setters.
	 * --------------------------------------------------------------------- */

	/**
	 * Записва стойност на мета-поле (с update_meta_data — пази се при save()).
	 *
	 * @param string $key   Мета ключ.
	 * @param mixed  $value Стойност.
	 */
	private function set_ea_meta( string $key, $value ): void {
		$this->update_meta_data( $key, $value );
	}

	/** Сетва стартова цена. */
	public function set_ea_start_price( $value ): void {
		$this->set_ea_meta( '_ea_start_price', wc_format_decimal( $value ) );
	}

	/** Сетва стъпка. */
	public function set_ea_bid_increment( $value ): void {
		$this->set_ea_meta( '_ea_bid_increment', wc_format_decimal( $value ) );
	}

	/** Сетва резерв. */
	public function set_ea_reserve_price( $value ): void {
		$this->set_ea_meta( '_ea_reserve_price', '' === $value ? '' : wc_format_decimal( $value ) );
	}

	/** Сетва „купи сега“. */
	public function set_ea_buy_now_price( $value ): void {
		$this->set_ea_meta( '_ea_buy_now_price', '' === $value ? '' : wc_format_decimal( $value ) );
	}

	/** Сетва начало. */
	public function set_ea_start_date( string $value ): void {
		$this->set_ea_meta( '_ea_start_date', $value );
	}

	/** Сетва край. */
	public function set_ea_end_date( string $value ): void {
		$this->set_ea_meta( '_ea_end_date', $value );
	}

	/** Сетва статус. */
	public function set_ea_status( string $value ): void {
		$allowed = array( 'scheduled', 'active', 'ended', 'sold', 'unsold' );
		if ( in_array( $value, $allowed, true ) ) {
			$this->set_ea_meta( '_ea_status', $value );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Поведение на продукта.
	 * --------------------------------------------------------------------- */

	/**
	 * Цена за показване — текущата водеща оферта или стартовата цена.
	 * (Реалната логика за оферти идва във Фаза 2.)
	 */
	public function get_price( $context = 'view' ) {
		$price = parent::get_price( $context );
		if ( '' === $price || null === $price ) {
			$price = $this->get_ea_start_price( $context );
		}
		return $price;
	}

	/**
	 * Виртуален/без доставка по подразбиране? Не — търгуват се реални вещи.
	 * Оставяме стандартното поведение.
	 */
}
