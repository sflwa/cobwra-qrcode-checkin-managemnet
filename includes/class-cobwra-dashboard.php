<?php
/**
 * COBWRA Dashboard - Visual Intelligence (v24.0)
 * Update: Added Announced Guest (Form 4) Tracking & Dedicated UI Section.
 * * @package COBWRA_Admin
 * @version 24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the constant for the Announced Guest form is defined.
 * If not defined in the core file, we default to 4 as specified.
 */
if ( ! defined( 'COBWRA_ANNOUNCED_FORM' ) ) {
	define( 'COBWRA_ANNOUNCED_FORM', 4 );
}

class COBWRA_Dashboard {
	/**
	 * Reference to the Analysis Engine.
	 *
	 * @var object
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param object $engine The analysis engine instance.
	 */
	public function __construct( $engine ) {
		$this->engine = $engine;
		// Use priority 1 to catch the export before any HTML is rendered.
		add_action( 'admin_init', array( $this, 'handle_actions' ), 1 );
		add_shortcode( 'cobwra_dashboard', array( $this, 'render_dashboard' ) );
	}

	/**
	 * Route administrative actions.
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 1. Handle CSV Export (CRITICAL: Must happen before any output).
		if ( isset( $_POST['cobwra_export'] ) ) {
			if ( ! isset( $_POST['cobwra_dashboard_nonce'] ) || ! wp_verify_nonce( $_POST['cobwra_dashboard_nonce'], 'cobwra_dashboard_action' ) ) {
				return;
			}
			$this->export_csv();
		}

		// 2. Handle Sync.
		if ( isset( $_POST['cobwra_sync'] ) ) {
			check_admin_referer( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' );
			$this->sync_form_6();
		}
	}

	/**
	 * Main Dashboard Renderer.
	 *
	 * @return string HTML Content.
	 */
	public function render_dashboard() {
		global $wpdb;

		// 1. Setup Totals and Quorum.
		$total_active  = intval( get_option( 'cobwra_active_comm_count', 0 ) );
		$quorum_target = ceil( $total_active * 0.4 );

		// 2. Fetch standard scans (Form 6).
		$scans = $wpdb->get_results( 
			$wpdb->prepare( 
				"SELECT m.meta_value as rsvp_id 
				 FROM {$wpdb->prefix}gf_entry_meta m 
				 JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
				 WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active' 
				 ORDER BY e.id DESC", 
				COBWRA_TEMP_FORM 
			) 
		);

		// 3. Fetch Announced Guest scans (Form 4).
		$announced_scans = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.meta_value as rsvp_id 
				 FROM {$wpdb->prefix}gf_entry_meta m 
				 JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
				 WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active' 
				 ORDER BY e.id DESC",
				COBWRA_ANNOUNCED_FORM
			)
		);

		// Initialize buckets.
		$groups           = array( 'CONFLICT' => array(), 'VACANCY' => array(), 'ROLE MISMATCH' => array(), 'ANNOUNCED' => array() );
		$checked_in_reps  = array();
		$announced_guests = array();
		$public_count     = 0;
		$announced_count  = 0;

		// Process Standard Scans.
		foreach ( $scans as $s ) {
			$res = $this->engine->analyze_scan( $s->rsvp_id );
			if ( 0 === $res['flag'] ) {
				$checked_in_reps[] = $res['comm'];
			}
			if ( 'MATCHED' === $res['status'] ) {
				continue;
			}
			if ( 'GUEST/PUBLIC' === $res['status'] || 'WALK-IN' === $res['status'] ) {
				$public_count++;
				continue;
			}
			if ( 'ANNOUNCED' === $res['status'] ) {
				$announced_count++;
			}
			$groups[ $res['status'] ][] = $res;
		}

		// Process Explicit Announced Guests (Form 4).
		foreach ( $announced_scans as $as ) {
			$res = $this->engine->analyze_scan( $as->rsvp_id );
			$announced_guests[] = $res['name'] . ' (' . $res['comm'] . ')';
			$announced_count++;
		}

		$checked_in_reps = array_unique( $checked_in_reps );
		sort( $checked_in_reps );

		// Identify missing communities.
		$approved = $wpdb->get_col( 
			$wpdb->prepare( 
				"SELECT DISTINCT m.meta_value 
				 FROM {$wpdb->prefix}gf_entry_meta m 
				 JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id 
				 JOIN {$wpdb->prefix}gf_entry_meta m_app ON m.entry_id = m_app.entry_id 
				 WHERE e.form_id = %d AND e.status = 'active' AND m.meta_key = '3' 
				 AND m_app.meta_key = 'is_approved' AND m_app.meta_value = '1'", 
				COBWRA_MASTER_FORM 
			) 
		);
		$missing = array_diff( $approved, $checked_in_reps );
		sort( $missing );

		ob_start();
		?>
		<style>
			.cobwra-dash { font-family: sans-serif; background:#f4f4f4; padding:20px; border-radius:10px; }
			.hero { padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; }
			.stat-bar { display: flex; gap: 15px; margin-bottom: 25px; }
			.stat-item { flex: 1; background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #ddd; text-align: center; }
			.grid-box { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 0.85em; background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 25px; }
			.section-label { color: #fff; padding: 12px; border-radius: 5px 5px 0 0; margin: 25px 0 0; font-weight: bold; }
		</style>

		<div class="cobwra-dash">
			<div class="hero" style="background:<?php echo ( count( $checked_in_reps ) >= $quorum_target && $quorum_target > 0 ) ? '#27ae60' : '#c0392b'; ?>;">
				<h1 style="margin:0; font-size:3.5em;"><?php echo count( $checked_in_reps ); ?> / <?php echo (int) $quorum_target; ?> Communities</h1>
				<p style="margin:5px 0 0; font-size:1.3em; opacity:0.9;">Official Quorum (40% of <?php echo (int) $total_active; ?> Approved Communities)</p>
			</div>

			<div class="stat-bar">
				<div class="stat-item"><h4>Official Reps</h4><strong><?php echo count( $checked_in_reps ); ?></strong></div>
				<div class="stat-item"><h4>Announced Guests</h4><strong><?php echo (int) $announced_count; ?></strong></div>
				<div class="stat-item"><h4>General Public</h4><strong><?php echo (int) $public_count; ?></strong></div>
				<div class="stat-item"><h4>Total Scans</h4><strong><?php echo count( $scans ) + count( $announced_scans ); ?></strong></div>
			</div>

			<h3 class="section-label" style="background:#27ae60;">Official Attendance</h3>
			<div class="grid-box"><?php $i = 1; foreach ( $checked_in_reps as $c ) { echo "<div>{$i}. " . esc_html( $c ) . '</div>'; $i++; } ?></div>

			<h3 class="section-label" style="background:#c0392b;">Communities Not in Attendance</h3>
			<div class="grid-box"><?php $i = 1; foreach ( $missing as $m ) { echo "<div>{$i}. " . esc_html( $m ) . '</div>'; $i++; } ?></div>

			<h3 class="section-label" style="background:#f39c12;">Announced Guests (Scanned In)</h3>
			<div class="grid-box">
				<?php 
				if ( ! empty( $announced_guests ) ) {
					$i = 1; 
					foreach ( $announced_guests as $ag ) { 
						echo "<div>{$i}. " . esc_html( $ag ) . '</div>'; 
						$i++; 
					} 
				} else {
					echo '<div>No guests scanned.</div>';
				}
				?>
			</div>

			<h3 class="section-label" style="background:#2c3e50;">Action Item Log</h3>
			<div style="background:#fff; padding:20px; border:1px solid #ddd; border-top:none; border-radius: 0 0 8px 8px;">
				<form method="POST" style="margin-bottom:15px; text-align:right;">
					<?php wp_nonce_field( 'cobwra_dashboard_action', 'cobwra_dashboard_nonce' ); ?>
					<input type="submit" name="cobwra_export" value="Download Discrepancy CSV" class="button button-secondary">
					<input type="submit" name="cobwra_sync" value="Sync Unique Reps" class="button button-primary">
				</form>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Attendee</th><th>Status</th><th>Note</th></tr></thead>
					<tbody>
						<?php 
						foreach ( array( 'CONFLICT', 'VACANCY', 'ROLE MISMATCH', 'ANNOUNCED' ) as $k ) {
							foreach ( $groups[ $k ] as $res ) {
								echo "<tr><td><strong>" . esc_html( $res['name'] ) . "</strong><br><small>" . esc_html( $res['comm'] ) . "</small></td><td><span style='background:{$res['color']}; color:white; padding:4px 8px; border-radius:4px; font-size:0.8em; font-weight:bold; text-transform:uppercase;'>" . esc_html( $res['status'] ) . "</span></td><td>" . $res['note'] . "</td></tr>";
							}
						} 
						?>
						<tr style="background:#f9f9f9;"><td colspan="3" style="text-align:center; color:#888;">Matches and <?php echo (int) $public_count; ?> Public scans hidden.</td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Export logic for CSV.
	 */
	private function export_csv() {
		global $wpdb;
		
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		$scans = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value as rsvp_id FROM {$wpdb->prefix}gf_entry_meta m JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active'", COBWRA_TEMP_FORM ) );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cobwra-discrepancies-' . date( 'Y-m-d' ) . '.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$o = fopen( 'php://output', 'w' );
		fputcsv( $o, array( 'Status', 'Name', 'Community', 'Email', 'Role', 'Observation' ) );
		
		foreach ( $scans as $s ) {
			$res = $this->engine->analyze_scan( $s->rsvp_id );
			if ( 'MATCHED' !== $res['status'] && 'GUEST/PUBLIC' !== $res['status'] && 'WALK-IN' !== $res['status'] ) {
				fputcsv( $o, array(
					$res['status'], 
					$res['name'], 
					$res['comm'], 
					$res['email'], 
					$res['role'], 
					strip_tags( $res['note'] )
				) );
			}
		}
		fclose( $o );
		exit;
	}

	/**
	 * Sync local unique reps to the final attendance list.
	 */
	private function sync_form_6() {
		global $wpdb;
		$reps  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m1.meta_value FROM {$wpdb->prefix}gf_entry_meta m1 JOIN {$wpdb->prefix}gf_entry e ON m1.entry_id = e.id JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id WHERE e.form_id = %d AND e.status = 'active' AND m1.meta_key = '1' AND m2.meta_key = '5' AND m2.meta_value = '0'", COBWRA_TEMP_FORM ) );
		$today = current_time( 'Y-m-d' ) . '%';
		foreach ( $reps as $c ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT e.id FROM {$wpdb->prefix}gf_entry e JOIN {$wpdb->prefix}gf_entry_meta m ON e.id = m.entry_id WHERE e.form_id = %d AND e.date_created LIKE %s AND m.meta_key = '1' AND m.meta_value = %s", COBWRA_FINAL_FORM, $today, $c ) );
			if ( ! $exists ) {
				$wpdb->insert( "{$wpdb->prefix}gf_entry", array( 'form_id' => COBWRA_FINAL_FORM, 'date_created' => current_time( 'mysql' ), 'status' => 'active' ) );
				$nid = $wpdb->insert_id;
				$wpdb->insert( "{$wpdb->prefix}gf_entry_meta", array( 'entry_id' => $nid, 'form_id' => COBWRA_FINAL_FORM, 'meta_key' => '1', 'meta_value' => $c ) );
			}
		}
	}
}
