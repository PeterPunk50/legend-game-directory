<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LCDP_Roles {
	// Capabilities grouped by role
	private static function role_caps() {
		return array(
			'lcdp_developer' => array(
				// Standard WP
				'read'                         => true,
				'upload_files'                 => true,
				// Game profiles
				'create_lcdp_games'            => true,
				'edit_lcdp_games'              => true,
				'delete_lcdp_games'            => true,
				'publish_lcdp_games'           => false, // requires editorial approval
				// Developer profile
				'edit_lcdp_developers'         => true,
				'create_lcdp_developers'       => true,
				// Platform actions
				'lcdp_submit_game'             => true,
				'lcdp_purchase_services'       => true,
				'lcdp_view_own_campaigns'      => true,
				'lcdp_view_own_reports'        => true,
				'lcdp_view_own_billing'        => true,
				'lcdp_request_playtest'        => true,
				'lcdp_manage_developer_profile'=> true,
			),
			'lcdp_tester' => array(
				'read'                         => true,
				'upload_files'                 => true,
				'lcdp_view_campaign_invites'   => true,
				'lcdp_apply_campaigns'         => true,
				'lcdp_submit_feedback'         => true,
				'lcdp_submit_bug_reports'      => true,
				'lcdp_view_own_rewards'        => true,
				'lcdp_view_tester_dashboard'   => true,
				'lcdp_manage_tester_profile'   => true,
			),
			'lcdp_expert_reviewer' => array(
				'read'                         => true,
				'upload_files'                 => true,
				'lcdp_view_expert_assignments' => true,
				'lcdp_submit_expert_review'    => true,
				'lcdp_view_expert_payments'    => true,
				'lcdp_manage_expert_profile'   => true,
				// Expert can edit their own profile CPT post
				'edit_lcdp_experts'            => true,
				'create_lcdp_experts'          => true,
			),
			'lcdp_community_coordinator' => array(
				'read'                          => true,
				'upload_files'                  => true,
				'edit_posts'                    => true,
				'lcdp_review_tester_applications' => true,
				'lcdp_manage_campaigns'         => true,
				'lcdp_assign_testers'           => true,
				'lcdp_review_submissions'       => true,
				'lcdp_communicate_testers'      => true,
				'lcdp_view_all_applications'    => true,
				'lcdp_manage_matching'          => true,
				// CPT access
				'edit_lcdp_campaigns'           => true,
				'read_lcdp_campaigns'           => true,
				'edit_lcdp_games'               => true,
				'edit_lcdp_developers'          => true,
			),
			'lcdp_editor' => array(
				'read'                          => true,
				'upload_files'                  => true,
				'edit_posts'                    => true,
				'publish_posts'                 => true,
				'delete_posts'                  => true,
				'edit_others_posts'             => true,
				'lcdp_review_guides'            => true,
				'lcdp_approve_sponsored'        => true,
				'lcdp_verify_disclosures'       => true,
				'lcdp_manage_game_pages'        => true,
				// CPT access
				'edit_lcdp_games'               => true,
				'publish_lcdp_games'            => true,
				'delete_lcdp_games'             => true,
				'edit_lcdp_sponsoreds'          => true,
				'publish_lcdp_sponsoreds'       => true,
				'edit_lcdp_experts'             => true,
				'publish_lcdp_experts'          => true,
			),
		);
	}

	// Extra caps to merge into the administrator role
	private static function admin_caps() {
		return array(
			'lcdp_manage_platform'            => true,
			'lcdp_view_admin_dashboard'       => true,
			'lcdp_approve_outreach'           => true,
			'lcdp_manage_leads'               => true,
			'lcdp_approve_reports'            => true,
			'lcdp_manage_memberships'         => true,
			'lcdp_view_financials'            => true,
			'lcdp_reject_applicants'          => true,
			'lcdp_verify_experts'             => true,
			'lcdp_approve_sponsored'          => true,
			'lcdp_manage_campaigns'           => true,
			'lcdp_review_tester_applications' => true,
			'lcdp_assign_testers'             => true,
			'lcdp_review_submissions'         => true,
			'lcdp_communicate_testers'        => true,
			'lcdp_view_all_applications'      => true,
			'lcdp_manage_matching'            => true,
			'lcdp_view_all_campaigns'         => true,
			'lcdp_view_all_reports'           => true,
			// CPT full access
			'edit_lcdp_games'                 => true,
			'publish_lcdp_games'              => true,
			'delete_lcdp_games'               => true,
			'edit_others_lcdp_games'          => true,
			'read_private_lcdp_games'         => true,
			'edit_lcdp_campaigns'             => true,
			'publish_lcdp_campaigns'          => true,
			'read_lcdp_campaigns'             => true,
			'edit_lcdp_developers'            => true,
			'publish_lcdp_developers'         => true,
			'edit_lcdp_experts'               => true,
			'publish_lcdp_experts'            => true,
			'edit_lcdp_sponsoreds'            => true,
			'publish_lcdp_sponsoreds'         => true,
		);
	}

	public static function install() {
		foreach ( self::role_caps() as $role_key => $caps ) {
			$display_names = array(
				'lcdp_developer'            => 'Developer',
				'lcdp_tester'               => 'Tester',
				'lcdp_expert_reviewer'      => 'Expert Reviewer',
				'lcdp_community_coordinator'=> 'Community Coordinator',
				'lcdp_editor'               => 'Content Editor',
			);
			$existing = get_role( $role_key );
			if ( $existing ) {
				$existing->capabilities = array_merge( $existing->capabilities, $caps );
			} else {
				add_role( $role_key, $display_names[ $role_key ], $caps );
			}
		}
		// Merge extra caps into administrator
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::admin_caps() as $cap => $val ) {
				$admin->add_cap( $cap, $val );
			}
		}
	}

	public static function remove() {
		foreach ( array_keys( self::role_caps() ) as $role_key ) {
			remove_role( $role_key );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::admin_caps() ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
