<?php
/**
 * COBWRA Dashboard - Visual Intelligence (v35.0)
 * Fix: Enforced strict Master/Guest buckets and restored Action Item Log.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Dashboard {
	/**
	 * @var COBWRA_Engine
	 */
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_shortcode( 'cobwra_dashboard', array( $this, 'render_dashboard' ) );
		// Sync logic removed temporarily per request; will integrate your snippet later.
	}

	public function render_dashboard() {
		global $wpdb;

		// 1. Quorum Configuration
		$total_active = intval( get_option( 'cobwra_active_comm_count', 0 ) );
		$quorum_target = ceil( $total_active * 0.4 );

		// 2. Fetch all raw scans from the temporary table
		$scans = $wpdb->get_results( $wpdb->prepare( 
			"SELECT m.meta_value as rsvp_id 
			 FROM {$wpdb->prefix}gf_entry_meta m 
			 JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
			 WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active' 
			 ORDER BY e.id DESC", 
			COBWRA_TEMP_FORM 
		) );

		// Data Buckets
		$stats = array( 'reps' => 0, 'guests' => 0, 'others' => 0 );
		$checked_in_comms = array(); 
		$announced_list   = array(); 
		$action_log       = array();

		// 3. Process Scans through the Engine
		if ( $scans ) {
			foreach ( $scans as $s ) {
				$res = $this->engine->analyze_scan( $s->rsvp_id );
				
				if ( 'MATCHED' === $res['status'] ) {
					$checked_in_comms[] = $res['comm'];
					$stats['reps']++;
				} elseif ( 'ANNOUNCED' === $res['status'] ) {
					$announced_list[] = $res;
					$stats['guests']++;
				} else {
					$stats['others']++;
					// Capture only Conflicts and Vacancies for the Action Log
					if ( in_array( $res['status'], array( 'CONFLICT', 'VACANCY' ) ) ) {
						$action_log[] = $res;
					}
				}
			}
		}
		
		// 4. Quorum Calculations
		$unique_comms = array_unique( $checked_in_comms );
		sort( $unique_comms );

		// Identify Missing Communities from Master
		$approved = $wpdb->get_col( $wpdb->prepare( 
			"SELECT DISTINCT m.meta_value 
			 FROM {$wpdb->prefix}gf_entry_meta m 
			 JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
			 JOIN {$wpdb->prefix}gf_entry_meta m_app ON m.entry_id = m_app.entry_id 
			 WHERE e.form_id = %d AND e.status = 'active' AND m.meta_key = '3' 
			 AND m_app.meta_key = 'is_approved' AND m_app.meta_value = '1'", 
			COBWRA_MASTER_FORM 
		) );
		$missing = array_diff( $approved, $unique_comms );
		sort( $missing );

		ob_start(); ?>
		<div class="cobwra-dash" style="font-family:sans-serif; background:#f4f4f4; padding:20px; border-radius:10px;">
			
			<div class="hero" style="padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; background:<?php echo ( count( $unique_comms ) >= $quorum_target && $quorum_target > 0 ) ? '#27ae60' : '#c0392b'; ?>;">
				<h1 style="margin:0; font-size:3.5em;"><?php echo count( $unique_comms ); ?> / <?php echo (int) $quorum_target; ?></h1>
				<p style="margin:5px 0 0; font-size:1.3em; opacity:0.9;">Official Quorum (40% of <?php echo (int) $total_active; ?> Communities)</p>
			</div>

			<div class="stat-bar" style="display:flex; gap:15px; margin-bottom:25px; text-align:center;">
				<div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
					<h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Rep Scans</h4>
					<strong style="font-size:1.8em;"><?php echo (int) $stats['reps']; ?></strong>
				</div>
				<div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
					<h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Announced Guests</h4>
					<strong style="font-size:1.8em;"><?php echo (int) $stats['guests']; ?></strong>
				</div>
				<div style="flex:1; background:#fff; padding:15px; border-radius:8px; border:1px solid #ddd;">
					<h4 style="margin:0 0 5px; color:#7f8c8d; font-size:0.9em; text-transform:uppercase;">Total Scans</h4>
					<strong style="font-size:1.8em;"><?php echo count( $scans ); ?></strong>
				</div>
			</div>

			<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
				<div>
					<h3 style="background:#27ae60; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Official Attendance (Voting)</h3>
					<div style="background:#fff; padding:15px; border:1px solid #ddd; height:300px; overflow-y:auto; font-size:0.9em;">
						<?php $i=1; foreach( $unique_comms as $c ) { echo "<div>{$i}. " . esc_html($c) . "</div>"; $i++; } ?>
					</div>
				</div>

				<div>
					<h3 style="background:#c0392b; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:0;">Missing Communities</h3>
					<div style="background:#fff; padding:15px; border:1px solid #ddd; height:300px; overflow-y:auto; font-size:0.9em;">
						<?php $i=1; foreach( $missing as $m ) { echo "<div>{$i}. " . esc_html($m) . "</div>"; $i++; } ?>
					</div>
				</div>
			</div>

			<h3 style="background:#f39c12; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:25px 0 0;">Announced Guests (Scanned In)</h3>
			<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; background:#fff; padding:15px; border:1px solid #ddd;">
				<?php 
				if ( empty( $announced_list ) ) echo "<div>No guests scanned.</div>";
				foreach ( $announced_list as $g ) { 
					echo "<div><strong>" . esc_html($g['name']) . "</strong><br><small style='color:#666;'>" . esc_html($g['comm']) . "</small></div>"; 
				} 
				?>
			</div>

			<h3 style="background:#2c3e50; color:#fff; padding:12px; border-radius:5px 5px 0 0; margin:25px 0 0;">Action Item Log (Conflicts & Vacancies)</h3>
			<div style="background:#fff; padding:20px; border:1px solid #ddd; border-top:none; border-radius: 0 0 8px 8px;">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th width="30%">Attendee</th>
							<th width="30%">Community</th>
							<th width="15%">Status</th>
							<th>Observation</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						if ( empty( $action_log ) ) {
							echo "<tr><td colspan='4' style='text-align:center;'>No discrepancies requiring action.</td></tr>";
						} else {
							foreach ( $action_log as $item ) {
								echo "<tr>
									<td><strong>" . esc_html($item['name']) . "</strong><br><small>" . esc_html($item['role']) . "</small></td>
									<td>" . esc_html($item['comm']) . "</td>
									<td><span style='background:{$item['color']}; color:#fff; padding:4px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;'>{$item['status']}</span></td>
									<td>" . esc_html($item['note']) . "</td>
								</tr>";
							}
						}
						?>
					</tbody>
				</table>
			</div>
		</div>
		<?php return ob_get_clean();
	}
}
