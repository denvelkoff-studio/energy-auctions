<?php
/**
 * Админ: полета за auction продукт + запис и валидация.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions\Admin;

use EnergyAuctions\Plugin;
use EnergyAuctions\Time;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Product_Data.
 */
class Product_Data {

	/**
	 * Transient key за грешки при валидация (по потребител).
	 */
	private const ERROR_TRANSIENT = 'ea_admin_errors_';

	/**
	 * Единствена инстанция.
	 *
	 * @var Product_Data|null
	 */
	private static ?Product_Data $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Product_Data {
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
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		// Приоритет 20 — след като WC core зададе типа на продукта.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20, 1 );
		add_action( 'admin_notices', array( $this, 'render_errors' ) );
		add_action( 'admin_head', array( $this, 'inline_styles' ) );

		// Колона „Търг“ в списъка с продукти.
		add_filter( 'manage_product_posts_columns', array( $this, 'add_status_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_status_column' ), 10, 2 );
	}

	/**
	 * Добавя колона „Търг“ след колоната с име.
	 *
	 * @param array<string,string> $columns Колони.
	 * @return array<string,string>
	 */
	public function add_status_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['ea_auction'] = __( 'Търг', 'energy-auctions' );
			}
		}
		return $new;
	}

	/**
	 * Рендира съдържанието на колоната „Търг“.
	 *
	 * @param string $column  Колона.
	 * @param int    $post_id ID.
	 */
	public function render_status_column( string $column, int $post_id ): void {
		if ( 'ea_auction' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof \EnergyAuctions\Product\Auction_Product ) {
			echo '—';
			return;
		}
		$status = $product->get_ea_status();
		$end    = $product->get_ea_end_date();
		$count  = \EnergyAuctions\Bids::get_count( $post_id );

		printf(
			'<strong>%s</strong><br><small>%s</small><br><small>%s</small>',
			esc_html( $status ),
			esc_html( sprintf( /* translators: %d count */ _n( '%d оферта', '%d оферти', $count, 'energy-auctions' ), $count ) ),
			esc_html( '' !== $end ? Time::format( $end ) : '' )
		);
	}

	/**
	 * Добавя таб „Търг“ и показва нужните стандартни табове за нашия тип.
	 *
	 * @param array<string,array<string,mixed>> $tabs Табове.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_data_tab( array $tabs ): array {
		$type = Plugin::PRODUCT_TYPE;

		$tabs['ea_auction'] = array(
			'label'    => __( 'Търг', 'energy-auctions' ),
			'target'   => 'ea_auction_data',
			'class'    => array( 'show_if_' . $type ),
			'priority' => 11,
		);

		// Стандартните табове Inventory/Shipping/Advanced също да важат за auction.
		foreach ( array( 'inventory', 'shipping', 'advanced' ) as $key ) {
			if ( isset( $tabs[ $key ]['class'] ) && is_array( $tabs[ $key ]['class'] ) ) {
				$tabs[ $key ]['class'][] = 'show_if_' . $type;
			}
		}

		// Табът „General“ е с ценови полета, които не ни трябват — крием за auction.
		if ( isset( $tabs['general']['class'] ) && is_array( $tabs['general']['class'] ) ) {
			$tabs['general']['class'][] = 'hide_if_' . $type;
		}

		return $tabs;
	}

	/**
	 * Рендира панела с полетата на търга.
	 */
	public function render_panel(): void {
		global $post;

		$product = $post ? wc_get_product( $post->ID ) : null;

		$start_price   = $product ? $product->get_meta( '_ea_start_price' ) : '';
		$bid_increment = $product ? $product->get_meta( '_ea_bid_increment' ) : '';
		$reserve_price = $product ? $product->get_meta( '_ea_reserve_price' ) : '';
		$buy_now_price = $product ? $product->get_meta( '_ea_buy_now_price' ) : '';
		$start_date    = $product ? $product->get_meta( '_ea_start_date' ) : '';
		$end_date      = $product ? $product->get_meta( '_ea_end_date' ) : '';
		$status        = $product ? $product->get_meta( '_ea_status' ) : '';
		$status        = '' === $status ? 'scheduled' : $status;

		$currency = get_woocommerce_currency_symbol();

		echo '<div id="ea_auction_data" class="panel woocommerce_options_panel hidden">';

		wp_nonce_field( 'ea_save_auction', 'ea_auction_nonce' );

		// --- Цени ---
		echo '<div class="options_group">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_ea_start_price',
				'value'             => $start_price,
				'label'             => sprintf( /* translators: %s currency symbol */ __( 'Стартова цена (%s)', 'energy-auctions' ), $currency ),
				'desc_tip'          => true,
				'description'       => __( 'Началната цена, от която стартира наддаването.', 'energy-auctions' ),
				'data_type'         => 'price',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_ea_bid_increment',
				'value'             => $bid_increment,
				'label'             => sprintf( /* translators: %s currency symbol */ __( 'Стъпка на наддаване (%s)', 'energy-auctions' ), $currency ),
				'desc_tip'          => true,
				'description'       => __( 'Минимална разлика между две последователни оферти.', 'energy-auctions' ),
				'data_type'         => 'price',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_ea_reserve_price',
				'value'             => $reserve_price,
				'label'             => sprintf( /* translators: %s currency symbol */ __( 'Скрит резерв (%s)', 'energy-auctions' ), $currency ),
				'desc_tip'          => true,
				'description'       => __( 'Скрита минимална цена за продажба. Ако най-високата оферта е под нея, търгът е unsold. Оставете празно за без резерв.', 'energy-auctions' ),
				'data_type'         => 'price',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_ea_buy_now_price',
				'value'             => $buy_now_price,
				'label'             => sprintf( /* translators: %s currency symbol */ __( 'Купи сега (%s)', 'energy-auctions' ), $currency ),
				'desc_tip'          => true,
				'description'       => __( 'Опционално. Цена за моментална покупка, която затваря търга. Оставете празно за изключено.', 'energy-auctions' ),
				'data_type'         => 'price',
				'custom_attributes' => array( 'step' => 'any', 'min' => '0' ),
			)
		);

		echo '</div>';

		// --- Период ---
		echo '<div class="options_group">';

		$this->datetime_input( '_ea_start_date', __( 'Начало на търга', 'energy-auctions' ), $start_date );
		$this->datetime_input( '_ea_end_date', __( 'Край на търга', 'energy-auctions' ), $end_date );

		echo '</div>';

		// --- Статус ---
		echo '<div class="options_group">';

		woocommerce_wp_select(
			array(
				'id'          => '_ea_status',
				'value'       => $status,
				'label'       => __( 'Статус', 'energy-auctions' ),
				'desc_tip'    => true,
				'description' => __( 'Обикновено се изчислява автоматично от датите. „sold“/„unsold“ се задават при затваряне.', 'energy-auctions' ),
				'options'     => array(
					'scheduled' => __( 'Предстоящ (scheduled)', 'energy-auctions' ),
					'active'    => __( 'Активен (active)', 'energy-auctions' ),
					'ended'     => __( 'Приключил (ended)', 'energy-auctions' ),
					'sold'      => __( 'Продаден (sold)', 'energy-auctions' ),
					'unsold'    => __( 'Непродаден (unsold)', 'energy-auctions' ),
				),
			)
		);

		echo '</div>';

		echo '</div>'; // #ea_auction_data
	}

	/**
	 * Рендира datetime-local поле в стила на WC панелите.
	 *
	 * @param string $id    Мета ключ / id.
	 * @param string $label Етикет.
	 * @param string $value Текуща стойност (Y-m-d H:i:s).
	 */
	private function datetime_input( string $id, string $label, string $value ): void {
		// MySQL datetime → input[type=datetime-local] формат (Y-m-d\TH:i).
		$html_value = '';
		if ( '' !== $value ) {
			$ts = strtotime( $value );
			if ( $ts ) {
				$html_value = gmdate( 'Y-m-d\TH:i', $ts );
			}
		}

		printf(
			'<p class="form-field %1$s_field"><label for="%1$s">%2$s</label>
				<input type="datetime-local" class="short" name="%1$s" id="%1$s" value="%3$s" /></p>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $html_value )
		);
	}

	/**
	 * Записва мета-полетата при запис на продукта.
	 *
	 * @param int $post_id ID на продукта.
	 */
	public function save( int $post_id ): void {
		// Само за нашия тип.
		$type = isset( $_POST['product-type'] ) ? sanitize_text_field( wp_unslash( $_POST['product-type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( Plugin::PRODUCT_TYPE !== $type ) {
			return;
		}

		// Nonce.
		if ( ! isset( $_POST['ea_auction_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ea_auction_nonce'] ) ), 'ea_save_auction' ) ) {
			return;
		}

		// Capability.
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$errors = array();

		// --- Sanitize вход ---
		$start_price   = isset( $_POST['_ea_start_price'] ) ? wc_format_decimal( wp_unslash( $_POST['_ea_start_price'] ) ) : '';
		$bid_increment = isset( $_POST['_ea_bid_increment'] ) ? wc_format_decimal( wp_unslash( $_POST['_ea_bid_increment'] ) ) : '';
		$reserve_raw   = isset( $_POST['_ea_reserve_price'] ) ? trim( (string) wp_unslash( $_POST['_ea_reserve_price'] ) ) : '';
		$buy_now_raw   = isset( $_POST['_ea_buy_now_price'] ) ? trim( (string) wp_unslash( $_POST['_ea_buy_now_price'] ) ) : '';
		$reserve_price = '' === $reserve_raw ? '' : wc_format_decimal( $reserve_raw );
		$buy_now_price = '' === $buy_now_raw ? '' : wc_format_decimal( $buy_now_raw );

		$start_date = $this->sanitize_datetime( isset( $_POST['_ea_start_date'] ) ? (string) wp_unslash( $_POST['_ea_start_date'] ) : '' );
		$end_date   = $this->sanitize_datetime( isset( $_POST['_ea_end_date'] ) ? (string) wp_unslash( $_POST['_ea_end_date'] ) : '' );

		$status_in = isset( $_POST['_ea_status'] ) ? sanitize_text_field( wp_unslash( $_POST['_ea_status'] ) ) : 'scheduled';

		// --- Валидация ---
		if ( '' === $start_price || (float) $start_price < 0 ) {
			$errors[] = __( 'Стартовата цена е задължителна и не може да е отрицателна.', 'energy-auctions' );
			$start_price = '' === $start_price ? '0' : $start_price;
		}

		if ( '' === $bid_increment || (float) $bid_increment <= 0 ) {
			$errors[] = __( 'Стъпката на наддаване трябва да е положително число.', 'energy-auctions' );
		}

		if ( '' !== $reserve_price && (float) $reserve_price < (float) $start_price ) {
			$errors[] = __( 'Резервът не може да е под стартовата цена.', 'energy-auctions' );
		}

		if ( '' !== $buy_now_price && (float) $buy_now_price <= (float) $start_price ) {
			$errors[] = __( '„Купи сега“ трябва да е над стартовата цена.', 'energy-auctions' );
		}

		if ( '' === $start_date ) {
			$errors[] = __( 'Началната дата е задължителна.', 'energy-auctions' );
		}
		if ( '' === $end_date ) {
			$errors[] = __( 'Крайната дата е задължителна.', 'energy-auctions' );
		}
		if ( '' !== $start_date && '' !== $end_date && strtotime( $end_date ) <= strtotime( $start_date ) ) {
			$errors[] = __( 'Краят на търга трябва да е след началото.', 'energy-auctions' );
		}

		// --- Изчисляване на статус (ако не е приключен ръчно) ---
		$status = $this->derive_status( $status_in, $start_date, $end_date );

		// --- Запис на мета (записва се валидното; ценим да няма fatal-и) ---
		$product->update_meta_data( '_ea_start_price', $start_price );
		$product->update_meta_data( '_ea_bid_increment', $bid_increment );
		$product->update_meta_data( '_ea_reserve_price', $reserve_price );
		$product->update_meta_data( '_ea_buy_now_price', $buy_now_price );
		$product->update_meta_data( '_ea_start_date', $start_date );
		$product->update_meta_data( '_ea_end_date', $end_date );
		$product->update_meta_data( '_ea_status', $status );

		// Цена за каталога = стартова цена (за коректно показване/филтри).
		$product->set_price( '' === $start_price ? '' : $start_price );
		$product->set_regular_price( '' === $start_price ? '' : $start_price );

		$product->save();

		if ( ! empty( $errors ) ) {
			set_transient( self::ERROR_TRANSIENT . get_current_user_id(), $errors, 60 );
		}
	}

	/**
	 * Изчислява статуса спрямо датите, без да презаписва финални статуси.
	 *
	 * @param string $requested  Поискан статус от формата.
	 * @param string $start_date Начало (Y-m-d H:i:s).
	 * @param string $end_date   Край (Y-m-d H:i:s).
	 * @return string
	 */
	private function derive_status( string $requested, string $start_date, string $end_date ): string {
		// Финалните статуси се пазят (задават се при затваряне/продажба).
		if ( in_array( $requested, array( 'sold', 'unsold' ), true ) ) {
			return $requested;
		}

		if ( '' === $start_date || '' === $end_date ) {
			return 'scheduled';
		}

		$now   = Time::now_ts();
		$start = Time::to_ts( $start_date );
		$end   = Time::to_ts( $end_date );

		if ( $now < $start ) {
			return 'scheduled';
		}
		if ( $now >= $end ) {
			return 'ended';
		}
		return 'active';
	}

	/**
	 * Преобразува datetime-local вход в MySQL datetime (Y-m-d H:i:s).
	 *
	 * @param string $value Вход.
	 * @return string Празен низ при невалиден вход.
	 */
	private function sanitize_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$ts = strtotime( $value );
		if ( ! $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Показва натрупаните грешки от валидацията.
	 */
	public function render_errors(): void {
		$key    = self::ERROR_TRANSIENT . get_current_user_id();
		$errors = get_transient( $key );
		if ( empty( $errors ) || ! is_array( $errors ) ) {
			return;
		}
		delete_transient( $key );

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Energy Auctions — проблеми при запис:', 'energy-auctions' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( (string) $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Дребни инлайн стилове за полетата в админ панела.
	 */
	public function inline_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		echo '<style>#ea_auction_data .form-field input[type="datetime-local"]{width:50%;}</style>';
	}
}
