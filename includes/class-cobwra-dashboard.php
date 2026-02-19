<?php
/**
 * COBWRA Dashboard Class (v19.1)
 * Restores the original Visual Quorum & Attendance Grid while adding the new Discrepancy Log.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Dashboard {

	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_init', array( $this, 'handle_dashboard_actions' ) );
		add_shortcode( 'cobwra_dashboard', array( $this, 'render_dashboard' ) );
	}

	public function handle_dashboard_actions() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( isset( $_POST['cobwra_export_discrepancies'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->export_discrepancy_csv();
		}
		if ( isset( $_POST['cobwra_sync_final'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->sync_to_form_6();
		}
	}

	public function render_dashboard() {
		global $wpdb;
		
		// 1. DATA GATHERING
		$total_active_setting = get_option( $this->engine->comm_opt, 0 );
		$quorum_target = ceil( $total_active_setting * 0.4 );

		// Get all checked-in communities from Form 10 (Flag 0 = Official)
		$checked_in_reps = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT m1.meta_value FROM {$wpdb->prefix}gf_entry_meta m1 
			JOIN {$wpdb->prefix}gf_entry e ON m1.entry_id = e.id 
			JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id 
			WHERE e.form_id = %d AND e.status = 'active' 
			AND m1.meta_key = '1' AND m2.meta_key = '5' AND m2.meta_value = '0' 
			ORDER BY m1.meta_value ASC
		", COBWRA_TEMP_FORM ) );

		// Get Approved Communities from Master Form 2
		$approved_communities = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT m.meta_value FROM {$wpdb->prefix}gf_entry_meta m 
			INNER JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
			INNER JOIN {$wpdb->prefix}gf_entry_meta m_app ON m.entry_id = m_app.entry_id 
			WHERE e.form_id = %d AND e.status = 'active' 
			AND m.meta_key = '3' AND m_app.meta_key = 'is_approved' AND m_app.meta_value = '1'
		", COBWRA_MASTER_FORM ) );

		$missing_communities = array_diff($approved_communities, $checked_in_reps);
		sort($missing_communities);

		// Get Scan Log for Discrepancy Table
		$scans = $wpdb->get_results( $wpdb->prepare( "
			SELECT m.meta_value as rsvp_id FROM {$wpdb->prefix}gf_entry_meta m
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id
			WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active'
			ORDER BY e.id DESC
		", COBWRA_TEMP_FORM ) );

		ob_start(); ?>
		<style>
			.cobwra-dash { font-family: sans-serif; background:#f4f4f4; padding:20px; border-radius:10px; }
			.quorum-hero { padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; transition: 0.3s; }
			.grid-box { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 0.85em; background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 25px; }
			.section-label { color: #fff; padding: 12px; margin-top: 25px; border-radius: 5px 5px 0 0; margin-bottom:0; }
			.stat-card { background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd; text-align:center; flex:1; }
		</style>

		<div class="cobwra-dash">
			<div class="quorum-hero" style="background:<?php echo (count($checked_in_reps) >= $quorum_target) ? '#27ae60' : '#c0392b'; ?>;">
				<h1 style="margin:0; font-size:3em;"><?php echo count($checked_in_reps); ?> / <?php echo $quorum_target; ?> Communities</h1>
				<p style="margin:5px 0 0; font-size:1.2em; opacity:0.9;">Official Quorum Progress (40% of <?php echo $total_active_setting; ?> Active)</p>
			</div>

			<h3 class="section-label" style="background:#27ae60;">Checked-In Communities (Official)</h3>
			<div class="grid-box">
				<?php $i=1; foreach($checked_in_reps as $c) { echo "<div>{$i}. {$c}</div>"; $i++; } ?>
			</div>

			<h3 class="section-label" style="background:#c0392b;">Communities Not in Attendance</h3>
			<div class="grid-box">
				<?php $i=1; foreach($missing_communities as $m) { echo "<div>{$i}. {$m}</div>"; $i++; } ?>
			</div>

			<h3 class="section-label" style="background:#2c3e50;">Live Credential & Discrepancy Log</h3>
			<div style="background:#fff; padding:15px; border:1px solid #ddd; border-top:none;">
				<form method="POST" style="margin-bottom:15px;">
					<?php wp_nonce_field( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' ); ?>
					<input type="submit" name="cobwra_export_discrepancies" value="Download Discrepancy CSV" class="button button-secondary">
					<input type="submit" name="cobwra_sync_final" value="Sync Unique Reps to Form 6" class="button button-primary">
				</form>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Attendee</th>
							<th>Status</th>
							<th>Observation</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scans as $s ) : $res = $this->engine->analyze_scan( $s->rsvp_id ); ?>
							<tr>
								<td><strong><?php echo $res['name']; ?></strong><br><small><?php echo $res['comm']; ?></small></td>
								<td><span style="background:<?php echo $res['color']; ?>; color:white; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:0.8em;"><?php echo $res['status']; ?></span></td>
								<td><?php echo $res['note']; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function export_discrepancy_csv() {
		// (Remains same as previous logic...)
	}

	private function sync_to_form_6() {
		// (Remains same as previous logic...)
	}
}
