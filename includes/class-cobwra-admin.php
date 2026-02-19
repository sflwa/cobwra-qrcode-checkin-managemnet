<?php
/**
 * COBWRA Admin Settings Class
 * Handles CSV uploads, data previews, and plugin configuration.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Admin {

	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_init', array( $this, 'handle_admin_actions' ) );
	}

	public function add_menu_pages() {
		// Main Menu Item
		add_menu_page(
			'COBWRA Check-in',
			'COBWRA Check-in',
			'manage_options',
			'cobwra-checkin',
			array( $this, 'render_settings_page' ),
			'dashicons-id-alt',
			26
		);

		// Submenu for the Live Dashboard (so it's accessible in admin too)
		add_submenu_page(
			'cobwra-checkin',
			'Attendance Dashboard',
			'Attendance Log',
			'manage_options',
			'cobwra-dashboard',
			array( $this, 'render_admin_dashboard' )
		);
	}

	/**
	 * Logic for CSV Uploads and Deletions
	 */
	public function handle_admin_actions() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// 1. Handle CSV Upload
		if ( isset( $_FILES['cobwra_csv_upload'] ) && $_FILES['cobwra_csv_upload']['size'] > 0 ) {
			check_admin_referer( 'cobwra_admin_action', 'cobwra_nonce' );
			
			$file = $_FILES['cobwra_csv_upload']['tmp_name'];
			$data = array();

			if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
				// Map headers: First, Last, Email, Role, Community, Entry ID
				fgetcsv( $handle ); // Skip header row
				while ( ( $row = fgetcsv( $handle, 1000, "," ) ) !== FALSE ) {
					$entry_id = trim( $row[6] );
					if ( ! empty( $entry_id ) ) {
						$data[ $entry_id ] = array(
							'first' => trim( $row[0] ),
							'last'  => trim( $row[1] ),
							'email' => trim( $row[2] ),
							'role'  => trim( $row[3] ),
							'comm'  => trim( $row[4] )
						);
					}
				}
				fclose( $handle );
				update_option( $this->engine->csv_opt, $data );
				add_settings_error( 'cobwra_msg', 'updated', 'RSVP List Updated Successfully.', 'updated' );
			}
		}

		// 2. Handle Settings Save (Community Count)
		if ( isset( $_POST['save_cobwra_settings'] ) ) {
			check_admin_referer( 'cobwra_admin_action', 'cobwra_nonce' );
			update_option( $this->engine->comm_opt, intval( $_POST['active_comm_count'] ) );
			add_settings_error( 'cobwra_msg', 'settings_updated', 'Configuration Saved.', 'updated' );
		}

		// 3. Handle Clear Data
		if ( isset( $_POST['clear_cobwra_data'] ) ) {
			check_admin_referer( 'cobwra_admin_action', 'cobwra_nonce' );
			delete_option( $this->engine->csv_opt );
			add_settings_error( 'cobwra_msg', 'data_cleared', 'RSVP Data Cleared.', 'updated' );
		}
	}

	/**
	 * The Primary Settings Page
	 */
	public function render_settings_page() {
		settings_errors( 'cobwra_msg' );
		$csv_data = get_option( $this->engine->csv_opt, array() );
		$comm_count = get_option( $this->engine->comm_opt, 0 );
		?>
		<div class="wrap">
			<h1>COBWRA Check-in Configuration</h1>
			
			<div class="card" style="max-width: 100%; margin-top: 20px; padding: 20px;">
				<form method="POST" enctype="multipart/form-data">
					<?php wp_nonce_field( 'cobwra_admin_action', 'cobwra_nonce' ); ?>
					
					<h3>1. General Configuration</h3>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="active_comm_count">Total Active Communities</label></th>
							<td>
								<input name="active_comm_count" type="number" id="active_comm_count" value="<?php echo esc_attr( $comm_count ); ?>" class="small-text">
								<p class="description">Required for accurate Quorum (40%) calculations.</p>
							</td>
						</tr>
					</table>

					<hr>

					<h3>2. RSVP Data Management</h3>
					<p>Upload the latest RSVP CSV export from COBWRA.org to refresh the "Credential Engine."</p>
					<input type="file" name="cobwra_csv_upload" accept=".csv">
					
					<p class="submit">
						<input type="submit" name="save_cobwra_settings" class="button button-primary" value="Save Settings & Upload CSV">
						<?php if ( ! empty( $csv_data ) ) : ?>
							<input type="submit" name="clear_cobwra_data" class="button button-secondary" value="Clear RSVP Data" onclick="return confirm('Are you sure you want to delete all RSVP records?');">
						<?php endif; ?>
					</p>
				</form>
			</div>

			<?php if ( ! empty( $csv_data ) ) : ?>
				<div style="margin-top: 30px;">
					<h3>Data Preview: Current RSVP List (First 10 Rows)</h3>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>ID</th>
								<th>Name</th>
								<th>Community</th>
								<th>Claimed Role</th>
								<th>Email</th>
							</tr>
						</thead>
						<tbody>
							<?php 
							$preview = array_slice( $csv_data, 0, 10, true );
							foreach ( $preview as $id => $row ) {
								echo "<tr>
										<td><strong>{$id}</strong></td>
										<td>{$row['first']} {$row['last']}</td>
										<td>{$row['comm']}</td>
										<td>{$row['role']}</td>
										<td>{$row['email']}</td>
									  </tr>";
							}
							?>
						</tbody>
					</table>
					<p class="description">Total Records Loaded: <?php echo count( $csv_data ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Wraps the Dashboard shortcode for use in the admin menu
	 */
	public function render_admin_dashboard() {
		echo '<div class="wrap">' . do_shortcode('[cobwra_dashboard]') . '</div>';
	}
}
