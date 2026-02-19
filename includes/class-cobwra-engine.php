<?php
/**
 * COBWRA Engine - Credential Verification Logic
 */
class COBWRA_Engine {
	public $csv_opt = 'cobwra_rsvp_lookup_data';

	public function analyze_scan( $rsvp_id ) {
		global $wpdb;
		$csv_data = get_option( $this->csv_opt, [] );
		$id = str_replace( ['&amp;', 'amp;'], '', sanitize_text_field( $rsvp_id ) );

		if ( ! isset( $csv_data[$id] ) ) {
			return [ 'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 'name' => 'Unknown', 'comm' => 'N/A', 'note' => 'ID not found in RSVP export.' ];
		}

		$rsvp = $csv_data[$id];
		$full_name = trim( $rsvp['first'] . ' ' . $rsvp['last'] );
		$community = $rsvp['comm'];
		$claimed_role = trim( $rsvp['role'] );
		$email = $rsvp['email'] ?? '';

		// 1. Fetch Official Community Roster from Master (Form 2)
		$roster = $wpdb->get_results( $wpdb->prepare( "
			SELECT 
				MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f,
				MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l,
				MAX(CASE WHEN meta_key = '2' THEN meta_value END) as r
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND entry_id IN (
				SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta WHERE meta_key = '3' AND meta_value = %s
			) GROUP BY entry_id
		", COBWRA_MASTER_FORM, $community ) );

		$name_match_entry = null; 
		$role_incumbents = [];

		foreach ( $roster as $rep ) {
			$rep_name = trim( ($rep->f ?? '') . ' ' . ($rep->l ?? '') );
			if ( ! empty( $rep->r ) ) {
				$role_incumbents[strtolower(trim($rep->r))] = $rep_name;
			}
			if ( strcasecmp( $rep_name, $full_name ) === 0 ) {
				$name_match_entry = $rep;
			}
		}

		// --- LOGIC SCENARIOS ---

		// 1. MATCHED (Green)
		if ( $name_match_entry && strcasecmp( $name_match_entry->r, $claimed_role ) === 0 ) {
			return [ 'status' => 'MATCHED', 'color' => '#27ae60', 'flag' => 0, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $email, 'note' => 'Official Representative.' ];
		}

		// 2. ROLE MISMATCH (Yellow) - Name is official, but role is wrong
		if ( $name_match_entry ) {
			return [ 'status' => 'ROLE MISMATCH', 'color' => '#f1c40f', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $email, 'note' => "Master list has them as: <strong>{$name_match_entry->r}</strong>." ];
		}

		// 3. CONFLICT (Orange) - Not in system + seat is already taken
		$role_key = strtolower($claimed_role);
		if ( isset($role_incumbents[$role_key]) && ! empty(trim($role_incumbents[$role_key])) ) {
			$official = $role_incumbents[$role_key];
			return [ 'status' => 'CONFLICT', 'color' => '#e67e22', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $email, 'note' => "Seat held by <strong>$official</strong>. Attendee is General Public." ];
		}

		// 4. VACANCY (Blue) - Not in system + seat is empty
		return [ 'status' => 'VACANCY', 'color' => '#3498db', 'flag' => 1, 'name' => $full_name, 'comm' => $community, 'role' => $claimed_role, 'email' => $email, 'note' => "Slot is vacant in Master. Potential new Representative." ];
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
}
