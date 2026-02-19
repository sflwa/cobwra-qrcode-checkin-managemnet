<?php
/**
 * Plugin Name: COBWRA Quick Check-in System (v19.0)
 * Description: Modular Credential Engine with 4-way Discrepancy Logic and CSV Follow-up Export.
 * Version:     19.0
 * Author:      Philip Levine / SFLWA
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Constants for easy adjustment
define( 'COBWRA_MASTER_FORM', 2 );
define( 'COBWRA_TEMP_FORM', 10 );
define( 'COBWRA_FINAL_FORM', 6 );
define( 'COBWRA_PATH', plugin_dir_path( __FILE__ ) );


// Load Components
require_once COBWRA_PATH . 'includes/class-cobwra-engine.php';
require_once COBWRA_PATH . 'includes/class-cobwra-kiosk.php';
require_once COBWRA_PATH . 'includes/class-cobwra-dashboard.php';
require_once COBWRA_PATH . 'includes/class-cobwra-admin.php';

final class COBWRA_Checkin_Plugin {
	public function __construct() {
		$engine = new COBWRA_Engine();
		new COBWRA_Kiosk( $engine );
		new COBWRA_Dashboard( $engine );
		new COBWRA_Admin( $engine );
	}
}
new COBWRA_Checkin_Plugin();
