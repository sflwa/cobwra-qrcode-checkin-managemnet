/**
 * COBWRA Engine - Professional Normalization (v25.0)
 * Fix: Added Name-Based Lookup for Master List QR Codes.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Engine {
    public $csv_opt   = 'cobwra_rsvp_lookup_data';
    private $guest_master_form_id = 4;

    public function analyze_scan( $rsvp_id ) {
        global $wpdb;
        $id = sanitize_text_field( str_replace( ['&amp;', 'amp;'], '', $rsvp_id ) );

        // 1. If ID is numeric, proceed with Entry ID lookups
        if ( is_numeric( $id ) ) {
            $announced = $this->check_announced_guests( $id );
            if ( $announced ) return $announced;

            $master_rep = $this->check_master_roster_by_id( $id );
            if ( $master_rep ) return $master_rep;
        } 
        
        // 2. If ID is NOT numeric (or ID lookup failed), try Name/Community match
        // This handles QR codes like ?c=Banyan%20Springs&l=Turner
        $master_name_match = $this->check_master_roster_by_name( $id );
        if ( $master_name_match ) return $master_name_match;

        // 3. Fallback to RSVP Cache
        $csv_data = get_option( $this->csv_opt, [] );
        if ( isset( $csv_data[$id] ) ) {
            return [ 
                'status' => 'MATCHED', 
                'color'  => '#27ae60', 
                'flag'   => 0, 
                'name'   => $csv_data[$id]['first'] . ' ' . $csv_data[$id]['last'], 
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

    /**
     * Lookup by Name/Community for Master List QR codes that don't use Entry IDs
     */
    private function check_master_roster_by_name( $name_string ) {
        global $wpdb;
        
        // If the QR code format is "Community|LastName" or similar, we parse it.
        // Otherwise, we search the meta table for the string provided.
        $search_val = '%' . $wpdb->esc_like( $name_string ) . '%';

        $rep = $wpdb->get_row( $wpdb->prepare( "
            SELECT 
                MAX(CASE WHEN meta_key = '1.3' THEN meta_value END) as f, 
                MAX(CASE WHEN meta_key = '1.6' THEN meta_value END) as l, 
                MAX(CASE WHEN meta_key = '3' THEN meta_value END) as comm,
                MAX(CASE WHEN meta_key = '2' THEN meta_value END) as role 
            FROM {$wpdb->prefix}gf_entry_meta 
            WHERE form_id = %d AND entry_id IN (
                SELECT entry_id FROM {$wpdb->prefix}gf_entry_meta WHERE meta_value LIKE %s
            )
            GROUP BY entry_id LIMIT 1", 
            COBWRA_MASTER_FORM, 
            $search_val 
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

    // ... Rest of the helper methods (check_announced_guests, log_scan, etc.) remain the same ...
}
