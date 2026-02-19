<?php
/**
 * class-cobwra-dashboard.php
 * Frontend Monitoring Dashboard - Staging Table Version (v48.4)
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

        // 1. Quorum Logic: 40% of Total Communities in Master Database
        $total_communities = $wpdb->get_var( "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->prefix}gf_entry_meta WHERE form_id = 2 AND meta_key = '3'" );
        $present_communities = $wpdb->get_var( "SELECT COUNT(DISTINCT community_name) FROM $this->table_name WHERE checkin_status = 'Checked In' AND voting_authority = 1" );
        
        $quorum_target = ceil( $total_communities * 0.4 );
        $is_quorum_met = ( $present_communities >= $quorum_target );

        // 2. Fetch Data Buckets for Display
        $checked_in_officials = $wpdb->get_results( "SELECT * FROM $this->table_name WHERE checkin_status = 'Checked In' AND is_announced = 0 ORDER BY community_name ASC" );
        $announced_guests = $wpdb->get_results( "SELECT * FROM $this->table_name WHERE checkin_status = 'Checked In' AND is_announced = 1 ORDER BY last_name ASC" );
        $action_items = $wpdb->get_results( "SELECT * FROM $this->table_name WHERE conflict_flag IS NOT NULL AND checkin_status = 'Checked In'" );

        ob_start(); ?>
        <div class="cobwra-dash" style="font-family:sans-serif; background:#f4f4f4; padding:20px; border-radius:10px;">
            
            <div class="hero" style="padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; background:<?php echo $is_quorum_met ? '#27ae60' : '#c0392b'; ?>;">
                <h1 style="margin:0; font-size:3.5em;"><?php echo $present_communities; ?> / <?php echo (int) $total_communities; ?></h1>
                <p style="margin:5px 0 0; font-size:1.3em; opacity:0.9;">Communities Present (40% Quorum Req: <?php echo (int) $quorum_target; ?>)</p>
            </div>

            <div class="stat-bar" style="display:flex; gap:15px; margin-bottom:25px; text-align:center;">
                <div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
                    <h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Official Reps</h4>
                    <strong style="font-size:1.8em;"><?php echo count( $checked_in_officials ); ?></strong>
                </div>
                <div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
                    <h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Announced Guests</h4>
                    <strong style="font-size:1.8em;"><?php echo count( $announced_guests ); ?></strong>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div>
                    <h3 style="background:#27ae60; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Official Attendance (Voting)</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:300px; overflow-y:auto; font-size:0.9em;">
                        <?php foreach( $checked_in_officials as $o ) { 
                            echo "<div><strong>" . esc_html($o->community_name) . "</strong>: " . esc_html($o->first_name . ' ' . $o->last_name) . "</div>"; 
                        } ?>
                    </div>
                </div>

                <div>
                    <h3 style="background:#8e44ad; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Announced Guests Present</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:300px; overflow-y:auto; font-size:0.9em;">
                        <?php foreach( $announced_guests as $g ) { 
                            echo "<div><strong>" . esc_html($g->first_name . ' ' . $g->last_name) . "</strong> (" . esc_html($g->official_role) . ")</div>"; 
                        } ?>
                    </div>
                </div>
            </div>

            <h3 style="background:#2c3e50; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:25px 0 0;">Action Item Log (Discrepancies)</h3>
            <div style="background:#fff; padding:20px; border:1px solid #ddd; border-top:none; border-radius: 0 0 8px 8px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="30%">Attendee</th>
                            <th width="30%">Community</th>
                            <th width="15%">Status</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $action_items ) ) : ?>
                            <tr><td colspan='4' style='text-align:center;'>No discrepancies detected.</td></tr>
                        <?php else : foreach ( $action_items as $item ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($item->first_name . ' ' . $item->last_name); ?></strong><br><small><?php echo esc_html($item->rsvp_role); ?></small></td>
                                <td><?php echo esc_html($item->community_name); ?></td>
                                <td><span style='background:#d35400; color:#fff; padding:4px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;'><?php echo esc_html($item->conflict_flag); ?></span></td>
                                <td><?php echo esc_html($item->official_role === 'None' ? 'Not in Master Database' : 'Role Mismatch'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}
