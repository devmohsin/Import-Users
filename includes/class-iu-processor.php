<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Process Database: find-or-create the WP user, enroll into all course IDs
 * (expanding bundles into their children), verify, then mark the row processed.
 */
class IU_Processor {

	/**
	 * @return array{succeeded:int, failed:int, remaining_unprocessed:int}
	 */
	public static function process_all() {
		global $wpdb;
		$table = IU_Activator::table_name();

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE processed = 0 AND status != 'invalid'",
			ARRAY_A
		);

		$succeeded = 0;
		$failed    = 0;

		foreach ( $rows as $row ) {
			if ( self::process_record( $row ) ) {
				$succeeded++;
			} else {
				$failed++;
			}
		}

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE processed = 0 AND status != 'invalid'"
		);

		return array(
			'succeeded'             => $succeeded,
			'failed'                => $failed,
			'remaining_unprocessed' => $remaining,
		);
	}

	/**
	 * @param array $record Row from iu_import_records.
	 * @return bool True if the record is now fully processed and verified.
	 */
	public static function process_record( array $record ) {
		$user = self::find_or_create_user( $record );

		if ( ! $user['user_id'] ) {
			self::mark_error( $record['id'], $user['error'] );
			return false;
		}

		if ( ! self::verify_user( $user['user_id'] ) ) {
			self::mark_error( $record['id'], __( 'User existence/role could not be verified.', 'import-users' ), $user['user_id'] );
			return false;
		}

		$course_ids = self::parse_course_ids( $record['course_ids'] );
		$resolved   = IU_Enrollment::resolve_with_bundle_children( $course_ids );

		$errors = array();
		foreach ( $resolved as $item ) {
			$result = IU_Enrollment::ensure_enrolled( $user['user_id'], $item['course_id'] );

			if ( $result['ok'] ) {
				self::log_grant( $user['user_id'], $item['course_id'], $item['parent_bundle_id'], (int) $record['id'] );
			} else {
				$errors[] = $result['message'];
			}
		}

		if ( ! empty( $errors ) ) {
			self::mark_error( $record['id'], implode( '; ', $errors ), $user['user_id'] );
			return false;
		}

		self::mark_processed( $record['id'], $user['user_id'] );
		return true;
	}

	/**
	 * @return array{user_id:int, created:bool, error:string}
	 */
	private static function find_or_create_user( array $record ) {
		$email = sanitize_email( $record['email'] );

		if ( ! is_email( $email ) ) {
			return array(
				'user_id' => 0,
				'created' => false,
				'error'   => __( 'Invalid email address.', 'import-users' ),
			);
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			return array(
				'user_id' => (int) $existing->ID,
				'created' => false,
				'error'   => '',
			);
		}

		$username = self::generate_unique_username( $record['first_name'], $record['last_name'], $email );
		$password = wp_generate_password( 20, true, true );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => $password,
				'first_name'   => $record['first_name'],
				'last_name'    => $record['last_name'],
				'display_name' => trim( $record['first_name'] . ' ' . $record['last_name'] ),
				'role'         => iu_get_student_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return array(
				'user_id' => 0,
				'created' => false,
				'error'   => $user_id->get_error_message(),
			);
		}

		// Mark the account the same way CL Tutor's guest-checkout flow does, so the
		// existing set-password email/page logic (Task 6) works unmodified for it.
		if ( class_exists( '\CodeLinden\TutorCourses\Guest_Checkout' ) ) {
			update_user_meta( $user_id, \CodeLinden\TutorCourses\Guest_Checkout::META_PASSWORD_UNVERIFIED, 1 );
		}

		return array(
			'user_id' => (int) $user_id,
			'created' => true,
			'error'   => '',
		);
	}

	private static function generate_unique_username( $first_name, $last_name, $email ) {
		$base = sanitize_user( strtolower( str_replace( '@', '.', $email ) ), true );

		if ( '' === $base ) {
			$base = sanitize_user( strtolower( $first_name . '.' . $last_name ), true );
		}
		if ( '' === $base ) {
			$base = 'student';
		}

		$username = $base;
		$suffix   = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $suffix;
			$suffix++;
		}

		return $username;
	}

	private static function verify_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		return $user instanceof WP_User && ! empty( $user->roles );
	}

	private static function parse_course_ids( $course_ids_csv ) {
		if ( '' === trim( (string) $course_ids_csv ) ) {
			return array();
		}

		$ids = array_map( 'absint', explode( ',', (string) $course_ids_csv ) );
		return array_values( array_filter( $ids ) );
	}

	private static function log_grant( $user_id, $course_id, $parent_bundle_id, $import_record_id ) {
		$log = get_user_meta( $user_id, 'iu_enrollment_log', true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		foreach ( $log as $entry ) {
			if ( (int) ( $entry['course_id'] ?? 0 ) === (int) $course_id
				&& (int) ( $entry['import_record_id'] ?? 0 ) === (int) $import_record_id ) {
				return; // Already logged on a previous run of Process Database.
			}
		}

		$course_title = get_the_title( $course_id );
		if ( '' === $course_title ) {
			$course_title = sprintf( 'Course #%d', $course_id );
		}

		if ( $parent_bundle_id ) {
			$reason = sprintf(
				/* translators: 1: course ID, 2: course title, 3: bundle course ID */
				__( 'User was granted access to course ID %1$d ("%2$s") as a child of bundle course ID %3$d, from the spreadsheet import.', 'import-users' ),
				$course_id,
				$course_title,
				$parent_bundle_id
			);
		} else {
			$reason = sprintf(
				/* translators: 1: course ID, 2: course title */
				__( 'User was granted access to course ID %1$d ("%2$s") from the spreadsheet import.', 'import-users' ),
				$course_id,
				$course_title
			);
		}

		$log[] = array(
			'course_id'         => (int) $course_id,
			'parent_bundle_id'  => $parent_bundle_id ? (int) $parent_bundle_id : null,
			'import_record_id'  => (int) $import_record_id,
			'reason'            => $reason,
			'granted_at'        => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, 'iu_enrollment_log', $log );
	}

	private static function mark_processed( $id, $user_id ) {
		global $wpdb;
		$wpdb->update(
			IU_Activator::table_name(),
			array(
				'processed'     => 1,
				'status'        => 'processed',
				'user_id'       => $user_id,
				'error_message' => '',
			),
			array( 'id' => $id )
		);
	}

	private static function mark_error( $id, $message, $user_id = 0 ) {
		global $wpdb;
		$data   = array(
			'status'        => 'error',
			'error_message' => $message,
		);
		$format = array( '%s', '%s' );

		if ( $user_id ) {
			$data['user_id'] = $user_id;
			$format[]        = '%d';
		}

		$wpdb->update( IU_Activator::table_name(), $data, array( 'id' => $id ), $format, array( '%d' ) );
	}
}
