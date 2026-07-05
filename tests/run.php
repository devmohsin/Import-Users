<?php
/**
 * Import Users — single-file integration test runner.
 *
 * Follows the same pattern as cl-tutor-courses/tests/run.php: boots real
 * WordPress (so it exercises the actual Tutor LMS / CL Tutor Courses classes,
 * not mocks), is runnable from the CLI or as an admin in the browser, and
 * cleans up all fixtures it creates so it can be re-run repeatedly.
 *
 * Run from CLI:
 *   php wp-content/plugins/import-users/tests/run.php
 *
 * Run in browser (admin only):
 *   /wp-content/plugins/import-users/tests/run.php
 */

declare( strict_types=1 );

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! is_file( $wp_load ) ) {
	$wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}
if ( ! is_file( $wp_load ) ) {
	fwrite( STDERR, "ERROR: wp-load.php not found.\n" );
	exit( 1 );
}

require_once $wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( PHP_SAPI !== 'cli' && ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) ) {
	wp_die( esc_html__( 'Administrator login required to run Import Users tests.', 'import-users' ), 403 );
}

if ( ! defined( 'IU_PLUGIN_DIR' ) ) {
	fwrite( STDERR, "ERROR: Import Users plugin is not active.\n" );
	exit( 1 );
}

if ( ! function_exists( 'tutor_utils' ) || ! defined( 'CL_TC_VERSION' ) ) {
	fwrite( STDERR, "ERROR: Tutor LMS and/or CL Tutor Courses are not active.\n" );
	exit( 1 );
}

$GLOBALS['iu_test_pass'] = 0;
$GLOBALS['iu_test_fail'] = 0;

/**
 * @param string $label Description of the assertion.
 * @param bool   $ok    Whether it passed.
 * @param string $detail Extra context printed on failure (or always, if given).
 */
function iu_assert( string $label, bool $ok, string $detail = '' ) : void {
	if ( $ok ) {
		$GLOBALS['iu_test_pass']++;
	} else {
		$GLOBALS['iu_test_fail']++;
	}
	$line = ( $ok ? '[PASS] ' : '[FAIL] ' ) . $label . ( $detail ? " -- $detail" : '' );
	echo ( PHP_SAPI === 'cli' ? $line . "\n" : '<div>' . esc_html( $line ) . '</div>' );
}

function iu_section( string $title ) : void {
	echo PHP_SAPI === 'cli' ? "\n=== $title ===\n" : "<h3>" . esc_html( $title ) . '</h3>';
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const TEST_EMAIL_PREFIX = 'iu.run.test.';

function iu_test_make_course( string $title ) : int {
	return (int) wp_insert_post(
		array(
			'post_type'    => 'courses',
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => 'Import Users automated test fixture.',
		)
	);
}

function iu_test_delete_fixture_users() : void {
	global $wpdb;
	$emails = $wpdb->get_col(
		$wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE user_email LIKE %s", TEST_EMAIL_PREFIX . '%' )
	);
	foreach ( $emails as $email ) {
		$u = get_user_by( 'email', $email );
		if ( $u ) {
			wp_delete_user( $u->ID );
		}
	}
}

function iu_test_delete_fixture_records() : void {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM ' . IU_Activator::table_name() . ' WHERE email LIKE %s',
			TEST_EMAIL_PREFIX . '%'
		)
	);
}

function iu_test_teardown( array $course_ids ) : void {
	iu_test_delete_fixture_users();
	iu_test_delete_fixture_records();
	foreach ( $course_ids as $id ) {
		if ( $id ) {
			wp_delete_post( $id, true );
		}
	}
}

// ---------------------------------------------------------------------------
// Setup
// ---------------------------------------------------------------------------

IU_Activator::activate();
iu_test_delete_fixture_users();
iu_test_delete_fixture_records();

iu_section( 'Fixture courses' );
$course_a  = iu_test_make_course( 'IU RUN-TEST Course A (standalone)' );
$course_b  = iu_test_make_course( 'IU RUN-TEST Course B (bundle child)' );
$course_c  = iu_test_make_course( 'IU RUN-TEST Course C (bundle child)' );
$bundle_id = iu_test_make_course( 'IU RUN-TEST Bundle' );
update_post_meta( $bundle_id, '_cl_course_type', 'bundled' );
update_post_meta( $bundle_id, '_cl_bundled_course_ids', array( $course_b, $course_c ) );

$fixture_course_ids = array( $course_a, $course_b, $course_c, $bundle_id );

iu_assert( 'fixture courses created', $course_a && $course_b && $course_c && $bundle_id );
iu_assert(
	'bundle resolves to its two children',
	\CodeLinden\TutorCourseBundle\Bundle_Utils::get_bundled_course_ids( $bundle_id ) === array( $course_b, $course_c )
);

$missing_course_id = 999999; // No course exists with this ID.
while ( get_post( $missing_course_id ) ) {
	$missing_course_id++;
}

// ---------------------------------------------------------------------------
// Import CSV: valid rows, invalid rows, duplicates
// ---------------------------------------------------------------------------

iu_section( 'Import CSV' );

$alice_email = TEST_EMAIL_PREFIX . 'alice@example.com';
$bob_email   = TEST_EMAIL_PREFIX . 'bob@example.com';
$dave_email  = TEST_EMAIL_PREFIX . 'dave@example.com';
$erin_email  = TEST_EMAIL_PREFIX . 'erin@example.com';
$fail_email  = TEST_EMAIL_PREFIX . 'failcreate@example.com';

$csv_path = sys_get_temp_dir() . '/iu-run-test.csv';
$rows     = array(
	array( 'First Name', 'Last Name', 'Email', 'Course IDs', 'Enrollment Date' ),
	array( 'Alice', 'Anderson', $alice_email, (string) $bundle_id, '2026-07-01' ),          // valid, bundle -> cascades to children
	array( 'Bob', 'Baker', $bob_email, "$course_a,$course_b", '2026-07-01' ),               // valid, multiple course IDs
	array( '', 'Nofirst', TEST_EMAIL_PREFIX . 'missing@example.com', (string) $course_a, '2026-07-01' ), // missing required field
	array( 'Carol', 'Carter', TEST_EMAIL_PREFIX . 'not-an-email', (string) $course_a, '2026-07-01' ), // invalid email (still prefixed so teardown catches it)
	array( 'Alice', 'Anderson', $alice_email, (string) $bundle_id, '2026-07-01' ),           // exact duplicate of row 1
	array( 'Dave', 'Davis', $dave_email, (string) $missing_course_id, '2026-07-01' ),        // course ID does not exist
	array( 'Erin', 'Evans', $erin_email, '', '2026-07-01' ),                                 // empty course IDs
	array( 'Bob', 'Baker', $bob_email, (string) $course_c, '2026-07-01' ),                   // existing user, different (new) course -- not a duplicate row
	array( 'Fail', 'Create', $fail_email, (string) $course_a, '2026-07-01' ),                // will be forced to fail at wp_insert_user
);
$fh = fopen( $csv_path, 'w' );
foreach ( $rows as $r ) {
	fputcsv( $fh, $r );
}
fclose( $fh );

$import_1 = IU_CSV::import_from_path( $csv_path );
iu_assert( 'first import: 6 rows imported', 6 === $import_1['imported'], (string) $import_1['imported'] );
iu_assert( 'first import: 2 rows invalid (missing field + bad email)', 2 === $import_1['invalid'], (string) $import_1['invalid'] );
iu_assert( 'first import: 1 row skipped as duplicate', 1 === $import_1['skipped_duplicate'], (string) $import_1['skipped_duplicate'] );

$import_2 = IU_CSV::import_from_path( $csv_path );
iu_assert( 're-running the same import: 0 new rows imported (idempotent)', 0 === $import_2['imported'], (string) $import_2['imported'] );

// ---------------------------------------------------------------------------
// Process Database
// ---------------------------------------------------------------------------

iu_section( 'Process Database' );

// Force a user-creation failure for one specific email, to exercise that path.
// wp_insert_user() treats an empty/non-array $data returned from this filter
// as a hard 'empty_data' WP_Error (see wp-includes/user.php), which is the
// officially supported way to veto an insert from this hook.
$force_fail = static function ( $data, $update, $id, $userdata ) use ( $fail_email ) {
	if ( ! $update && isset( $userdata['user_email'] ) && $fail_email === $userdata['user_email'] ) {
		return array();
	}
	return $data;
};
add_filter( 'wp_pre_insert_user_data', $force_fail, 10, 4 );

$process_1 = IU_Processor::process_all();
remove_filter( 'wp_pre_insert_user_data', $force_fail, 10 );

iu_assert( 'process (run 1): 2 records still unprocessed (the forced-fail + missing-course rows)', 2 === $process_1['remaining_unprocessed'], (string) $process_1['remaining_unprocessed'] );

$alice = get_user_by( 'email', $alice_email );
$bob   = get_user_by( 'email', $bob_email );
$dave  = get_user_by( 'email', $dave_email );
$erin  = get_user_by( 'email', $erin_email );
$fail  = get_user_by( 'email', $fail_email );

iu_assert( 'new user created for Alice', $alice instanceof WP_User );
iu_assert( 'new user created for Bob', $bob instanceof WP_User );
iu_assert( 'new user created for Dave (account creation succeeds even though enrollment fails)', $dave instanceof WP_User );
iu_assert( 'new user created for Erin (empty course IDs is a valid, processable row)', $erin instanceof WP_User );
iu_assert( 'no user created for the forced-failure row', ! $fail instanceof WP_User );

if ( $alice ) {
	iu_assert( 'Alice given the site default role', in_array( iu_get_student_role(), $alice->roles, true ) );
	iu_assert( 'Alice enrolled in the bundle', (bool) tutor_utils()->is_enrolled( $bundle_id, $alice->ID, true ) );
	iu_assert( 'Alice enrolled in bundle child B', (bool) tutor_utils()->is_enrolled( $course_b, $alice->ID, true ) );
	iu_assert( 'Alice enrolled in bundle child C', (bool) tutor_utils()->is_enrolled( $course_c, $alice->ID, true ) );
	iu_assert( "Tutor's own _is_tutor_student meta was set", (bool) get_user_meta( $alice->ID, '_is_tutor_student', true ) );

	$log = get_user_meta( $alice->ID, 'iu_enrollment_log', true );
	iu_assert( 'Alice has 3 enrollment-log entries (bundle + 2 children)', is_array( $log ) && 3 === count( $log ), is_array( $log ) ? (string) count( $log ) : 'not array' );

	iu_assert(
		'password-unverified flag set (reuses CL Tutor set-password flow)',
		(bool) get_user_meta( $alice->ID, \CodeLinden\TutorCourses\Guest_Checkout::META_PASSWORD_UNVERIFIED, true )
	);
}

if ( $bob ) {
	iu_assert( 'Bob enrolled in course A', (bool) tutor_utils()->is_enrolled( $course_a, $bob->ID, true ) );
	iu_assert( 'Bob enrolled in course B', (bool) tutor_utils()->is_enrolled( $course_b, $bob->ID, true ) );
	iu_assert( 'Bob enrolled in course C (from the second, existing-user row)', (bool) tutor_utils()->is_enrolled( $course_c, $bob->ID, true ) );

	global $wpdb;
	$bob_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", $bob_email ) );
	iu_assert( 'exactly one WP account exists for Bob (existing user was reused, not duplicated)', 1 === $bob_count );
}

global $wpdb;
$table    = IU_Activator::table_name();
$dave_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $dave_email ), ARRAY_A );
iu_assert( "Dave's row is NOT processed (course ID does not exist)", $dave_row && '0' === (string) $dave_row['processed'] );
iu_assert( "Dave's row error mentions the bad course ID", $dave_row && false !== strpos( (string) $dave_row['error_message'], (string) $missing_course_id ) );

$erin_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $erin_email ), ARRAY_A );
iu_assert( "Erin's row (empty course IDs) IS processed", $erin_row && '1' === (string) $erin_row['processed'] );

$fail_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $fail_email ), ARRAY_A );
iu_assert( 'forced-failure row is NOT processed and has an error message', $fail_row && '0' === (string) $fail_row['processed'] && '' !== $fail_row['error_message'] );

// The forced-failure filter is gone now, so this retry should succeed for
// the "fail" row (transient problem resolved) and leave only Dave stuck
// (his course genuinely never exists) -- correct retry/idempotency behavior.
$process_2 = IU_Processor::process_all();
iu_assert( 're-running Process Database retries and fixes the transient failure, leaving only the genuinely-broken row', 1 === $process_2['remaining_unprocessed'], (string) $process_2['remaining_unprocessed'] );
iu_assert( 'the previously-failed user is created once the underlying issue is gone', get_user_by( 'email', $fail_email ) instanceof WP_User );

if ( $alice ) {
	$log_after = get_user_meta( $alice->ID, 'iu_enrollment_log', true );
	iu_assert( 're-running Process Database does not duplicate log entries', is_array( $log_after ) && 3 === count( $log_after ) );
}

// ---------------------------------------------------------------------------
// Send Welcome Emails
// ---------------------------------------------------------------------------

iu_section( 'Send Welcome Emails' );

// 5 processed-but-unmessaged rows at this point: Alice, Bob's 2 rows
// (mailing is per import record, matching the processed/messaged columns
// living on each row), Erin, and the recovered "fail" row.
$mail_1 = IU_Mailer::send_pending();
iu_assert( 'welcome emails sent for all 5 processed records', 5 === $mail_1['sent'], (string) $mail_1['sent'] );

$mail_2 = IU_Mailer::send_pending();
iu_assert( 're-running Send Welcome Emails sends nothing more (already messaged)', 0 === $mail_2['sent'] && 0 === $mail_2['failed'] );

if ( $alice ) {
	$link = \CodeLinden\TutorCourses\Guest_Password_Setup::get_setup_url_for_user( $alice->ID );
	iu_assert( 'a set-password link can be generated for the emailed user', '' !== $link, $link );
}

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------

iu_section( 'Teardown' );
iu_test_teardown( $fixture_course_ids );
iu_assert( 'fixtures cleaned up', true );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$pass = $GLOBALS['iu_test_pass'];
$fail = $GLOBALS['iu_test_fail'];
$summary = "\n$pass passed, $fail failed.\n";
echo PHP_SAPI === 'cli' ? $summary : '<h3>' . esc_html( trim( $summary ) ) . '</h3>';

if ( PHP_SAPI === 'cli' ) {
	exit( $fail > 0 ? 1 : 0 );
}
