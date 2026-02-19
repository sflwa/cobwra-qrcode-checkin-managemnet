<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v48.7)
 * Handles Manifest Preparation, Strict RSVP Matching, and Data Verification.
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
		add_menu_page( 
			'COBWRA Meeting', 
			'Meeting Manager', 
			'manage_options', 
			'cobwra-dashboard', 
			array( $this, 'render_dashboard' ), 
			'dashicons-groups', 
			25 
		);
	}

	/**
	 * Renders the Management Dashboard UI.
	 */
	public function render_dashboard() {
		global $wpdb;

		// Fetch all records for the verification table
		$all_records = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY community_name ASC, last_name ASC");
		$total_manifest = count($all_records);
		
		// Boarding stats
		$checked_in = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s", 'Checked In'));
		$conflicts = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE conflict_flag IS NOT NULL");

		?>
		<div class="wrap">
			<h1>COBWRA Meeting Manager (v48.7)</h1>
			
			<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; margin-top: 20px;">
				<h2>1. Manifest Preparation</h2>
				<p>Sync Master Database and Guests, then upload RSVPs to finalize the boarding manifest.</p>
				
				<div style="display:flex; gap:10px; margin-bottom:20px;">
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_sync_authority'); ?>" class="button button-primary">Sync Master DB & Guests</a>
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_clear_roster'); ?>" class="button" onclick="return confirm('Wipe current meeting roster?')">Clear Roster</a>
				</div>
				
				<hr>
				
				<h3>2. Match RSVPs (Final CSV Format)</h3>
				<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="cobwra_import_rsvp">
					<input type="file" name="rsvp_csv" accept=".csv" required>
					<?php submit_button('Match RSVPs to Manifest', 'secondary', 'submit', false); ?>
				</form>
			</div>

			<div style="margin-top:20px; display:flex; gap:20px;">
				<div style="flex:1; background:#f0f0f0; padding:15px; border-radius:5px; border-left: 5px solid #2c3e50;">
					<strong>Manifest Total:</strong> <?php echo (int)$total_manifest; ?> Records
				</div>
				<div style="flex:1; background:#d4edda; padding:15px; border-radius:5px; border-left: 5px solid #27ae60;">
					<strong>Boarded:</strong> <?php echo (int)$checked_in; ?> Checked In
				</div>
				<div style="flex:1; background:#f8d7da; padding:15px; border-radius:5px; border-left: 5px solid #c0392b;">
					<strong>Alerts:</strong> <?php echo (int)$conflicts; ?> Discrepancies
				</div>
			</div>

			<div style="margin-top:30px;">
				<h3>Complete Manifest Table</h3>
				<p class="description">Review all synchronized data and matched RSVPs here.</p>
				<table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
					<thead>
						<tr>
							<th width="20%">Name</th>
							<th width="25%">Community / Organization</th>
							<th width="15%">Role (Master)</th>
							<th width="15%">Role (RSVP)</th>
							<th width="8%">Voting</th>
							<th width="17%">Status</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($all_records)) : ?>
							<tr><td colspan="6" style="text-align:center; padding:20px;">No records found. Please Sync from Master DB.</td></tr>
						<?php else : foreach($all_records as $r) : ?>
							<tr style="<?php echo !empty($r->conflict_flag) ? 'background:#fff5f0;' : ''; ?>">
								<td><strong><?php echo esc_html($r->first_name . ' ' . $r->last_name); ?></strong></td>
								<td><?php echo esc_html($r->community_name); ?></td>
								<td><?php echo esc_html($r->official_role); ?></td>
								<td><?php echo esc_html($r->rsvp_role); ?></td>
								<td><?php echo $r->voting_authority ? '✅' : '❌'; ?></td>
								<td>
									<?php if ($r->checkin_status === 'Checked In') : ?>
										<span style="background:#27ae60; color:#fff; padding:2px 8px; border-radius:10px; font-size:0.85em; font-weight:bold;">BOARDED</span>
									<?php else : ?>
										<span style="color:#666;">Expected</span>
									<?php endif; ?>
									<?php if ($r->conflict_flag) echo "<br><small style='color:red; font-weight:bold;'>({$r->conflict_flag})</small>"; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * ACTION: Sync from Form 2 (Officials) and Form 4 (Dignitaries).
	 */
	public function handle_sync_authority() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );

		// 1. Sync Form 2 (Master Database - Voting Officials)
		$officials = $this->get_gravity_entries( 2 ); 
		foreach ( $officials as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name'   => $entry['3'],   // Field 3: Community
				'first_name'       => $entry['1.3'], // Field 1.3: First
				'last_name'        => $entry['1.6'], // Field 1.6: Last
				'official_role'    => $entry['2'],   // Field 2: Role
				'voting_authority' => 1,
				'is_announced'     => 0,
				'checkin_status'   => 'Expected'
			) );
		}

		// 2. Sync Form 4 (Announce Guests)
		$guests = $this->get_gravity_entries( 4 );
		foreach ( $guests as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name'   => $entry['6'], // Field 6: Organization
				'first_name'       => $entry['1.3'],
				'last_name'        => $entry['1.6'],
				'official_role'    => $entry['5'], // Field 5: Title
				'voting_authority' => 0,
				'is_announced'     => 1,
				'checkin_status'   => 'Expected'
			) );
		}

		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&sync=complete' ) );
		exit;
	}

	/**
	 * ACTION: Import RSVP CSV using Final Gravity Forms Export Format.
	 */
	public function handle_rsvp_import() {
		if ( ! isset( $_FILES['rsvp_csv'] ) ) return;
		global $wpdb;
		$handle = fopen( $_FILES['rsvp_csv']['tmp_name'], 'r' );
		
		// Skip header
		fgetcsv( $handle ); 

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			// Mapping for Final Export Format
			$first = trim($row[1]);  // Name (First)
			$last  = trim($row[3]);  // Name (Last)
			$role  = trim($row[6]);  // COBWRA Role
			$rid   = trim($row[10]); // Entry Id (The QR Scan Key)

			// Community Logic: Primary field (7) or Organization field (8)
			$comm  = !empty(trim($row[7])) ? trim($row[7]) : trim($row[8]);

			if (empty($first) && empty($last)) continue;

			// Fuzzy match against Manifest (Case-Insensitive)
			$match = $wpdb->get_row( $wpdb->prepare( 
				"SELECT id FROM $this->table_name 
				 WHERE LOWER(community_name) = LOWER(%s) 
				 AND LOWER(last_name) = LOWER(%s) 
				 AND LOWER(first_name) = LOWER(%s)", 
				$comm, $last, $first 
			) );

			if ( $match ) {
				// Matched: Update the Manifest record
				$wpdb->update( $this->table_name, 
					array( 'rsvp_role' => $role, 'rsvp_id' => $rid ), 
					array( 'id' => $match->id ) 
				);
			} else {
				// No Match: Treat as a Conflict or New Guest
				$is_official = ( stripos($role, 'Delegate') !== false || stripos($role, 'Alternate') !== false );
				
				$wpdb->insert( $this->table_name, array(
					'community_name'   => $comm,
					'first_name'       => $first,
					'last_name'        => $last,
					'official_role'    => 'None',
					'rsvp_role'        => $role,
					'rsvp_id'          => $rid,
					'conflict_flag'    => $is_official ? 'Conflict/Vacancy' : NULL,
					'checkin_status'   => 'Expected',
					'voting_authority' => 0 
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
