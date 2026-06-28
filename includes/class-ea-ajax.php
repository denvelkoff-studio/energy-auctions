<?php
/**
 * AJAX endpoint-и за real-time обновяване.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

use EnergyAuctions\Product\Auction_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Ajax.
 */
class Ajax {

	/**
	 * Единствена инстанция.
	 *
	 * @var Ajax|null
	 */
	private static ?Ajax $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Ajax {
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
		add_action( 'wp_ajax_ea_auction_state', array( $this, 'auction_state' ) );
		add_action( 'wp_ajax_nopriv_ea_auction_state', array( $this, 'auction_state' ) );
	}

	/**
	 * Връща текущото състояние на търг (за polling).
	 */
	public function auction_state(): void {
		$auction_id = isset( $_GET['auction_id'] ) ? absint( $_GET['auction_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		$product = $auction_id ? wc_get_product( $auction_id ) : null;
		if ( ! $product instanceof Auction_Product ) {
			wp_send_json_error( array( 'message' => 'not_found' ), 404 );
		}

		$highest = Bids::get_highest( $auction_id );
		$count   = Bids::get_count( $auction_id );
		$current = $highest ? (float) $highest->amount : (float) $product->get_ea_start_price();

		wp_send_json_success(
			array(
				'status'       => $product->get_ea_status(),
				'current_html' => wc_price( $current ),
				'count_text'   => sprintf( /* translators: %d count */ _n( '%d оферта', '%d оферти', $count, 'energy-auctions' ), $count ),
				'min_next'     => Bids::get_min_next_bid( $product ),
				'end_iso'      => Time::to_iso( $product->get_ea_end_date() ),
			)
		);
	}
}
