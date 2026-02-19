<?php
class COBWRA_Dashboard {
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_init', [ $this, 'handle_export' ] );
		add_shortcode( 'cobwra_dashboard', [ $this, 'render_dashboard' ] );
	}

	/**
	 * Export Discrepancies to CSV for follow-up
	 */
	public function handle_export() {
		if ( ! isset($_POST['cobwra_export_discrepancies']) ) return;
		
		check_admin_referer( 'cobwra_export' );

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=cobwra-followup-'.date('Y-m-d').'.csv');
		
		$output = fopen('php://output', 'w');
		fputcsv($output, ['Status', 'Name', 'Community', 'Email', 'Claimed Role', 'Observation/Correct Role']);

		$scans = $this->get_todays_scans();
		foreach ( $scans as $s ) {
			$res = $this->engine->analyze_scan( $s->id );
			if ( $res['status'] !== 'MATCHED' ) {
				fputcsv($output, [
					$res['status'], 
					$res['name'], 
					$res['comm'], 
					$res['email'] ?? 'N/A', 
					$res['role'], 
					strip_tags($res['note'])
				]);
			}
		}
		fclose($output);
		exit;
	}

	public function render_dashboard() {
		$entries = $this->get_todays_scans();
		ob_start(); ?>
		<div class="wrap" style="font-family: sans-serif;">
			<div style="background:#2c3e50; color:white; padding:20px; border-radius:10px; margin-bottom:20px; display:flex; justify-content: space-between; align-items: center;">
				<h2 style="color:white; margin:0;">Attendance & Credential Report</h2>
				<form method="POST">
					<?php wp_nonce_field( 'cobwra_export' ); ?>
					<input type="submit" name="cobwra_export_discrepancies" value="Download Follow-up CSV" class="button button-secondary">
					<input type="submit" name="cobwra_sync_final" value="Sync Unique Reps to Form 6" class="button button-primary">
				</form>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Attendee</th>
						<th>Status</th>
						<th>Notes / Correct Info</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $e ) : $res = $this->engine->analyze_scan( $e->id ); ?>
						<tr>
							<td><strong><?php echo $res['name']; ?></strong><br><small><?php echo $res['comm']; ?> (<?php echo $res['role']; ?>)</small></td>
							<td><span style="background:<?php echo $res['color']; ?>; color:white; padding:5px 10px; border-radius:4px; font-weight:bold; font-size:0.9em;"><?php echo $res['status']; ?></span></td>
							<td><?php echo $res['note']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php return ob_get_clean();
	}

	private function get_todays_scans() {
		global $wpdb;
		$today = current_time('Y-m-d') . '%';
		return $wpdb->get_results( $wpdb->prepare( "
			SELECT m.meta_value as id FROM {$wpdb->prefix}gf_entry_meta m
			JOIN {$wpdb->prefix}gf_entry e ON m.entry_id = e.id
			WHERE e.form_id = %d AND m.meta_key = '6' AND e.date_created LIKE %s
			ORDER BY e.id DESC
		", COBWRA_TEMP_FORM, $today ) );
	}
}
