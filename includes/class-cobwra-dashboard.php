<?php
/**
 * COBWRA Dashboard Class
 * Handles the meeting report, Form 6 synchronization, and CSV discrepancy export.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Dashboard {

	private $engine;

	/**
	 * Constructor
	 * @param COBWRA_Engine $engine The logic engine instance.
	 */
	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_init', array( $this, 'handle_dashboard_actions' ) );
		add_shortcode( 'cobwra_dashboard', array( $this, 'render_dashboard' ) );
	}

	/**
	 * Handles Export and Sync button actions
	 */
	public function handle_dashboard_actions() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// 1. Handle CSV Discrepancy Export
		if ( isset( $_POST['cobwra_export_discrepancies'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->export_discrepancy_csv();
		}

		// 2. Handle Form 6 Sync
		if ( isset( $_POST['cobwra_sync_final'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->sync_to_form_6();
		}
	}

	/**
	 * Render the Attendance & Discrepancy Dashboard
	 */
	public function render_dashboard() {
		global $wpdb;
		
		// Fetch all current scans in Form 10
		$scans = $wpdb->get_results( $wpdb->prepare( "
			SELECT m.meta_value as rsvp_id, e.id as entry_id 
			FROM {$wpdb->prefix}gf_entry_meta m
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id
			WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active'
			ORDER BY e.id DESC
		", COBWRA_TEMP_FORM ) );

		ob_start(); ?>
		<div class="cobwra-dashboard-wrap" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
			
			<div style="background:#2c3e50; color:white; padding:25px; border-radius:12px; margin-bottom:25px; display:flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
				<div style="margin:0;">
					<h2 style="color:white; margin:0; font-size:1.8em;">Meeting Attendance & Discrepancy Log</h2>
					<p style="margin:5px 0 0; opacity:0.8;">Total Scans in Current Session: <strong><?php echo count($scans); ?></strong></p>
				</div>
				<form method="POST">
					<?php wp_nonce_field( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' ); ?>
					<input type="submit" name="cobwra_export_discrepancies" value="Download Follow-up CSV" class="button button-secondary button-large" style="margin-right:10px;">
					<input type="submit" name="cobwra_sync_final" value="Sync Unique Reps to Form 6" class="button button-primary button-large">
				</form>
			</div>

			<table class="wp-list-table widefat fixed striped" style="border-radius:8px; overflow:hidden; border:1px solid #ccd0d4;">
				<thead>
					<tr>
						<th style="padding:15px; font-weight:bold;">Attendee (Scanned)</th>
						<th style="padding:15px; font-weight:bold;">Verification Status</th>
						<th style="padding:15px; font-weight:bold;">Master DB Observation</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty($scans) ) : ?>
						<tr><td colspan="3" style="padding:30px; text-align:center; color:#666;">No scans found in the temporary database. Ready for a new meeting.</td></tr>
					<?php else : ?>
						<?php foreach ( $scans as $s ) : 
							$res = $this->engine->analyze_scan( $s->rsvp_id ); 
						?>
							<tr>
								<td style="padding:12px 15px;">
									<strong style="font-size:1.1em;"><?php echo esc_html($res['name']); ?></strong><br>
									<span style="color:#555;"><?php echo esc_html($res['comm']); ?></span> 
									<small style="color:#888;">(<?php echo esc_html($res['role']); ?>)</small>
								</td>
								<td style="padding:12px 15px; vertical-align: middle;">
									<span style="background:<?php echo $res['color']; ?>; color:white; padding:6px 12px; border-radius:50px; font-weight:bold; font-size:0.85em; display:inline-block; text-transform:uppercase;">
										<?php echo $res['status']; ?>
									</span>
								</td>
								<td style="padding:12px 15px; vertical-align: middle; color:#2c3e50; font-size:0.95em;">
									<?php echo $res['note']; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generates a CSV of all non-matched scans for follow-up
	 */
	private function export_discrepancy_csv() {
		global $wpdb;
		
		$scans = $wpdb->get_results( $wpdb->prepare( "
			SELECT m.meta_value as rsvp_id FROM {$wpdb->prefix}gf_entry_meta m
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id
			WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active'
		", COBWRA_TEMP_FORM ) );

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=cobwra-meeting-discrepancies-'.date('Y-m-d').'.csv');
		
		$output = fopen('php://output', 'w');
		fputcsv($output, array('Status', 'Scanned Name', 'Community', 'Email', 'RSVP Role', 'Master Observation'));

		foreach ( $scans as $s ) {
			$res = $this->engine->analyze_scan( $s->rsvp_id );
			if ( $res['status'] !== 'MATCHED' ) {
				fputcsv($output, array(
					$res['status'],
					$res['name'],
					$res['comm'],
					$res['email'],
					$res['role'],
					strip_tags($res['note'])
				));
			}
		}
		fclose($output);
		exit;
	}

	/**
	 * Syncs unique Official Reps (Flag 0) to Form 6
	 */
	private function sync_to_form_6() {
		global $wpdb;
		
		// Get unique official reps from current Form 10 data
		$officials = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT m1.meta_value 
			FROM {$wpdb->prefix}gf_entry_meta m1
			JOIN {$wpdb->prefix}gf_entry e ON m1.entry_id = e.id
			JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id
			WHERE e.form_id = %d AND e.status = 'active'
			AND m1.meta_key = '1' AND m2.meta_key = '5' AND m2.meta_value = '0'
		", COBWRA_TEMP_FORM ) );

		if ( empty($officials) ) return;

		foreach ( $officials as $comm ) {
			// Check for duplicates in Form 6 for today
			$today = current_time('Y-m-d') . '%';
			$exists = $wpdb->get_var( $wpdb->prepare( "
				SELECT e.id FROM {$wpdb->prefix}gf_entry e 
				JOIN {$wpdb->prefix}gf_entry_meta m ON e.id = m.entry_id 
				WHERE e.form_id = %d AND e.date_created LIKE %s AND m.meta_key = '1' AND m.meta_value = %s
			", COBWRA_FINAL_FORM, $today, $comm ) );

			if ( ! $exists ) {
				$wpdb->insert( "{$wpdb->prefix}gf_entry", array(
					'form_id' => COBWRA_FINAL_FORM, 
					'date_created' => current_time('mysql'), 
					'status' => 'active'
				));
				$new_id = $wpdb->insert_id;
				$wpdb->insert( "{$wpdb->prefix}gf_entry_meta", array(
					'entry_id' => $new_id, 
					'form_id' => COBWRA_FINAL_FORM, 
					'meta_key' => '1', 
					'meta_value' => $comm
				));
			}
		}
	}
}
