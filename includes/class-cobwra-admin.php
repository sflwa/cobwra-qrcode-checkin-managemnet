<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Roster Management (v49.1)
 * Updated with strict Approval Status Filtering for Manifest Synchronization.
 */

// ... (Keep existing constructor and render_dashboard logic) ...

	/**
	 * ACTION: Sync from Master Database (Form 2) and Announced Guests (Form 4).
	 * Now strictly filters for 'Approved' status only.
	 */
	public function handle_sync_authority() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE $this->table_name" );
		
		// 1. Sync Form 2 (Voting Officials) - ONLY APPROVED 
		$officials = $this->get_gravity_entries( 2, true ); 
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

		// 2. Sync Form 4 (Announced Guests) - ONLY APPROVED 
		$guests = $this->get_gravity_entries( 4, true );
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
	 * Helper: Fetch entries from Gravity Forms with optional Approval filter.
	 */
	private function get_gravity_entries( $form_id, $only_approved = false ) {
		global $wpdb;
		
		// Base Query: Get Active entries 
		$query = $wpdb->prepare( 
			"SELECT id FROM {$wpdb->prefix}gf_entry WHERE form_id = %d AND status = 'active'", 
			$form_id 
		);
		
		$entry_ids = $wpdb->get_col( $query );
		$mapped_data = array();

		foreach ( $entry_ids as $eid ) {
			$meta = $wpdb->get_results( $wpdb->prepare( 
				"SELECT meta_key, meta_value FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d", 
				$eid 
			) );
			
			$row = array();
			$is_entry_approved = false;
			
			foreach ( $meta as $m ) {
				$row[$m->meta_key] = $m->meta_value;
				
				// Check for the specific Approval Key (usually 'is_approved') 
				// We look for value '1' as per your confirmation of Approved status.
				if ( $m->meta_key === 'is_approved' && $m->meta_value == '1' ) {
					$is_approved_entry = true;
				}
			}
			
			// Only include if approved (if requested) 
			if ( $only_approved && !isset($row['is_approved']) || (isset($row['is_approved']) && $row['is_approved'] != '1') ) {
				continue;
			}

			$mapped_data[] = $row;
		}
		return $mapped_data;
	}

// ... (Rest of file: handle_rsvp_import, handle_clear_roster, handle_save_settings) ...
