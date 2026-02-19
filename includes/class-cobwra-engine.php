<?php
/**
 * class-cobwra-engine.php
 * Unified Roster Engine - Live Validation (v48.3)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
    }

    /**
     * Primary Check-in Validation Logic
     */
    public function analyze_scan( $comm_or_id, $last_name = '' ) {
        global $wpdb;

        // 1. Determine if we are searching by RSVP ID or Name/Community
        if ( is_numeric( $comm_or_id ) ) {
            $match = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM $this->table_name WHERE rsvp_id = %s", $comm_or_id 
            ) );
        } else {
            $match = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM $this->table_name WHERE community_name = %s AND last_name = %s", 
                $comm_or_id, $last_name 
            ) );
        }

        // 2. Evaluate the match found in the Roster
        if ( $match ) {
            return $this->process_roster_match( $match );
        }

        // 3. Fallback for Walk-ins
        return array(
            'status' => 'WALK-IN',
            'color'  => '#95a5a6',
            'name'   => $last_name ?: 'Unknown',
            'comm'   => $comm_or_id,
            'role'   => 'Guest',
            'note'   => 'No record found in Meeting Roster.'
        );
    }

    /**
     * Determine Status: Matched Rep, Guest, or Conflict
     */
    private function process_staged_match( $m ) {
        $status = 'MATCHED';
        $color  = '#27ae60'; 

        if ( (int)$m->is_announced === 1 ) {
            $status = 'ANNOUNCED GUEST';
            $color  = '#8e44ad';
        } elseif ( ! empty( $m->conflict_flag ) ) {
            // Vacancy/Conflict identified during the prep/import phase
            $status = strtoupper( $m->conflict_flag );
            $color  = ( $status === 'VACANCY' ) ? '#e67e22' : '#d35400';
        }

        return array(
            'status' => $status,
            'color'  => $color,
            'name'   => "{$m->first_name} {$m->last_name}",
            'comm'   => $m->community_name,
            'role'   => ( $m->official_role !== 'None' ) ? $m->official_role : $m->rsvp_role,
            'note'   => $m->conflict_flag ?: 'Record Verified.'
        );
    }

    /**
     * Logs the attendance to Form 10
     */
    public function log_scan( $res, $scan_id ) {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}gf_entry", array( 
            'form_id' => 10, 'date_created' => current_time('mysql'), 'status' => 'active' 
        ) );
        $eid = $wpdb->insert_id;
        
        $meta = array( 
            '1' => $res['comm'], 
            '3' => "{$res['name']} ({$res['role']})", 
            '4' => current_time('Y-m-d'), 
            '5' => $res['status'], 
            '6' => $scan_id 
        );
        
        foreach ( $meta as $k => $v ) {
            $wpdb->insert( "{$wpdb->prefix}gf_entry_meta", array( 
                'entry_id' => $eid, 'form_id' => 10, 'meta_key' => (string)$k, 'meta_value' => $v 
            ) );
        }
    }
}
