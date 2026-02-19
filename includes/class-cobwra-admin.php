<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v48.6)
 * Handles Manifest Preparation, RSVP Matching, and Data Verification.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Admin {

	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
		
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_cobwra_sync_authority', array( $this, 'handle_sync_authority' ) );
		add_action( 'admin_post_cobwra_clear_roster', array( $this, 'handle_clear_roster' ) );
		add_action( 'admin_post_cobwra_import_rsvp', array( $this, 'handle_rsvp_import' ) );
	}

	public function add_admin_menu() {
		add_menu_page( 'COBWRA Meeting', 'Meeting Manager', 'manage_options', 'cobwra-dashboard', array( $this, 'render_dashboard' ), 'dashicons-groups', 25 );
	}

	public function render_dashboard() {
		global $wpdb;

		$total_manifest = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");
		$checked_in = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s", 'Checked In'));
		
		// Fetch first 20 records for verification
		$preview_records = $wpdb->get_results("SELECT * FROM $this->table_name LIMIT 20");
		$conflicts = $wpdb->get_results("SELECT * FROM $this->table_name WHERE conflict_flag IS NOT NULL");

		?>
		<div class="wrap">
			<h1>COBWRA Meeting Manager (v48.6)</h1>
			
			<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; margin-top: 20px;">
				<h2>1. Manifest Actions</h2>
				<div style="display:flex; gap:10px; margin-bottom:20px;">
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_sync_authority'); ?>" class="button button-primary">Sync Master DBs</a>
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_clear_roster'); ?>" class="button" onclick="return confirm('Wipe roster?')">Clear Roster</a>
				</div>
				
				<h3>2. Match RSVPs (CSV Import)</h3>
				<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="cobwra_import_rsvp">
					<input type="file" name="rsvp_csv" accept=".csv" required>
					<?php submit_button('Match RSVPs', 'secondary', 'submit', false); ?>
				</form>
			</div>

			<div style="margin-top:20px; display:flex; gap:20px;">
				<div style="flex:1; background:#f0f0f0; padding:15px; border-radius:5px;"><strong>Manifest Total:</strong> <?php echo (int)$total_manifest; ?></div>
				<div style="flex:1; background:#d4edda; padding:15px; border-radius:5px;"><strong>Boarded (Checked In):</strong> <?php echo (int)$checked_in; ?></div>
			</div>

			<?php if ( !empty($preview_records) ) : ?>
			<div style="margin-top:30px;">
				<h3>Manifest Preview (First 20 Records)</h3>
				<p class="description">Verify that names, communities, and voting rights matched correctly from the Master DB and RSVPs.</p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Name</th>
							<th>Community</th>
							<th>Role (Master)</th>
							<th>Role (RSVP)</th>
							<th>Voting Authority</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($preview_records as $r) : ?>
						<tr>
							<td><?php echo esc_html($r->first_name . ' ' . $r->last_name); ?></td>
							<td><?php echo esc_html($r->community_name); ?></td>
							<td><?php echo esc_html($r->official_role); ?></td>
							<td><?php echo esc_html($r->rsvp_role); ?></td>
							<td><?php echo $r->voting_authority ? '✅ Yes' : '❌ No'; ?></td>
							<td><?php echo esc_html($r->checkin_status); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_sync_authority() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );
		
		// Sync Form 2 (Master DB - Officials)
		$officials = $this->get_gravity_entries( 2 );
		foreach ( $officials as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name' => $entry['3'],
				'first_name' => $entry['1.3'],
				'last_name' => $entry['1.6'],
				'official_role' => $entry['2'],
				'voting_authority' => 1,
				'checkin_status' => 'Expected'
			) );
		}
		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&sync=complete' ) );
		exit;
	}

	public function handle_rsvp_import() {
		if ( ! isset( $_FILES['rsvp_csv'] ) ) return;
		global $wpdb;
		$handle = fopen( $_FILES['rsvp_csv']['tmp_name'], 'r' );
		fgetcsv( $handle ); // Skip Header

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			// Mapping: 0:First, 1:Last, 3:Role, 4:Community, 6:ID
			$first = trim($row[0]); $last = trim($row[1]); $role = trim($row[3]); $comm = trim($row[4]); $rid = trim($row[6]);

			$match = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $this->table_name WHERE community_name = %s AND last_name = %s AND first_name = %s", $comm, $last, $first ) );

			if ( $match ) {
				$wpdb->update( $this->table_name, array( 'rsvp_role' => $role, 'rsvp_id' => $rid ), array( 'id' => $match->id ) );
			} else {
				$is_official = ( stripos( $role, 'Delegate' ) !== false || stripos( $role, 'Alternate' ) !== false );
				$wpdb->insert( $this->table_name, array(
					'community_name' => $comm, 'first_name' => $first, 'last_name' => $last,
					'official_role' => 'None', 'rsvp_role' => $role, 'rsvp_id' => $rid,
					'conflict_flag' => $is_official ? 'Conflict/Vacancy' : NULL, 'checkin_status' => 'Expected'
				) );
			}
		}
		fclose( $handle );
		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&import=complete' ) );
		exit;
	}

	private function get_gravity_entries( $form_id ) {
		global $wpdb;
		$entries = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'active'", $form_id ) );
		$mapped = array();
		foreach ( $entries as $entry ) {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d", $entry->id ) );
			$row = array();
			foreach ( $meta as $m ) { $row[$m->meta_key] = $m->meta_value; }
			$mapped[] = $row;
		}
		return $mapped;
	}

	public function handle_clear_roster() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );
		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&clear=complete' ) );
		exit;
	}
}
