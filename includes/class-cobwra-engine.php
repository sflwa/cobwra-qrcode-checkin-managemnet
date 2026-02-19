<?php
/**
 * COBWRA Engine - Credential Verification Logic (v19.4)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt  = 'cobwra_rsvp_lookup_data';
	public $comm_opt = 'cobwra_active_comm_count';
	public $alias_opt = 'cobwra_name_aliases';
	private $guest_master_form_id = 4;

	/**
	 * Analyze a scanned ID against RSVP CSV and Master Form 2
	 */
	public function analyze_scan( $rsvp_id ) {
		global $wpdb;
		$csv_data = get_option( $this->csv_opt, [] );
		$id = str_replace( ['&amp;', 'amp;'], '', sanitize_text_field( $rsvp_id ) );

		// 1. CHECK ANNOUNCED GUESTS (FORM 4) FIRST
		$announced = $this->check_announced_guests($id);
		if ( $announced ) return $announced;

		// 2. CHECK RSVP DATA
		if ( ! isset( $csv_data[$id] ) ) {
			return [ 'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 'name' => 'Unknown', 'comm' => 'N/A', 'role' => 'Guest', 'note' => 'No RSVP Record Found.' ];
		}

		$rsvp = $csv_data[$id];
		$full_name = trim( $rsvp['first'] . ' ' . $rsvp['last'] );
		$community = $rsvp['comm'];
		$claimed_role = trim( $rsvp['role'] );
		$is_rep_rsvp = (str_contains(strtolower($claimed_role), 'delegate') || str_contains(strtolower($claimed_role), 'alternate'));

		// Fetch Master Roster for Community
		$roster = $wpdb->get_results( $wpdb->prepare( "
			SELECT MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f,
				   MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l,
				   MAX(CASE WHEN meta_key = '2' THEN meta_value END) as r
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND entry_id IN (
				SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = '3' AND meta_value = %s
			) GROUP BY entry_id
		", COBWRA_MASTER_FORM, $community ) );

		$name_match = false; 
		$fuzzy_match = false; 
		$official_role = ''; 
		$role_incumbent = '';

		foreach ( $roster as $rep ) {
			$master_name = trim( $rep->f . ' ' . $rep->l );
			
			// Check Exact Match
			if ( strcasecmp( $master_name, $full_name ) === 0 ) { 
				$name_match = true; 
				$official_role = $rep->r; 
			}
			
			// Check Fuzzy Match (Alias list + Levenshtein)
			if ( ! $name_match && $this->is_fuzzy_match( $full_name, $master_name ) ) { 
				$fuzzy_match = true; 
				$official_role = $rep->r; 
			}

			if ( strcasecmp( $rep->r, $claimed_role ) === 0 ) { 
				$role_incumbent = $master_name; 
			}
		}

		// LOGIC BRANCHING
		if ( $name_match || $fuzzy_match ) {
			$match_note = $fuzzy_match ? "Fuzzy Match recognized." : "Official Record.";
			if ( strcasecmp( $official_role, $claimed_role ) === 0 ) {
				return [ 'status' => 'MATCHED', 'color' => '#27ae60', 'flag' => 0, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $rsvp['email'], 'note' => $match_note ];
			}
			return [ 'status' => 'ROLE MISMATCH', 'color' => '#f1c40f', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $rsvp['email'], 'note' => "Master lists as: $official_role." ];
		}

		if ( $role_incumbent && !empty(trim($role_incumbent)) ) {
			return [ 'status' => 'CONFLICT', 'color' => '#e67e22', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $rsvp['email'], 'note' => "Seat held by $role_incumbent." ];
		}

		if ( $is_rep_rsvp ) {
			return [ 'status' => 'VACANCY', 'color' => '#3498db', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $rsvp['email'], 'note' => "Seat is vacant in Master Roster." ];
		}

		return [ 'status' => 'GUEST/PUBLIC', 'color' => '#95a5a6', 'flag' => 2, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $rsvp['email'], 'note' => "General Public." ];
	}

	private function check_announced_guests($id) {
		global $wpdb;
		$guest = $wpdb->get_row( $wpdb->prepare( "SELECT MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, MAX(CASE WHEN meta_key = '5' THEN meta_value END) as title FROM {$wpdb->prefix}gf_entry_meta WHERE form_id = %d AND entry_id IN (SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = '6' AND meta_value = %s) GROUP BY entry_id", $this->guest_master_form_id, $id ) );
		if ( $guest ) { 
			return [ 'status' => 'ANNOUNCED', 'color' => '#8e44ad', 'flag' => 1, 'name' => "$guest->f $guest->l", 'comm' => 'Guest Master', 'role' => $guest->title, 'note' => 'Elected Official / Guest (Form 4).' ]; 
		}
		return false;
	}

	private function is_fuzzy_match( $n1, $n2 ) {
		$n1 = strtolower(trim(str_replace('Dr. ', '', $n1)));
		$n2 = strtolower(trim(str_replace('Dr. ', '', $n2)));

		// Check Admin Aliases
		$alias_blob = get_option( $this->alias_opt, '' );
		if ( ! empty( $alias_blob ) ) {
			$lines = explode( "\n", str_replace( "\r", "", $alias_blob ) );
			foreach ( $lines as $line ) {
				$names = array_map( 'trim', explode( ',', strtolower( $line ) ) );
				$f1 = explode(' ', $n1)[0];
				$f2 = explode(' ', $n2)[0];
				if ( in_array($f1, $names) && in_array($f2, $names) ) return true;
			}
		}
		
		// Fallback: Substring (Steve/Steven) + shared root
		if ( (str_contains($n1, $n2) || str_contains($n2, $n1)) && substr($n1, 0, 3) === substr($n2, 0, 3) ) return true;
		
		return levenshtein($n1, $n2) <= 2;
	}

	public function log_scan( $res, $id ) {
		global $wpdb;
		$wpdb->insert( "{$wpdb->prefix}gf_entry", [ 'form_id' => COBWRA_TEMP_FORM, 'date_created' => current_time('mysql'), 'status' => 'active' ] );
		$eid = $wpdb->insert_id;
		$meta = [ '1' => $res['comm'], '3' => "{$res['name']} ({$res['role']})", '5' => $res['flag'], '6' => $id ];
		foreach ( $meta as $k => $v ) { $wpdb->insert( "{$wpdb->prefix}gf_entry_meta", [ 'entry_id' => $eid, 'form_id' => COBWRA_TEMP_FORM, 'meta_key' => (string)$k, 'meta_value' => $v ] ); }
	}
}
