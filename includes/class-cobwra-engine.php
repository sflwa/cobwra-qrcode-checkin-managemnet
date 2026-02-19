<?php
/**
 * class-cobwra-engine.php
 * Unified Roster Engine - Live Validation (v48.6)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
    }

    public function analyze_scan( $comm_or_id, $last_name = '' ) {
        global $wpdb;

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

        if ( $match ) {
            // Update the 'Manifest' to show this person has 'Boarded'
            $wpdb->update( 
                $this->table_name, 
                array( 'checkin_status' => 'Checked In' ), 
                array( 'id' => $match->id ) 
            );
            return $this->process_staged_match( $match );
        }

        return array(
            'status' => 'WALK-IN',
            'color'  => '#95a5a6',
            'name'   => $last_name ?: 'Unknown',
            'comm'   => $comm_or_id,
            'role'   => 'Guest',
            'note'   => 'Not in Roster. Please Verify at Admin Table.'
        );
    }

    private function process_staged_match( $m ) {
        $status = 'CHECKED IN';
        $color  = '#27ae60'; 

        if ( (int)$m->is_announced === 1 ) {
            $status = 'GUEST CHECK-IN';
            $color  = '#8e44ad';
        } elseif ( ! empty( $m->conflict_flag ) ) {
            $status = strtoupper( $m->conflict_flag );
            $color  = '#d35400';
        }

        return array(
            'status' => $status,
            'color'  => $color,
            'name'   => "{$m->first_name} {$m->last_name}",
            'comm'   => $m->community_name,
            'role'   => ( $m->official_role !== 'None' ) ? $m->official_role : $m->rsvp_role,
            'note'   => $m->conflict_flag ?: 'Identity Verified.'
        );
    }

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
