<?php
/**
 * COBWRA Engine - Professional Normalization (v32.0)
 * Fix: Re-integrated First Name Fuzzy Alias logic as a Master lookup fallback.
 * Logic: ID -> Strict (Comm + Last) -> Fuzzy Name Alias (First Name only).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt   = 'cobwra_rsvp_lookup_data';
	public $alias_opt = 'cobwra_name_aliases'; // The Admin panel setting
	private $guest_master_form_id = 4;

	public function analyze_scan( $primary_id, $last_name = '' ) {
		global $wpdb;
		$input_id = sanitize_text_field( str_replace( array( '&amp;', 'amp;' ), '', $primary_id ) );

		// 1. PRIMARY: Numeric ID Match (RSVP/Badge ID)
		if ( is_numeric( $input_id ) ) {
			$master = $this->check_roster_by_id( COBWRA_MASTER_FORM, $input_id );
			if ( $master ) return $master;

			$guest = $this->check_roster_by_id( $this->guest_master_form_id, $input_id );
			if ( $guest ) return $guest;
		}

		// 2. SECONDARY: Strict String Match (Community + Last Name)
		// This is the "Banyan Springs + Turner" logic.
		if ( ! empty( $last_name ) ) {
			$strict_master = $this->lookup_roster_strict( COBWRA_MASTER_FORM, $input_id, $last_name );
			if ( $strict_master ) return $strict_master;

			$strict_guest = $this->lookup_roster_strict( $this->guest_master_form_id, $input_id, $last_name );
			if ( $strict_guest ) return $strict_guest;
		}

		// 3. TERTIARY: RSVP Fallback & Discrepancy Tracking
		$csv_data = get_option( $this->csv_opt, array() );
		if ( isset( $csv_data[$input_id] ) ) {
			return $this->identify_discrepancy( $csv_data[$input_id] );
		}

		return array( 
			'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 
			'name' => 'Unknown', 'comm' => 'N/A', 'role' => 'Guest', 
			'note' => 'Not recognized in any database.' 
		);
	}

	/**
	 * Looks up a record by Community/Org and Last Name.
	 * If First Name doesn't match exactly, it triggers the Fuzzy Alias logic.
	 */
	private function lookup_roster_strict( $form_id, $comm_val, $last_val ) {
		global $wpdb;
		$comm_key = ( $form_id == $this->guest_master_form_id ) ? '6' : '3';

		// Get all potential entries for this community and last name
		$entries = $wpdb->get_results( $wpdb->prepare( "
			SELECT m1.entry_id, 
				   MAX(CASE WHEN m1.meta_key = '1.3' THEN m1.meta_value END) as f,
				   MAX(CASE WHEN m1.meta_key = '1.6' THEN m1.meta_value END) as l,
				   MAX(CASE WHEN m1.meta_key = %s THEN m1.meta_value END) as c
			FROM {$wpdb->prefix}gf_entry_meta m1
			WHERE m1.form_id = %d
			GROUP BY m1.entry_id
			HAVING c = %s AND l = %s",
			$comm_key, $form_id, $comm_val, $last_val
		) );

		if ( $entries ) {
			foreach ( $entries as $entry ) {
				// Here is your Fuzzy Logic: We check first names using the Admin Alias list
				if ( $this->is_first_name_match( $entry->f, $comm_val ) ) {
					return $this->check_roster_by_id( $form_id, $entry->entry_id );
				}
			}
		}
		return false;
	}

	/**
	 * Fuzzy First Name Logic using Admin Panel Aliases
	 */
	private function is_first_name_match( $db_first, $input_first ) {
		$f1 = strtolower( trim( $db_first ) );
		$f2 = strtolower( trim( $input_first ) );

		if ( $f1 === $f2 ) return true;

		// Check the Admin-defined comma separated list
		$alias_list = get_option( $this->alias_opt, '' );
		if ( ! empty( $alias_list ) ) {
			$lines = explode( "\n", str_replace( "\r", "", $alias_list ) );
			foreach ( $lines as $line ) {
				$names = array_map( 'trim', explode( ',', strtolower( $line ) ) );
				if ( in_array( $f1, $names ) && in_array( $f2, $names ) ) {
					return true;
				}
			}
		}
		return false;
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
			'role' => $rsvp['role'], 'note' => 'Claims official role; verifying position.' 
		);
	}
}
