<?php
/**
 * COBWRA Kiosk UI Class (v32.2)
 * Fix: Redirects full URL scans to clean URL parameters for a fresh reload.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Kiosk {
	private $engine;

	public function __construct( $engine ) {
		$this->engine = $engine;
		add_shortcode( 'cobwra_checkin', array( $this, 'render_kiosk' ) );
	}

	public function render_kiosk() {
		// 1. Capture current GET parameters (from the redirected URL)
		$id_param   = $_GET['id'] ?? '';
		$comm_param = $_GET['c'] ?? '';
		$last_param = $_GET['l'] ?? '';

		$result_html = '';
		
		// 2. If we have parameters, process them through the engine
		if ( ! empty( $id_param ) || ! empty( $comm_param ) ) {
			$analysis = $this->engine->analyze_scan( $id_param ?: $comm_param, $last_param );
			
			// Log for history
			$log_id = ! empty( $id_param ) ? $id_param : trim( $comm_param . ' ' . $last_param );
			$this->engine->log_scan( $analysis, $log_id );

			$result_html = $this->generate_result_card( $analysis );
		}

		ob_start();
		?>
		<div id="cobwra-kiosk-container" style="max-width: 1000px; margin: 20px auto; font-family: sans-serif;">
			<?php echo $result_html; ?>

			<div class="cobwra-scan-zone" style="text-align:center; padding:60px 20px; border:5px solid #2c3e50; border-radius:30px; background:#fff; box-shadow: 0 15px 35px rgba(0,0,0,0.1);">
				<h1 style="color:#2c3e50; font-size:3em; margin-bottom:20px; text-transform:uppercase;">Ready for Scan</h1>
				
				<form id="cobwra-kiosk-form">
					<input type="text" id="cobwra-scanner-input" placeholder="Scan Badge/QR..." autocomplete="off"
						   style="width:85%; padding:30px; font-size:3.5em; text-align:center; border:4px solid #3498db; border-radius:20px; outline:none;">
				</form>
				
				<p style="color:#7f8c8d; margin-top:30px; font-size:1.4em;">The system is active. <strong>Auto-Redirect Enabled.</strong></p>
			</div>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const scannerInput = document.getElementById('cobwra-scanner-input');
				const kioskForm = document.getElementById('cobwra-kiosk-form');

				// Maintain Auto-focus
				scannerInput.focus();
				document.addEventListener('click', () => scannerInput.focus());

				// Intercept Submission (Scanner Enter Key)
				kioskForm.addEventListener('submit', function(e) {
					e.preventDefault();
					const val = scannerInput.value.trim();

					if (!val) return;

					// Check if it's a URL
					if (val.startsWith('http')) {
						try {
							const url = new URL(val);
							const params = new URLSearchParams(url.search);
							
							// Construct new local URL with just the parameters
							const newUrl = window.location.pathname + '?' + params.toString();
							window.location.href = newUrl;
						} catch (err) {
							console.error("Invalid URL scanned:", val);
						}
					} else {
						// It's a raw ID (Badge scan), just pass it as an ID
						window.location.href = window.location.pathname + '?id=' + encodeURIComponent(val);
					}
				});
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
