<?php
/**
 * COBWRA Admin - Header-Aware Importer (v21.0)
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
		add_menu_page('COBWRA Config', 'COBWRA Config', 'manage_options', 'cobwra-checkin', array($this, 'render_page'), 'dashicons-id-alt', 26);
	}

	public function handle_actions() {
		if ( ! current_user_can('manage_options') ) return;

		if ( isset($_POST['save_cobwra_config']) ) {
			check_admin_referer('cobwra_admin_save');
			update_option('cobwra_active_comm_count', intval($_POST['active_comm_count']));
			update_option('cobwra_name_aliases', sanitize_textarea_field($_POST['name_aliases']));
			
			if (isset($_FILES['rsvp_csv']) && $_FILES['rsvp_csv']['size'] > 0) {
				$this->process_rsvp_csv($_FILES['rsvp_csv']['tmp_name']);
			}
			add_settings_error('cobwra', 'saved', 'Settings Updated.', 'updated');
		}
	}

	private function process_rsvp_csv($file) {
		if (($handle = fopen($file, "r")) !== FALSE) {
			$headers = fgetcsv($handle);
			$map = [
				'id'    => array_search('Entry Id', $headers),
				'first' => array_search('Name (First)', $headers),
				'last'  => array_search('Name (Last)', $headers),
				'email' => array_search('Email', $headers),
				'role'  => array_search('COBWRA Role', $headers),
				'comm1' => array_search('Community Name', $headers),
				'comm2' => array_search('Community / Organization & Role', $headers)
			];

			$data = [];
			while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
				$id = $row[$map['id']] ?? '';
				if (!$id) continue;
				
				// Community logic: check primary field, fallback to organization field
				$comm = !empty($row[$map['comm1']]) ? $row[$map['comm1']] : ($row[$map['comm2']] ?? 'N/A');

				$data[$id] = [
					'first' => $row[$map['first']] ?? '',
					'last'  => $row[$map['last']]  ?? '',
					'email' => $row[$map['email']] ?? '',
					'role'  => $row[$map['role']]  ?? '',
					'comm'  => $comm
				];
			}
			fclose($handle);
			update_option('cobwra_rsvp_lookup_data', $data);
		}
	}

	public function render_page() {
		settings_errors('cobwra');
		$csv_data = get_option('cobwra_rsvp_lookup_data', []);
		$aliases = get_option('cobwra_name_aliases', '');
		?>
		<div class="wrap">
			<h1>COBWRA Configuration</h1>
			<div class="card" style="max-width:800px; padding:20px; margin-top:20px; background:#fff; border:1px solid #ccd0d4;">
				<form method="POST" enctype="multipart/form-data">
					<?php wp_nonce_field('cobwra_admin_save'); ?>
					<h3>1. Active Communities & RSVP Upload</h3>
					<table class="form-table">
						<tr><th>Total Active Communities</th><td><input type="number" name="active_comm_count" value="<?php echo get_option('cobwra_active_comm_count'); ?>" class="small-text"></td></tr>
						<tr><th>RSVP Export (Full CSV)</th><td><input type="file" name="rsvp_csv"></td></tr>
					</table>
					<hr>
					<h3>2. Name Aliases</h3>
					<textarea name="name_aliases" rows="6" class="large-text" placeholder="Pat,Patricia,Patty"><?php echo esc_textarea($aliases); ?></textarea>
					<p class="submit"><input type="submit" name="save_cobwra_config" class="button button-primary" value="Save All Changes"></p>
				</form>
			</div>
			<?php if(!empty($csv_data)): ?>
				<h3 style="margin-top:40px;">Loaded RSVP Preview (10 Records)</h3>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Entry ID</th><th>Name</th><th>Community</th><th>Role</th></tr></thead>
					<tbody>
						<?php $preview = array_slice($csv_data, 0, 10, true);
						foreach($preview as $id => $r) { echo "<tr><td>$id</td><td>{$r['first']} {$r['last']}</td><td>{$r['comm']}</td><td>{$r['role']}</td></tr>"; } ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
