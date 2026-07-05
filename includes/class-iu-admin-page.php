<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools > Import Users admin page: Download Demo CSV, Import CSV,
 * Process Database, Send Welcome Emails sections.
 */
class IU_Admin_Page {

	const CAPABILITY = 'manage_options';
	const NONCE_ACTION = 'iu_admin_action';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_iu_download_demo_csv', array( $this, 'handle_download_demo_csv' ) );
		add_action( 'admin_post_iu_import_csv', array( $this, 'handle_import_csv' ) );
		add_action( 'admin_post_iu_process_database', array( $this, 'handle_process_database' ) );
		add_action( 'admin_post_iu_send_welcome_emails', array( $this, 'handle_send_welcome_emails' ) );
	}

	public function register_page() {
		add_management_page(
			__( 'Import Users', 'import-users' ),
			__( 'Import Users', 'import-users' ),
			self::CAPABILITY,
			'import-users',
			array( $this, 'render_page' )
		);
	}

	private function page_url() {
		return admin_url( 'tools.php?page=import-users' );
	}

	private function check_request( $expected_action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'import-users' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
	}

	private function redirect_with_notice( $type, $message ) {
		set_transient( 'iu_admin_notice_' . get_current_user_id(), array(
			'type'    => $type,
			'message' => $message,
		), MINUTE_IN_SECONDS * 5 );

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	public function handle_download_demo_csv() {
		$this->check_request( 'iu_download_demo_csv' );
		IU_CSV::stream_demo_csv();
	}

	public function handle_import_csv() {
		$this->check_request( 'iu_import_csv' );

		if ( empty( $_FILES['iu_csv_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['iu_csv_file']['error'] ) {
			$this->redirect_with_notice( 'error', __( 'Please choose a CSV file to upload.', 'import-users' ) );
		}

		$file = $_FILES['iu_csv_file'];
		$filetype = wp_check_filetype( $file['name'], array( 'csv' => 'text/csv' ) );

		if ( 'csv' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
			$this->redirect_with_notice( 'error', __( 'Please upload a .csv file.', 'import-users' ) );
		}

		$result = IU_CSV::import_from_path( $file['tmp_name'] );

		$message = sprintf(
			/* translators: 1: imported count, 2: duplicate count, 3: invalid count */
			__( 'Import complete: %1$d record(s) imported, %2$d skipped as duplicate, %3$d invalid.', 'import-users' ),
			$result['imported'],
			$result['skipped_duplicate'],
			$result['invalid']
		);

		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . implode( ' ', array_slice( $result['errors'], 0, 10 ) );
		}

		$this->redirect_with_notice( $result['imported'] > 0 || 0 === $result['total_rows'] ? 'success' : 'warning', $message );
	}

	public function handle_process_database() {
		$this->check_request( 'iu_process_database' );

		$result = IU_Processor::process_all();

		if ( 0 === $result['remaining_unprocessed'] ) {
			$message = __( 'All records processed successfully.', 'import-users' );
			$type    = 'success';
		} else {
			$message = sprintf(
				/* translators: 1: succeeded count, 2: failed count */
				__( 'Processed %1$d record(s) successfully, %2$d failed verification and were left for retry.', 'import-users' ),
				$result['succeeded'],
				$result['failed']
			);
			$type = 'warning';
		}

		$this->redirect_with_notice( $type, $message );
	}

	public function handle_send_welcome_emails() {
		$this->check_request( 'iu_send_welcome_emails' );

		$result = IU_Mailer::send_pending();

		$message = sprintf(
			/* translators: 1: sent count, 2: failed count */
			__( 'Welcome emails: %1$d sent, %2$d failed.', 'import-users' ),
			$result['sent'],
			$result['failed']
		);

		$this->redirect_with_notice( $result['failed'] > 0 ? 'warning' : 'success', $message );
	}

	private function render_notice() {
		$notice = get_transient( 'iu_admin_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'iu_admin_notice_' . get_current_user_id() );

		$css_class = 'notice-success';
		if ( 'error' === $notice['type'] ) {
			$css_class = 'notice-error';
		} elseif ( 'warning' === $notice['type'] ) {
			$css_class = 'notice-warning';
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $css_class ),
			esc_html( $notice['message'] )
		);
	}

	private function get_counts() {
		global $wpdb;
		$table = IU_Activator::table_name();

		return array(
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND processed = 0" ),
			'processed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE processed = 1" ),
			'awaiting_email' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE processed = 1 AND messaged = 0" ),
			'messaged'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE messaged = 1" ),
			'invalid'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'invalid'" ),
		);
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$counts = $this->get_counts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Users', 'import-users' ); ?></h1>
			<?php $this->render_notice(); ?>

			<p>
				<?php
				printf(
					/* translators: 1-6 are counts */
					esc_html__( 'Total records: %1$d — Pending: %2$d — Processed: %3$d — Awaiting email: %4$d — Emailed: %5$d — Invalid: %6$d', 'import-users' ),
					(int) $counts['total'],
					(int) $counts['pending'],
					(int) $counts['processed'],
					(int) $counts['awaiting_email'],
					(int) $counts['messaged'],
					(int) $counts['invalid']
				);
				?>
			</p>

			<hr />

			<h2><?php esc_html_e( '1. Download Demo CSV', 'import-users' ); ?></h2>
			<p><?php esc_html_e( 'Download a sample CSV with the expected columns: First name, Last name, Email, Course IDs, Enrollment date.', 'import-users' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="iu_download_demo_csv" />
				<?php submit_button( __( 'Download Demo CSV', 'import-users' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( '2. Import CSV', 'import-users' ); ?></h2>
			<p><?php esc_html_e( 'Upload a CSV of students to queue for import. Duplicate email+course rows are skipped automatically.', 'import-users' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="iu_import_csv" />
				<input type="file" name="iu_csv_file" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Import CSV', 'import-users' ), 'primary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( '3. Process Database', 'import-users' ); ?></h2>
			<p><?php esc_html_e( 'Create/reuse WordPress users for each pending record and enroll them into their courses (including bundle child courses).', 'import-users' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="iu_process_database" />
				<?php submit_button( __( 'Process Database', 'import-users' ), 'primary', 'submit', false, $counts['pending'] > 0 ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( '4. Send Welcome Emails', 'import-users' ); ?></h2>
			<p><?php esc_html_e( 'Send the set-password welcome email to processed records that have not been emailed yet.', 'import-users' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="iu_send_welcome_emails" />
				<?php submit_button( __( 'Send Welcome Emails', 'import-users' ), 'primary', 'submit', false, $counts['awaiting_email'] > 0 ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Recent Records', 'import-users' ); ?></h2>
			<?php $this->render_records_table(); ?>
		</div>
		<?php
	}

	private function render_records_table() {
		global $wpdb;
		$table   = IU_Activator::table_name();
		$records = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 50", ARRAY_A );

		if ( empty( $records ) ) {
			echo '<p>' . esc_html__( 'No records yet.', 'import-users' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'import-users' ); ?></th>
					<th><?php esc_html_e( 'Email', 'import-users' ); ?></th>
					<th><?php esc_html_e( 'Course IDs', 'import-users' ); ?></th>
					<th><?php esc_html_e( 'Status', 'import-users' ); ?></th>
					<th><?php esc_html_e( 'Processed', 'import-users' ); ?></th>
					<th><?php esc_html_e( 'Messaged', 'import-users' ); ?></th>
					<th><?php esc_html_e( 'Error', 'import-users' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $records as $record ) : ?>
					<tr>
						<td><?php echo esc_html( trim( $record['first_name'] . ' ' . $record['last_name'] ) ); ?></td>
						<td><?php echo esc_html( $record['email'] ); ?></td>
						<td><?php echo esc_html( $record['course_ids'] ); ?></td>
						<td><?php echo esc_html( $record['status'] ); ?></td>
						<td><?php echo $record['processed'] ? '&#10003;' : '&#8212;'; ?></td>
						<td><?php echo $record['messaged'] ? '&#10003;' : '&#8212;'; ?></td>
						<td><?php echo esc_html( $record['error_message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
