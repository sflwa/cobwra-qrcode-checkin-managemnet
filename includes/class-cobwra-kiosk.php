<?php
/**
 * COBWRA Kiosk UI Class
 * Handles the high-visibility front-end scan station.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Kiosk {

	/**
	 * @var COBWRA_Engine
	 */
	private $engine;

	/**
	 * Constructor
	 * * @param COBWRA_Engine $engine The logic engine instance.
	 */
	public function __construct( $engine ) {
		$this->engine = $engine;
		add_shortcode( 'cobwra_checkin', array( $this, 'render_kiosk' ) );
	}

	/**
	 * Render the Kiosk Scanner UI
	 * * @return string HTML Output.
	 */
	public function render_kiosk() {
		// Capture the scan from POST (Scanner) or GET (Manual/QR)
		$id = $_POST['scanner_id'] ?? $_GET['id'] ?? '';
		
		$result_html = '';

		if ( ! empty( $id ) ) {
			// Process through the 4-way verification engine
			$analysis = $this->engine->analyze_scan( $id );
			
			// Log the result to Form 10
			$this->engine->log_scan( $analysis, $id );

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
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function() {
				const scannerInput = document.getElementById('cobwra-scanner-input');
				if (scannerInput) {
					// 1. Initial Focus
					scannerInput.focus();
					
					// 2. Sticky Focus: Return focus to input if user clicks away
					document.addEventListener('click', function() {
						scannerInput.focus();
					});

					// 3. Clear result after 8 seconds to reset the screen (Optional)
					// setTimeout(() => { 
					//    const card = document.querySelector('.cobwra-result-card');
					//    if(card) card.style.opacity = '0.3'; 
					// }, 8000);
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
