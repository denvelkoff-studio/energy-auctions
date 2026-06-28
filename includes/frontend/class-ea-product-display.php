<?php
/**
 * Frontend визуализация на търга върху продуктовата страница.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions\Frontend;

use EnergyAuctions\Bids;
use EnergyAuctions\Plugin;
use EnergyAuctions\Time;
use EnergyAuctions\Product\Auction_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Product_Display.
 */
class Product_Display {

	/**
	 * Единствена инстанция.
	 *
	 * @var Product_Display|null
	 */
	private static ?Product_Display $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Product_Display {
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
		// WooCommerce извиква woocommerce_{type}_add_to_cart за area-та с бутона.
		add_action( 'woocommerce_auction_add_to_cart', array( $this, 'render_bidding_box' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Зарежда CSS/JS само на auction продуктови страници.
	 */
	public function enqueue_assets(): void {
		if ( ! is_product() ) {
			return;
		}
		global $post;
		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product instanceof Auction_Product ) {
			return;
		}

		wp_enqueue_style(
			'ea-frontend',
			EA_PLUGIN_URL . 'assets/css/ea-frontend.css',
			array(),
			EA_VERSION
		);

		wp_enqueue_script(
			'ea-frontend',
			EA_PLUGIN_URL . 'assets/js/ea-frontend.js',
			array( 'jquery' ),
			EA_VERSION,
			true
		);

		wp_localize_script(
			'ea-frontend',
			'EA_Data',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'pollAction' => 'ea_auction_state',
				'pollEvery'  => (int) apply_filters( 'ea_poll_interval_seconds', 7 ),
				'confirmBid' => __( 'Сигурни ли сте, че искате да подадете тази оферта? Наддаването е обвързващо.', 'energy-auctions' ),
				'i18n'       => array(
					'ended'   => __( 'Търгът приключи', 'energy-auctions' ),
					'days'    => __( 'д', 'energy-auctions' ),
					'hours'   => __( 'ч', 'energy-auctions' ),
					'minutes' => __( 'м', 'energy-auctions' ),
					'seconds' => __( 'с', 'energy-auctions' ),
				),
			)
		);
	}

	/**
	 * Рендира кутията за наддаване.
	 */
	public function render_bidding_box(): void {
		global $product;
		if ( ! $product instanceof Auction_Product ) {
			return;
		}

		$auction_id = $product->get_id();
		$status     = $product->get_ea_status();
		$end_date   = $product->get_ea_end_date();
		$start_date = $product->get_ea_start_date();
		$highest    = Bids::get_highest( $auction_id );
		$count      = Bids::get_count( $auction_id );
		$min_next   = Bids::get_min_next_bid( $product );
		$buy_now    = (float) $product->get_ea_buy_now_price();
		$user_id    = get_current_user_id();
		$is_owner   = $user_id && (int) get_post_field( 'post_author', $auction_id ) === $user_id;

		$current = $highest ? (float) $highest->amount : (float) $product->get_ea_start_price();

		echo '<div class="ea-auction" data-auction-id="' . esc_attr( (string) $auction_id ) . '" data-status="' . esc_attr( $status ) . '" data-end="' . esc_attr( Time::to_iso( $end_date ) ) . '">';

		// --- Статус банер ---
		$this->render_status_banner( $status, $start_date );

		// --- Текуща цена ---
		echo '<div class="ea-current">';
		echo '<span class="ea-current__label">' . ( $highest ? esc_html__( 'Текуща оферта', 'energy-auctions' ) : esc_html__( 'Начална цена', 'energy-auctions' ) ) . '</span>';
		echo '<span class="ea-current__amount" data-ea-current>' . wp_kses_post( wc_price( $current ) ) . '</span>';
		echo '<span class="ea-current__count" data-ea-count>' . esc_html( sprintf( /* translators: %d count */ _n( '%d оферта', '%d оферти', $count, 'energy-auctions' ), $count ) ) . '</span>';
		echo '</div>';

		// --- Countdown ---
		if ( in_array( $status, array( 'active', 'scheduled' ), true ) ) {
			echo '<div class="ea-countdown" data-ea-countdown>';
			echo '<span class="ea-countdown__label">' . ( 'scheduled' === $status ? esc_html__( 'Започва на', 'energy-auctions' ) : esc_html__( 'Оставащо време', 'energy-auctions' ) ) . '</span>';
			echo '<span class="ea-countdown__value" data-ea-timer>' . esc_html( Time::format( 'scheduled' === $status ? $start_date : $end_date ) ) . '</span>';
			echo '</div>';
		}

		// --- Форма за наддаване ---
		if ( 'active' === $status ) {
			$this->render_bid_form( $auction_id, $min_next, $buy_now, $user_id, $is_owner );
		} else {
			$this->render_closed_notice( $status, $product );
		}

		// --- История ---
		$this->render_history( $auction_id );

		echo '</div>'; // .ea-auction
	}

	/**
	 * Статус банер.
	 *
	 * @param string $status     Статус.
	 * @param string $start_date Начало.
	 */
	private function render_status_banner( string $status, string $start_date ): void {
		$labels = array(
			'scheduled' => __( 'Предстоящ търг', 'energy-auctions' ),
			'active'    => __( 'Активен търг', 'energy-auctions' ),
			'ended'     => __( 'Търгът приключи', 'energy-auctions' ),
			'sold'      => __( 'Продаден', 'energy-auctions' ),
			'unsold'    => __( 'Непродаден', 'energy-auctions' ),
		);
		$label = $labels[ $status ] ?? $status;
		echo '<div class="ea-badge ea-badge--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</div>';
	}

	/**
	 * Форма за наддаване + „Купи сега“.
	 *
	 * @param int   $auction_id ID.
	 * @param float $min_next   Мин. следваща оферта.
	 * @param float $buy_now    Цена „купи сега“ (0 ако няма).
	 * @param int   $user_id    Текущ потребител.
	 * @param bool  $is_owner   Дали е собственик.
	 */
	private function render_bid_form( int $auction_id, float $min_next, float $buy_now, int $user_id, bool $is_owner ): void {
		if ( ! $user_id ) {
			echo '<p class="ea-login-notice">';
			printf(
				/* translators: %s login url */
				wp_kses_post( __( 'За да наддавате, <a href="%s">влезте в профила си</a>.', 'energy-auctions' ) ),
				esc_url( wp_login_url( get_permalink( $auction_id ) ) )
			);
			echo '</p>';
			return;
		}

		if ( $is_owner ) {
			echo '<p class="ea-owner-notice">' . esc_html__( 'Това е вашият търг — не можете да наддавате.', 'energy-auctions' ) . '</p>';
			return;
		}

		$action = esc_url( admin_url( 'admin-post.php' ) );

		echo '<form class="ea-bid-form" method="post" action="' . $action . '">';
		echo '<input type="hidden" name="action" value="ea_place_bid" />';
		echo '<input type="hidden" name="ea_auction_id" value="' . esc_attr( (string) $auction_id ) . '" />';
		wp_nonce_field( 'ea_place_bid_' . $auction_id, 'ea_bid_nonce' );

		echo '<label class="ea-bid-form__label" for="ea_bid_amount">';
		printf(
			/* translators: %s minimal next bid */
			esc_html__( 'Вашата оферта (мин. %s)', 'energy-auctions' ),
			wp_kses_post( wc_price( $min_next ) )
		);
		echo '</label>';

		echo '<div class="ea-bid-form__row">';
		printf(
			'<input type="number" step="0.01" min="%1$s" name="ea_bid_amount" id="ea_bid_amount" value="%1$s" data-ea-min="%1$s" required />',
			esc_attr( (string) $min_next )
		);
		echo '<button type="submit" class="button alt ea-bid-form__submit">' . esc_html__( 'Наддай', 'energy-auctions' ) . '</button>';
		echo '</div>';
		echo '</form>';

		// „Купи сега“.
		if ( $buy_now > 0 ) {
			echo '<form class="ea-buynow-form" method="post" action="' . $action . '">';
			echo '<input type="hidden" name="action" value="ea_buy_now" />';
			echo '<input type="hidden" name="ea_auction_id" value="' . esc_attr( (string) $auction_id ) . '" />';
			wp_nonce_field( 'ea_buy_now_' . $auction_id, 'ea_buy_now_nonce' );
			echo '<button type="submit" class="button ea-buynow-form__submit">';
			printf(
				/* translators: %s buy now price */
				esc_html__( 'Купи сега за %s', 'energy-auctions' ),
				wp_kses_post( wc_price( $buy_now ) )
			);
			echo '</button>';
			echo '</form>';
		}
	}

	/**
	 * Съобщение при затворен търг.
	 *
	 * @param string          $status  Статус.
	 * @param Auction_Product $product Продукт.
	 */
	private function render_closed_notice( string $status, Auction_Product $product ): void {
		if ( 'scheduled' === $status ) {
			echo '<p class="ea-closed-notice">' . esc_html__( 'Търгът още не е започнал. Заповядайте отново скоро.', 'energy-auctions' ) . '</p>';
			return;
		}
		if ( 'sold' === $status ) {
			echo '<p class="ea-closed-notice ea-closed-notice--sold">' . esc_html__( 'Този търг е продаден.', 'energy-auctions' ) . '</p>';
			return;
		}
		if ( 'unsold' === $status ) {
			echo '<p class="ea-closed-notice">' . esc_html__( 'Търгът приключи без достигнат резерв.', 'energy-auctions' ) . '</p>';
			return;
		}
		echo '<p class="ea-closed-notice">' . esc_html__( 'Търгът приключи.', 'energy-auctions' ) . '</p>';
	}

	/**
	 * История на офертите (с маскирани имена).
	 *
	 * @param int $auction_id ID.
	 */
	private function render_history( int $auction_id ): void {
		$history = Bids::get_history( $auction_id, 20 );
		echo '<div class="ea-history" data-ea-history>';
		echo '<h3 class="ea-history__title">' . esc_html__( 'История на офертите', 'energy-auctions' ) . '</h3>';

		if ( empty( $history ) ) {
			echo '<p class="ea-history__empty">' . esc_html__( 'Все още няма оферти. Бъдете първи!', 'energy-auctions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="ea-history__table"><thead><tr>';
		echo '<th>' . esc_html__( 'Наддавач', 'energy-auctions' ) . '</th>';
		echo '<th>' . esc_html__( 'Оферта', 'energy-auctions' ) . '</th>';
		echo '<th>' . esc_html__( 'Кога', 'energy-auctions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $history as $bid ) {
			echo '<tr>';
			echo '<td>' . esc_html( Bids::masked_bidder_name( (int) $bid->user_id ) ) . '</td>';
			echo '<td>' . wp_kses_post( wc_price( (float) $bid->amount ) ) . '</td>';
			echo '<td>' . esc_html( Time::format( $bid->created_at ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
