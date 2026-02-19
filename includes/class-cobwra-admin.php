<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v48.4)
 * Handles Menu Registration, Sync, Clear, and RSVP Import Logic.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Admin {

	private $table_name;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
		
		// Register the Sidebar Menu
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		
		// Hooks for Admin Actions
		add_action( 'admin_post_cobwra_sync_authority', array( $this, 'handle_sync_authority' ) );
		add_action( 'admin_post_cobwra_clear_roster', array( $this, 'handle_clear_roster' ) );
		add_action( 'admin_post_cobwra_import_rsvp', array( $this, 'handle_rsvp_import' ) );
	}

	/**
	 * Register the "Meeting Manager" item in the WordPress Sidebar.
	 */
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

		// Fetch stats for the dashboard
		$total_roster = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");
		$voting_members = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE voting_authority = 1");
		$announced_guests = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE is_announced = 1");
		?>
		<div class="wrap">
			<h1>COBWRA Meeting Manager (v48.4)</h1>
			
			<div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; margin-top: 20px;">
				<h2>Data Preparation</h2>
				<p>Perform these steps in order before the meeting starts to populate the high-performance roster.</p>
				
				<div style="display:flex; gap:10px; margin-bottom:20px;">
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_sync_authority'); ?>" class="button button-primary">1. Synchronize from Master DBs</a>
					<a href="<?php echo admin_url('admin-post.php?action=cobwra_clear_roster'); ?>" class="button" onclick="return confirm('Wipe current meeting roster?')">Clear Roster</a>
				</div>
				
				<hr>
				
				<h3>2. Import RSVPs (CSV)</h3>
				<p>Upload the RSVP CSV to match attendees to the roster and identify vacancies/conflicts.</p>
				<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="cobwra_import_rsvp">
					<input type="file" name="rsvp_csv" accept=".csv" required>
					<?php submit_button('Upload and Match RSVPs', 'secondary', 'submit', false); ?>
				</form>
			</div>

			<div style="margin-top:20px;">
				<h3>Current Roster Stats</h3>
				<ul>
					<li><strong>Total Records:</strong> <?php echo (int)$total_roster; ?></li>
					<li><strong>Voting Authority (Form 2):</strong> <?php echo (int)$voting_members; ?></li>
					<li><strong>Announced Guests (Form 4):</strong> <?php echo (int)$announced_guests; ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * ACTION: Sync from Form 2 (Officials) and Form 4 (Dignitaries).
	 */
	public function handle_sync_authority() {
		global $wpdb;
		
		// 1. Clear existing roster data
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );

		// 2. Fetch Active Officials (Form 2)
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

		// 3. Fetch Active Dignitaries (Form 4)
		$dignitaries = $this->get_gravity_entries( 4 );
		foreach ( $dignitaries as $entry ) {
			$wpdb->insert( $this->table_name, array(
				'community_name'   => $entry['6'], // Field 6: Organization
				'first_name'       => $entry['1.3'],
				'last_name'        => $entry['1.6'],
				'official_role'    => $entry['5'], // Field 5: Office/Title
				'voting_authority' => 0,
				'is_announced'     => 1,
				'checkin_status'   => 'Expected'
			) );
		}

		wp_redirect( admin_url( 'admin.php?page=cobwra-dashboard&sync=complete' ) );
		exit;
	}

	/**
	 * ACTION: Import RSVP CSV & Flag Conflicts/Vacancies.
	 */
	public function handle_rsvp_import() {
		if ( ! isset( $_FILES['rsvp_csv'] ) ) return;

		global $wpdb;
		$handle = fopen( $_FILES['rsvp_csv']['tmp_name'], 'r' );
		fgetcsv( $handle ); // Skip header

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			// Mapping: 0=ID, 1=First, 2=Last, 3=Email, 4=Role, 5=Community
			$rsvp_id   = $row[0];
			$first     = trim( $row[1] );
			$last      = trim( $row[2] );
			$rsvp_role = trim( $row[4] );
			$comm      = trim( $row[5] );

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

	/**
	 * Helper: Fetch active entry data as an array.
	 */
	private function get_gravity_entries( $form_id ) {
		global $wpdb;
		$entries = $wpdb->get_results( $wpdb->prepare( 
			"SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'active'", 
			$form_id 
		) );
		
		$mapped_data = array();
		foreach ( $entries as $entry ) {
			$meta = $wpdb->get_results( $wpdb->prepare( 
				"SELECT meta_key, meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d", 
				$entry->id 
			) );
			
			$row = array();
			foreach ( $meta as $m ) {
				$row[$m->meta_key] = $m->meta_value;
			}
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
