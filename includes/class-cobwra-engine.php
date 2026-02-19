<?php
/**
 * COBWRA Engine - Professional Normalization (v26.1)
 * Fix: Restored log_scan to prevent Kiosk Fatal Error.
 * Fix: Added logic to handle ID-less Master List QR Codes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt   = 'cobwra_rsvp_lookup_data';
	public $alias_opt = 'cobwra_name_aliases';
	private $guest_master_form_id = 4;

	public function analyze_scan( $rsvp_id ) {
		global $wpdb;
		$id = sanitize_text_field( str_replace( ['&amp;', 'amp;'], '', $rsvp_id ) );

		// 1. Check Form 4 Announced Guests (Lookup by Entry ID)
		if ( is_numeric( $id ) ) {
			$announced = $this->check_announced_guests( $id );
			if ( $announced ) return $announced;

			$master_rep = $this->check_master_roster_by_id( $id );
			if ( $master_rep ) return $master_rep;
		} 

		// 2. String-based lookup for QR codes (e.g., ?c=Banyan%20Springs&l=Turner)
		$master_name_match = $this->check_master_roster_by_name( $id );
		if ( $master_name_match ) return $master_name_match;

		// 3. Fallback to RSVP Cache
		$csv_data = get_option( $this->csv_opt, [] );
		if ( isset( $csv_data[$id] ) ) {
			return [ 
				'status' => 'MATCHED', 
				'color'  => '#27ae60', 
				'flag'   => 0, 
				'name'   => trim($csv_data[$id]['first'] . ' ' . $csv_data[$id]['last']), 
				'comm'   => $csv_data[$id]['comm'], 
				'role'   => $csv_data[$id]['role'], 
				'note'   => 'Matched via RSVP List.' 
			];
		}

		return [ 
			'status' => 'WALK-IN', 
			'color'  => '#636e72', 
			'flag'   => 2, 
			'name'   => 'Unknown', 
			'comm'   => 'N/A', 
			'role'   => 'Guest', 
			'note'   => 'ID/Name not recognized.' 
		];
	}

	private function check_master_roster_by_name( $search_str ) {
		global $wpdb;
		if ( empty($search_str) || strlen($search_str) < 3 ) return false;

		$like_val = '%' . $wpdb->esc_like( $search_str ) . '%';

		$rep = $wpdb->get_row( $wpdb->prepare( "
			SELECT 
				MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, 
				MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, 
				MAX(CASE WHEN meta_key = '3' THEN meta_value END) as comm,
				MAX(CASE WHEN meta_key = '2' THEN meta_value END) as role 
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND entry_id IN (
				SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta 
				WHERE meta_value LIKE %s
			)
			GROUP BY entry_id LIMIT 1", 
			COBWRA_MASTER_FORM, 
			$like_val 
		) );

		if ( $rep && !empty($rep->f) ) {
			return [ 
				'status' => 'MATCHED', 
				'color'  => '#27ae60', 
				'flag'   => 0, 
				'name'   => trim("$rep->f $rep->l"), 
				'comm'   => $rep->comm, 
				'role'   => $rep->role, 
				'note'   => 'Official Rep (Master Name Match).' 
			];
		}
		return false;
	}

	private function check_announced_guests( $id ) {
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

	private function check_master_roster_by_id( $id ) {
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
				'note'   => 'Official Rep (ID Match).' 
			];
		}
		return false;
	}

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
