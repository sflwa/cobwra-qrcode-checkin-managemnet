<?php
/**
 * COBWRA Engine - Professional Normalization (v30.0)
 * Fix: Added Strict Parameter Matching to prevent "Isola/Sol" false positives.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
	public $csv_opt   = 'cobwra_rsvp_lookup_data';
	public $alias_opt = 'cobwra_name_aliases';
	private $guest_master_form_id = 4;

	/**
	 * Main entry point. Accepts individual params for strict matching.
	 */
	public function analyze_scan( $id_or_comm, $last_name = '' ) {
		global $wpdb;
		
		$comm = sanitize_text_field( $id_or_comm );
		$last = sanitize_text_field( $last_name );

		// 1. STRICT MATCH (If both Community and Last Name are provided)
		if ( ! empty( $comm ) && ! empty( $last ) ) {
			$strict_master = $this->lookup_strict( COBWRA_MASTER_FORM, '3', $comm, $last );
			if ( $strict_master ) return $strict_master;

			$strict_guest = $this->lookup_strict( $this->guest_master_form_id, '6', $comm, $last );
			if ( $strict_guest ) return $strict_guest;
		}

		// 2. NUMERIC ID MATCH (Direct Badge/RSVP ID)
		if ( is_numeric( $comm ) ) {
			$master_id = $this->check_master_roster_by_id( $comm );
			if ( $master_id ) return $master_id;
			
			$guest_id = $this->check_announced_guests( $comm );
			if ( $guest_id ) return $guest_id;
		}

		// 3. FUZZY FALLBACK (Only if strict match fails)
		$input = trim( $comm . ' ' . $last );
		$fuzzy_master = $this->lookup_master_fuzzy( $input );
		if ( $fuzzy_master ) return $fuzzy_master;

		// 4. RSVP CACHE FALLBACK
		$csv_data = get_option( $this->csv_opt, array() );
		if ( isset( $csv_data[$comm] ) ) {
			return $this->process_discrepancy( $csv_data[$comm], $comm );
		}

		return array( 
			'status' => 'WALK-IN', 'color' => '#636e72', 'flag' => 2, 
			'name' => 'Unknown', 'comm' => 'N/A', 'role' => 'Guest', 
			'note' => 'No match found in Master, Guest, or RSVP lists.' 
		);
	}

	/**
	 * Strict AND matching for Form + Community/Org + Last Name
	 */
	private function lookup_strict( $form_id, $comm_key, $comm_val, $last_val ) {
		global $wpdb;
		
		$entry_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT m1.entry_id 
			FROM {$wpdb->prefix}gf_entry_meta m1
			INNER JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id
			WHERE m1.form_id = %d 
			AND (m1.meta_key = %s AND m1.meta_value = %s)
			AND (m2.meta_key = '1.6' AND m2.meta_value = %s)
			LIMIT 1",
			$form_id, $comm_key, $comm_val, $last_val
		) );

		if ( $entry_id ) {
			return ( $form_id == COBWRA_MASTER_FORM ) ? $this->check_master_roster_by_id( $entry_id ) : $this->check_announced_guests( $entry_id );
		}
		return false;
	}

	private function lookup_master_fuzzy( $input ) {
		global $wpdb;
		if ( empty( $input ) || strlen( $input ) < 4 ) return false;

		$parts = explode( ' ', $input );
		$conditions = array();
		foreach ( $parts as $part ) {
			if ( strlen( $part ) < 3 ) continue;
			$conditions[] = $wpdb->prepare( "meta_value LIKE %s", '%' . $wpdb->esc_like( $part ) . '%' );
		}
		if ( empty( $conditions ) ) return false;

		$entry_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta 
			WHERE form_id = %d AND (" . implode( ' OR ', $conditions ) . ") 
			AND meta_key IN ('1.3', '1.6', '3') 
			GROUP BY entry_id ORDER BY COUNT(entry_id) DESC LIMIT 1", 
			COBWRA_MASTER_FORM
		) );

		return $entry_id ? $this->check_master_roster_by_id( $entry_id ) : false;
	}

	public function check_master_roster_by_id( $id ) {
		global $wpdb;
		$rep = $wpdb->get_row( $wpdb->prepare( "
			SELECT 
				MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, 
				MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, 
				MAX(CASE WHEN meta_key = '3' THEN meta_value END) as comm,
				MAX(CASE WHEN meta_key = '2' THEN meta_value END) as role 
			FROM {$wpdb->prefix}gf_entry_meta 
			WHERE entry_id = %d GROUP BY entry_id", $id 
		) );
		if ( $rep && ! empty( $rep->f ) ) {
			return array( 'status' => 'MATCHED', 'color' => '#27ae60', 'flag' => 0, 'name' => trim( "$rep->f $rep->l" ), 'comm' => $rep->comm, 'role' => $rep->role, 'note' => 'Official Master Database Match.' );
		}
		return false;
	}

	public function check_announced_guests( $id ) {
		global $wpdb;
		$guest = $wpdb->get_row( $wpdb->prepare( "
			SELECT MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, MAX(CASE WHEN meta_key = '6' THEN meta_value END) as org, MAX(CASE WHEN meta_key = '5' THEN meta_value END) as title 
			FROM {$wpdb->prefix}gf_entry_meta WHERE entry_id = %d GROUP BY entry_id", $id 
		) );
		if ( $guest && ! empty( $guest->f ) ) {
			return array( 'status' => 'ANNOUNCED', 'color' => '#8e44ad', 'flag' => 1, 'name' => trim( "$guest->f $guest->l" ), 'comm' => $guest->org, 'role' => $guest->title, 'note' => 'Guest Master Match.' );
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

	private function process_discrepancy( $rsvp, $id ) {
		return array( 'status' => 'MATCHED', 'color' => '#27ae60', 'flag' => 0, 'name' => $rsvp['first'] . ' ' . $rsvp['last'], 'comm' => $rsvp['comm'], 'role' => $rsvp['role'], 'note' => 'Matched via RSVP fallback.' );
	}
}
