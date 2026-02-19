<?php
class COBWRA_Engine {
	public $csv_opt  = 'cobwra_rsvp_lookup_data';
	public $comm_opt = 'cobwra_active_comm_count';
	private $guest_master_form_id = 4;

	public function analyze_scan( $rsvp_id ) {
		global $wpdb;
		$csv_data = get_option( $this->csv_opt, [] );
		$id = str_replace( ['&amp;', 'amp;'], '', sanitize_text_field( $rsvp_id ) );

		if ( ! isset( $csv_data[$id] ) ) {
			return $this->check_announced_guests($id);
		}

		$rsvp = $csv_data[$id];
		$full_name = trim( $rsvp['first'] . ' ' . $rsvp['last'] );
		$community = $rsvp['comm'];
		$claimed_role = trim( $rsvp['role'] );
		$is_rep_rsvp = (str_contains(strtolower($claimed_role), 'delegate') || str_contains(strtolower($claimed_role), 'alternate'));

		$roster = $wpdb->get_results( $wpdb->prepare( "
			SELECT MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f,
				   MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l,
				   MAX(CASE WHEN meta_key = '2' THEN meta_value END) as r
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND entry_id IN (
				SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = '3' AND meta_value = %s
			) GROUP BY entry_id
		", COBWRA_MASTER_FORM, $community ) );

		$name_match = false; $fuzzy_match = false; $official_role = ''; $role_incumbent = '';

		foreach ( $roster as $rep ) {
			$master_name = trim( $rep->f . ' ' . $rep->l );
			if ( strcasecmp( $master_name, $full_name ) === 0 ) { $name_match = true; $official_role = $rep->r; }
			if ( ! $name_match && $this->is_fuzzy_match( $full_name, $master_name ) ) { $fuzzy_match = true; $official_role = $rep->r; }
			if ( strcasecmp( $rep->r, $claimed_role ) === 0 ) { $role_incumbent = $master_name; }
		}

		// 1. MATCHED (Exact or Fuzzy)
		if ( $name_match || $fuzzy_match ) {
			$note = $fuzzy_match ? "Fuzzy Match (e.g. Steve/Steven)." : "Official Record.";
			if ( strcasecmp( $official_role, $claimed_role ) === 0 ) {
				return [ 'status' => 'MATCHED', 'color' => '#27ae60', 'flag' => 0, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'note' => $note ];
			}
			return [ 'status' => 'ROLE MISMATCH', 'color' => '#f1c40f', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'note' => "Master lists as: $official_role." ];
		}

		// 2. CONFLICT (Role taken by someone else)
		if ( $role_incumbent && !empty(trim($role_incumbent)) ) {
			return [ 'status' => 'CONFLICT', 'color' => '#e67e22', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'note' => "Seat held by $role_incumbent." ];
		}

		// 3. STRICT VACANCY (Only if they registered as a Rep and seat is empty)
		if ( $is_rep_rsvp ) {
			return [ 'status' => 'VACANCY', 'color' => '#3498db', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'note' => "Role is vacant in Master. Potential new Rep." ];
		}

		// 4. GENERAL PUBLIC (They registered as Guest/Public, even if a seat is open)
		return [ 'status' => 'GUEST/PUBLIC', 'color' => '#95a5a6', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'note' => "Registered as Guest/Public." ];
	}

	private function check_announced_guests($id) {
		global $wpdb;
		$guest = $wpdb->get_row( $wpdb->prepare( "SELECT MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, MAX(CASE WHEN meta_key = '5' THEN meta_value END) as title FROM {$wpdb->prefix}gf_entry_meta WHERE form_id = %d AND entry_id IN (SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = '6' AND meta_value = %s) GROUP BY entry_id", $this->guest_master_form_id, $id ) );
		if ( $guest ) { return [ 'status' => 'ANNOUNCED', 'color' => '#8e44ad', 'flag' => 1, 'name' => "$guest->f $guest->l", 'comm' => 'Guest Master', 'role' => $guest->title, 'note' => 'Confirmed in Form 4.' ]; }
		return [ 'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 'name' => 'Unknown', 'comm' => 'N/A', 'note' => 'No record found.' ];
	}

	private function is_fuzzy_match( $n1, $n2 ) {
		$n1 = strtolower(trim($n1)); $n2 = strtolower(trim($n2));
		if ( (str_contains($n1, $n2) || str_contains($n2, $n1)) && substr($n1, 0, 4) === substr($n2, 0, 4) ) return true;
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
