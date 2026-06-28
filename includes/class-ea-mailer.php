<?php
/**
 * Имейли (на български, преводими). Неблокиращи — през WC mailer/wp_mail.
 *
 * @package EnergyAuctions
 */

declare( strict_types=1 );

namespace EnergyAuctions;

defined( 'ABSPATH' ) || exit;

/**
 * Mailer.
 */
class Mailer {

	/**
	 * Единствена инстанция.
	 *
	 * @var Mailer|null
	 */
	private static ?Mailer $instance = null;

	/**
	 * Връща инстанцията.
	 */
	public static function instance(): Mailer {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Закача hook-овете към lifecycle събитията.
	 */
	private function hooks(): void {
		add_action( 'ea_bid_placed', array( $this, 'on_bid_placed' ), 10, 4 );
		add_action( 'ea_auction_sold', array( $this, 'on_sold' ), 10, 5 );
		add_action( 'ea_auction_unsold', array( $this, 'on_unsold' ), 10, 1 );
		add_action( 'ea_auction_unpaid', array( $this, 'on_unpaid' ), 10, 2 );
	}

	/**
	 * „Наддаден си“ — до предишния водач.
	 *
	 * @param int   $auction_id ID.
	 * @param int   $user_id    Новият водач.
	 * @param float $amount     Новата оферта.
	 * @param int   $prev_uid   Предишният водач.
	 */
	public function on_bid_placed( int $auction_id, int $user_id, float $amount, int $prev_uid ): void {
		if ( ! $prev_uid || $prev_uid === $user_id ) {
			return;
		}
		$user = get_userdata( $prev_uid );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$product = wc_get_product( $auction_id );
		if ( ! $product ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s product name */
			__( 'Някой наддаде над вас: %s', 'energy-auctions' ),
			$product->get_name()
		);

		$body  = '<p>' . esc_html( sprintf( /* translators: %s name */ __( 'Здравейте, %s,', 'energy-auctions' ), $user->display_name ) ) . '</p>';
		$body .= '<p>' . esc_html( sprintf( /* translators: %s product */ __( 'Вече не сте начело в търга „%s“.', 'energy-auctions' ), $product->get_name() ) ) . '</p>';
		$body .= '<p>' . sprintf( /* translators: %s amount */ esc_html__( 'Нова водеща оферта: %s', 'energy-auctions' ), wp_kses_post( wc_price( $amount ) ) ) . '</p>';
		$body .= $this->button( get_permalink( $auction_id ), __( 'Наддай отново', 'energy-auctions' ) );

		$this->send( $user->user_email, $subject, $body );
	}

	/**
	 * „Спечели“ — до победителя.
	 *
	 * @param int    $auction_id ID.
	 * @param int    $winner_id  Победител.
	 * @param float  $amount     Печеливша сума.
	 * @param string $via        auction|buy_now.
	 * @param int    $order_id   ID на поръчката (опц.).
	 */
	public function on_sold( int $auction_id, int $winner_id, float $amount, string $via = 'auction', int $order_id = 0 ): void {
		$user = get_userdata( $winner_id );
		$product = wc_get_product( $auction_id );
		if ( ! $product ) {
			return;
		}

		// Имейл до победителя.
		if ( $user && is_email( $user->user_email ) ) {
			$subject = sprintf( /* translators: %s product */ __( 'Поздравления! Спечелихте: %s', 'energy-auctions' ), $product->get_name() );
			$body    = '<p>' . esc_html( sprintf( /* translators: %s name */ __( 'Здравейте, %s,', 'energy-auctions' ), $user->display_name ) ) . '</p>';
			$body   .= '<p>' . esc_html( sprintf( /* translators: %s product */ __( 'Спечелихте търга „%s“!', 'energy-auctions' ), $product->get_name() ) ) . '</p>';
			$body   .= '<p>' . sprintf( /* translators: %s amount */ esc_html__( 'Печеливша сума: %s', 'energy-auctions' ), wp_kses_post( wc_price( $amount ) ) ) . '</p>';

			$pay_url = '';
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$pay_url = $order->get_checkout_payment_url();
				}
			}
			if ( $pay_url ) {
				$body .= '<p>' . esc_html__( 'Моля, завършете плащането, за да получите продукта:', 'energy-auctions' ) . '</p>';
				$body .= $this->button( $pay_url, __( 'Плати сега', 'energy-auctions' ) );
			}

			$this->send( $user->user_email, $subject, $body );
		}

		// Имейл „търгът приключи“ до собственика.
		$this->notify_owner_ended( $auction_id, $product, true, $amount );
	}

	/**
	 * „Търгът приключи“ без продажба — до собственика.
	 *
	 * @param int $auction_id ID.
	 */
	public function on_unsold( int $auction_id ): void {
		$product = wc_get_product( $auction_id );
		if ( ! $product ) {
			return;
		}
		$this->notify_owner_ended( $auction_id, $product, false, 0.0 );
	}

	/**
	 * „Неплатена поръчка“ — до победителя.
	 *
	 * @param int $auction_id ID.
	 * @param int $order_id   Поръчка.
	 */
	public function on_unpaid( int $auction_id, int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		$product = wc_get_product( $auction_id );
		$name    = $product ? $product->get_name() : '#' . $auction_id;

		$subject = __( 'Срокът за плащане изтече', 'energy-auctions' );
		$body    = '<p>' . esc_html( sprintf( /* translators: %s product */ __( 'Поръчката ви за „%s“ не беше платена в срок.', 'energy-auctions' ), $name ) ) . '</p>';
		$body   .= '<p>' . esc_html__( 'Ако все още желаете продукта, свържете се с нас.', 'energy-auctions' ) . '</p>';
		$pay_url = $order->get_checkout_payment_url();
		if ( $pay_url ) {
			$body .= $this->button( $pay_url, __( 'Плати сега', 'energy-auctions' ) );
		}

		$this->send( $email, $subject, $body );
	}

	/**
	 * Имейл до собственика, че търгът е приключил.
	 *
	 * @param int     $auction_id ID.
	 * @param \WC_Product $product Продукт.
	 * @param bool    $sold       Продаден ли е.
	 * @param float   $amount     Сума при продажба.
	 */
	private function notify_owner_ended( int $auction_id, \WC_Product $product, bool $sold, float $amount ): void {
		$author_id = (int) get_post_field( 'post_author', $auction_id );
		$owner     = $author_id ? get_userdata( $author_id ) : null;
		if ( ! $owner || ! is_email( $owner->user_email ) ) {
			return;
		}

		$subject = sprintf( /* translators: %s product */ __( 'Търгът приключи: %s', 'energy-auctions' ), $product->get_name() );
		$body    = '<p>' . esc_html( sprintf( /* translators: %s product */ __( 'Търгът „%s“ приключи.', 'energy-auctions' ), $product->get_name() ) ) . '</p>';
		if ( $sold ) {
			$body .= '<p>' . sprintf( /* translators: %s amount */ esc_html__( 'Резултат: ПРОДАДЕН за %s.', 'energy-auctions' ), wp_kses_post( wc_price( $amount ) ) ) . '</p>';
		} else {
			$body .= '<p>' . esc_html__( 'Резултат: непродаден (без оферти или под резерва).', 'energy-auctions' ) . '</p>';
		}

		$this->send( $owner->user_email, $subject, $body );
	}

	/**
	 * Бутон в имейл.
	 *
	 * @param string $url   URL.
	 * @param string $label Текст.
	 * @return string
	 */
	private function button( string $url, string $label ): string {
		if ( ! $url ) {
			return '';
		}
		return '<p><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;background:#4ea1ff;color:#061018;font-weight:600;text-decoration:none;border-radius:8px;">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Изпраща HTML имейл през WC mailer (стилизиран), с fallback към wp_mail.
	 *
	 * @param string $to      Получател.
	 * @param string $subject Тема.
	 * @param string $body    HTML тяло.
	 */
	private function send( string $to, string $subject, string $body ): void {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$mailer  = WC()->mailer();
			$wrapped = $mailer->wrap_message( $subject, $body );
			$mailer->send( $to, $subject, $wrapped, $headers );
			return;
		}

		wp_mail( $to, $subject, $body, $headers ); // phpcs:ignore
	}
}
