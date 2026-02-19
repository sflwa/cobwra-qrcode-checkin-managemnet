<?php
/**
 * class-cobwra-dashboard.php
 * Frontend Monitoring Dashboard - Staging Table Version (v48.6)
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

        // 1. Total possible communities (The denominator for Quorum)
        // We pull this from Form 2 (Master DB) to know how many COULD attend.
        $total_communities = $wpdb->get_var( "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->prefix}gf_entry_meta WHERE form_id = 2 AND meta_key = '3'" );
        
        // 2. Communities ACTUALLY PRESENT (The numerator)
        // We only count communities where at least one Official Rep has 'Checked In'.
        $present_communities = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT community_name) FROM $this->table_name 
             WHERE checkin_status = %s AND voting_authority = 1", 
            'Checked In'
        ) );
        
        $quorum_target = ceil( $total_communities * 0.4 );
        $is_quorum_met = ( $present_communities >= $quorum_target );

        // 3. Data Buckets (Filtered by 'Checked In')
        $checked_in_officials = $wpdb->get_results( "SELECT * FROM $this->table_name WHERE checkin_status = 'Checked In' AND voting_authority = 1 ORDER BY community_name ASC" );
        $announced_guests = $wpdb->get_results( "SELECT * FROM $this->table_name WHERE checkin_status = 'Checked In' AND is_announced = 1 ORDER BY last_name ASC" );

        ob_start(); ?>
        <div class="cobwra-dash" style="font-family:sans-serif; background:#f4f4f4; padding:20px; border-radius:10px;">
            
            <div class="hero" style="padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; background:<?php echo $is_quorum_met ? '#27ae60' : '#c0392b'; ?>;">
                <h1 style="margin:0; font-size:4em;"><?php echo (int)$present_communities; ?> / <?php echo (int)$total_communities; ?></h1>
                <p style="margin:5px 0 0; font-size:1.4em; opacity:0.9;">Communities Present (40% Quorum Req: <?php echo (int)$quorum_target; ?>)</p>
                <div style="margin-top:10px; font-weight:bold; font-size:1.2em; text-transform:uppercase; letter-spacing:1px;">
                    <?php echo $is_quorum_met ? '✔ Quorum Established' : '✘ Quorum Not Met'; ?>
                </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
                <div>
                    <h3 style="background:#27ae60; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Voting Reps On-Site (<?php echo count($checked_in_officials); ?>)</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:400px; overflow-y:auto; font-size:0.95em;">
                        <?php if(empty($checked_in_officials)) echo "<p style='color:#999;'>Waiting for first official check-in...</p>"; ?>
                        <?php foreach( $checked_in_officials as $o ) : ?>
                            <div style="margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;">
                                <strong><?php echo esc_html($o->community_name); ?></strong><br>
                                <?php echo esc_html($o->first_name . ' ' . $o->last_name); ?> <span style="font-size:0.85em; color:#666;">(<?php echo esc_html($o->official_role); ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <h3 style="background:#8e44ad; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Announced Guests (<?php echo count($announced_guests); ?>)</h3>
                    <div style="background:#fff; padding:15px; border:1px solid #ddd; height:400px; overflow-y:auto; font-size:0.95em;">
                        <?php if(empty($announced_guests)) echo "<p style='color:#999;'>No guests checked in.</p>"; ?>
                        <?php foreach( $announced_guests as $g ) : ?>
                            <div style="margin-bottom:10px; border-bottom:1px solid #eee; padding-bottom:5px;">
                                <strong><?php echo esc_html($g->first_name . ' ' . $g->last_name); ?></strong><br>
                                <span style="font-size:0.85em; color:#666;"><?php echo esc_html($g->official_role); ?> (<?php echo esc_html($g->community_name); ?>)</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}
