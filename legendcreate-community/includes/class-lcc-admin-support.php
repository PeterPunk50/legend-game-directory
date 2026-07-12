<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin-only support view: consolidated member details (profile, membership,
 * orders/payments, current cart, squads) so staff can help a member without
 * hunting across screens. Replaces the useless default "author archive" link.
 */
final class LCC_Admin_Support {

	public function __construct() {
		add_filter( 'user_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	public function add_row_action( $actions, $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return $actions; }
		unset( $actions['view'] ); // the public author-archive link is useless for members.
		$url = add_query_arg( array( 'page' => 'lcc-member-detail', 'user_id' => $user->ID ), admin_url( 'users.php' ) );
		$actions['lcc_support_view'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Support Details', 'legendcreate-community' ) . '</a>';
		return $actions;
	}

	public function add_page() {
		add_users_page(
			__( 'Member Support Details', 'legendcreate-community' ),
			__( 'Member Details', 'legendcreate-community' ),
			'manage_woocommerce',
			'lcc-member-detail',
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'legendcreate-community' ) );
		}
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;

		echo '<div class="wrap"><h1>' . esc_html__( 'Member Support Details', 'legendcreate-community' ) . '</h1>';

		if ( ! $user ) {
			echo '<p>' . esc_html__( 'Pick a member from the Users list using "Support Details".', 'legendcreate-community' ) . '</p></div>';
			return;
		}

		$this->section_account( $user );
		$this->section_profile( $user->ID );
		$this->section_membership( $user->ID );
		$this->section_orders( $user->ID );
		$this->section_cart( $user->ID );
		$this->section_squads( $user->ID );

		echo '</div>';
	}

	private function table_open() {
		echo '<table class="widefat striped" style="max-width:900px;margin-bottom:2em"><tbody>';
	}

	private function row( $label, $value ) {
		echo '<tr><th style="width:220px;text-align:left">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function section_account( $user ) {
		echo '<h2>' . esc_html__( 'Account', 'legendcreate-community' ) . '</h2>';
		$this->table_open();
		$verified = ( class_exists( 'LCC_Registration' ) && method_exists( 'LCC_Registration', 'is_verified' ) )
			? ( LCC_Registration::is_verified( $user->ID ) ? __( 'Yes', 'legendcreate-community' ) : __( 'No', 'legendcreate-community' ) )
			: __( 'N/A', 'legendcreate-community' );
		$this->row( __( 'Username', 'legendcreate-community' ), esc_html( $user->user_login ) );
		$this->row( __( 'Display name', 'legendcreate-community' ), esc_html( $user->display_name ) );
		$this->row( __( 'Email', 'legendcreate-community' ), esc_html( $user->user_email ) );
		$this->row( __( 'Registered', 'legendcreate-community' ), esc_html( mysql2date( get_option( 'date_format' ), $user->user_registered ) ) );
		$this->row( __( 'Role', 'legendcreate-community' ), esc_html( implode( ', ', $user->roles ) ) );
		$this->row( __( 'Email verified', 'legendcreate-community' ), esc_html( $verified ) );
		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html__( 'Edit User', 'legendcreate-community' ) . '</a></p>';
	}

	private function section_profile( $user_id ) {
		echo '<h2>' . esc_html__( 'Community Profile', 'legendcreate-community' ) . '</h2>';
		if ( ! class_exists( 'LCC_Profiles' ) ) { echo '<p>&mdash;</p>'; return; }
		$p = LCC_Profiles::get_profile( $user_id );
		$this->table_open();
		$this->row( __( 'Bio', 'legendcreate-community' ), esc_html( $p['bio'] ) );
		$this->row( __( 'Favourite games', 'legendcreate-community' ), esc_html( implode( ', ', (array) $p['fav_games'] ) ) );
		$this->row( __( 'Platforms', 'legendcreate-community' ), esc_html( implode( ', ', (array) $p['platforms'] ) ) );
		$this->row( __( 'Interests', 'legendcreate-community' ), esc_html( implode( ', ', (array) $p['interests'] ) ) );
		$this->row( __( 'Public profile', 'legendcreate-community' ), $p['public'] ? esc_html__( 'Yes', 'legendcreate-community' ) : esc_html__( 'No', 'legendcreate-community' ) );
		$this->row( __( 'Onboarded', 'legendcreate-community' ), $p['onboarded'] ? esc_html__( 'Yes', 'legendcreate-community' ) : esc_html__( 'No', 'legendcreate-community' ) );
		echo '</tbody></table>';
	}

	private function section_membership( $user_id ) {
		echo '<h2>' . esc_html__( 'Membership', 'legendcreate-community' ) . '</h2>';
		if ( ! class_exists( 'LCC_Memberships' ) ) { echo '<p>&mdash;</p>'; return; }
		$is_premium = LCC_Memberships::is_premium( $user_id );
		$until      = LCC_Memberships::premium_until( $user_id );
		$tier       = method_exists( 'LCC_Memberships', 'current_tier' ) ? LCC_Memberships::current_tier( $user_id ) : '';
		$this->table_open();
		$this->row( __( 'Status', 'legendcreate-community' ), $is_premium ? '<strong>' . esc_html__( 'Legend Premium', 'legendcreate-community' ) . '</strong>' : esc_html__( 'Free Member', 'legendcreate-community' ) );
		if ( $is_premium && $until ) {
			$this->row( __( 'Premium until', 'legendcreate-community' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $until . ' UTC' ) ) ) );
		}
		if ( $is_premium ) {
			$this->row( __( 'Plan tier', 'legendcreate-community' ), esc_html( $tier ? $tier : __( 'unknown (pre-tier order)', 'legendcreate-community' ) ) );
		}
		echo '</tbody></table>';
	}

	private function section_orders( $user_id ) {
		echo '<h2>' . esc_html__( 'Orders & Payments', 'legendcreate-community' ) . '</h2>';
		if ( ! function_exists( 'wc_get_orders' ) ) { echo '<p>' . esc_html__( 'WooCommerce is not active.', 'legendcreate-community' ) . '</p>'; return; }
		$orders = wc_get_orders( array( 'customer_id' => $user_id, 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( empty( $orders ) ) { echo '<p>' . esc_html__( 'No orders found for this member.', 'legendcreate-community' ) . '</p>'; return; }
		echo '<table class="widefat striped" style="max-width:1000px;margin-bottom:2em"><thead><tr>'
			. '<th>' . esc_html__( 'Order', 'legendcreate-community' ) . '</th>'
			. '<th>' . esc_html__( 'Date', 'legendcreate-community' ) . '</th>'
			. '<th>' . esc_html__( 'Status', 'legendcreate-community' ) . '</th>'
			. '<th>' . esc_html__( 'Total', 'legendcreate-community' ) . '</th>'
			. '<th>' . esc_html__( 'Payment method', 'legendcreate-community' ) . '</th>'
			. '<th>' . esc_html__( 'Items', 'legendcreate-community' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $orders as $order ) {
			$items = array();
			foreach ( $order->get_items() as $item ) { $items[] = $item->get_name() . ' &times; ' . $item->get_quantity(); }
			echo '<tr>'
				. '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>'
				. '<td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td>'
				. '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>'
				. '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>'
				. '<td>' . esc_html( $order->get_payment_method_title() ) . '</td>'
				. '<td>' . wp_kses_post( implode( ', ', $items ) ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	private function section_cart( $user_id ) {
		echo '<h2>' . esc_html__( 'Current Cart', 'legendcreate-community' ) . '</h2>';
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		$cart     = get_user_meta( $user_id, $meta_key, true );
		if ( empty( $cart ) || empty( $cart['cart'] ) ) {
			echo '<p>' . esc_html__( 'Cart is empty.', 'legendcreate-community' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:700px;margin-bottom:2em"><thead><tr><th>' . esc_html__( 'Product', 'legendcreate-community' ) . '</th><th>' . esc_html__( 'Qty', 'legendcreate-community' ) . '</th></tr></thead><tbody>';
		foreach ( $cart['cart'] as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$name       = $product ? $product->get_name() : sprintf( __( 'Product #%d', 'legendcreate-community' ), $product_id );
			$qty        = isset( $item['quantity'] ) ? $item['quantity'] : '';
			echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $qty ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function section_squads( $user_id ) {
		echo '<h2>' . esc_html__( 'Squads', 'legendcreate-community' ) . '</h2>';
		if ( ! class_exists( 'LCC_Squads' ) ) { echo '<p>&mdash;</p>'; return; }
		$ids = LCC_Squads::get_user_squads( $user_id );
		if ( empty( $ids ) ) { echo '<p>' . esc_html__( 'Not in a squad.', 'legendcreate-community' ) . '</p>'; return; }
		echo '<ul>';
		foreach ( $ids as $squad_id ) {
			echo '<li><a href="' . esc_url( get_edit_post_link( $squad_id ) ) . '">' . esc_html( get_the_title( $squad_id ) ) . '</a></li>';
		}
		echo '</ul>';
	}
}
