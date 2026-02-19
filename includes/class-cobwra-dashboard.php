<?php
/**
 * class-cobwra-dashboard.php
 * Frontend Monitoring Dashboard - Staging Table Version (v48.9)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Dashboard {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
        add_shortcode( 'cobwra_dashboard', array( $this, 'render_dashboard' ) );
    }

    public function render_dashboard() {
        global $wpdb;

        // 1. Quorum Logic: Strictly using the Active Manifest from the staging table
        $total_communities = $wpdb->get_var( "SELECT COUNT(DISTINCT community_name) FROM $this->table_name WHERE voting_authority = 1" );
        $present_communities = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT community_name) FROM $this->table_name WHERE checkin_status = %s AND voting_authority = 1", 
            'Checked In'
        ) );
        
        $quorum_target = ceil( $total_communities * 0.4 );
        $is_quorum_met = ( $present_communities >= $quorum_target );

        // 2. Attendance Stats for Header Bar
        $rep_count = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s AND voting_authority = 1", 'Checked In') );
        $guest_count = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s AND is_announced = 1", 'Checked In') );
        $public_count = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $this->table_name WHERE checkin_status = %s AND voting_authority = 0 AND is_announced = 0", 'Checked In') );
        $total_checked_in = (int)$rep_count + (int)$guest_count + (int)$public_count;

        // 3. Data Buckets for Lists
        $checked_in_comms = $wpdb->get_col( $wpdb->prepare("SELECT DISTINCT community_name FROM $this->table_name WHERE checkin_status = %s AND voting_authority = 1 ORDER BY community_name ASC", 'Checked In') );
        $missing_comms = $wpdb->get_col( $wpdb->prepare("SELECT DISTINCT community_name FROM $this->table_name WHERE voting_authority = 1 AND community_name NOT IN (SELECT DISTINCT community_name FROM $this->table_name WHERE checkin_status = %s AND voting_authority = 1) ORDER BY community_name ASC", 'Checked In') );
        $announced_guests = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $this->table_name WHERE checkin_status = %s AND is_announced = 1 ORDER BY last_name ASC", 'Checked In') );
        $action_items = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $this->table_name WHERE conflict_flag IS NOT NULL AND checkin_status = %s", 'Checked In') );

        ob_start(); ?>
        <div class="cobwra-dash" style="font-family:sans-serif; background:#f4f4f4; padding:20px; border-radius:10px;">
            
            <div class="hero" style="padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; background:<?php echo $is_quorum_met ? '#27ae60' : '#c0392b'; ?>;">
                <h1 style="margin:0; font-size:3.5em;"><?php echo (int)$present_communities; ?> / <?php echo (int)$quorum_target; ?> Communities</h1>
                <p style="margin:5px 0 0; font-size:1.3em; opacity:0.9;">Required for Quorum (Total Active Communities: <?php echo (int)$total_communities; ?>)</p>
            </div>

            <div class="stat-bar" style="display:flex; gap:15px; margin-bottom:25px; text-align:center;">
                <div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
                    <h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Reps</h4>
                    <strong style="font-size:1.8em;"><?php echo (int)$rep_count; ?></strong>
                </div>
                <div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
                    <h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Guests</h4>
                    <strong style="font-size:1.8em;"><?php echo (int)$guest_count; ?></strong>
                </div>
                <div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
                    <h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Public</h4>
                    <strong style="font-size:1.8em;"><?php echo (int)$public_count; ?></strong>
                </div>
                <div style="flex:1; background:#2c3e50; padding:15px; border-radius:8px; color:white;">
                    <h4 style="margin:0 0 5px; color:#bdc3c7; font-size:0.9em; text-transform:uppercase;">Total</h4>
                    <strong style="font-size:1.8em;"><?php echo (int)$total_checked_in; ?></strong>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:20px;">
                <div>
                    <h3 style="background:#27ae60; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Checked In</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:400px; overflow-y:auto; font-size:0.85em; line-height:1.6;">
                        <?php foreach( $checked_in_comms as $comm ) { echo "<div>" . esc_html($comm) . "</div>"; } ?>
                    </div>
                </div>

                <div>
                    <h3 style="background:#c0392b; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Missing</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:400px; overflow-y:auto; font-size:0.85em; line-height:1.6;">
                        <?php foreach( $missing_comms as $comm ) { echo "<div>" . esc_html($comm) . "</div>"; } ?>
                    </div>
                </div>

                <div>
                    <h3 style="background:#8e44ad; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Announced Guests</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:400px; overflow-y:auto; font-size:0.85em;">
                        <?php foreach( $announced_guests as $g ) { 
                            echo "<div style='margin-bottom:8px;'><strong>" . esc_html($g->first_name . ' ' . $g->last_name) . "</strong><br><small>" . esc_html($g->official_role) . "</small></div>"; 
                        } ?>
                    </div>
                </div>
            </div>

            <h3 style="background:#2c3e50; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:25px 0 0;">Action Item Log (Boarding Discrepancies)</h3>
            <div style="background:#fff; padding:20px; border:1px solid #ddd; border-top:none; border-radius: 0 0 8px 8px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="30%">Attendee</th>
                            <th width="30%">Community</th>
                            <th width="15%">Flag</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $action_items ) ) : ?>
                            <tr><td colspan='4' style='text-align:center;'>No discrepancies detected among boarded attendees.</td></tr>
                        <?php else : foreach ( $action_items as $item ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($item->first_name . ' ' . $item->last_name); ?></strong><br><small><?php echo esc_html($item->rsvp_role); ?></small></td>
                                <td><?php echo esc_html($item->community_name); ?></td>
                                <td><span style='background:#d35400; color:#fff; padding:4px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;'><?php echo esc_html($item->conflict_flag); ?></span></td>
                                <td><?php echo esc_html($item->official_role === 'None' ? 'Not in Master DB' : 'Role Discrepancy'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}
