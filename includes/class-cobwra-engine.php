<?php
/**
 * COBWRA Engine - Professional Normalization (v35.0)
 * Logic: Strict Matching with Persistent File Logging.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt   = 'cobwra_rsvp_lookup_data';
	public $alias_opt = 'cobwra_name_aliases';
	private $guest_master_form_id = 4;
	private $log_file;

	public function __construct() {
		// Define log path: wp-content/plugins/your-plugin/includes/cobwra_debug.log
		$this->log_file = plugin_dir_path( __FILE__ ) . 'cobwra_debug.log';
	}

	/**
	 * Custom Logger
	 */
	private function log_event( $message ) {
		$timestamp = current_time( 'mysql' );
		$entry = "[{$timestamp}] {$message}\n";
		file_put_contents( $this->log_file, $entry, FILE_APPEND );
	}

	public function analyze_scan( $comm_or_id, $last_name = '' ) {
		global $wpdb;
		
		$comm_input = sanitize_text_field( $comm_or_id );
		$last_input = sanitize_text_field( $last_name );
		
		$this->log_event( "--- NEW SCAN DETECTED ---" );
		$this->log_event( "Input Params: Community/ID: '{$comm_input}', Last Name: '{$last_input}'" );

		// 1. STEP: Strict Master Roster Match (Form 2)
		if ( ! empty( $comm_input ) && ! empty( $last_input ) ) {
			$this->log_event( "Step 1: Attempting Strict Master Match (Form 2) for {$comm_input} | {$last_input}" );
			$master = $this->lookup_roster_strict( COBWRA_MASTER_FORM, '3', $comm_input, $last_input );
			if ( $master ) {
				$this->log_event( "Result: SUCCESS - Match found in Master Roster." );
				return $master;
			}
			$this->log_event( "Result: No Match in Master Roster." );

			// 2. STEP: Strict Guest Master Match (Form 4)
			$this->log_event( "Step 2: Attempting Strict Guest Match (Form 4) for {$comm_input} | {$last_input}" );
			$guest = $this->lookup_roster_strict( $this->guest_master_form_id, '6', $comm_input, $last_input );
			if ( $guest ) {
				$this->log_event( "Result: SUCCESS - Match found in Guest Master." );
				return $guest;
			}
			$this->log_event( "Result: No Match in Guest Master." );
		}

		// 3. STEP: RSVP/ID Fallback
		$lookup_id = is_numeric( $comm_input ) ? $comm_input : '';
		if ( ! empty( $lookup_id ) ) {
			$this->log_event( "Step 3: Attempting RSVP ID Fallback for ID: {$lookup_id}" );
			$csv_data = get_option( $this->csv_opt, array() );
			if ( isset( $csv_data[$lookup_id] ) ) {
				$this->log_event( "Result: Match found in RSVP CSV Cache." );
				return $this->identify_discrepancy( $csv_data[$lookup_id] );
			}
			$this->log_event( "Result: No Match in RSVP Cache." );
		}

		$this->log_event( "Final Status: WALK-IN (No records found)" );
		return array( 
			'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 
			'name' => 'Unknown', 'comm' => 'N/A', 'role' => 'Guest', 
			'note' => 'Not recognized.' 
		);
	}

	private function lookup_roster_strict( $form_id, $comm_key, $comm_val, $last_val ) {
		global $wpdb;
		$sql = $wpdb->prepare( "
			SELECT m1.entry_id 
			FROM {$wpdb->prefix}gf_entry_meta m1
			JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id
			WHERE m1.form_id = %d 
			AND (m1.meta_key = %s AND m1.meta_value = %s)
			AND (m2.meta_key = '1.6' AND m2.meta_value = %s)
			LIMIT 1",
			$form_id, $comm_key, $comm_val, $last_val
		);
		
		$this->log_event( "Running SQL: " . $sql );
		$entry_id = $wpdb->get_var( $sql );
		
		if ( $entry_id ) {
			$this->log_event( "SQL found Entry ID: " . $entry_id );
			return $this->check_roster_by_id( $form_id, $entry_id );
		}
		return false;
	}

    // ... (rest of helper methods check_roster_by_id and log_scan remain unchanged) ...
    private function check_roster_by_id( $form_id, $eid ) {
		global $wpdb;
		$is_guest = ( $form_id == $this->guest_master_form_id );
		$comm_field = $is_guest ? '6' : '3';
		$role_field = $is_guest ? '5' : '2';

		$data = $wpdb->get_row( $wpdb->prepare( "
			SELECT MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f,
				   MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l,
				   MAX(CASE WHEN meta_key = %s THEN meta_value END) as c,
				   MAX(CASE WHEN meta_key = %s THEN meta_value END) as r
			FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d GROUP BY entry_id", 
			$comm_field, $role_field, $eid 
		) );

		if ( $data && ! empty( $data->f ) ) {
			return array(
				'status' => $is_guest ? 'ANNOUNCED' : 'MATCHED',
				'color'  => $is_guest ? '#8e44ad' : '#27ae60',
				'flag'   => 0,
				'name'   => trim( "$data->f $data->l" ),
				'comm'   => $data->c,
				'role'   => $data->r,
				'note'   => $is_guest ? 'Confirmed Guest' : 'Official Representative'
			);
		}
		return false;
	}

	public function log_scan( $res, $id ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}gf_entry", array( 'form_id' => COBWRA_TEMP_FORM, 'date_created' => current_time( 'mysql' ), 'status' => 'active' ) );
		$eid = $wpdb->insert_id;
		$meta = array( '1' => $res['comm'], '3' => "{$res['name']} ({$res['role']})", '5' => $res['flag'], '6' => $id );
		foreach ( $meta as $k => $v ) { $wpdb->insert( "{$wpdb->prefix}gf_entry_meta", array( 'entry_id' => $eid, 'form_id' => COBWRA_TEMP_FORM, 'meta_key' => (string) $k, 'meta_value' => $v ) ); }
	}

	private function identify_discrepancy( $rsvp ) {
		return array( 
			'status' => 'VACANCY', 'color' => '#3498db', 'flag' => 1, 
			'name' => $rsvp['first'] . ' ' . $rsvp['last'], 'comm' => $rsvp['comm'], 
			'role' => $rsvp['role'], 'note' => 'Claims official role; verify seat in Master Roster.' 
		);
	}
}
