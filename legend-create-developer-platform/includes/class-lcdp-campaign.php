<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Campaign {

	public static function statuses() {
		return array(
			'developer_enquiry'    => 'Developer Enquiry',
			'scope_review'         => 'Scope Review',
			'quotation_sent'       => 'Quotation Sent',
			'awaiting_payment'     => 'Awaiting Payment',
			'preparing_test'       => 'Preparing Test',
			'recruiting'           => 'Recruiting',
			'testing_active'       => 'Testing Active',
			'submissions_review'   => 'Submissions Under Review',
			'expert_review'        => 'Expert Review',
			'report_draft'         => 'Report Draft',
			'developer_review'     => 'Developer Review',
			'completed'            => 'Completed',
			'follow_up_available'  => 'Follow-Up Available',
			'cancelled'            => 'Cancelled',
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_lcdp_create_campaign',       array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_lcdp_update_campaign_status',array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_lcdp_assign_tester',         array( $this, 'ajax_assign_tester' ) );
		add_action( 'woocommerce_payment_complete',        array( $this, 'on_order_paid' ) );
	}

	public static function get( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('campaigns') . ' WHERE id=%d', $campaign_id
		) );
	}

	public static function get_for_developer( $user_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('campaigns') . ' WHERE developer_user_id=%d ORDER BY created_at DESC',
			$user_id
		) );
	}

	public function ajax_create() {
		LCDP_Security::ajax_check( 'lcdp_campaign_nonce', 'lcdp_request_playtest' );
		$user_id = get_current_user_id();
		$data    = LCDP_Security::sanitize_form( $_POST, array(
			'game_post_id'       => 'int',
			'title'              => 'text',
			'service_package'    => 'slug',
			'build_version'      => 'text',
			'development_stage'  => 'text',
			'genre'              => 'text',
			'similar_games'      => 'text',
			'test_goals'         => 'textarea',
			'tester_requirements'=> 'textarea',
			'tester_count'       => 'int',
			'session_duration'   => 'int',
			'build_delivery'     => 'text',
			'min_requirements'   => 'textarea',
			'known_issues'       => 'textarea',
			'nda_required'       => 'bool',
			'recording_permission'=> 'bool',
			'start_date'         => 'text',
			'end_date'           => 'text',
		) );

		if ( empty( $data['title'] ) || empty( $data['service_package'] ) ) {
			wp_send_json_error( array( 'message' => 'Title and service package are required.' ) );
		}

		$packages  = LCDP_Developer::service_packages();
		$platforms = is_array( $_POST['platforms'] ?? null )
			? wp_json_encode( array_map('sanitize_text_field', $_POST['platforms']) )
			: '[]';

		global $wpdb;
		$wpdb->insert( LCDP_Database::table('campaigns'), array(
			'developer_user_id'   => $user_id,
			'game_post_id'        => $data['game_post_id'],
			'title'               => $data['title'],
			'service_package'     => $data['service_package'],
			'build_version'       => $data['build_version'],
			'development_stage'   => $data['development_stage'],
			'platforms'           => $platforms,
			'genre'               => $data['genre'],
			'similar_games'       => $data['similar_games'],
			'test_goals'          => $data['test_goals'],
			'tester_requirements' => $data['tester_requirements'],
			'tester_count'        => max( 1, min( 50, $data['tester_count'] ) ),
			'session_duration'    => max( 10, min( 120, $data['session_duration'] ) ),
			'build_delivery'      => $data['build_delivery'],
			'min_requirements'    => $data['min_requirements'],
			'known_issues'        => $data['known_issues'],
			'nda_required'        => $data['nda_required'] ? 1 : 0,
			'recording_permission'=> $data['recording_permission'] ? 1 : 0,
			'start_date'          => sanitize_text_field( $data['start_date'] ) ?: null,
			'end_date'            => sanitize_text_field( $data['end_date'] ) ?: null,
			'status'              => 'developer_enquiry',
			'created_at'          => current_time('mysql'),
			'updated_at'          => current_time('mysql'),
		) );

		$campaign_id = $wpdb->insert_id;
		LCDP_Security::audit( 'campaign_created', "Campaign '{$data['title']}' created", 'campaign', $campaign_id );
		do_action( 'lcdp_campaign_created', $campaign_id, $user_id );
		LCDP_Email::send_developer_notification( $user_id, 'campaign_received', $campaign_id );

		wp_send_json_success( array( 'campaign_id' => $campaign_id, 'message' => 'Campaign request received.' ) );
	}

	// Staff/coordinator updates campaign status
	public function ajax_update_status() {
		LCDP_Security::ajax_check( 'lcdp_admin_nonce', 'lcdp_manage_campaigns' );
		$campaign_id = absint( $_POST['campaign_id'] ?? 0 );
		$new_status  = sanitize_key( $_POST['status'] ?? '' );
		$notes       = sanitize_textarea_field( $_POST['notes'] ?? '' );
		if ( ! $campaign_id || ! isset( self::statuses()[ $new_status ] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid parameters.' ) );
		}
		self::update_status( $campaign_id, $new_status, $notes );
		wp_send_json_success( array( 'message' => 'Status updated.' ) );
	}

	public static function update_status( $campaign_id, $new_status, $notes = '' ) {
		global $wpdb;
		$campaign = self::get( $campaign_id );
		if ( ! $campaign ) { return false; }
		$wpdb->update( LCDP_Database::table('campaigns'), array(
			'status'     => $new_status,
			'updated_at' => current_time('mysql'),
		), array( 'id' => $campaign_id ) );

		LCDP_Security::audit( 'campaign_status_updated', "Campaign {$campaign_id} → {$new_status}", 'campaign', $campaign_id );
		do_action( 'lcdp_campaign_status_changed', $campaign_id, $new_status, $campaign->status );

		if ( 'recruiting' === $new_status ) {
			LCDP_Email::send_developer_notification( $campaign->developer_user_id, 'campaign_recruiting', $campaign_id );
		} elseif ( 'testing_active' === $new_status ) {
			LCDP_Email::send_developer_notification( $campaign->developer_user_id, 'campaign_active', $campaign_id );
		} elseif ( 'completed' === $new_status ) {
			LCDP_Email::send_developer_notification( $campaign->developer_user_id, 'campaign_completed', $campaign_id );
			do_action( 'lcdp_campaign_completed', $campaign_id, $campaign->developer_user_id );
		}
		return true;
	}

	// Coordinator assigns a tester (must be approved, matched, no conflict)
	public function ajax_assign_tester() {
		LCDP_Security::ajax_check( 'lcdp_admin_nonce', 'lcdp_assign_testers' );
		$campaign_id     = absint( $_POST['campaign_id'] ?? 0 );
		$application_id  = absint( $_POST['application_id'] ?? 0 );
		$notes           = sanitize_textarea_field( $_POST['notes'] ?? '' );

		global $wpdb;
		$app = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . LCDP_Database::table('campaign_applications') . ' WHERE id=%d', $application_id
		) );
		if ( ! $app || $app->campaign_id != $campaign_id ) {
			wp_send_json_error( array( 'message' => 'Invalid application.' ) );
		}
		$tester_profile = LCDP_Tester::get_profile( $app->tester_user_id );
		if ( ! $tester_profile || ! in_array( $tester_profile->status, array( 'approved', 'specialist_approved' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Tester must be approved before assignment.' ) );
		}

		$wpdb->update( LCDP_Database::table('campaign_applications'), array(
			'status'            => 'assigned',
			'coordinator_notes' => $notes,
			'assigned_at'       => current_time('mysql'),
		), array( 'id' => $application_id ) );

		LCDP_Security::audit( 'tester_assigned', "Tester {$app->tester_user_id} assigned to campaign {$campaign_id}", 'campaign', $campaign_id );
		LCDP_Email::send_tester_notification( $app->tester_user_id, 'assignment_confirmed', $campaign_id );
		do_action( 'lcdp_tester_assigned', $app->tester_user_id, $campaign_id );
		wp_send_json_success( array( 'message' => 'Tester assigned.' ) );
	}

	// Attach WooCommerce order to campaign on payment
	public function on_order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) { return; }
		$campaign_id = (int) $order->get_meta( '_lcdp_campaign_id' );
		if ( ! $campaign_id ) { return; }
		global $wpdb;
		$wpdb->update( LCDP_Database::table('campaigns'), array(
			'woo_order_id' => $order_id,
			'status'       => 'preparing_test',
			'updated_at'   => current_time('mysql'),
		), array( 'id' => $campaign_id ) );
		do_action( 'lcdp_campaign_paid', $campaign_id, $order->get_customer_id() );
	}

	// Profitability calculator
	public static function calculate_margin( $campaign_id ) {
		$campaign = self::get( $campaign_id );
		if ( ! $campaign ) { return null; }
		$order = $campaign->woo_order_id ? wc_get_order( $campaign->woo_order_id ) : null;
		$revenue         = $order ? (float) $order->get_total() : 0;
		$tester_rewards  = (float) $campaign->tester_reward_budget;
		$expert_fees     = (float) $campaign->expert_fee;
		$payment_fees    = round( $revenue * 0.029 + 0.30, 2 ); // approx Stripe fees
		$platform_cost   = (float) $campaign->platform_fee;
		$total_costs     = $tester_rewards + $expert_fees + $payment_fees + $platform_cost;
		$margin          = $revenue - $total_costs;
		$margin_pct      = $revenue > 0 ? round( ($margin / $revenue) * 100, 1 ) : 0;
		$min_margin      = (float) get_option( 'lcdp_min_margin_pct', 30 );
		return array(
			'revenue'        => $revenue,
			'tester_rewards' => $tester_rewards,
			'expert_fees'    => $expert_fees,
			'payment_fees'   => $payment_fees,
			'platform_cost'  => $platform_cost,
			'total_costs'    => $total_costs,
			'margin'         => $margin,
			'margin_pct'     => $margin_pct,
			'warning'        => $margin_pct < $min_margin,
			'min_margin'     => $min_margin,
		);
	}
}
