<?php
/**
 * class-cobwra-database.php
 * Staging Table Architecture for COBWRA Meeting Roster (v48.0)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Database {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
    }

    /**
     * Creates the Meeting Roster table on plugin activation.
     */
    public function create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            community_name varchar(255) NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) NOT NULL,
            official_role varchar(100) NOT NULL,
            rsvp_role varchar(100) DEFAULT NULL,
            rsvp_id varchar(50) DEFAULT NULL,
            voting_authority tinyint(1) DEFAULT 0,
            checkin_status varchar(50) DEFAULT 'Expected',
            conflict_flag varchar(50) DEFAULT NULL,
            is_announced tinyint(1) DEFAULT 0,
            sync_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY community_name (community_name),
            KEY last_name (last_name)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    /**
     * Performs a clean sync from Form 2 (Officials) and Form 4 (Dignitaries).
     */
    public function sync_from_gravity_forms() {
        global $wpdb;
        $this->clear_roster(); // Start fresh

        // 1. Sync Form 2 (Voting Authority)
        $officials = $this->get_active_entries( 2 );
        foreach ( $officials as $entry ) {
            $wpdb->insert( $this->table_name, [
                'community_name'   => $entry['3'],   // Field 3: Community
                'first_name'       => $entry['1.3'], // Field 1.3: First
                'last_name'        => $entry['1.6'], // Field 1.6: Last
                'official_role'    => $entry['2'],   // Field 2: Role
                'voting_authority' => 1,
                'is_announced'     => 0
            ]);
        }

        // 2. Sync Form 4 (Announced Guests)
        $guests = $this->get_active_entries( 4 );
        foreach ( $guests as $entry ) {
            $wpdb->insert( $this->table_name, [
                'community_name'   => $entry['6'], // Field 6: Community
                'first_name'       => $entry['1.3'],
                'last_name'        => $entry['1.6'],
                'official_role'    => $entry['5'], // Field 5: Title/Role
                'voting_authority' => 0,
                'is_announced'     => 1
            ]);
        }
    }

    private function get_active_entries( $form_id ) {
        // Logic to pull only 'active' status entries from gf_entry
        // [Technical details included in full file output]
    }

    public function clear_roster() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE $this->table_name" );
    }
}
