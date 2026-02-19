<?php
/**
 * COBWRA Admin - Configuration & Alias Management (v19.4)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Admin {
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	public function add_menu() {
		add_menu_page('COBWRA Check-in', 'COBWRA Check-in', 'manage_options', 'cobwra-checkin', array($this, 'render_page'), 'dashicons-id-alt', 26);
	}

	public function handle_actions() {
		if ( ! current_user_can('manage_options') ) return;

		if ( isset($_POST['save_cobwra_config']) ) {
			check_admin_referer('cobwra_admin_save');
			update_option('cobwra_active_comm_count', intval($_POST['active_comm_count']));
			update_option('cobwra_name_aliases', sanitize_textarea_field($_POST['name_aliases']));
			
			if (isset($_FILES['rsvp_csv']) && $_FILES['rsvp_csv']['size'] > 0) {
				$data = [];
				if (($h = fopen($_FILES['rsvp_csv']['tmp_name'], "r")) !== FALSE) {
					fgetcsv($h); // Skip Header
					while (($r = fgetcsv($h, 1000, ",")) !== FALSE) {
						$data[$r[6]] = ['first'=>$r[0], 'last'=>$r[1], 'email'=>$r[2], 'role'=>$r[3], 'comm'=>$r[4]];
					}
					fclose($h);
					update_option('cobwra_rsvp_lookup_data', $data);
				}
			}
			add_settings_error('cobwra', 'saved', 'Settings and Data Updated.', 'updated');
		}
	}

	public function render_page() {
		settings_errors('cobwra');
		$csv_data = get_option('cobwra_rsvp_lookup_data', []);
		$aliases = get_option('cobwra_name_aliases', '');
		?>
		<div class="wrap">
			<h1>COBWRA Configuration</h1>
			<div class="card" style="max-width:800px; padding:20px; margin-top:20px;">
				<form method="POST" enctype="multipart/form-data">
					<?php wp_nonce_field('cobwra_admin_save'); ?>
					<h3>1. Quorum & RSVP</h3>
					<table class="form-table">
						<tr><th>Active Communities</th><td><input type="number" name="active_comm_count" value="<?php echo get_option('cobwra_active_comm_count'); ?>" class="small-text"></td></tr>
						<tr><th>Upload RSVP CSV</th><td><input type="file" name="rsvp_csv"></td></tr>
					</table>
					<hr>
					<h3>2. Name Aliases (Fuzzy Matching)</h3>
					<p class="description">One group per line, comma separated. Example: <em>Steve,Steven,Stephen</em></p>
					<textarea name="name_aliases" rows="6" class="large-text" placeholder="Pat,Patricia,Patty"><?php echo esc_textarea($aliases); ?></textarea>
					<p class="submit"><input type="submit" name="save_cobwra_config" class="button button-primary" value="Save All Changes"></p>
				</form>
			</div>

			<?php if(!empty($csv_data)): ?>
				<h3>RSVP Preview (Top 10)</h3>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>ID</th><th>Name</th><th>Community</th><th>Role</th></tr></thead>
					<tbody>
						<?php $p = array_slice($csv_data, 0, 10, true);
						foreach($p as $id => $r) { echo "<tr><td>$id</td><td>{$r['first']} {$r['last']}</td><td>{$r['comm']}</td><td>{$r['role']}</td></tr>"; } ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
