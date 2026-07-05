<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin glue over CL Tutor Courses' own enrollment/bundle classes so Process
 * Database reuses their logic instead of re-implementing tutor_utils()->do_enroll().
 *
 * Bundle expansion deliberately reads Bundle_Utils::get_bundled_course_ids()
 * directly rather than relying solely on the tutor_after_enrolled hook chain
 * (CodeLinden\TutorCourseBundle\Bundle_Enrollment): that hook resolves an
 * option-tiered bundle's children via the WooCommerce order line items, which
 * doesn't exist for an admin/CSV-granted enrollment (order_id = 0), so it
 * silently grants nothing for bundles configured with purchase options. The
 * flat _cl_bundled_course_ids meta is kept in sync for both simple and
 * option-based bundles (see Bundle_Options::sync_flat_bundled_course_ids),
 * so reading it directly is correct for both cases.
 */
class IU_Enrollment {

	public static function dependencies_ready() {
		return function_exists( 'tutor_utils' )
			&& class_exists( '\CodeLinden\UserCourseAccessAdmin\Enrollment_Handler' )
			&& class_exists( '\CodeLinden\TutorCourseBundle\Bundle_Utils' );
	}

	public static function course_post_type() {
		if ( class_exists( '\CodeLinden\TutorCourseBundle\Bundle_Utils' ) ) {
			return \CodeLinden\TutorCourseBundle\Bundle_Utils::get_course_post_type();
		}
		return 'courses';
	}

	public static function course_exists( $course_id ) {
		$course_id = (int) $course_id;
		if ( ! $course_id ) {
			return false;
		}

		$post = get_post( $course_id );
		return $post instanceof WP_Post && self::course_post_type() === $post->post_type;
	}

	/**
	 * Expand a list of imported course IDs into the full set of courses to
	 * enroll, flagging which ones are bundle children (and of which parent).
	 *
	 * @param int[] $course_ids Raw course IDs from the CSV row.
	 * @return array<int, array{course_id:int, parent_bundle_id:int|null}>
	 */
	public static function resolve_with_bundle_children( array $course_ids ) {
		$resolved = array();
		$seen     = array();

		foreach ( $course_ids as $course_id ) {
			$course_id = (int) $course_id;
			if ( ! $course_id || isset( $seen[ $course_id ] ) ) {
				continue;
			}
			$seen[ $course_id ] = true;
			$resolved[]         = array(
				'course_id'        => $course_id,
				'parent_bundle_id' => null,
			);

			if ( ! class_exists( '\CodeLinden\TutorCourseBundle\Bundle_Utils' ) ) {
				continue;
			}

			if ( \CodeLinden\TutorCourseBundle\Bundle_Utils::is_bundled_course( $course_id ) ) {
				foreach ( \CodeLinden\TutorCourseBundle\Bundle_Utils::get_bundled_course_ids( $course_id ) as $child_id ) {
					$child_id = (int) $child_id;
					if ( ! $child_id || isset( $seen[ $child_id ] ) ) {
						continue;
					}
					$seen[ $child_id ] = true;
					$resolved[]        = array(
						'course_id'        => $child_id,
						'parent_bundle_id' => $course_id,
					);
				}
			}
		}

		return $resolved;
	}

	public static function is_verified_enrolled( $user_id, $course_id ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return false;
		}
		return (bool) tutor_utils()->is_enrolled( $course_id, $user_id, true );
	}

	/**
	 * Enroll (idempotently) and verify. Uses CL Tutor's own Enrollment_Handler
	 * so status-forcing/hook behavior matches what the rest of the site does.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public static function ensure_enrolled( $user_id, $course_id ) {
		if ( ! self::course_exists( $course_id ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %d: course ID */
					__( 'Course ID %d does not exist.', 'import-users' ),
					(int) $course_id
				),
			);
		}

		if ( ! self::dependencies_ready() ) {
			return array(
				'ok'      => false,
				'message' => __( 'Tutor LMS / CL Tutor Courses enrollment classes are unavailable.', 'import-users' ),
			);
		}

		if ( ! self::is_verified_enrolled( $user_id, $course_id ) ) {
			\CodeLinden\UserCourseAccessAdmin\Enrollment_Handler::grant_enrollment(
				$course_id,
				$user_id,
				array( 'send_email' => false )
			);
		}

		if ( self::is_verified_enrolled( $user_id, $course_id ) ) {
			return array(
				'ok'      => true,
				'message' => '',
			);
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: %d: course ID */
				__( 'Enrollment could not be verified for course ID %d.', 'import-users' ),
				(int) $course_id
			),
		);
	}
}
