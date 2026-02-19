<?php
/**
 * COBWRA Kiosk UI Class (v31.0)
 * Merged high-visibility scanner station with URL parameter support & Admin Debugging.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Kiosk {

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
		add_shortcode( 'cobwra_checkin', array( $this, 'render_kiosk' ) );
	}

	/**
	 * Render the Kiosk Scanner UI
	 * @return string HTML Output.
	 */
	public function render_kiosk() {
		global $wpdb;

		// 1. Capture inputs: Priority to POST (Scanner), then URL (ID, Community, or Name)
		$post_id    = $_POST['scanner_id'] ?? '';
		$get_id     = $_GET['id'] ?? '';
		$comm_param = $_GET['c'] ?? '';
		$last_param = $_GET['l'] ?? '';

		$result_html = '';
		$captured_query = 'No search performed.';
		$debug_input_source = 'None';

		// 2. Logic: Process if we have a Badge Scan OR URL parameters
		if ( ! empty( $post_id ) || ! empty( $get_id ) || ! empty( $comm_param ) ) {
			
			// Determine what to send to the engine
			if ( ! empty( $post_id ) ) {
				$lookup_primary = $post_id;
				$lookup_secondary = '';
				$debug_input_source = 'POST (Scanner)';
			} elseif ( ! empty( $get_id ) ) {
				$lookup_primary = $get_id;
				$lookup_secondary = '';
				$debug_input_source = 'GET (ID Parameter)';
			} else {
				$lookup_primary = $comm_param;
				$lookup_secondary = $last_param;
				$debug_input_source = 'GET (Community/Name Parameters)';
			}

			// Process through strict matching engine
			$analysis = $this->engine->analyze_scan( $lookup_primary, $lookup_secondary );
			
			// Capture the SEARCH query before log_scan executes its own INSERTs
			$captured_query = $wpdb->last_query;

			// Log the result to Form 10
			$log_id = ! empty( $post_id ) ? $post_id : ( ! empty( $get_id ) ? $get_id : trim( $comm_param . ' ' . $last_param ) );
			$this->engine->log_scan( $analysis, $log_id );

			// Build the Result Card
			$result_html = $this->generate_result_card( $analysis );
		}

		ob_start();
		?>
		<div id="cobwra-kiosk-container" style="max-width: 1000px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
			
			<?php echo $result_html; ?>

			<div class="cobwra-scan-zone" style="text-align:center; padding:60px 20px; border:5px solid #2c3e50; border-radius:30px; background:#fff; box-shadow: 0 15px 35px rgba(0,0,0,0.1);">
				<h1 style="color:#2c3e50; font-size:3em; margin-bottom:20px; text-transform:uppercase; letter-spacing:2px;">
					Ready for Scan
				</h1>
				
				<form method="POST" id="cobwra-kiosk-form">
					<input type="text" 
						   name="scanner_id" 
						   id="cobwra-scanner-input" 
						   placeholder="Scan Badge/QR..." 
						   autocomplete="off"
						   style="width:85%; padding:30px; font-size:3.5em; text-align:center; border:4px solid #3498db; border-radius:20px; outline:none; transition: border-color 0.3s;"
					>
				</form>
				
				<p style="color:#7f8c8d; margin-top:30px; font-size:1.4em;">
					The system is active. <strong>Auto-focus enabled.</strong>
				</p>
			</div>

			<?php if ( current_user_can( 'manage_options' ) && ( ! empty( $comm_param ) || ! empty( $post_id ) ) ): ?>
				<div style="margin-top:50px; padding:20px; background:#222; color:#0f0; text-align:left; font-family:monospace; font-size:12px; border-radius:15px; border: 2px solid #444;">
					<strong style="color:#fff; border-bottom:1px solid #444; display:block; margin-bottom:10px; font-size:14px;">🛠️ ADMIN DEBUG CONSOLE</strong>
					<div><strong>Input Source:</strong> <?php echo $debug_input_source; ?></div>
					<div><strong>Params:</strong> C: "<?php echo esc_html($comm_param); ?>", L: "<?php echo esc_html($last_param); ?>", Post: "<?php echo esc_html($post_id); ?>"</div>
					<div style="color:#aaa; margin-top:10px;">--- Last SQL Search ---</div>
					<div style="word-break:break-all; color:#77f; padding:5px; background:#111; border-radius:5px; margin-top:5px;">
						<?php echo $captured_query; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const scannerInput = document.getElementById('cobwra-scanner-input');
				if (scannerInput) {
					scannerInput.focus();
					document.addEventListener('click', function() {
						scannerInput.focus();
					});
				}
			});
		</script>

		<style>
			#cobwra-scanner-input:focus { border-color: #2ecc71 !important; box-shadow: 0 0 20px rgba(46, 204, 113, 0.2); }
			.cobwra-result-card { animation: cobwraFadeIn 0.5s ease-out; }
			@keyframes cobwraFadeIn {
				from { opacity: 0; transform: translateY(-20px); }
				to { opacity: 1; transform: translateY(0); }
			}
		</style>
		<?php
		return ob_get_clean();
	}

	/**
	 * Helper to generate the result card UI
	 */
	private function generate_result_card( $res ) {
		return "
		<div class='cobwra-result-card' style='background:{$res['color']}; color:white; padding:40px; border-radius:25px; margin-bottom:30px; text-align:center; box-shadow: 0 10px 20px rgba(0,0,0,0.15);'>
			<h2 style='margin:0; font-size:4.5em; text-transform:uppercase; line-height:1;'>{$res['name']}</h2>
			<p style='font-size:2.2em; margin:15px 0; opacity:0.95; font-weight:300;'>
				{$res['comm']} <span style='opacity:0.6;'>&bull;</span> {$res['role']}
			</p>
			<div style='background:rgba(255,255,255,0.95); color:#2c3e50; padding:25px; border-radius:15px; margin-top:20px; font-size:1.6em; font-weight:bold; border-left:15px solid #2c3e50;'>
				{$res['note']}
			</div>
		</div>";
	}
}
