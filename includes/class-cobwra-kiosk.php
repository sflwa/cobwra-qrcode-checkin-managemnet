<?php
/**
 * COBWRA Kiosk UI Class (v32.1)
 * Fix: Dissects full URLs entered into the Scan Input field.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Kiosk {
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_shortcode( 'cobwra_checkin', array( $this, 'render_kiosk' ) );
	}

	public function render_kiosk() {
		global $wpdb;

		// 1. Capture Raw Inputs from both the Input Box (POST) and URL (GET)
		$scanner_input = $_POST['scanner_id'] ?? '';
		$get_id        = $_GET['id'] ?? '';
		$comm_param    = $_GET['c'] ?? '';
		$last_param    = $_GET['l'] ?? '';

		// 2. SMART PARSING: If the scanner typed a full URL into the box
		if ( filter_var( $scanner_input, FILTER_VALIDATE_URL ) ) {
			$url_parts = parse_url( $scanner_input );
			if ( isset( $url_parts['query'] ) ) {
				parse_str( $url_parts['query'], $query_params );
				// Extract the data from the URL the scanner just typed
				$comm_param = $query_params['c'] ?? $comm_param;
				$last_param = $query_params['l'] ?? $last_param;
				$get_id     = $query_params['id'] ?? $get_id;
			}
		}

		$result_html = '';
		// Logic: If we have an ID or a Community/Last Name pair, run the engine
		if ( ! empty( $get_id ) || ! empty( $comm_param ) || ! empty( $scanner_input ) ) {
			
			// Decide what the "Primary" and "Secondary" search terms are
			// We prioritize the parsed parameters over the raw URL string
			$search_primary   = !empty($get_id) ? $get_id : ($comm_param ?: $scanner_input);
			$search_secondary = $last_param;

			$analysis = $this->engine->analyze_scan( $search_primary, $search_secondary );
			
			// Log the result to the Temp Table
			$log_id = ! empty( $get_id ) ? $get_id : ( ! empty( $scanner_input ) ? $scanner_input : trim( $comm_param . ' ' . $last_param ) );
			$this->engine->log_scan( $analysis, $log_id );

			$result_html = $this->generate_result_card( $analysis );
		}

		ob_start();
		?>
		<div id="cobwra-kiosk-container" style="max-width: 1000px; margin: 20px auto; font-family: sans-serif;">
			<?php echo $result_html; ?>

			<div class="cobwra-scan-zone" style="text-align:center; padding:60px 20px; border:5px solid #2c3e50; border-radius:30px; background:#fff; box-shadow: 0 15px 35px rgba(0,0,0,0.1);">
				<h1 style="color:#2c3e50; font-size:3em; margin-bottom:20px; text-transform:uppercase;">Ready for Scan</h1>
				<form method="POST" id="cobwra-kiosk-form">
					<input type="text" name="scanner_id" id="cobwra-scanner-input" placeholder="Scan Badge/QR..." autocomplete="off"
						   style="width:85%; padding:30px; font-size:3.5em; text-align:center; border:4px solid #3498db; border-radius:20px; outline:none;">
				</form>
				<p style="color:#7f8c8d; margin-top:30px; font-size:1.4em;">The system is active. <strong>Auto-focus enabled.</strong></p>
			</div>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const scannerInput = document.getElementById('cobwra-scanner-input');
				if (scannerInput) {
					scannerInput.focus();
					document.addEventListener('click', () => scannerInput.focus());
				}
			});
		</script>
		<?php
		return ob_get_clean();
	}

	private function generate_result_card( $res ) {
		return "
		<div class='cobwra-result-card' style='background:{$res['color']}; color:white; padding:40px; border-radius:25px; margin-bottom:30px; text-align:center; box-shadow: 0 10px 20px rgba(0,0,0,0.15);'>
			<h2 style='margin:0; font-size:4.5em; text-transform:uppercase;'>{$res['name']}</h2>
			<p style='font-size:2.2em; margin:15px 0;'>{$res['comm']} &bull; {$res['role']}</p>
			<div style='background:rgba(255,255,255,0.95); color:#2c3e50; padding:25px; border-radius:15px; font-size:1.6em; font-weight:bold; border-left:15px solid #2c3e50;'>
				{$res['note']}
			</div>
		</div>";
	}
}
