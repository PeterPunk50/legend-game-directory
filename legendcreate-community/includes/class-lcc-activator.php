<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Activation routine. Idempotent — safe to run on every activation/upgrade.
 */
final class LCC_Activator {

	const DB_VERSION = 1;

	public static function install() {
		LCC_Roles::install_roles();
		LCC_Memberships::schedule();
		update_option( 'lcc_db_version', self::DB_VERSION );
		update_option( 'lcc_installed_at', get_option( 'lcc_installed_at', current_time( 'mysql', true ) ) );
	}
}
