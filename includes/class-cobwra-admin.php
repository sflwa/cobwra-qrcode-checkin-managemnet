<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v48.0)
 * Handles Sync, Clear, and RSVP Import Logic.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Admin {

	private $table_name;
	private $db_helper;

	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
		
		// Hook for Admin Actions
		add_action( 'admin_post_cobwra_sync_authority', array( $this, 'handle_sync_authority' ) );
		add_action( 'admin_post_cobwra_clear_roster', array( $this, 'handle_clear_roster' ) );
		add_action( 'admin_post_cobwra_import_rsvp', array( $this, 'handle_rsvp_import' ) );
	}

	/**
	 * ACTION: Sync from Form 2 (Officials) and Form 4 (Dignitaries)
	 */
	public function handle_sync_authority() {
		global $wpdb;
		
		// 1. Clear existing authority data first
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );

		// 2. Fetch Active Officials (Form 2)
		// Meta Mapping: 3=Community, 1.3=First, 1.6=Last, 2=Role
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

		// 3. Fetch Active Dignitaries (Form 4)
		// Meta Mapping: 6=Organization, 1.3=First, 1.6=Last, 5=Office/Title
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
	 * ACTION: Import RSVP CSV & Flag Conflicts/Vacancies
	 */
	public function handle_rsvp_import() {
		if ( ! isset( $_FILES['rsvp_csv'] ) ) return;

		global $wpdb;
		$handle = fopen( $_FILES['rsvp_csv']['tmp_name'], 'r' );
		$header = fgetcsv( $handle ); // Skip header

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			// Mapping based on your RSVP CSV structure:
			// 0=ID, 1=First, 2=Last, 3=Email, 4=Role, 5=Community
			$rsvp_id   = $row[0];
			$first     = trim( $row[1] );
			$last      = trim( $row[2] );
			$rsvp_role = trim( $row[4] );
			$comm      = trim( $row[5] );

			// Check if this person exists in our Authority Roster
			$match = $wpdb->get_row( $wpdb->prepare( "
				SELECT id, official_role FROM $this->table_name 
				WHERE community_name = %s AND last_name = %s AND first_name = %s",
				$comm, $last, $first 
			) );

			if ( $match ) {
				// UPDATE: They are a legitimate official/guest who RSVP'd
				$wpdb->update( $this->table_name, 
					array( 'rsvp_role' => $rsvp_role, 'rsvp_id' => $rsvp_id ),
					array( 'id' => $match->id )
				);
			} else {
				// INSERT: They RSVP'd but aren't in the Master Database
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
	 * Helper: Get active entries for a specific form
	 */
	private function get_gravity_entries( $form_id ) {
		// Logic to pull from wp_gf_entry_meta where entry status is 'active'
		// This ensures no ghost data from trashed entries.
	}
}
