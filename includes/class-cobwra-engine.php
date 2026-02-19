<?php
/**
 * class-cobwra-engine.php
 * Unified Roster Engine - Live Validation (v49.2)
 * Fix: Synchronized matching logic for Announced Guests and RSVPs.
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

        // 1. First, always try to match by RSVP ID (The "Boarding Pass" key) [cite: 5, 10]
        if ( ! empty( $comm_or_id ) && is_numeric( $comm_or_id ) ) {
            $match = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM $this->table_name WHERE rsvp_id = %s", $comm_or_id 
            ) );
            
            if ( $match ) return $this->process_staged_match( $match );
        }

        // 2. Fallback: Match by Community/Org and Last Name (Manual entry or Badge)
        $match = $wpdb->get_row( $wpdb->prepare( 
            "SELECT * FROM $this->table_name WHERE LOWER(community_name) = LOWER(%s) AND LOWER(last_name) = LOWER(%s)", 
            $comm_or_id, $last_name 
        ) );

        if ( $match ) {
            return $this->process_staged_match( $match );
        }

        // 3. True Walk-in (Not found in Manifest via ID or Name)
        return array(
            'status' => 'WALK-IN',
            'color'  => '#95a5a6',
            'name'   => $last_name ?: 'Unknown',
            'comm'   => $comm_or_id ?: 'Guest',
            'role'   => 'Public',
            'note'   => 'No record found in Manifest. Please verify at Admin table.'
        );
    }

    /**
     * Determine Status and Update Boarding record 
     */
    private function process_staged_match( $m ) {
        global $wpdb;

        // Mark as 'Checked In' in the manifest table
        $wpdb->update( 
            $this->table_name, 
            array( 'checkin_status' => 'Checked In' ), 
            array( 'id' => $m->id ) 
        );

        $status = 'CHECKED IN';
        $color  = '#27ae60'; 

        // If it's an Announced Guest (from Form 4 or RSVP) 
        if ( (int)$m->is_announced === 1 || stripos($m->rsvp_role, 'Guest') !== false ) {
            $status = 'ANNOUNCED GUEST';
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
            'note'   => $m->conflict_flag ?: 'Boarding Successful.'
        );
    }

    /**
     * Logs the attendance to Form 10 (Check-in Log) [cite: 4]
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
