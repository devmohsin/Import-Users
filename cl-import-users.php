<?php
/**
 * Plugin Name: Import Users
 * Description: Bulk-import students from CSV, provision their WordPress accounts, enroll them into Tutor LMS courses (including bundles), and send welcome/set-password emails.
 * Version: 1.0.0
 * Author: Mohsin Ghouri
 * Text Domain: cl-import-users
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IU_PLUGIN_FILE', __FILE__ );
define( 'IU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'IU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'IU_VERSION', '1.0.0' );
define( 'IU_DB_VERSION', '1.0.0' );

require_once IU_PLUGIN_DIR . 'includes/class-iu-logger.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-activator.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-csv.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-enrollment.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-processor.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-mailer.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-admin-page.php';
require_once IU_PLUGIN_DIR . 'includes/class-iu-users-page.php';

register_activation_hook( IU_PLUGIN_FILE, array( 'IU_Activator', 'activate' ) );

/**
 * Bail out (with an admin notice) if the plugins this one depends on
 * are missing, instead of fataling on a missing function/class.
 */
function iu_missing_dependencies() {
	$missing = array();

	if ( ! function_exists( 'tutor' ) ) {
		$missing[] = 'Tutor LMS';
	}

	if ( ! defined( 'CL_TC_VERSION' ) ) {
		$missing[] = 'CL Tutor Courses';
	}

	return $missing;
}

/**
 * The role newly-imported users are given.
 *
 * Neither Tutor LMS nor CL Tutor Courses register a dedicated "student"
 * role — Tutor LMS marks students purely via the `_is_tutor_student` user
 * meta flag, which is set automatically as a side effect of enrollment
 * (see Utils::do_enroll()). So the role assigned here is simply the site's
 * normal default new-user role, matching how every other real student
 * account on the site is set up.
 *
 * @return string
 */
function iu_get_student_role() {
	return apply_filters( 'iu_student_role', get_option( 'default_role', 'subscriber' ) );
}

function iu_admin_dependency_notice() {
	$missing = iu_missing_dependencies();

	if ( empty( $missing ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: comma-separated list of missing plugin names */
				__( 'Import Users requires the following plugin(s) to be active: %s. The plugin has been deactivated.', 'cl-import-users' ),
				implode( ', ', $missing )
			)
		)
	);
}

function iu_maybe_self_deactivate() {
	if ( ! empty( iu_missing_dependencies() ) && is_plugin_active( plugin_basename( IU_PLUGIN_FILE ) ) ) {
		deactivate_plugins( plugin_basename( IU_PLUGIN_FILE ) );
		add_action( 'admin_notices', 'iu_admin_dependency_notice' );

		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}
}
add_action( 'admin_init', 'iu_maybe_self_deactivate' );

/**
 * Boot the admin page + admin-post handlers only in wp-admin.
 */
function iu_bootstrap() {
	if ( ! empty( iu_missing_dependencies() ) ) {
		return;
	}

	new IU_Admin_Page();
	new IU_Users_Page();
}
add_action( 'plugins_loaded', 'iu_bootstrap' );
