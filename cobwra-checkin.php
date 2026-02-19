<?php
/**
 * Plugin Name: COBWRA Meeting Manager
 * Description: Staging Table Architecture - High Performance Check-in (v48.3)
 * Author: Philip Levine / SFLWA Coding
 * Version: 48.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define Path Constants
define( 'COBWRA_PATH', plugin_dir_path( __FILE__ ) );

// Load Required Files
require_once COBWRA_PATH . 'includes/class-cobwra-database.php';
require_once COBWRA_PATH . 'includes/class-cobwra-engine.php';
require_once COBWRA_PATH . 'includes/class-cobwra-admin.php';
require_once COBWRA_PATH . 'includes/class-cobwra-kiosk.php';

/**
 * Plugin Activation Hook
 */
register_activation_hook( __FILE__, 'cobwra_plugin_activate' );
function cobwra_plugin_activate() {
    $db = new COBWRA_Database();
    $db->create_table(); 
}

/**
 * Main Plugin Controller Class
 */
class COBWRA_Core {
    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'assemble_plugin' ) );
    }

    public function assemble_plugin() {
        // 1. Instantiate the Engine
        $engine = new COBWRA_Engine();

        // 2. Pass the Engine to the Kiosk for live validation
        new COBWRA_Kiosk( $engine );

        // 3. Initialize Admin components
        new COBWRA_Admin();
    }
}

// Start the plugin
new COBWRA_Core();
