<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IU_Activator {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'iu_import_records';
	}

	public static function activate() {
		self::maybe_upgrade();
	}

	/**
	 * Also called on plugins_loaded-ish timing indirectly via admin_init,
	 * so a plugin update that changes the schema doesn't require deactivate/reactivate.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'iu_db_version' ) === IU_DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			first_name VARCHAR(255) NOT NULL DEFAULT '',
			last_name VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(255) NOT NULL,
			course_ids TEXT NULL,
			enrollment_date DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			processed TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			messaged TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			error_message TEXT NULL,
			user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY processed (processed),
			KEY messaged (messaged)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'iu_db_version', IU_DB_VERSION );
	}
}
