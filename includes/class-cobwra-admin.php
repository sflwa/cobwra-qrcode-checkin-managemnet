<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v48.6)
 * Handles Manifest Preparation, RSVP Matching, and Conflict Monitoring.
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

		// 1. Manifest Stats (The "Airplane" capacity)
		$total_manifest = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");
		$voting_members = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE voting_authority = 1");
		
		// 2. Boarding Stats (Who has actually checked in)
		$checked_in_total = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s", 'Checked In'));
		$checked_in_reps  = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s AND voting_authority = 1", 'Checked In'));
		
		// 3. Conflicts
		$conflicts = $wpdb->get_results("SELECT * FROM $this->table_name WHERE conflict_flag IS NOT NULL ORDER BY community_name ASC");

		?>
		<div class="wrap">
			<h1>COBWRA Meeting Manager (v48.6)</h1>
			
			<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; margin-top: 20px;">
				<h2>1. Manifest Preparation</h2>
				<p>Sync the Master Database and upload RSVPs to build today's manifest.</p>
				
				<div style="display:flex; gap:10px; margin-bottom:20px;">
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_sync_authority'); ?>" class="button button-primary">Synchronize Master DBs</a>
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_clear_roster'); ?>" class="button" onclick="return confirm('Wipe current meeting roster?')">Clear Roster</a>
				</div>
				
				<hr>
				
				<h3>2. Match RSVPs (CSV Import)</h3>
				<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="cobwra_import_rsvp">
					<input type="file" name="rsvp_csv" accept=".csv" required>
					<?php submit_button('Match RSVPs to Manifest', 'secondary', 'submit', false); ?>
				</form>
			</div>

			<div style="margin-top:20px; display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
				<div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:5px;">
					<h3>Manifest Summary (Expected)</h3>
					<ul>
						<li><strong>Total on Manifest:</strong> <?php echo (int)$total_manifest; ?></li>
						<li><strong>Total Voting Reps:</strong> <?php echo (int)$voting_members; ?></li>
					</ul>
				</div>
				<div style="background:#e7f3ff; border:1px solid #b8daff; padding:20px; border-radius:5px;">
					<h3>Boarding Summary (Checked In)</h3>
					<ul>
						<li><strong>Total Boarded:</strong> <?php echo (int)$checked_in_total; ?></li>
						<li><strong>Voting Reps Present:</strong> <?php echo (int)$checked_in_reps; ?></li>
					</ul>
				</div>
			</div>

			<?php if ( !empty($conflicts) ) : ?>
			<div style="margin-top:30px;">
				<h3>Alerts: Conflicts & Vacancies</h3>
				<p class="description">Review these discrepancies before or during the meeting. These are people who RSVP'd but do not match the Master DB records.</p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Attendee</th>
							<th>Community</th>
							<th>Claimed Role</th>
							<th>Status Flag</th>
							<th>Boarding Status</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach($conflicts as $c) : ?>
						<tr>
							<td><strong><?php echo esc_html($c->first_name . ' ' . $c->last_name); ?></strong></td>
							<td><?php echo esc_html($c->community_name); ?></td>
							<td><?php echo esc_html($c->rsvp_role); ?></td>
							<td><span style="color:#d35400; font-weight:bold;"><?php echo esc_html($c->conflict_flag); ?></span></td>
							<td>
								<span class="status-badge" style="padding:3px 8px; border-radius:12px; font-size:0.9em; background:<?php echo ($c->checkin_status === 'Checked In') ? '#d4edda; color:#155724;' : '#eee; color:#666;'; ?>">
									<?php echo esc_html($c->checkin_status); ?>
								</span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * ACTION: Sync from Form 2 (Officials) and Form 4 (Dignitaries).
	 */
	public function handle_sync_authority() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );

		// Sync Form 2 (Voting Authority)
		$officials = $this->get_gravity_entries( 2 );
		foreach ( $officials as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name'   => $entry['3'],
				'first_name'       => $entry['1.3'],
				'last_name'        => $entry['1.6'],
				'official_role'    => $entry['2'],
				'voting_authority' => 1,
				'is_announced'     => 0,
				'checkin_status'   => 'Expected'
			) );
		}

		// Sync Form 4 (Announced Guests)
		$dignitaries = $this->get_gravity_entries( 4 );
		foreach ( $dignitaries as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name'   => $entry['6'],
				'first_name'       => $entry['1.3'],
				'last_name'        => $entry['1.6'],
				'official_role'    => $entry['5'],
				'voting_authority' => 0,
				'is_announced'     => 1,
				'checkin_status'   => 'Expected'
			) );
		}

		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&sync=complete' ) );
		exit;
	}

	/**
	 * ACTION: Import RSVP CSV & Identify Discrepancies.
	 */
	public function handle_rsvp_import() {
		if ( ! isset( $_FILES['rsvp_csv'] ) ) return;

		global $wpdb;
		$handle = fopen( $_FILES['rsvp_csv']['tmp_name'], 'r' );
		fgetcsv( $handle ); // Skip header

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			$first     = trim( $row[0] );
			$last      = trim( $row[1] );
			$rsvp_role = trim( $row[3] );
			$comm      = trim( $row[4] );
			$rsvp_id   = trim( $row[6] );

			// Check if they are already in the manifest (Master DB)
			$match = $wpdb->get_row( $wpdb->prepare( "
				SELECT id FROM $this->table_name 
				WHERE community_name = %s AND last_name = %s AND first_name = %s",
				$comm, $last, $first 
			) );

			if ( $match ) {
				$wpdb->update( $this->table_name, 
					array( 'rsvp_role' => $rsvp_role, 'rsvp_id' => $rsvp_id ),
					array( 'id' => $match->id )
				);
			} else {
				// Not in Master DB - is this a conflict or a general guest?
				$is_official_claim = ( stripos( $rsvp_role, 'Delegate' ) !== false || stripos( $rsvp_role, 'Alternate' ) !== false );
				
				$wpdb->insert( $this->table_name, array(
					'community_name' => $comm,
					'first_name'     => $first,
					'last_name'      => $last,
					'official_role'  => 'None',
					'rsvp_role'      => $rsvp_role,
					'rsvp_id'        => $rsvp_id,
					'conflict_flag'  => $is_official_claim ? 'Conflict/Vacancy' : NULL,
					'checkin_status' => 'Expected'
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
