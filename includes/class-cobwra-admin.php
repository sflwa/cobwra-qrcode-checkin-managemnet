<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v48.9)
 * Handles Manifest Preparation, Dynamic Fuzzy Name Mapping, and Data Verification.
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
		add_action( 'admin_post_cobwra_save_settings', array( $this, 'handle_save_settings' ) );
	}

	public function add_admin_menu() {
		add_menu_page( 'COBWRA Meeting', 'Meeting Manager', 'manage_options', 'cobwra-dashboard', array( $this, 'render_dashboard' ), 'dashicons-groups', 25 );
	}

	public function render_dashboard() {
		global $wpdb;
		$all_records = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY community_name ASC, last_name ASC");
		$total_manifest = count($all_records);
		$checked_in = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s", 'Checked In'));
		$conflicts = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE conflict_flag IS NOT NULL");
		
		// Load Dynamic Fuzzy Map Settings
		$fuzzy_map_raw = get_option('cobwra_fuzzy_name_map', "Nathan,Nate\nRobert,Bob,Rob,Bobby\nPatricia,Pat,Trish\nPatrick,Pat\nDeborah,Debbie\nWilliam,Bill,Will");

		?>
		<div class="wrap">
			<h1>COBWRA Meeting Manager (v48.9)</h1>
			
			<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
				<div>
					<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
						<h2>1. Manifest Preparation</h2>
						<div style="display:flex; gap:10px; margin-bottom:20px;">
							<a href="<?php echo admin_url('admin-post.php?action=cobwra_sync_authority'); ?>" class="button button-primary">Sync Master DB & Guests</a>
							<a href="<?php echo admin_url('admin-post.php?action=cobwra_clear_roster'); ?>" class="button" onclick="return confirm('Wipe roster?')">Clear Roster</a>
						</div>
						<hr>
						<h3>2. Match RSVPs (Final CSV Format)</h3>
						<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
							<input type="hidden" name="action" value="cobwra_import_rsvp">
							<input type="file" name="rsvp_csv" accept=".csv" required>
							<?php submit_button('Match RSVPs to Manifest', 'secondary', 'submit', false); ?>
						</form>
					</div>
				</div>

				<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
					<h3>Fuzzy Name Mapping</h3>
					<p class="description">Enter formal and informal names on one line, separated by commas.</p>
					<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
						<input type="hidden" name="action" value="cobwra_save_settings">
						<textarea name="fuzzy_map" style="width:100%; height:150px; font-family:monospace;"><?php echo esc_textarea($fuzzy_map_raw); ?></textarea>
						<?php submit_button('Save Mappings', 'secondary'); ?>
					</form>
				</div>
			</div>

			<div style="margin-top:20px; display:flex; gap:20px;">
				<div style="flex:1; background:#f0f0f0; padding:15px; border-radius:5px; border-left: 5px solid #2c3e50;"><strong>Manifest Total:</strong> <?php echo (int)$total_manifest; ?></div>
				<div style="flex:1; background:#d4edda; padding:15px; border-radius:5px; border-left: 5px solid #27ae60;"><strong>Boarded:</strong> <?php echo (int)$checked_in; ?></div>
				<div style="flex:1; background:#f8d7da; padding:15px; border-radius:5px; border-left: 5px solid #c0392b;"><strong>Alerts:</strong> <?php echo (int)$conflicts; ?></div>
			</div>

			<div style="margin-top:30px;">
				<h3>Manifest Table</h3>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Name</th><th>Community / Org</th><th>Role (Master)</th><th>Role (RSVP)</th><th>Voting</th><th>Status</th></tr></thead>
					<tbody>
						<?php foreach($all_records as $r) : ?>
							<tr style="<?php echo !empty($r->conflict_flag) ? 'background:#fff5f0;' : ''; ?>">
								<td><strong><?php echo esc_html($r->first_name . ' ' . $r->last_name); ?></strong></td>
								<td><?php echo esc_html($r->community_name); ?></td>
								<td><?php echo esc_html($r->official_role); ?></td>
								<td><?php echo esc_html($r->rsvp_role); ?></td>
								<td><?php echo $r->voting_authority ? '✅' : '❌'; ?></td>
								<td>
									<?php echo ($r->checkin_status === 'Checked In') ? '<b style="color:green;">BOARDED</b>' : 'Expected'; ?>
									<?php if ($r->conflict_flag) echo "<br><small style='color:red;'>({$r->conflict_flag})</small>"; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public function handle_save_settings() {
		if (isset($_POST['fuzzy_map'])) {
			update_option('cobwra_fuzzy_name_map', sanitize_textarea_field($_POST['fuzzy_map']));
		}
		wp_redirect(admin_url('admin.php?page=cobwra-dashboard&settings=saved'));
		exit;
	}

	public function handle_sync_authority() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );
		
		$officials = $this->get_gravity_entries( 2 ); 
		foreach ( $officials as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name' => $entry['3'], 'first_name' => $entry['1.3'], 'last_name' => $entry['1.6'],
				'official_role' => $entry['2'], 'voting_authority' => 1, 'checkin_status' => 'Expected'
			) );
		}

		$guests = $this->get_gravity_entries( 4 );
		foreach ( $guests as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name' => $entry['6'], 'first_name' => $entry['1.3'], 'last_name' => $entry['1.6'],
				'official_role' => $entry['5'], 'voting_authority' => 0, 'checkin_status' => 'Expected', 'is_announced' => 1
			) );
		}
		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&sync=complete' ) );
		exit;
	}

	public function handle_rsvp_import() {
		if ( ! isset( $_FILES['rsvp_csv'] ) ) return;
		global $wpdb;
		
		// Load Fuzzy Map from Options
		$fuzzy_raw = get_option('cobwra_fuzzy_name_map', '');
		$fuzzy_lines = explode("\n", str_replace("\r", "", strtolower($fuzzy_raw)));
		$name_groups = array();
		foreach ($fuzzy_lines as $line) {
			$names = array_map('trim', explode(',', $line));
			if (!empty($names[0])) { $name_groups[] = $names; }
		}

		$handle = fopen( $_FILES['rsvp_csv']['tmp_name'], 'r' );
		fgetcsv( $handle ); // Skip header

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			$first = strtolower(trim($row[1])); // Name (First)
			$last  = strtolower(trim($row[3])); // Name (Last)
			$role  = trim($row[6]);             // COBWRA Role
			$rid   = trim($row[10]);            // Entry Id
			$comm  = strtolower(!empty(trim($row[7])) ? trim($row[7]) : trim($row[8]));

			if (empty($first) && empty($last)) continue;

			// Step 1: Exact Match Search
			$match = $wpdb->get_row( $wpdb->prepare( 
				"SELECT id, first_name FROM $this->table_name WHERE LOWER(community_name) = %s AND LOWER(last_name) = %s AND LOWER(first_name) = %s", 
				$comm, $last, $first 
			) );

			// Step 2: Dynamic Fuzzy Search
			if ( ! $match ) {
				$candidates = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, first_name FROM $this->table_name WHERE LOWER(community_name) = %s AND LOWER(last_name) = %s",
					$comm, $last
				) );

				foreach ( $candidates as $can ) {
					$can_first = strtolower($can->first_name);
					
					// Rule A: Registered starts with Database (e.g. Pat starts Patricia)
					if ( strpos($first, $can_first) === 0 || strpos($can_first, $first) === 0 ) {
						$match = $can; break;
					}
					// Rule B: Match via Dynamic Settings Box
					foreach ($name_groups as $group) {
						if (in_array($first, $group) && in_array($can_first, $group)) {
							$match = $can; break 2;
						}
					}
				}
			}

			if ( $match ) {
				$wpdb->update( $this->table_name, array( 'rsvp_role' => $role, 'rsvp_id' => $rid ), array( 'id' => $match->id ) );
			} else {
				$is_official = ( stripos($role, 'Delegate') !== false || stripos($role, 'Alternate') !== false );
				$wpdb->insert( $this->table_name, array(
					'community_name' => ucwords($comm), 'first_name' => ucwords($first), 'last_name' => ucwords($last),
					'official_role' => 'None', 'rsvp_role' => $role, 'rsvp_id' => $rid,
					'conflict_flag' => $is_official ? 'Conflict/Vacancy' : NULL, 'checkin_status' => 'Expected', 'voting_authority' => 0 
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
		$mapped_data = array();
		foreach ( $entries as $entry ) {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d", $entry->id ) );
			$row = array();
			foreach ( $meta as $m ) { $row[$m->meta_key] = $m->meta_value; }
			$mapped_data[] = $row;
		}
		return $mapped_data;
	}

	public function handle_clear_roster() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );
		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&clear=complete' ) );
		exit;
	}
}
