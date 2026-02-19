<?php
/**
 * COBWRA Engine - Professional Normalization (v34.0)
 * Fix: Prioritized Community/Name string matching OVER Numeric IDs.
 * Logic: Strict String (Comm + Last) -> Strict Guest (Org + Last) -> RSVP ID Fallback.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt   = 'cobwra_rsvp_lookup_data';
	public $alias_opt = 'cobwra_name_aliases';
	private $guest_master_form_id = 4;

	public function analyze_scan( $comm_or_id, $last_name = '' ) {
		global $wpdb;
		
		$comm_input = sanitize_text_field( $comm_or_id );
		$last_input = sanitize_text_field( $last_name );

		// 1. PRIMARY: Strict Master Roster Match (Community + Last Name)
		// This is for Master Directory QR codes (?c=Banyan%20Springs&l=Turner)
		if ( ! empty( $comm_input ) && ! empty( $last_input ) ) {
			$master = $this->lookup_roster_strict( COBWRA_MASTER_FORM, '3', $comm_input, $last_input );
			if ( $master ) return $master;

			// 2. SECONDARY: Strict Guest Master Match (Org + Last Name)
			$guest = $this->lookup_roster_strict( $this->guest_master_form_id, '6', $comm_input, $last_input );
			if ( $guest ) return $guest;
		}

		// 3. TERTIARY: RSVP/ID Fallback (The numeric safety net)
		// Used only if the string match fails or if ONLY an ID was passed.
		$lookup_id = is_numeric( $comm_input ) ? $comm_input : '';
		if ( ! empty( $lookup_id ) ) {
			$csv_data = get_option( $this->csv_opt, array() );
			if ( isset( $csv_data[$lookup_id] ) ) {
				return $this->identify_discrepancy( $csv_data[$lookup_id] );
			}
			
			// Check if the ID itself belongs to a Master/Guest record directly
			$direct_master = $this->check_roster_by_id( COBWRA_MASTER_FORM, $lookup_id );
			if ( $direct_master ) return $direct_master;
		}

		return array( 
			'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 
			'name' => 'Unknown', 'comm' => 'N/A', 'role' => 'Guest', 
			'note' => 'Not recognized in Master, Guest, or RSVP.' 
		);
	}

	private function lookup_roster_strict( $form_id, $comm_key, $comm_val, $last_val ) {
		global $wpdb;

		// Use INNER JOIN to find an entry that satisfies BOTH conditions
		$entry_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT m1.entry_id 
			FROM {$wpdb->prefix}gf_entry_meta m1
			JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id
			WHERE m1.form_id = %d 
			AND (m1.meta_key = %s AND m1.meta_value = %s)
			AND (m2.meta_key = '1.6' AND m2.meta_value = %s)
			LIMIT 1",
			$form_id, $comm_key, $comm_val, $last_val
		) );

		return $entry_id ? $this->check_roster_by_id( $form_id, $entry_id ) : false;
	}

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
