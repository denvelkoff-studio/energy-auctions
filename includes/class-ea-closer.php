<?php
/**
 * Затваряне на търгове: активиране, определяне на победител, поръчка,
 * проверка за неплатени и безопасно затваряне при изтрит продукт.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

use EnergyAuctions\Product\Auction_Product;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Closer.
 */
class Closer {

	/**
	 * Единствена инстанция.
	 *
	 * @var Closer|null
	 */
	private static ?Closer $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Closer {
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
		// Реалният системен cron извиква това action (виж HANDOFF.md / README).
		add_action( 'ea_close_auctions_cron', array( $this, 'run' ) );

		// Резервен WP-cron (на всеки 5 мин.) — не е основният механизъм.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule_fallback' ) );
		add_action( 'ea_close_auctions_fallback', array( $this, 'run' ) );

		// Фаза 5: безопасно затваряне при кош/триене на продукта.
		add_action( 'wp_trash_post', array( $this, 'on_trash' ) );
		add_action( 'before_delete_post', array( $this, 'on_trash' ) );
	}

	/**
	 * Брой дни за плащане преди маркиране „неплатен“.
	 */
	public static function payment_due_days(): int {
		return (int) apply_filters( 'ea_payment_due_days', 3 );
	}

	/**
	 * Добавя 5-минутен интервал за резервния WP-cron.
	 *
	 * @param array<string,array<string,mixed>> $schedules Графици.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules['ea_five_minutes'] ) ) {
			$schedules['ea_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'На всеки 5 минути (Energy Auctions)', 'energy-auctions' ),
			);
		}
		return $schedules;
	}

	/**
	 * Планира резервния WP-cron, ако не е планиран.
	 */
	public function maybe_schedule_fallback(): void {
		if ( ! wp_next_scheduled( 'ea_close_auctions_fallback' ) ) {
			wp_schedule_event( time() + 60, 'ea_five_minutes', 'ea_close_auctions_fallback' );
		}
	}

	/**
	 * Основен цикъл: активира започнали, затваря изтекли, проверява неплатени.
	 */
	public function run(): void {
		$this->activate_due();
		$this->close_expired();
		$this->sweep_unpaid();
	}

	/**
	 * Активира scheduled търгове, чието начало е минало.
	 */
	private function activate_due(): void {
		global $wpdb;
		$now = Time::now_mysql();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm_status.post_id
				 FROM {$wpdb->postmeta} pm_status
				 INNER JOIN {$wpdb->postmeta} pm_start ON pm_start.post_id = pm_status.post_id AND pm_start.meta_key = '_ea_start_date'
				 INNER JOIN {$wpdb->postmeta} pm_end ON pm_end.post_id = pm_status.post_id AND pm_end.meta_key = '_ea_end_date'
				 WHERE pm_status.meta_key = '_ea_status' AND pm_status.meta_value = 'scheduled'
				 AND pm_start.meta_value <> '' AND pm_start.meta_value <= %s
				 AND pm_end.meta_value > %s",
				$now,
				$now
			)
		);

		foreach ( (array) $ids as $id ) {
			$product = wc_get_product( (int) $id );
			if ( $product instanceof Auction_Product ) {
				$product->set_ea_status( 'active' );
				$product->save();
				do_action( 'ea_auction_activated', (int) $id );
			}
		}
	}

	/**
	 * Затваря активни търгове, чийто край е минал.
	 */
	private function close_expired(): void {
		global $wpdb;
		$now = Time::now_mysql();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm_status.post_id
				 FROM {$wpdb->postmeta} pm_status
				 INNER JOIN {$wpdb->postmeta} pm_end ON pm_end.post_id = pm_status.post_id AND pm_end.meta_key = '_ea_end_date'
				 WHERE pm_status.meta_key = '_ea_status' AND pm_status.meta_value = 'active'
				 AND pm_end.meta_value <> '' AND pm_end.meta_value <= %s",
				$now
			)
		);

		foreach ( (array) $ids as $id ) {
			$this->close_auction( (int) $id );
		}
	}

	/**
	 * Затваря конкретен търг — определя победител и генерира поръчка.
	 *
	 * @param int $auction_id ID.
	 */
	public function close_auction( int $auction_id ): void {
		$product = wc_get_product( $auction_id );
		if ( ! $product instanceof Auction_Product ) {
			return;
		}

		// Двойна проверка, че още е активен (срещу състезание с друг процес).
		if ( 'active' !== $product->get_ea_status() ) {
			return;
		}

		$highest = Bids::get_highest( $auction_id );
		$reserve = $product->get_ea_reserve_price();
		$reserve = '' === $reserve ? null : (float) $reserve;

		// Без оферти, или под резерва → unsold.
		if ( ! $highest || ( null !== $reserve && (float) $highest->amount < $reserve ) ) {
			$product->set_ea_status( 'unsold' );
			$product->save();
			do_action( 'ea_auction_unsold', $auction_id );
			return;
		}

		$winner_id      = (int) $highest->user_id;
		$winning_amount = (float) $highest->amount;

		$product->set_ea_status( 'sold' );
		$product->update_meta_data( '_ea_winner_id', $winner_id );
		$product->update_meta_data( '_ea_winning_amount', wc_format_decimal( $winning_amount ) );
		$product->save();

		// Генерираме чакаща поръчка за победителя (native плащане).
		$order_id = $this->create_winner_order( $product, $winner_id, $winning_amount );

		do_action( 'ea_auction_sold', $auction_id, $winner_id, $winning_amount, 'auction', $order_id );
	}

	/**
	 * Създава чакаща WooCommerce поръчка за победителя.
	 *
	 * @param Auction_Product $product Продукт.
	 * @param int             $winner_id Победител.
	 * @param float           $amount  Печеливша сума.
	 * @return int ID на поръчката (0 при неуспех).
	 */
	private function create_winner_order( Auction_Product $product, int $winner_id, float $amount ): int {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return 0;
		}

		try {
			$order = wc_create_order( array( 'customer_id' => $winner_id ) );
			if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
				return 0;
			}

			$order->add_product(
				$product,
				1,
				array(
					'subtotal' => $amount,
					'total'    => $amount,
				)
			);

			// Адрес от профила на победителя.
			$customer = new \WC_Customer( $winner_id );
			$order->set_address( $this->address_from_customer( $customer, 'billing' ), 'billing' );
			$order->set_address( $this->address_from_customer( $customer, 'shipping' ), 'shipping' );

			$order->update_meta_data( '_ea_auction_id', $product->get_id() );
			$order->update_meta_data( '_ea_due_date', Time::add_days( Time::now_mysql(), self::payment_due_days() ) );

			$order->calculate_totals();
			$order->set_status( 'pending', __( 'Чакащо плащане — победител в търг.', 'energy-auctions' ) );
			$order->add_order_note( sprintf( /* translators: %s product name */ __( 'Поръчка от спечелен търг: %s', 'energy-auctions' ), $product->get_name() ) );
			$order->save();

			$product->update_meta_data( '_ea_order_id', $order->get_id() );
			$product->save();

			return $order->get_id();
		} catch ( \Throwable $e ) {
			// Без fatal — логваме само.
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'EA order creation failed: ' . $e->getMessage(), array( 'source' => 'energy-auctions' ) );
			}
			return 0;
		}
	}

	/**
	 * Извлича адрес от WC_Customer.
	 *
	 * @param \WC_Customer $customer Клиент.
	 * @param string       $type     billing|shipping.
	 * @return array<string,string>
	 */
	private function address_from_customer( \WC_Customer $customer, string $type ): array {
		$prefix = $type . '_';
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );
		$out    = array();
		foreach ( $fields as $field ) {
			$getter        = 'get_' . $prefix . $field;
			$out[ $field ] = method_exists( $customer, $getter ) ? (string) $customer->$getter() : '';
		}
		if ( 'billing' === $type ) {
			$out['email'] = $customer->get_billing_email();
			$out['phone'] = $customer->get_billing_phone();
		}
		return $out;
	}

	/**
	 * Маркира неплатени поръчки от търгове след изтекъл срок.
	 */
	private function sweep_unpaid(): void {
		$orders = wc_get_orders(
			array(
				'status'     => array( 'pending', 'on-hold', 'failed' ),
				'limit'      => 50,
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_ea_auction_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$now = Time::now_ts();

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( $order->get_meta( '_ea_unpaid_flagged' ) ) {
				continue;
			}
			$due = (string) $order->get_meta( '_ea_due_date' );
			if ( '' === $due || Time::to_ts( $due ) > $now ) {
				continue;
			}

			$order->update_meta_data( '_ea_unpaid_flagged', '1' );
			$order->add_order_note( __( 'Срокът за плащане изтече — поръчката е маркирана като неплатена.', 'energy-auctions' ) );
			$order->save();

			do_action( 'ea_auction_unpaid', (int) $order->get_meta( '_ea_auction_id' ), $order->get_id() );
		}
	}

	/**
	 * Безопасно затваряне при кош/триене на продукт-търг (Фаза 5).
	 *
	 * @param int $post_id ID.
	 */
	public function on_trash( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof Auction_Product ) {
			return;
		}
		if ( in_array( $product->get_ea_status(), array( 'active', 'scheduled' ), true ) ) {
			$product->set_ea_status( 'unsold' );
			$product->save();
			do_action( 'ea_auction_cancelled', $post_id );
		}
	}
}
