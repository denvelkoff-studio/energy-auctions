<?php
/**
 * Frontend контролер за наддаване и „Купи сега“.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions\Frontend;

use EnergyAuctions\Bids;
use EnergyAuctions\Plugin;
use EnergyAuctions\Time;
use EnergyAuctions\Product\Auction_Product;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Bidding.
 */
class Bidding {

	/**
	 * Флаг, че в момента тече „Купи сега“ добавяне в кошницата.
	 *
	 * @var bool
	 */
	private bool $buy_now_in_progress = false;

	/**
	 * Единствена инстанция.
	 *
	 * @var Bidding|null
	 */
	private static ?Bidding $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Bidding {
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
		add_action( 'admin_post_ea_place_bid', array( $this, 'handle_place_bid' ) );
		add_action( 'admin_post_nopriv_ea_place_bid', array( $this, 'handle_guest' ) );
		add_action( 'admin_post_ea_buy_now', array( $this, 'handle_buy_now' ) );
		add_action( 'admin_post_nopriv_ea_buy_now', array( $this, 'handle_guest' ) );

		// Кошница: пази auction продуктите да не се добавят по стандартния път.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'guard_add_to_cart' ), 10, 2 );
		// Цена в кошницата за „Купи сега“.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_buy_now_cart_price' ), 20 );
	}

	/**
	 * Гост опит за наддаване → обратно с грешка.
	 */
	public function handle_guest(): void {
		$this->redirect_with_notice(
			wp_get_referer() ?: home_url(),
			__( 'Трябва да сте влезли в профила си, за да наддавате.', 'energy-auctions' ),
			'error'
		);
	}

	/**
	 * Обработва подаване на оферта.
	 */
	public function handle_place_bid(): void {
		$auction_id = isset( $_POST['ea_auction_id'] ) ? absint( $_POST['ea_auction_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$redirect   = get_permalink( $auction_id ) ?: home_url();

		// Nonce.
		if ( ! isset( $_POST['ea_bid_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ea_bid_nonce'] ) ), 'ea_place_bid_' . $auction_id ) ) {
			$this->redirect_with_notice( $redirect, __( 'Невалидна заявка. Презаредете страницата.', 'energy-auctions' ), 'error' );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->redirect_with_notice( $redirect, __( 'Трябва да сте влезли в профила си, за да наддавате.', 'energy-auctions' ), 'error' );
		}

		$product = $this->get_auction( $auction_id );
		if ( ! $product ) {
			$this->redirect_with_notice( $redirect, __( 'Търгът не е намерен.', 'energy-auctions' ), 'error' );
		}

		// Собственикът не може да наддава.
		if ( (int) get_post_field( 'post_author', $auction_id ) === $user_id ) {
			$this->redirect_with_notice( $redirect, __( 'Не можете да наддавате за собствен търг.', 'energy-auctions' ), 'error' );
		}

		$amount = isset( $_POST['ea_bid_amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['ea_bid_amount'] ) ) : 0.0;
		if ( $amount <= 0 ) {
			$this->redirect_with_notice( $redirect, __( 'Въведете валидна сума.', 'energy-auctions' ), 'error' );
		}

		// Запомняме предишния водач (за имейл „наддаден си“).
		$previous = Bids::get_highest( $auction_id );
		$prev_uid = $previous ? (int) $previous->user_id : 0;

		$result = Bids::place_bid( $product, $user_id, $amount );

		if ( $result instanceof WP_Error ) {
			$this->redirect_with_notice( $redirect, $result->get_error_message(), 'error' );
		}

		// Уведомяваме слушателите (имейли се закачат във Фаза 4).
		do_action( 'ea_bid_placed', $auction_id, $user_id, $amount, $prev_uid );

		$message = sprintf(
			/* translators: %s amount */
			__( 'Офертата ви от %s е приета! Вие сте начело.', 'energy-auctions' ),
			wc_price( $amount )
		);
		if ( ! empty( $result['extended'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %s new end time */
				__( 'Краят на търга е удължен до %s.', 'energy-auctions' ),
				esc_html( Time::format( $result['new_end'] ) )
			);
		}

		$this->redirect_with_notice( $redirect, $message, 'success' );
	}

	/**
	 * Обработва „Купи сега“.
	 */
	public function handle_buy_now(): void {
		$auction_id = isset( $_POST['ea_auction_id'] ) ? absint( $_POST['ea_auction_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$redirect   = get_permalink( $auction_id ) ?: home_url();

		if ( ! isset( $_POST['ea_buy_now_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ea_buy_now_nonce'] ) ), 'ea_buy_now_' . $auction_id ) ) {
			$this->redirect_with_notice( $redirect, __( 'Невалидна заявка. Презаредете страницата.', 'energy-auctions' ), 'error' );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			$this->redirect_with_notice( $redirect, __( 'Трябва да сте влезли в профила си.', 'energy-auctions' ), 'error' );
		}

		$product = $this->get_auction( $auction_id );
		if ( ! $product ) {
			$this->redirect_with_notice( $redirect, __( 'Търгът не е намерен.', 'energy-auctions' ), 'error' );
		}

		if ( (int) get_post_field( 'post_author', $auction_id ) === $user_id ) {
			$this->redirect_with_notice( $redirect, __( 'Не можете да купите собствен търг.', 'energy-auctions' ), 'error' );
		}

		$buy_now = (float) $product->get_ea_buy_now_price();
		if ( $buy_now <= 0 ) {
			$this->redirect_with_notice( $redirect, __( '„Купи сега“ не е активно за този търг.', 'energy-auctions' ), 'error' );
		}

		if ( 'active' !== $product->get_ea_status() || Time::to_ts( $product->get_ea_end_date() ) <= Time::now_ts() ) {
			$this->redirect_with_notice( $redirect, __( 'Търгът вече не е активен.', 'energy-auctions' ), 'error' );
		}

		// Затваряме търга като продаден на този купувач.
		$product->set_ea_status( 'sold' );
		$product->update_meta_data( '_ea_winner_id', $user_id );
		$product->update_meta_data( '_ea_winning_amount', wc_format_decimal( $buy_now ) );
		$product->update_meta_data( '_ea_end_date', Time::now_mysql() );
		$product->save();

		// Записваме офертата „купи сега“ в историята.
		$this->record_buy_now_bid( $auction_id, $user_id, $buy_now );

		// Добавяме в кошницата с флаг за цената.
		$this->buy_now_in_progress = true;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->add_to_cart(
				$auction_id,
				1,
				0,
				array(),
				array( 'ea_buy_now' => wc_format_decimal( $buy_now ) )
			);
		}
		$this->buy_now_in_progress = false;

		do_action( 'ea_auction_sold', $auction_id, $user_id, $buy_now, 'buy_now' );

		// Към checkout.
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url();
		$this->redirect_with_notice( $checkout, __( 'Поздравления! Продуктът е добавен в кошницата за плащане.', 'energy-auctions' ), 'success' );
	}

	/**
	 * Записва „купи сега“ оферта в таблицата (за историята).
	 *
	 * @param int   $auction_id ID.
	 * @param int   $user_id    Купувач.
	 * @param float $amount     Сума.
	 */
	private function record_buy_now_bid( int $auction_id, int $user_id, float $amount ): void {
		global $wpdb;
		$wpdb->insert(
			\EnergyAuctions\Install::bids_table(),
			array(
				'auction_id' => $auction_id,
				'user_id'    => $user_id,
				'amount'     => $amount,
				'is_auto'    => 0,
				'created_at' => Time::now_mysql(),
			),
			array( '%d', '%d', '%f', '%d', '%s' )
		);
	}

	/**
	 * Блокира стандартно добавяне на auction продукт в кошницата.
	 *
	 * @param bool $passed     Дали е позволено.
	 * @param int  $product_id ID.
	 * @return bool
	 */
	public function guard_add_to_cart( bool $passed, int $product_id ): bool {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( Plugin::PRODUCT_TYPE ) && ! $this->buy_now_in_progress ) {
			wc_add_notice( __( 'Този продукт е търг — използвайте бутона за наддаване или „Купи сега“.', 'energy-auctions' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Задава цената в кошницата за „Купи сега“ артикули.
	 *
	 * @param \WC_Cart $cart Кошницата.
	 */
	public function set_buy_now_cart_price( $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['ea_buy_now'] ) && isset( $item['data'] ) ) {
				$item['data']->set_price( (float) $item['ea_buy_now'] );
			}
		}
	}

	/**
	 * Връща Auction_Product или null.
	 *
	 * @param int $auction_id ID.
	 * @return Auction_Product|null
	 */
	private function get_auction( int $auction_id ): ?Auction_Product {
		$product = $auction_id ? wc_get_product( $auction_id ) : null;
		if ( $product instanceof Auction_Product ) {
			return $product;
		}
		return null;
	}

	/**
	 * Редирект с WooCommerce notice.
	 *
	 * @param string $url     URL.
	 * @param string $message Съобщение.
	 * @param string $type    success|error|notice.
	 */
	private function redirect_with_notice( string $url, string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
