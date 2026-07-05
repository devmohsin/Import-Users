<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Demo CSV generation + import CSV parsing/validation/dedup.
 */
class IU_CSV {

	/**
	 * Canonical column key => list of header aliases (normalized: lowercase,
	 * non-alphanumeric collapsed to underscore) that should map to it.
	 */
	const HEADER_ALIASES = array(
		'first_name'      => array( 'first_name', 'firstname' ),
		'last_name'       => array( 'last_name', 'lastname' ),
		'email'           => array( 'email', 'email_address' ),
		'course_ids'      => array( 'course_ids', 'course_id', 'enrolled_course_ids' ),
		'enrollment_date' => array( 'enrollment_date', 'date_of_enrollment' ),
	);

	private static function normalize_header( $header ) {
		$header = strtolower( trim( $header ) );
		$header = preg_replace( '/[^a-z0-9]+/', '_', $header );
		return trim( $header, '_' );
	}

	private static function map_columns( array $header_row ) {
		$map = array();

		foreach ( $header_row as $index => $raw_header ) {
			$normalized = self::normalize_header( $raw_header );

			foreach ( self::HEADER_ALIASES as $canonical => $aliases ) {
				if ( in_array( $normalized, $aliases, true ) ) {
					$map[ $canonical ] = $index;
					break;
				}
			}
		}

		return $map;
	}

	public static function stream_demo_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'import-users' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="demo-import-users.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'First Name', 'Last Name', 'Email', 'Course IDs', 'Enrollment Date' ) );
		fputcsv( $out, array( 'Jane', 'Doe', 'jane.doe@example.com', '101,102', gmdate( 'Y-m-d' ) ) );
		fputcsv( $out, array( 'John', 'Smith', 'john.smith@example.com', '103', gmdate( 'Y-m-d' ) ) );
		fclose( $out );
		exit;
	}

	/**
	 * @param string $tmp_path Path to the uploaded (temp) CSV file.
	 * @return array {
	 *     @type int   $imported
	 *     @type int   $skipped_duplicate
	 *     @type int   $invalid
	 *     @type int   $total_rows
	 *     @type string[] $errors  Human-readable per-row messages (invalid + duplicate), capped for display.
	 * }
	 */
	public static function import_from_path( $tmp_path ) {
		$result = array(
			'imported'          => 0,
			'skipped_duplicate' => 0,
			'invalid'           => 0,
			'total_rows'        => 0,
			'errors'            => array(),
		);

		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			$result['errors'][] = __( 'Could not open the uploaded file.', 'import-users' );
			return $result;
		}

		$header_row = fgetcsv( $handle );
		if ( ! $header_row ) {
			fclose( $handle );
			$result['errors'][] = __( 'The CSV file is empty.', 'import-users' );
			return $result;
		}

		$columns = self::map_columns( $header_row );

		foreach ( array( 'first_name', 'last_name', 'email' ) as $required_column ) {
			if ( ! isset( $columns[ $required_column ] ) ) {
				fclose( $handle );
				$result['errors'][] = sprintf(
					/* translators: %s: missing column name */
					__( 'CSV is missing the required "%s" column.', 'import-users' ),
					$required_column
				);
				return $result;
			}
		}

		global $wpdb;
		$table = IU_Activator::table_name();

		// Existing signatures already in the table, so we can dedupe against past imports too.
		$existing_signatures = array();
		$existing_rows       = $wpdb->get_results( "SELECT email, course_ids FROM {$table}", ARRAY_A );
		foreach ( $existing_rows as $row ) {
			$existing_signatures[ self::signature( $row['email'], $row['course_ids'] ) ] = true;
		}

		$seen_in_batch = array();
		$line_number   = 1; // header was line 1

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$line_number++;

			// Skip fully blank lines some spreadsheet exports leave behind.
			if ( count( $row ) === 1 && trim( (string) $row[0] ) === '' ) {
				continue;
			}

			$result['total_rows']++;

			$first_name = isset( $row[ $columns['first_name'] ] ) ? trim( $row[ $columns['first_name'] ] ) : '';
			$last_name  = isset( $row[ $columns['last_name'] ] ) ? trim( $row[ $columns['last_name'] ] ) : '';
			$email      = isset( $row[ $columns['email'] ] ) ? trim( $row[ $columns['email'] ] ) : '';
			$raw_courses = isset( $columns['course_ids'], $row[ $columns['course_ids'] ] ) ? $row[ $columns['course_ids'] ] : '';
			$raw_date    = isset( $columns['enrollment_date'], $row[ $columns['enrollment_date'] ] ) ? $row[ $columns['enrollment_date'] ] : '';

			$course_ids = self::normalize_course_ids( $raw_courses );
			$enrollment_date = self::normalize_date( $raw_date );

			$row_errors = array();

			if ( '' === $first_name ) {
				$row_errors[] = __( 'first name is required', 'import-users' );
			}
			if ( '' === $last_name ) {
				$row_errors[] = __( 'last name is required', 'import-users' );
			}
			if ( '' === $email ) {
				$row_errors[] = __( 'email is required', 'import-users' );
			} elseif ( ! is_email( $email ) ) {
				$row_errors[] = __( 'email is not a valid address', 'import-users' );
			}

			if ( ! empty( $row_errors ) ) {
				$result['invalid']++;
				$message = sprintf(
					/* translators: 1: line number, 2: comma-separated list of problems */
					__( 'Row %1$d skipped: %2$s.', 'import-users' ),
					$line_number,
					implode( ', ', $row_errors )
				);
				$result['errors'][] = $message;

				$wpdb->insert(
					$table,
					array(
						'first_name'      => $first_name,
						'last_name'       => $last_name,
						'email'           => $email,
						'course_ids'      => $course_ids,
						'enrollment_date' => $enrollment_date,
						'status'          => 'invalid',
						'error_message'   => implode( '; ', $row_errors ),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				continue;
			}

			$signature = self::signature( $email, $course_ids );

			if ( isset( $existing_signatures[ $signature ] ) || isset( $seen_in_batch[ $signature ] ) ) {
				$result['skipped_duplicate']++;
				$result['errors'][] = sprintf(
					/* translators: 1: line number, 2: email address */
					__( 'Row %1$d skipped: duplicate of an existing import for %2$s with the same course IDs.', 'import-users' ),
					$line_number,
					$email
				);
				continue;
			}

			$seen_in_batch[ $signature ] = true;

			$inserted = $wpdb->insert(
				$table,
				array(
					'first_name'      => $first_name,
					'last_name'       => $last_name,
					'email'           => $email,
					'course_ids'      => $course_ids,
					'enrollment_date' => $enrollment_date,
					'status'          => 'pending',
					'processed'       => 0,
					'messaged'        => 0,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )
			);

			if ( false === $inserted ) {
				$result['invalid']++;
				$result['errors'][] = sprintf(
					/* translators: 1: line number, 2: database error */
					__( 'Row %1$d could not be saved: %2$s', 'import-users' ),
					$line_number,
					$wpdb->last_error
				);
				continue;
			}

			$existing_signatures[ $signature ] = true;
			$result['imported']++;
		}

		fclose( $handle );

		return $result;
	}

	private static function signature( $email, $course_ids_csv ) {
		$ids = self::normalize_course_ids( $course_ids_csv );
		return strtolower( trim( $email ) ) . '|' . $ids;
	}

	/**
	 * Turn a raw "101, 102,103" style cell into a de-duped, sorted, comma-joined
	 * string of integer course IDs. Non-numeric entries are dropped, not fatal.
	 */
	private static function normalize_course_ids( $raw ) {
		if ( '' === trim( (string) $raw ) ) {
			return '';
		}

		$parts = preg_split( '/[,;]+/', (string) $raw );
		$ids   = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part && ctype_digit( $part ) ) {
				$ids[] = (int) $part;
			}
		}

		$ids = array_unique( $ids );
		sort( $ids );

		return implode( ',', $ids );
	}

	private static function normalize_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}

		$timestamp = strtotime( $raw );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}
}
