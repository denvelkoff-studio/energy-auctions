<?php
/**
 * Шорткодове: списък с активни търгове (за меню „Аукцион“).
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
 * Shortcodes.
 */
class Shortcodes {

	/**
	 * Единствена инстанция.
	 *
	 * @var Shortcodes|null
	 */
	private static ?Shortcodes $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Shortcodes {
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
		add_shortcode( 'ea_active_auctions', array( $this, 'render_active_auctions' ) );
	}

	/**
	 * Рендира мрежа с търгове.
	 *
	 * @param array<string,mixed>|string $atts Атрибути.
	 * @return string
	 */
	public function render_active_auctions( $atts ): string {
		$atts = shortcode_atts(
			array(
				'status'  => 'active',
				'limit'   => 12,
				'orderby' => 'end',
			),
			$atts,
			'ea_active_auctions'
		);

		$statuses = array_filter( array_map( 'trim', explode( ',', (string) $atts['status'] ) ) );

		$query = new \WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => (int) $atts['limit'],
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => Plugin::PRODUCT_TYPE,
					),
				),
				'meta_query'     => array(
					array(
						'key'     => '_ea_status',
						'value'   => $statuses,
						'compare' => 'IN',
					),
				),
				'meta_key'       => '_ea_end_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);

		if ( ! $query->have_posts() ) {
			return '<p class="ea-empty">' . esc_html__( 'В момента няма активни търгове.', 'energy-auctions' ) . '</p>';
		}

		// Гарантираме, че асетите са заредени (страницата може да не е продуктова).
		wp_enqueue_style( 'ea-frontend', EA_PLUGIN_URL . 'assets/css/ea-frontend.css', array(), EA_VERSION );
		wp_enqueue_script( 'ea-frontend', EA_PLUGIN_URL . 'assets/js/ea-frontend.js', array( 'jquery' ), EA_VERSION, true );
		wp_localize_script(
			'ea-frontend',
			'EA_Data',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'pollAction' => 'ea_auction_state',
				'pollEvery'  => (int) apply_filters( 'ea_poll_interval_seconds', 7 ),
				'i18n'       => array(
					'ended'   => __( 'Приключи', 'energy-auctions' ),
					'days'    => __( 'д', 'energy-auctions' ),
					'hours'   => __( 'ч', 'energy-auctions' ),
					'minutes' => __( 'м', 'energy-auctions' ),
					'seconds' => __( 'с', 'energy-auctions' ),
				),
			)
		);

		ob_start();
		echo '<div class="ea-grid">';

		while ( $query->have_posts() ) {
			$query->the_post();
			$product = wc_get_product( get_the_ID() );
			if ( ! $product instanceof Auction_Product ) {
				continue;
			}
			$this->render_card( $product );
		}

		echo '</div>';
		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	/**
	 * Рендира една карта.
	 *
	 * @param Auction_Product $product Продукт.
	 */
	private function render_card( Auction_Product $product ): void {
		$id      = $product->get_id();
		$highest = Bids::get_highest( $id );
		$current = $highest ? (float) $highest->amount : (float) $product->get_ea_start_price();
		$status  = $product->get_ea_status();
		$end     = $product->get_ea_end_date();

		echo '<a class="ea-card ea-auction" href="' . esc_url( (string) get_permalink( $id ) ) . '" data-auction-id="' . esc_attr( (string) $id ) . '" data-status="' . esc_attr( $status ) . '" data-end="' . esc_attr( Time::to_iso( $end ) ) . '">';

		if ( has_post_thumbnail( $id ) ) {
			echo '<span class="ea-card__thumb">' . get_the_post_thumbnail( $id, 'medium' ) . '</span>';
		}

		echo '<span class="ea-card__body">';
		echo '<span class="ea-badge ea-badge--' . esc_attr( $status ) . '">' . esc_html( $this->status_label( $status ) ) . '</span>';
		echo '<span class="ea-card__title">' . esc_html( $product->get_name() ) . '</span>';
		echo '<span class="ea-card__price" data-ea-current>' . wp_kses_post( wc_price( $current ) ) . '</span>';
		if ( 'active' === $status ) {
			echo '<span class="ea-card__meta">' . esc_html__( 'Остава:', 'energy-auctions' ) . ' <span data-ea-timer>' . esc_html( Time::format( $end ) ) . '</span></span>';
		}
		echo '</span>';

		echo '</a>';
	}

	/**
	 * Етикет за статус.
	 *
	 * @param string $status Статус.
	 * @return string
	 */
	private function status_label( string $status ): string {
		$labels = array(
			'scheduled' => __( 'Предстоящ', 'energy-auctions' ),
			'active'    => __( 'Активен', 'energy-auctions' ),
			'ended'     => __( 'Приключи', 'energy-auctions' ),
			'sold'      => __( 'Продаден', 'energy-auctions' ),
			'unsold'    => __( 'Непродаден', 'energy-auctions' ),
		);
		return $labels[ $status ] ?? $status;
	}
}
