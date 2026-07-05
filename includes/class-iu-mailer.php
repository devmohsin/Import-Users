<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send Welcome Emails: reuses CL Tutor Courses' own guest-checkout
 * welcome/set-password email (CodeLinden\TutorCourses\Guest_Checkout)
 * rather than building a second password-reset flow.
 */
class IU_Mailer {

	/**
	 * @return array{sent:int, failed:int}
	 */
	public static function send_pending() {
		global $wpdb;
		$table = IU_Activator::table_name();

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE processed = 1 AND messaged = 0",
			ARRAY_A
		);

		$sent   = 0;
		$failed = 0;

		foreach ( $rows as $row ) {
			if ( self::send_one( $row ) ) {
				$sent++;
			} else {
				$failed++;
			}
		}

		return array(
			'sent'   => $sent,
			'failed' => $failed,
		);
	}

	private static function send_one( array $row ) {
		$user_id = (int) $row['user_id'];
		$user    = $user_id ? get_user_by( 'id', $user_id ) : get_user_by( 'email', $row['email'] );

		if ( ! $user instanceof WP_User ) {
			self::mark_failed( $row['id'], __( 'No matching WordPress user found for this record.', 'cl-import-users' ) );
			return false;
		}

		if ( ! class_exists( '\CodeLinden\TutorCourses\Guest_Checkout' ) ) {
			self::mark_failed( $row['id'], __( 'CL Tutor Courses guest-checkout class is unavailable.', 'cl-import-users' ) );
			return false;
		}

		// Make sure the set-password link this email contains will actually
		// work, even if the account was created outside the WooCommerce
		// guest-checkout flow this class was originally built for.
		update_user_meta( $user->ID, \CodeLinden\TutorCourses\Guest_Checkout::META_PASSWORD_UNVERIFIED, 1 );

		$mail_succeeded = true;
		$on_failure     = static function () use ( &$mail_succeeded ) {
			$mail_succeeded = false;
		};

		add_action( 'wp_mail_failed', $on_failure );
		\CodeLinden\TutorCourses\Guest_Checkout::send_welcome_email( $user->ID );
		remove_action( 'wp_mail_failed', $on_failure );

		if ( $mail_succeeded ) {
			self::mark_sent( $row['id'] );
			return true;
		}

		self::mark_failed( $row['id'], __( 'wp_mail() reported a failure while sending the welcome email.', 'cl-import-users' ) );
		return false;
	}

	private static function mark_sent( $id ) {
		global $wpdb;
		$wpdb->update(
			IU_Activator::table_name(),
			array(
				'messaged'      => 1,
				'error_message' => '',
			),
			array( 'id' => $id )
		);
	}

	private static function mark_failed( $id, $message ) {
		global $wpdb;
		$wpdb->update(
			IU_Activator::table_name(),
			array( 'error_message' => $message ),
			array( 'id' => $id )
		);
	}
}
