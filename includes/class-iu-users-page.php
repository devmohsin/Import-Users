<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin/users.php integration:
 *  - row action to (re)send the custom CL Tutor set-password email to any user
 *  - display of this plugin's "granted from spreadsheet import" audit log on
 *    the edit-user screen
 */
class IU_Users_Page {

	const ACTION       = 'iu_resend_reset_link';
	const NONCE_ACTION = 'iu_resend_reset_link';

	public function __construct() {
		add_filter( 'user_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_resend' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'edit_user_profile', array( $this, 'render_enrollment_log' ) );
		add_action( 'show_user_profile', array( $this, 'render_enrollment_log' ) );
	}

	public function add_row_action( $actions, $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::ACTION,
					'user_id' => $user->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION
		);

		$actions['iu_resend_reset_link'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Send set-password email', 'cl-import-users' )
		);

		return $actions;
	}

	public function handle_resend() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'cl-import-users' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

		if ( ! $user instanceof WP_User ) {
			$this->redirect_with_notice( 'error', __( 'User not found.', 'cl-import-users' ) );
		}

		if ( ! class_exists( '\CodeLinden\TutorCourses\Guest_Checkout' ) ) {
			$this->redirect_with_notice( 'error', __( 'The set-password logic (CL Tutor Courses) is unavailable.', 'cl-import-users' ) );
		}

		update_user_meta( $user->ID, \CodeLinden\TutorCourses\Guest_Checkout::META_PASSWORD_UNVERIFIED, 1 );

		$mail_succeeded = true;
		$on_failure     = static function () use ( &$mail_succeeded ) {
			$mail_succeeded = false;
		};

		add_action( 'wp_mail_failed', $on_failure );
		\CodeLinden\TutorCourses\Guest_Checkout::send_welcome_email( $user->ID );
		remove_action( 'wp_mail_failed', $on_failure );

		if ( $mail_succeeded ) {
			$this->redirect_with_notice(
				'success',
				sprintf(
					/* translators: %s: user email address */
					__( 'Set-password email sent to %s.', 'cl-import-users' ),
					$user->user_email
				)
			);
		}

		$this->redirect_with_notice( 'error', __( 'Failed to send the set-password email.', 'cl-import-users' ) );
	}

	private function redirect_with_notice( $type, $message ) {
		set_transient(
			'iu_users_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS * 5
		);

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'users.php' ) );
		exit;
	}

	public function render_notice() {
		$key    = 'iu_users_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		$css_class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $css_class ),
			esc_html( $notice['message'] )
		);
	}

	public function render_enrollment_log( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$log = get_user_meta( $user->ID, 'iu_enrollment_log', true );
		if ( ! is_array( $log ) || empty( $log ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Import Users — Enrollment Log', 'cl-import-users' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Granted via CSV import', 'cl-import-users' ); ?></th>
				<td>
					<ul style="margin:0;">
						<?php foreach ( $log as $entry ) : ?>
							<li>
								<?php echo esc_html( $entry['reason'] ?? '' ); ?>
								<em>(<?php echo esc_html( $entry['granted_at'] ?? '' ); ?>)</em>
							</li>
						<?php endforeach; ?>
					</ul>
				</td>
			</tr>
		</table>
		<?php
	}
}
