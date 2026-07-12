<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LCDP_Email {

	const FROM_NAME  = 'Legend Create';
	const REPLY_TO   = 'hello@legendcreate.com';
	const MAILING_ADDRESS = 'Legend Create, Online';

	public function __construct() {
		add_action( 'lcdp_game_submitted',           array( $this, 'notify_admin_game_submitted' ), 10, 2 );
		add_action( 'lcdp_tester_applied',           array( $this, 'notify_admin_tester_applied' ), 10, 1 );
		add_action( 'lcdp_membership_reward_ready',  array( $this, 'notify_membership_ready' ), 10, 1 );
		add_action( 'lcdp_membership_redeemed',      array( $this, 'notify_membership_redeemed' ), 10, 2 );
	}

	// --- Developer notifications ---

	public static function send_developer_notification( $user_id, $type, $ref_id = 0 ) {
		if ( ! $user_id ) { return; }
		$user  = get_userdata( $user_id );
		if ( ! $user ) { return; }
		$email = $user->user_email;
		if ( LCDP_Consent::is_suppressed( $email, 'marketing' ) && in_array( $type, array('newsletter'), true ) ) { return; }

		$subject = '';
		$body    = '';
		$dash    = home_url('/developer-dashboard/');

		switch ( $type ) {
			case 'game_approved':
				$game = get_post( $ref_id );
				$subject = '[Legend Create] Your game profile is live!';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( "Great news — your game <strong>" . esc_html($game->post_title ?? 'your game') . "</strong> is now live on Legend Create." ) .
				           self::cta( 'View Your Game', get_permalink($ref_id) ) .
				           self::footer( $email );
				break;
			case 'campaign_received':
				$subject = '[Legend Create] Campaign request received';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'We have received your campaign request. Our team will review it and send you a scope confirmation within 1-2 business days.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'campaign_recruiting':
				$subject = '[Legend Create] Your campaign is recruiting testers';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'Great news — we are now actively recruiting testers for your campaign. We will update you when testing is ready to begin.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'campaign_completed':
				$subject = '[Legend Create] Your campaign has completed';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'Your playtest campaign has completed. Your developer report will be ready for review shortly. You will receive another email when it is available.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'membership_token_redeemed':
				$subject = '[Legend Create] Your 6-month membership is active!';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'Congratulations! You have redeemed your Legend Tokens for a 6-month Developer Starter membership. Your membership is now active.' ) .
				           self::cta( 'Go to Dashboard', $dash ) .
				           self::footer( $email );
				break;
			default:
				return;
		}
		self::send( $email, $subject, $body );
	}

	// --- Tester notifications ---

	public static function send_tester_notification( $user_id, $type, $ref_id = 0 ) {
		if ( ! $user_id ) { return; }
		$user  = get_userdata( $user_id );
		if ( ! $user ) { return; }
		$email = $user->user_email;
		if ( LCDP_Consent::is_suppressed( $email, 'playtest' ) ) { return; }

		$subject = '';
		$body    = '';
		$dash    = home_url('/tester-dashboard/');

		switch ( $type ) {
			case 'application_received':
				$subject = '[Legend Create] Application received — Playtest Crew';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'Thank you for applying to join the Legend Create Playtest Crew. We will review your application and be in touch within 3-5 business days.' ) .
				           self::p( '<strong>What happens next:</strong><br>• We review your profile and sample task<br>• You receive an approval or feedback email<br>• Approved testers receive campaign invitations' ) .
				           self::cta( 'View Your Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'application_approved':
				$subject = '[Legend Create] You are approved — Playtest Crew!';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'Great news — your Playtest Crew application has been approved. You will now be matched to campaigns that suit your profile.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'application_rejected':
				$subject = '[Legend Create] Playtest Crew application update';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'We reviewed your application and unfortunately we are not able to approve it at this time. If you believe there is an error in your application, please contact us.' ) .
				           self::p( '<a href="' . esc_url(home_url('/contact/')) . '">Contact us</a>' ) .
				           self::footer( $email );
				break;
			case 'assignment_confirmed':
				$subject = '[Legend Create] You have a new campaign assignment';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'You have been assigned to a new playtest campaign. Log in to your dashboard to see the test mission and get started.' ) .
				           self::cta( 'View Assignment', $dash ) .
				           self::footer( $email );
				break;
			case 'submission_received':
				$subject = '[Legend Create] Feedback received — thank you';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'We have received your playtest submission. Our team will review it and update your reward status within 3-5 business days.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'submission_approved':
				$subject = '[Legend Create] Your submission has been approved!';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'Your playtest submission has been approved. Your reward payment is now pending processing.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			case 'revision_required':
				$subject = '[Legend Create] Revision requested for your submission';
				$body    = self::header() . self::p( "Hi {$user->display_name}," ) .
				           self::p( 'We have reviewed your playtest submission and require a revision. Please log in to see the feedback and update your submission.' ) .
				           self::cta( 'View Dashboard', $dash ) .
				           self::footer( $email );
				break;
			default:
				return;
		}
		self::send( $email, $subject, $body );
	}

	// Admin notifications
	public function notify_admin_game_submitted( $post_id, $user_id ) {
		$admin = get_option('admin_email');
		$user  = get_userdata( $user_id );
		$game  = get_post( $post_id );
		self::send( $admin,
			'[Legend Create Admin] New game submission',
			self::header() . self::p("New game submitted for review:") .
			self::p("<strong>" . esc_html($game->post_title) . "</strong> by " . esc_html($user->display_name)) .
			self::cta('Review in Admin', admin_url("post.php?post={$post_id}&action=edit")) .
			self::footer($admin)
		);
	}

	public function notify_admin_tester_applied( $user_id ) {
		$admin = get_option('admin_email');
		$user  = get_userdata( $user_id );
		self::send( $admin,
			'[Legend Create Admin] New tester application',
			self::header() . self::p("New Playtest Crew application from: " . esc_html($user->display_name)) .
			self::cta('Review Application', admin_url('admin.php?page=lcdp-tester-applications')) .
			self::footer($admin)
		);
	}

	public function notify_membership_ready( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) { return; }
		self::send( $user->user_email,
			'[Legend Create] You have earned a free 6-month membership!',
			self::header() . self::p("Hi {$user->display_name},") .
			self::p('You have earned enough Legend Tokens to claim a free 6-month Developer Starter membership (worth $174)! Log in to redeem it.') .
			self::cta('Redeem Your Membership', home_url('/developer-dashboard/?tab=tokens')) .
			self::footer($user->user_email)
		);
	}

	public function notify_membership_redeemed( $user_id, $expiry ) {
		// Handled by send_developer_notification('membership_token_redeemed')
	}

	// --- Core send method ---

	public static function send( $to, $subject, $html_body ) {
		if ( LCDP_Consent::is_suppressed( $to, 'all' ) ) { return false; }
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . self::FROM_NAME . ' <' . get_option('admin_email') . '>',
			'Reply-To: ' . self::REPLY_TO,
		);
		$result = wp_mail( $to, $subject, $html_body, $headers );
		if ( ! $result ) {
			LCDP_Security::audit( 'email_failed', "Email failed to {$to}: {$subject}", 'email', 0, 'warning' );
		}
		return $result;
	}

	// --- HTML helpers ---

	private static function header() {
		return '<div style="max-width:560px;margin:0 auto;font-family:Arial,sans-serif;color:#e0e8ff;background:#0d1117;padding:32px;border-radius:8px">'
		     . '<div style="margin-bottom:24px;text-align:center"><strong style="color:#2dd4bf;font-size:1.3em">Legend Create</strong></div>';
	}

	private static function p( $text ) {
		return '<p style="margin:0 0 16px;line-height:1.6;font-size:0.95em">' . $text . '</p>';
	}

	private static function cta( $label, $url ) {
		return '<div style="text-align:center;margin:24px 0">'
		     . '<a href="' . esc_url($url) . '" style="background:#2dd4bf;color:#0d1117;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:0.95em">'
		     . esc_html($label) . '</a></div>';
	}

	private static function footer( $email ) {
		$unsub = LCDP_Consent::unsubscribe_url( $email );
		return '<hr style="border:none;border-top:1px solid #1e293b;margin:24px 0">'
		     . '<p style="font-size:0.78em;color:#64748b;text-align:center">Legend Create — ' . esc_html(self::MAILING_ADDRESS) . '<br>'
		     . '<a href="' . esc_url(home_url('/privacy-policy/')) . '" style="color:#2dd4bf">Privacy Policy</a> &nbsp;|&nbsp; '
		     . '<a href="' . esc_url($unsub) . '" style="color:#2dd4bf">Unsubscribe</a></p></div>';
	}
}
