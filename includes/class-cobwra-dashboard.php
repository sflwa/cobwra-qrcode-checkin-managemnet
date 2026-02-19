<?php
/**
 * COBWRA Dashboard - Visual Intelligence & Priority Reporting (v21.0)
 * * Features:
 * - Real-time Quorum calculation with "Communities" labeling
 * - Visual Grids for Official Attendance vs. Missing Communities
 * - Stats Summary Bar
 * - Priority-Sorted Verification Log (Conflicts > Vacancies > Mismatches > Announced)
 * - CSV Export for follow-up
 * - Sync functionality to Final Form 6
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Dashboard {

	/**
	 * @var COBWRA_Engine
	 */
	private $engine;

	/**
	 * Constructor
	 * @param COBWRA_Engine $engine The logic engine instance.
	 */
	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_shortcode( 'cobwra_dashboard', array( $this, 'render_dashboard' ) );
	}

	/**
	 * Handles Export and Sync button actions
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		// 1. Handle CSV Discrepancy Export
		if ( isset( $_POST['cobwra_export'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->export_csv();
		}

		// 2. Handle Form 6 Sync
		if ( isset( $_POST['cobwra_sync'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->sync_form_6();
		}
	}

	/**
	 * Render the Full Visual Dashboard
	 */
	public function render_dashboard() {
		global $wpdb;

		// 1. GATHER SETTINGS & QUORUM TARGETS
		$total_active = intval( get_option( 'cobwra_active_comm_count', 0 ) );
		$quorum_target = ceil( $total_active * 0.4 );

		// 2. FETCH ALL SCANS FROM TEMP FORM 10
		$scans = $wpdb->get_results( $wpdb->prepare( "
			SELECT m.meta_value as rsvp_id 
			FROM {$wpdb->prefix}gf_entry_meta m
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id
			WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active'
			ORDER BY e.id DESC
		", COBWRA_TEMP_FORM ) );

		// 3. PRE-PROCESS DATA GROUPS
		$groups = [ 'CONFLICT' => [], 'VACANCY' => [], 'ROLE MISMATCH' => [], 'ANNOUNCED' => [] ];
		$checked_in_reps = []; 
		$public_count = 0; 
		$announced_count = 0;

		foreach ( $scans as $s ) {
			$res = $this->engine->analyze_scan( $s->rsvp_id );
			
			// If Flag 0 (Matched or Role Mismatch), they count for Quorum
			if ( $res['flag'] == 0 ) {
				$checked_in_reps[] = $res['comm'];
			}

			// Logic for the Priority Log Table
			if ( $res['status'] === 'MATCHED' ) continue; // Hide perfect matches

			if ( $res['status'] === 'GUEST/PUBLIC' || $res['status'] === 'WALK-IN' ) {
				$public_count++;
				continue;
			}

			if ( $res['status'] === 'ANNOUNCED' ) {
				$announced_count++;
			}

			// Group by Status for Priority Display
			$groups[ $res['status'] ][] = $res;
		}
		
		$checked_in_reps = array_unique( $checked_in_reps );
		sort( $checked_in_reps );

		// 4. FETCH APPROVED COMMUNITIES NOT YET CHECKED IN
		$approved = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT m.meta_value FROM {$wpdb->prefix}gf_entry_meta m 
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
			JOIN {$wpdb->prefix}gf_entry_meta m_app ON m.entry_id = m_app.entry_id 
			WHERE e.form_id = %d AND e.status = 'active' 
			AND m.meta_key = '3' AND m_app.meta_key = 'is_approved' AND m_app.meta_value = '1'
		", COBWRA_MASTER_FORM ) );

		$missing = array_diff( $approved, $checked_in_reps );
		sort( $missing );

		ob_start(); ?>
		<style>
			.cobwra-dash { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background:#f4f4f4; padding:20px; border-radius:10px; }
			.hero { padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
			.stat-bar { display: flex; gap: 15px; margin-bottom: 25px; }
			.stat-item { flex: 1; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #ddd; text-align: center; }
			.stat-item h4 { margin: 0 0 5px; color: #666; text-transform: uppercase; font-size: 0.8em; }
			.stat-item strong { font-size: 1.8em; color: #2c3e50; }
			.grid-box { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 0.85em; background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05); }
			.section-label { color: #fff; padding: 12px; border-radius: 5px 5px 0 0; margin: 25px 0 0; font-weight: bold; font-size: 1.1em; }
			.status-pill { padding:4px 8px; border-radius:4px; color:white; font-weight:bold; font-size:0.8em; text-transform: uppercase; }
		</style>

		<div class="cobwra-dash">
			<div class="hero" style="background:<?php echo (count($checked_in_reps) >= $quorum_target && $quorum_target > 0) ? '#27ae60' : '#c0392b'; ?>;">
				<h1 style="margin:0; font-size:3.5em;"><?php echo count($checked_in_reps); ?> / <?php echo $quorum_target; ?> Communities</h1>
				<p style="margin:5px 0 0; font-size:1.3em; opacity:0.9;">Official Quorum (40% of <?php echo $total_active; ?> Approved Communities)</p>
			</div>

			<div class="stat-bar">
				<div class="stat-item"><h4>Official Reps</h4><strong><?php echo count($checked_in_reps); ?></strong></div>
				<div class="stat-item"><h4>Announced Guests</h4><strong><?php echo (int)$announced_count; ?></strong></div>
				<div class="stat-item"><h4>General Public</h4><strong><?php echo (int)$public_count; ?></strong></div>
				<div class="stat-item"><h4>Total Scanned</h4><strong><?php echo count($scans); ?></strong></div>
			</div>

			<h3 class="section-label" style="background:#27ae60;">Official Attendance</h3>
			<div class="grid-box">
				<?php 
				if(empty($checked_in_reps)) { echo "<div style='grid-column: span 4; text-align:center; padding:10px;'>No official reps checked in yet.</div>"; }
				$i=1; foreach($checked_in_reps as $c) { echo "<div>{$i}. {$c}</div>"; $i++; } 
				?>
			</div>

			<h3 class="section-label" style="background:#c0392b;">Communities Not in Attendance</h3>
			<div class="grid-box">
				<?php 
				if(empty($missing)) { echo "<div style='grid-column: span 4; text-align:center; padding:10px;'>All approved communities are present!</div>"; }
				$i=1; foreach($missing as $m) { echo "<div>{$i}. {$m}</div>"; $i++; } 
				?>
			</div>

			<h3 class="section-label" style="background:#2c3e50;">Credential Verification Log (Action Items)</h3>
			<div style="background:#fff; padding:20px; border:1px solid #ddd; border-top:none; border-radius: 0 0 8px 8px;">
				<form method="POST" style="margin-bottom:20px; text-align:right;">
					<?php wp_nonce_field( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' ); ?>
					<input type="submit" name="cobwra_export" value="Download Discrepancy CSV" class="button button-secondary">
					<input type="submit" name="cobwra_sync" value="Sync Official Reps to Form 6" class="button button-primary">
				</form>
				
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width:30%;">Attendee</th>
							<th style="width:20%;">Status</th>
							<th>Observation / Note</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$has_action_items = false;
						// Display Priority Order: Conflict -> Vacancy -> Role Mismatch -> Announced
						foreach ( ['CONFLICT', 'VACANCY', 'ROLE MISMATCH', 'ANNOUNCED'] as $key ) {
							if ( ! empty( $groups[ $key ] ) ) {
								$has_action_items = true;
								foreach ( $groups[ $key ] as $res ) {
									echo "<tr>
											<td><strong>" . esc_html($res['name']) . "</strong><br><small>" . esc_html($res['comm']) . "</small></td>
											<td><span class='status-pill' style='background:" . esc_attr($res['color']) . ";'>" . esc_html($res['status']) . "</span></td>
											<td>" . $res['note'] . "</td>
										  </tr>";
								}
							}
						}

						if ( ! $has_action_items ) {
							echo "<tr><td colspan='3' style='text-align:center; padding:20px;'>No discrepancies detected. All checked-in representatives match Master Records.</td></tr>";
						}
						?>
						<tr style="background:#f9f9f9;">
							<td colspan="3" style="text-align:center; color:#888; padding:10px;">
								Note: Matches and <?php echo $public_count; ?> General Public scans are hidden to condense view.
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Generates a CSV of all non-matched scans for board follow-up
	 */
	private function export_csv() {
		global $wpdb;
		$scans = $wpdb->get_results( $wpdb->prepare( "
			SELECT m.meta_value as rsvp_id FROM {$wpdb->prefix}gf_entry_meta m
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id
			WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active'
		", COBWRA_TEMP_FORM ) );

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=cobwra-discrepancy-report-'.date('Y-m-d').'.csv');
		
		$output = fopen('php://output', 'w');
		fputcsv($output, array('Status', 'Name', 'Community', 'Email', 'RSVP Role', 'Observation'));

		foreach ( $scans as $s ) {
			$res = $this->engine->analyze_scan( $s->rsvp_id );
			// Skip perfect matches and general public/walk-ins from the report
			if ( ! in_array( $res['status'], ['MATCHED', 'GUEST/PUBLIC', 'WALK-IN'] ) ) {
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
	private function sync_form_6() {
		global $wpdb;
		
		$officials = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT m1.meta_value 
			FROM {$wpdb->prefix}gf_entry_meta m1
			JOIN {$wpdb->prefix}gf_entry e ON m1.entry_id = e.id
			JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id
			WHERE e.form_id = %d AND e.status = 'active'
			AND m1.meta_key = '1' AND m2.meta_key = '5' AND m2.meta_value = '0'
		", COBWRA_TEMP_FORM ) );

		if ( empty($officials) ) return;

		$today = current_time('Y-m-d') . '%';
		foreach ( $officials as $comm ) {
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
