<?php
/**
 * COBWRA Engine - Professional Normalization (v24.1)
 * Fix: Restored log_scan() to resolve Fatal Error in Kiosk.
 * Update: ID-First Matching for Master Database and Form 4.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt   = 'cobwra_rsvp_lookup_data';
	public $comm_opt  = 'cobwra_active_comm_count';
	public $alias_opt = 'cobwra_name_aliases';
	private $guest_master_form_id = 4;

	/**
	 * Main Analysis Logic
	 */
	public function analyze_scan( $rsvp_id ) {
		global $wpdb;
		$csv_data = get_option( $this->csv_opt, [] );
		$id = str_replace( ['&amp;', 'amp;'], '', sanitize_text_field( $rsvp_id ) );

		// 1. PRIMARY: Check Form 4 Announced Guests (Lookup by Entry ID)
		$announced = $this->check_announced_guests($id);
		if ( $announced ) return $announced;

		// 2. PRIMARY: Check Master Roster (Official Reps by Entry ID)
		$master_rep = $this->check_master_roster_by_id($id);
		if ( $master_rep ) return $master_rep;

		// 3. SECONDARY: Check RSVP Cache (Fallback/Conflict detection)
		if ( isset( $csv_data[$id] ) ) {
			return $this->process_rsvp_fallback($csv_data[$id], $id);
		}

		// 4. FINAL: Unknown Walk-In
		return [ 
			'status' => 'WALK-IN', 
			'color'  => '#636e72', 
			'flag'   => 2, 
			'name'   => 'Unknown', 
			'comm'   => 'N/A', 
			'role'   => 'Guest', 
			'note'   => 'ID not recognized in Master or RSVP lists.' 
		];
	}

	private function check_announced_guests($id) {
		global $wpdb;
		$guest = $wpdb->get_row( $wpdb->prepare( "
			SELECT 
				MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, 
				MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, 
				MAX(CASE WHEN meta_key = '6' THEN meta_value END) as org,
				MAX(CASE WHEN meta_key = '5' THEN meta_value END) as title 
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND entry_id = %d 
			GROUP BY entry_id", 
			$this->guest_master_form_id, 
			$id 
		) );

		if ( $guest && !empty($guest->f) ) { 
			return [ 
				'status' => 'ANNOUNCED', 
				'color'  => '#8e44ad', 
				'flag'   => 1, 
				'name'   => trim("$guest->f $guest->l"), 
				'comm'   => !empty($guest->org) ? $guest->org : 'Guest', 
				'role'   => $guest->title, 
				'note'   => 'Confirmed Guest (Form 4).' 
			]; 
		}
		return false;
	}

	private function check_master_roster_by_id($id) {
		global $wpdb;
		$rep = $wpdb->get_row( $wpdb->prepare( "
			SELECT 
				MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, 
				MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, 
				MAX(CASE WHEN meta_key = '3' THEN meta_value END) as comm,
				MAX(CASE WHEN meta_key = '2' THEN meta_value END) as role 
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND entry_id = %d 
			GROUP BY entry_id", 
			COBWRA_MASTER_FORM, 
			$id 
		) );

		if ( $rep && !empty($rep->f) ) {
			return [ 
				'status' => 'MATCHED', 
				'color'  => '#27ae60', 
				'flag'   => 0, 
				'name'   => trim("$rep->f $rep->l"), 
				'comm'   => $rep->comm, 
				'role'   => $rep->role, 
				'note'   => 'Official Rep (Master Database Match).' 
			];
		}
		return false;
	}

	private function process_rsvp_fallback($rsvp, $id) {
		$full_name = trim($rsvp['first'] . ' ' . $rsvp['last']);
		return [ 
			'status' => 'MATCHED', 
			'color'  => '#27ae60', 
			'flag'   => 0, 
			'name'   => $full_name, 
			'comm'   => $rsvp['comm'], 
			'role'   => $rsvp['role'], 
			'note'   => 'Matched via RSVP List.' 
		];
	}

	/**
	 * RESTORED: Log Scan method for Kiosk use
	 */
	public function log_scan( $res, $id ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}gf_entry", [ 'form_id' => COBWRA_TEMP_FORM, 'date_created' => current_time('mysql'), 'status' => 'active' ] );
		$eid = $wpdb->insert_id;
		$meta = [ '1' => $res['comm'], '3' => "{$res['name']} ({$res['role']})", '5' => $res['flag'], '6' => $id ];
		foreach ( $meta as $k => $v ) { 
			$wpdb->insert( "{$wpdb->prefix}gf_entry_meta", [ 'entry_id' => $eid, 'form_id' => COBWRA_TEMP_FORM, 'meta_key' => (string)$k, 'meta_value' => $v ] ); 
		}
	}

	private function clean_name($str) {
		$str = strtolower(trim($str));
		$str = str_replace(['dr.', 'dr ', 'doctor', 'hon.', 'hon '], '', $str);
		$str = preg_replace('/\s[a-z]\.?$/', '', $str); 
		$str = preg_replace('/^[a-z]\.?\s/', '', $str); 
		return trim($str);
	}
}
