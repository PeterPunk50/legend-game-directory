<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Squads — "bring your squad" core.
 *
 * Each squad is an lc_squad CPT (public profile page + logo + description).
 * Membership lives in the {prefix}lcc_squad_members table. Public squads are
 * listed and join-by-button; private squads are hidden and join-by-invite-code.
 */
final class LCC_Squads {

	const CPT          = 'lc_squad';
	const MAX_PER_USER = 3; // squads a member may own
	const SKILLS       = array( 'Casual', 'Intermediate', 'Competitive' );

	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'pre_get_posts', array( $this, 'archive_query' ) );
		add_filter( 'template_include', array( $this, 'templates' ) );
		add_shortcode( 'lcc_squads', array( $this, 'directory_shortcode' ) );
		add_shortcode( 'lcc_squad_create', array( $this, 'create_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_lcc_create_squad', array( $this, 'handle_create' ) );
		add_action( 'admin_post_lcc_join_squad', array( $this, 'handle_join' ) );
		add_action( 'admin_post_lcc_leave_squad', array( $this, 'handle_leave' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lcc_squad_members';
	}

	// ── CPT ──────────────────────────────────────────────────────────────────────

	public static function register_cpt() {
		register_post_type( self::CPT, array(
			'labels'            => array(
				'name'          => __( 'Squads', 'legendcreate-community' ),
				'singular_name' => __( 'Squad', 'legendcreate-community' ),
			),
			'public'            => true,
			'has_archive'       => 'squads',
			'rewrite'           => array( 'slug' => 'squad', 'with_front' => false ),
			'menu_icon'         => 'dashicons-groups',
			'supports'          => array( 'title', 'editor', 'thumbnail' ),
			'show_in_rest'      => false,
			'capability_type'   => 'post',
		) );
	}

	public function assets() {
		if ( is_singular( self::CPT ) || is_post_type_archive( self::CPT )
			|| ( is_singular() && get_post() && has_shortcode( get_post()->post_content, 'lcc_squad_create' ) ) ) {
			wp_enqueue_style( 'lcc-community', LCC_URL . 'assets/css/community.css', array(), LCC_VERSION );
		}
	}

	public function templates( $template ) {
		if ( is_singular( self::CPT ) ) { return LCC_PATH . 'templates/single-lc_squad.php'; }
		if ( is_post_type_archive( self::CPT ) ) { return LCC_PATH . 'templates/archive-lc_squad.php'; }
		return $template;
	}

	public function archive_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( self::CPT ) ) { return; }
		$query->set( 'posts_per_page', 24 );
		$query->set( 'meta_query', array( array( 'key' => '_lcc_squad_visibility', 'value' => 'public' ) ) );
	}

	// ── Data layer ───────────────────────────────────────────────────────────────

	public static function is_member( $squad_id, $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM ' . self::table() . ' WHERE squad_id=%d AND user_id=%d AND status=%s',
			$squad_id, $user_id, 'active'
		) );
	}

	public static function member_role( $squad_id, $user_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			'SELECT role FROM ' . self::table() . ' WHERE squad_id=%d AND user_id=%d',
			$squad_id, $user_id
		) );
	}

	public static function member_count( $squad_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE squad_id=%d AND status=%s', $squad_id, 'active'
		) );
	}

	public static function get_members( $squad_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, role, points, joined_at FROM ' . self::table() . ' WHERE squad_id=%d AND status=%s ORDER BY (role="leader") DESC, joined_at ASC',
			$squad_id, 'active'
		) );
	}

	public static function get_user_squads( $user_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			'SELECT squad_id FROM ' . self::table() . ' WHERE user_id=%d AND status=%s ORDER BY joined_at DESC',
			$user_id, 'active'
		) );
		return array_map( 'intval', $ids );
	}

	public static function owned_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE user_id=%d AND role=%s', $user_id, 'leader'
		) );
	}

	public static function add_member( $squad_id, $user_id, $role = 'member', $status = 'active' ) {
		global $wpdb;
		return $wpdb->replace( self::table(), array(
			'squad_id'  => (int) $squad_id,
			'user_id'   => (int) $user_id,
			'role'      => $role,
			'status'    => $status,
			'joined_at' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%s', '%s', '%s' ) );
	}

	public static function remove_member( $squad_id, $user_id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'squad_id' => (int) $squad_id, 'user_id' => (int) $user_id ), array( '%d', '%d' ) );
	}

	/** Create a squad from sanitized data. Returns squad ID or WP_Error. */
	public static function create_squad( $owner_id, $data ) {
		$owner_id = (int) $owner_id;
		if ( $owner_id < 1 ) { return new WP_Error( 'lcc_squad_auth', __( 'You must be logged in.', 'legendcreate-community' ) ); }
		if ( self::owned_count( $owner_id ) >= self::MAX_PER_USER ) {
			return new WP_Error( 'lcc_squad_limit', __( 'You have reached the maximum number of squads you can lead.', 'legendcreate-community' ) );
		}
		$name = sanitize_text_field( $data['name'] );
		if ( '' === $name ) { return new WP_Error( 'lcc_squad_name', __( 'Please name your squad.', 'legendcreate-community' ) ); }

		$squad_id = wp_insert_post( array(
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => $name,
			'post_content' => isset( $data['description'] ) ? wp_kses_post( $data['description'] ) : '',
			'post_author'  => $owner_id,
		), true );
		if ( is_wp_error( $squad_id ) ) { return $squad_id; }

		update_post_meta( $squad_id, '_lcc_squad_owner', $owner_id );
		update_post_meta( $squad_id, '_lcc_squad_visibility', ( isset( $data['visibility'] ) && 'private' === $data['visibility'] ) ? 'private' : 'public' );
		update_post_meta( $squad_id, '_lcc_squad_region', sanitize_text_field( $data['region'] ?? '' ) );
		update_post_meta( $squad_id, '_lcc_squad_schedule', sanitize_text_field( $data['schedule'] ?? '' ) );
		update_post_meta( $squad_id, '_lcc_squad_skill', in_array( $data['skill'] ?? '', self::SKILLS, true ) ? $data['skill'] : '' );
		update_post_meta( $squad_id, '_lcc_squad_discord', esc_url_raw( $data['discord'] ?? '' ) );
		$games = isset( $data['games'] ) ? array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $data['games'] ) ) ), 0, 10 ) : array();
		update_post_meta( $squad_id, '_lcc_squad_games', $games );
		update_post_meta( $squad_id, '_lcc_squad_invite', wp_generate_password( 10, false ) );

		self::add_member( $squad_id, $owner_id, 'leader', 'active' );
		do_action( 'lcc_squad_created', $squad_id, $owner_id );
		return $squad_id;
	}

	public static function invite_code( $squad_id ) {
		return (string) get_post_meta( $squad_id, '_lcc_squad_invite', true );
	}

	public static function visibility( $squad_id ) {
		return get_post_meta( $squad_id, '_lcc_squad_visibility', true ) === 'private' ? 'private' : 'public';
	}

	public static function games( $squad_id ) {
		return array_filter( (array) get_post_meta( $squad_id, '_lcc_squad_games', true ) );
	}

	// ── Handlers ─────────────────────────────────────────────────────────────────

	public function handle_create() {
		if ( ! is_user_logged_in() || ! isset( $_POST['lcc_squad_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lcc_squad_nonce'] ), 'lcc_create_squad' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$games = isset( $_POST['lcc_squad_games'] ) ? (array) wp_unslash( $_POST['lcc_squad_games'] ) : array();
		if ( ! empty( $_POST['lcc_squad_games_other'] ) ) {
			$games = array_merge( $games, preg_split( '/[,\n]+/', wp_unslash( $_POST['lcc_squad_games_other'] ) ) );
		}
		$result = self::create_squad( get_current_user_id(), array(
			'name'        => isset( $_POST['lcc_squad_name'] ) ? wp_unslash( $_POST['lcc_squad_name'] ) : '',
			'description' => isset( $_POST['lcc_squad_desc'] ) ? wp_unslash( $_POST['lcc_squad_desc'] ) : '',
			'visibility'  => isset( $_POST['lcc_squad_visibility'] ) ? sanitize_key( wp_unslash( $_POST['lcc_squad_visibility'] ) ) : 'public',
			'region'      => isset( $_POST['lcc_squad_region'] ) ? wp_unslash( $_POST['lcc_squad_region'] ) : '',
			'schedule'    => isset( $_POST['lcc_squad_schedule'] ) ? wp_unslash( $_POST['lcc_squad_schedule'] ) : '',
			'skill'       => isset( $_POST['lcc_squad_skill'] ) ? sanitize_text_field( wp_unslash( $_POST['lcc_squad_skill'] ) ) : '',
			'discord'     => isset( $_POST['lcc_squad_discord'] ) ? wp_unslash( $_POST['lcc_squad_discord'] ) : '',
			'games'       => $games,
		) );

		if ( is_wp_error( $result ) ) {
			$back = lcc_squad_create_page() ? get_permalink( lcc_squad_create_page() ) : home_url( '/' );
			wp_safe_redirect( add_query_arg( 'lcc_squad_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}
		wp_safe_redirect( get_permalink( $result ) );
		exit;
	}

	public function handle_join() {
		if ( ! is_user_logged_in() || ! isset( $_POST['lcc_join_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lcc_join_nonce'] ), 'lcc_join_squad' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$squad_id = isset( $_POST['squad_id'] ) ? absint( $_POST['squad_id'] ) : 0;
		$code     = isset( $_POST['invite'] ) ? sanitize_text_field( wp_unslash( $_POST['invite'] ) ) : '';
		$uid      = get_current_user_id();

		if ( ! $squad_id || self::CPT !== get_post_type( $squad_id ) ) { wp_die( esc_html__( 'Squad not found.', 'legendcreate-community' ) ); }

		if ( ! self::is_member( $squad_id, $uid ) ) {
			if ( 'private' === self::visibility( $squad_id ) && ! hash_equals( self::invite_code( $squad_id ), $code ) ) {
				wp_safe_redirect( add_query_arg( 'lcc_squad_msg', 'invite', get_permalink( $squad_id ) ) );
				exit;
			}
			self::add_member( $squad_id, $uid, 'member', 'active' );
			do_action( 'lcc_squad_joined', $squad_id, $uid );
		}
		wp_safe_redirect( add_query_arg( 'lcc_squad_msg', 'joined', get_permalink( $squad_id ) ) );
		exit;
	}

	public function handle_leave() {
		if ( ! is_user_logged_in() || ! isset( $_POST['lcc_leave_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['lcc_leave_nonce'] ), 'lcc_leave_squad' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'legendcreate-community' ) );
		}
		$squad_id = isset( $_POST['squad_id'] ) ? absint( $_POST['squad_id'] ) : 0;
		$uid      = get_current_user_id();
		if ( ! $squad_id || ! self::is_member( $squad_id, $uid ) ) { wp_die( esc_html__( 'Not a member.', 'legendcreate-community' ) ); }

		$was_leader = 'leader' === self::member_role( $squad_id, $uid );
		self::remove_member( $squad_id, $uid );

		if ( $was_leader ) {
			// Promote the next-oldest member, or disband the squad if empty.
			$members = self::get_members( $squad_id );
			if ( $members ) {
				global $wpdb;
				$wpdb->update( self::table(), array( 'role' => 'leader' ), array( 'squad_id' => $squad_id, 'user_id' => (int) $members[0]->user_id ), array( '%s' ), array( '%d', '%d' ) );
				update_post_meta( $squad_id, '_lcc_squad_owner', (int) $members[0]->user_id );
			} else {
				wp_trash_post( $squad_id );
				$dash = lcc_dashboard_page();
				wp_safe_redirect( $dash ? get_permalink( $dash ) : home_url( '/' ) );
				exit;
			}
		}
		do_action( 'lcc_squad_left', $squad_id, $uid );
		wp_safe_redirect( add_query_arg( 'lcc_squad_msg', 'left', get_permalink( $squad_id ) ) );
		exit;
	}

	// ── Rendering helpers ────────────────────────────────────────────────────────

	public static function card( $squad_id ) {
		$games = self::games( $squad_id );
		$count = self::member_count( $squad_id );
		ob_start(); ?>
		<article class="lcc-squad-card">
			<a class="lcc-squad-card__logo" href="<?php echo esc_url( get_permalink( $squad_id ) ); ?>">
				<?php echo has_post_thumbnail( $squad_id ) ? get_the_post_thumbnail( $squad_id, 'thumbnail' ) : '<span class="lcc-squad-initial">' . esc_html( mb_substr( get_the_title( $squad_id ), 0, 1 ) ) . '</span>'; ?>
			</a>
			<div class="lcc-squad-card__body">
				<h3><a href="<?php echo esc_url( get_permalink( $squad_id ) ); ?>"><?php echo esc_html( get_the_title( $squad_id ) ); ?></a></h3>
				<?php if ( $games ) : ?><p class="lcc-muted"><?php echo esc_html( implode( ' · ', array_slice( $games, 0, 3 ) ) ); ?></p><?php endif; ?>
				<p class="lcc-squad-card__meta"><?php echo esc_html( sprintf( _n( '%d member', '%d members', $count, 'legendcreate-community' ), $count ) ); ?></p>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	public static function render_my_squads( $user_id ) {
		$ids = self::get_user_squads( $user_id );
		$create = lcc_squad_create_page();
		ob_start();
		echo '<h3>' . esc_html__( 'My Squads', 'legendcreate-community' ) . '</h3>';
		if ( $ids ) {
			echo '<ul class="lcc-squad-list">';
			foreach ( $ids as $sid ) {
				$role = self::member_role( $sid, $user_id );
				echo '<li><a href="' . esc_url( get_permalink( $sid ) ) . '">' . esc_html( get_the_title( $sid ) ) . '</a>'
					. ( 'leader' === $role ? ' <span class="lcc-badge lcc-badge-free">' . esc_html__( 'Leader', 'legendcreate-community' ) . '</span>' : '' ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="lcc-muted">' . esc_html__( 'You are not in a squad yet. Bring your crew or join one.', 'legendcreate-community' ) . '</p>';
		}
		if ( $create ) {
			echo '<a class="lcc-btn" href="' . esc_url( get_permalink( $create ) ) . '">' . esc_html__( 'Create a squad', 'legendcreate-community' ) . '</a> ';
		}
		echo '<a class="lcc-link" href="' . esc_url( get_post_type_archive_link( self::CPT ) ) . '">' . esc_html__( 'Browse squads', 'legendcreate-community' ) . '</a>';
		return ob_get_clean();
	}

	public function directory_shortcode() {
		$squads = get_posts( array(
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 24,
			'meta_query'     => array( array( 'key' => '_lcc_squad_visibility', 'value' => 'public' ) ),
		) );
		ob_start();
		echo '<div class="lcc-shell"><div class="lcc-squad-grid">';
		if ( $squads ) {
			foreach ( $squads as $s ) { echo self::card( $s->ID ); }
		} else {
			echo '<p class="lcc-muted">' . esc_html__( 'No public squads yet. Be the first to create one!', 'legendcreate-community' ) . '</p>';
		}
		echo '</div></div>';
		return ob_get_clean();
	}

	public function create_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="lcc-shell"><div class="lcc-panel lcc-gate"><p>' . esc_html__( 'Log in to create a squad.', 'legendcreate-community' )
				. '</p><a class="lcc-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'legendcreate-community' ) . '</a></div></div>';
		}
		$err = isset( $_GET['lcc_squad_error'] ) ? sanitize_text_field( wp_unslash( $_GET['lcc_squad_error'] ) ) : '';
		ob_start(); ?>
		<div class="lcc-shell"><div class="lcc-panel lcc-auth" style="max-width:620px">
			<h2><?php esc_html_e( 'Create a Squad', 'legendcreate-community' ); ?></h2>
			<?php if ( $err ) : ?><div class="lcc-notice lcc-notice-err"><?php echo esc_html( $err ); ?></div><?php endif; ?>
			<form class="lcc-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lcc_create_squad', 'lcc_squad_nonce' ); ?>
				<input type="hidden" name="action" value="lcc_create_squad">
				<label><?php esc_html_e( 'Squad name', 'legendcreate-community' ); ?><input type="text" name="lcc_squad_name" required maxlength="60"></label>
				<label><?php esc_html_e( 'Description', 'legendcreate-community' ); ?><textarea name="lcc_squad_desc" rows="3"></textarea></label>
				<fieldset><legend><?php esc_html_e( 'Main games', 'legendcreate-community' ); ?></legend>
					<?php foreach ( LCC_Members::MARQUEE_GAMES as $game ) : ?>
						<label class="lcc-check"><input type="checkbox" name="lcc_squad_games[]" value="<?php echo esc_attr( $game ); ?>"> <?php echo esc_html( $game ); ?></label>
					<?php endforeach; ?>
					<label><?php esc_html_e( 'Other games (comma separated)', 'legendcreate-community' ); ?><input type="text" name="lcc_squad_games_other"></label>
				</fieldset>
				<label><?php esc_html_e( 'Region', 'legendcreate-community' ); ?><input type="text" name="lcc_squad_region" placeholder="e.g. EU, NA, Global"></label>
				<label><?php esc_html_e( 'Play schedule', 'legendcreate-community' ); ?><input type="text" name="lcc_squad_schedule" placeholder="e.g. Weeknights 8pm"></label>
				<label><?php esc_html_e( 'Skill level', 'legendcreate-community' ); ?>
					<select name="lcc_squad_skill"><option value=""><?php esc_html_e( '— Any —', 'legendcreate-community' ); ?></option>
						<?php foreach ( self::SKILLS as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option><?php endforeach; ?>
					</select></label>
				<label><?php esc_html_e( 'Existing Discord/community link (optional)', 'legendcreate-community' ); ?><input type="text" name="lcc_squad_discord" placeholder="https://discord.gg/..."></label>
				<label><?php esc_html_e( 'Visibility', 'legendcreate-community' ); ?>
					<select name="lcc_squad_visibility">
						<option value="public"><?php esc_html_e( 'Public — listed and open to join', 'legendcreate-community' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private — invite link only', 'legendcreate-community' ); ?></option>
					</select></label>
				<button type="submit" class="lcc-btn lcc-btn-lg"><?php esc_html_e( 'Create squad', 'legendcreate-community' ); ?></button>
			</form>
		</div></div>
		<?php
		return ob_get_clean();
	}
}

function lcc_squad_create_page() { return (int) get_option( 'lcc_page_squad_create', 0 ); }
