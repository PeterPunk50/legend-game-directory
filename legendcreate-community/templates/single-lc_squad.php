<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();
the_post();

$squad_id  = get_the_ID();
$uid       = get_current_user_id();
$is_member = $uid && LCC_Squads::is_member( $squad_id, $uid );
$role      = $is_member ? LCC_Squads::member_role( $squad_id, $uid ) : '';
$is_leader = 'leader' === $role;
$visibility = LCC_Squads::visibility( $squad_id );
$games     = LCC_Squads::games( $squad_id );
$members   = LCC_Squads::get_members( $squad_id );
$region    = get_post_meta( $squad_id, '_lcc_squad_region', true );
$schedule  = get_post_meta( $squad_id, '_lcc_squad_schedule', true );
$skill     = get_post_meta( $squad_id, '_lcc_squad_skill', true );
$discord   = get_post_meta( $squad_id, '_lcc_squad_discord', true );
$invite    = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : '';
$msg       = isset( $_GET['lcc_squad_msg'] ) ? sanitize_key( wp_unslash( $_GET['lcc_squad_msg'] ) ) : '';
?>
<main class="lcc-shell" style="padding:30px 0">
	<div class="lcc-container" style="width:min(1080px,calc(100% - 32px));margin:auto">

		<?php if ( 'joined' === $msg ) : ?><div class="lcc-notice lcc-notice-ok"><?php esc_html_e( 'Welcome to the squad!', 'legendcreate-community' ); ?></div>
		<?php elseif ( 'left' === $msg ) : ?><div class="lcc-notice lcc-notice-ok"><?php esc_html_e( 'You left the squad.', 'legendcreate-community' ); ?></div>
		<?php elseif ( 'invite' === $msg ) : ?><div class="lcc-notice lcc-notice-err"><?php esc_html_e( 'This squad is private — a valid invite link is required.', 'legendcreate-community' ); ?></div><?php endif; ?>

		<div class="lcc-squad-hero">
			<div class="lcc-squad-hero__logo">
				<?php echo has_post_thumbnail() ? get_the_post_thumbnail( $squad_id, 'medium' ) : '<span class="lcc-squad-initial lcc-squad-initial-lg">' . esc_html( mb_substr( get_the_title(), 0, 1 ) ) . '</span>'; ?>
			</div>
			<div>
				<h1><?php the_title(); ?></h1>
				<?php if ( $games ) : ?><p class="lcc-squad-games"><?php echo esc_html( implode(' · ', $games ) ); ?></p><?php endif; ?>
				<p class="lcc-muted">
					<?php echo esc_html( sprintf( _n( '%d member', '%d members', count( $members ), 'legendcreate-community' ), count( $members ) ) ); ?>
					<?php if ( $region ) : ?> · <?php echo esc_html( $region ); ?><?php endif; ?>
					<?php if ( $skill ) : ?> · <?php echo esc_html( $skill ); ?><?php endif; ?>
					· <?php echo 'private' === $visibility ? esc_html__( 'Private', 'legendcreate-community' ) : esc_html__( 'Public', 'legendcreate-community' ); ?>
				</p>

				<div class="lcc-squad-actions">
					<?php if ( ! is_user_logged_in() ) : ?>
						<a class="lcc-btn" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in to join', 'legendcreate-community' ); ?></a>
					<?php elseif ( $is_member ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Leave this squad?', 'legendcreate-community' ) ); ?>');">
							<?php wp_nonce_field( 'lcc_leave_squad', 'lcc_leave_nonce' ); ?>
							<input type="hidden" name="action" value="lcc_leave_squad">
							<input type="hidden" name="squad_id" value="<?php echo esc_attr( $squad_id ); ?>">
							<button class="lcc-btn lcc-btn-ghost" type="submit"><?php echo $is_leader ? esc_html__( 'Leave (transfers lead)', 'legendcreate-community' ) : esc_html__( 'Leave squad', 'legendcreate-community' ); ?></button>
						</form>
					<?php elseif ( 'public' === $visibility || hash_equals( LCC_Squads::invite_code( $squad_id ), $invite ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'lcc_join_squad', 'lcc_join_nonce' ); ?>
							<input type="hidden" name="action" value="lcc_join_squad">
							<input type="hidden" name="squad_id" value="<?php echo esc_attr( $squad_id ); ?>">
							<input type="hidden" name="invite" value="<?php echo esc_attr( $invite ); ?>">
							<button class="lcc-btn" type="submit"><?php esc_html_e( 'Join squad', 'legendcreate-community' ); ?></button>
						</form>
					<?php else : ?>
						<span class="lcc-muted"><?php esc_html_e( 'Private squad — invite only.', 'legendcreate-community' ); ?></span>
					<?php endif; ?>

					<?php if ( $discord ) : ?><a class="lcc-btn lcc-btn-ghost" rel="nofollow noopener" target="_blank" href="<?php echo esc_url( $discord ); ?>"><?php esc_html_e( 'Discord', 'legendcreate-community' ); ?></a><?php endif; ?>
				</div>
			</div>
		</div>

		<div class="lcc-grid" style="margin-top:24px">
			<div class="lcc-panel">
				<h3><?php esc_html_e( 'About', 'legendcreate-community' ); ?></h3>
				<?php the_content(); ?>
				<?php if ( $schedule ) : ?><p class="lcc-muted"><?php echo esc_html( sprintf( __( 'Plays: %s', 'legendcreate-community' ), $schedule ) ); ?></p><?php endif; ?>

				<?php if ( $is_member ) :
					$link = add_query_arg( 'invite', LCC_Squads::invite_code( $squad_id ), get_permalink( $squad_id ) ); ?>
					<h3><?php esc_html_e( 'Invite your crew', 'legendcreate-community' ); ?></h3>
					<input class="lcc-invite" type="text" readonly onclick="this.select()" value="<?php echo esc_url( $link ); ?>">
					<p class="lcc-muted" style="font-size:.82rem"><?php esc_html_e( 'Share this link with friends to add them to the squad.', 'legendcreate-community' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="lcc-panel">
				<h3><?php esc_html_e( 'Members', 'legendcreate-community' ); ?></h3>
				<ul class="lcc-member-list">
					<?php foreach ( $members as $m ) : $u = get_userdata( $m->user_id ); if ( ! $u ) { continue; } ?>
						<li>
							<?php echo get_avatar( $m->user_id, 32 ); ?>
							<span><?php echo esc_html( $u->display_name ); ?></span>
							<?php if ( 'leader' === $m->role ) : ?><span class="lcc-badge lcc-badge-free"><?php esc_html_e( 'Leader', 'legendcreate-community' ); ?></span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

	</div>
</main>
<?php get_footer(); ?>
