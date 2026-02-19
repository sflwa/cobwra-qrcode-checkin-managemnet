<?php
class COBWRA_Dashboard {
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_shortcode( 'cobwra_dashboard', [ $this, 'render_dashboard' ] );
	}

	public function handle_actions() {
		if ( ! current_user_can('manage_options') ) return;
		if ( isset($_POST['cobwra_export']) ) $this->export_csv();
		if ( isset($_POST['cobwra_sync']) ) $this->sync_form_6();
	}

	public function render_dashboard() {
		global $wpdb;
		$total_active = intval( get_option( $this->engine->comm_opt, 0 ) );
		$quorum_target = ceil( $total_active * 0.4 );

		$checked_in_reps = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m1.meta_value FROM {$wpdb->prefix}gf_entry_meta m1 JOIN {$wpdb->prefix}gf_entry e ON m1.entry_id = e.id JOIN {$wpdb->prefix}gf_entry_meta m2 ON m1.entry_id = m2.entry_id WHERE e.form_id = %d AND e.status = 'active' AND m1.meta_key = '1' AND m2.meta_key = '5' AND m2.meta_value = '0' ORDER BY m1.meta_value ASC", COBWRA_TEMP_FORM ) );
		$approved = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.meta_value FROM {$wpdb->prefix}gf_entry_meta m JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id JOIN {$wpdb->prefix}gf_entry_meta m_app ON m.entry_id = m_app.entry_id WHERE e.form_id = %d AND e.status = 'active' AND m.meta_key = '3' AND m_app.meta_key = 'is_approved' AND m_app.meta_value = '1'", COBWRA_MASTER_FORM ) );
		$missing = array_diff($approved, $checked_in_reps);
		sort($missing);

		$scans = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_value as rsvp_id FROM {$wpdb->prefix}gf_entry_meta m JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id WHERE e.form_id = %d AND m.meta_key = '6' AND e.status = 'active' ORDER BY e.id DESC", COBWRA_TEMP_FORM ) );

		ob_start(); ?>
		<style>
			.quorum-hero { padding:30px; text-align:center; border-radius:10px; color:white; margin-bottom:20px; }
			.grid-box { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; font-size: 0.85em; background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px; }
			.section-label { color: #fff; padding: 10px; border-radius: 5px 5px 0 0; margin: 20px 0 0; font-weight: bold; }
		</style>
		<div class="cobwra-dash">
			<div class="quorum-hero" style="background:<?php echo (count($checked_in_reps) >= $quorum_target) ? '#27ae60' : '#c0392b'; ?>;">
				<h1 style="margin:0;"><?php echo count($checked_in_reps); ?> / <?php echo $quorum_target; ?> Communities</h1>
				<p>Official Quorum (40% of <?php echo $total_active; ?> Approved)</p>
			</div>

			<h3 class="section-label" style="background:#27ae60;">Official Attendance</h3>
			<div class="grid-box"><?php $i=1; foreach($checked_in_reps as $c) { echo "<div>{$i}. {$c}</div>"; $i++; } ?></div>

			<h3 class="section-label" style="background:#c0392b;">Missing Communities</h3>
			<div class="grid-box"><?php $i=1; foreach($missing as $m) { echo "<div>{$i}. {$m}</div>"; $i++; } ?></div>

			<h3 class="section-label" style="background:#2c3e50;">Discrepancy & Verification Log (Action Items)</h3>
			<div style="background:#fff; padding:20px; border:1px solid #ddd;">
				<form method="POST" style="margin-bottom:15px;">
					<input type="submit" name="cobwra_export" value="Download Discrepancy CSV" class="button button-secondary">
					<input type="submit" name="cobwra_sync" value="Sync to Form 6" class="button button-primary">
				</form>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Attendee</th><th>Status</th><th>Note</th></tr></thead>
					<tbody>
						<?php foreach($scans as $s) : 
							$res = $this->engine->analyze_scan($s->rsvp_id); 
							if($res['status'] === 'MATCHED') continue; ?>
							<tr>
								<td><strong><?php echo $res['name']; ?></strong><br><small><?php echo $res['comm']; ?></small></td>
								<td><span style="background:<?php echo $res['color']; ?>; color:white; padding:4px 8px; border-radius:4px; font-size:0.8em; font-weight:bold;"><?php echo $res['status']; ?></span></td>
								<td><?php echo $res['note']; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php return ob_get_clean();
	}
    // ... export and sync methods ...
}
