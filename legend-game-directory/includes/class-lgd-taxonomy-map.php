<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Taxonomy Term Mapping.
 *
 * Maps raw imported terms (e.g. GAME_ACTION, Roleplaying, Entertainment) to
 * approved canonical taxonomy slugs. Applied during import normalization so raw
 * source terms never appear publicly. Mappings are stored in lgd_taxonomy_map option.
 */
final class LGD_Taxonomy_Map {

	const OPTION = 'lgd_taxonomy_map';

	const TAXONOMIES = array(
		'game_type'    => 'Game Type',
		'game_platform' => 'Platform',
		'game_genre'   => 'Genre',
		'game_pricing' => 'Pricing',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_lgd_save_taxonomy_map', array( $this, 'save_entry' ) );
		add_action( 'admin_post_lgd_delete_taxonomy_map', array( $this, 'delete_entry' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=game',
			__( 'Taxonomy Map', 'legend-game-directory' ),
			__( 'Taxonomy Map', 'legend-game-directory' ),
			'manage_options',
			'lgd-taxonomy-map',
			array( $this, 'render_page' )
		);
	}

	/** Get the full mapping array: taxonomy → array( raw_lower → canonical_slug ). */
	public static function get_map() {
		$map = get_option( self::OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Return the canonical term slug for a raw imported term in a given taxonomy.
	 * Returns null when no mapping exists (caller should use the original value).
	 */
	public static function canonical( $taxonomy, $raw_term ) {
		$map = self::get_map();
		if ( ! isset( $map[ $taxonomy ] ) ) { return null; }
		$key = strtolower( trim( (string) $raw_term ) );
		return isset( $map[ $taxonomy ][ $key ] ) ? $map[ $taxonomy ][ $key ] : null;
	}

	/**
	 * Normalize a term name for import.
	 * Returns the canonical term name if a mapping exists, empty string if the
	 * canonical is '_drop_' (silently ignore this term), or the original $term_name
	 * when no mapping is set.
	 */
	public static function apply( $taxonomy, $term_name ) {
		$slug = self::canonical( $taxonomy, $term_name );
		if ( '_drop_' === $slug ) { return ''; }
		if ( $slug ) {
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term ) { return $term->name; }
		}
		return $term_name;
	}

	public function save_entry() {
		check_admin_referer( 'lgd_save_taxonomy_map' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); }

		$taxonomy  = sanitize_key( wp_unslash( isset( $_POST['taxonomy'] ) ? $_POST['taxonomy'] : '' ) );
		$raw_term  = strtolower( sanitize_text_field( wp_unslash( isset( $_POST['raw_term'] ) ? $_POST['raw_term'] : '' ) ) );
		$canonical = sanitize_title( wp_unslash( isset( $_POST['canonical_slug'] ) ? $_POST['canonical_slug'] : '' ) );

		if ( ! array_key_exists( $taxonomy, self::TAXONOMIES ) || '' === $raw_term || '' === $canonical ) {
			wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-taxonomy-map', 'lgd_err' => 'invalid' ), admin_url( 'edit.php' ) ) ); exit;
		}

		$map = self::get_map();
		if ( ! isset( $map[ $taxonomy ] ) ) { $map[ $taxonomy ] = array(); }
		$map[ $taxonomy ][ $raw_term ] = $canonical;
		update_option( self::OPTION, $map, false );
		LGD_Logger::log( 'taxonomy_map_saved', "Term mapping added: '{$raw_term}' → '{$canonical}' in {$taxonomy}.", array(), 'info' );

		wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-taxonomy-map', 'lgd_saved' => 1 ), admin_url( 'edit.php' ) ) ); exit;
	}

	public function delete_entry() {
		check_admin_referer( 'lgd_delete_taxonomy_map' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'legend-game-directory' ) ); }

		$taxonomy = sanitize_key( wp_unslash( isset( $_POST['taxonomy'] ) ? $_POST['taxonomy'] : '' ) );
		$raw_term = strtolower( sanitize_text_field( wp_unslash( isset( $_POST['raw_term'] ) ? $_POST['raw_term'] : '' ) ) );
		$map      = self::get_map();
		if ( isset( $map[ $taxonomy ][ $raw_term ] ) ) {
			unset( $map[ $taxonomy ][ $raw_term ] );
			update_option( self::OPTION, $map, false );
			LGD_Logger::log( 'taxonomy_map_deleted', "Term mapping removed: '{$raw_term}' from {$taxonomy}.", array(), 'info' );
		}
		wp_safe_redirect( add_query_arg( array( 'post_type' => 'game', 'page' => 'lgd-taxonomy-map', 'lgd_deleted' => 1 ), admin_url( 'edit.php' ) ) ); exit;
	}

	public function render_page() {
		$map = self::get_map();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Taxonomy Term Mapping', 'legend-game-directory' ); ?></h1>
			<p><?php esc_html_e( 'Map raw imported terms (e.g. GAME_ACTION, Roleplaying, Entertainment) to approved canonical taxonomy slugs. Mappings are applied during the next import run.', 'legend-game-directory' ); ?></p>

			<?php if ( ! empty( $_GET['lgd_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Mapping saved.', 'legend-game-directory' ); ?></p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['lgd_deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Mapping deleted.', 'legend-game-directory' ); ?></p></div><?php endif; ?>
			<?php if ( ! empty( $_GET['lgd_err'] ) ) : ?><div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Invalid input — check all fields and try again.', 'legend-game-directory' ); ?></p></div><?php endif; ?>

			<h2><?php esc_html_e( 'Add Mapping', 'legend-game-directory' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lgd_save_taxonomy_map' ); ?>
				<input type="hidden" name="action" value="lgd_save_taxonomy_map">
				<table class="form-table">
					<tr>
						<th><label for="lgd_map_tax"><?php esc_html_e( 'Taxonomy', 'legend-game-directory' ); ?></label></th>
						<td>
							<select id="lgd_map_tax" name="taxonomy">
								<?php foreach ( self::TAXONOMIES as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="lgd_map_raw"><?php esc_html_e( 'Raw term from import', 'legend-game-directory' ); ?></label></th>
						<td>
							<input id="lgd_map_raw" class="regular-text" name="raw_term" required placeholder="e.g. GAME_ACTION">
							<p class="description"><?php esc_html_e( 'Case-insensitive. Enter the value exactly as it appears in the import source.', 'legend-game-directory' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="lgd_map_can"><?php esc_html_e( 'Canonical slug', 'legend-game-directory' ); ?></label></th>
						<td>
							<input id="lgd_map_can" class="regular-text" name="canonical_slug" required placeholder="e.g. action">
							<p class="description"><?php esc_html_e( 'The exact WordPress term slug from the approved list (e.g. action, rpg, free-games). Use the special value _drop_ to silently ignore a raw term during import without creating it.', 'legend-game-directory' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Mapping', 'legend-game-directory' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Current Mappings', 'legend-game-directory' ); ?></h2>
			<?php if ( empty( $map ) || ! array_filter( $map ) ) : ?>
				<p><?php esc_html_e( 'No mappings defined yet.', 'legend-game-directory' ); ?></p>
			<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Taxonomy', 'legend-game-directory' ); ?></th>
						<th><?php esc_html_e( 'Raw Term (import value)', 'legend-game-directory' ); ?></th>
						<th><?php esc_html_e( 'Canonical Slug', 'legend-game-directory' ); ?></th>
						<th><?php esc_html_e( 'Action', 'legend-game-directory' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $map as $taxonomy => $entries ) : foreach ( $entries as $raw => $canonical ) : ?>
				<tr>
					<td><?php echo esc_html( isset( self::TAXONOMIES[ $taxonomy ] ) ? self::TAXONOMIES[ $taxonomy ] : $taxonomy ); ?></td>
					<td><code><?php echo esc_html( $raw ); ?></code></td>
					<td><code><?php echo esc_html( $canonical ); ?></code></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'lgd_delete_taxonomy_map' ); ?>
							<input type="hidden" name="action" value="lgd_delete_taxonomy_map">
							<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>">
							<input type="hidden" name="raw_term" value="<?php echo esc_attr( $raw ); ?>">
							<button class="button button-small"><?php esc_html_e( 'Delete', 'legend-game-directory' ); ?></button>
						</form>
					</td>
				</tr>
				<?php endforeach; endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
