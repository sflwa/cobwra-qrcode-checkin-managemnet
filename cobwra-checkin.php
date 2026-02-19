<?php
/**
 * Plugin Name: COBWRA Meeting Manager
 * Description: Expert WordPress Developer - Staging Architecture Core (v48.3)
 * Author: Philip Levine / SFLWA Coding
 * Version: 48.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define Path Constants
define( 'COBWRA_PATH', plugin_dir_path( __FILE__ ) );

// 1. Load All Required Files
require_once COBWRA_PATH . 'class-cobwra-database.php';
require_once COBWRA_PATH . 'class-cobwra-engine.php';
require_once COBWRA_PATH . 'class-cobwra-admin.php';
require_once COBWRA_PATH . 'class-cobwra-kiosk.php';

/**
 * Plugin Activation Hook
 */
register_activation_hook( __FILE__, 'cobwra_plugin_activate' );
function cobwra_plugin_activate() {
    $db = new COBWRA_Database();
    $db->create_table(); // Ensure staging table exists
}

/**
 * Main Plugin Controller Class
 */
class COBWRA_Core {
    public function __construct() {
        // Initialize components on 'plugins_loaded' to ensure WP is ready
        add_action( 'plugins_loaded', array( $this, 'assemble_plugin' ) );
    }

    public function assemble_plugin() {
        // 2. Instantiate the Engine first as a "Shared Service"
        $engine = new COBWRA_Engine();

        // 3. Pass the Engine to the Kiosk UI so it can run scans
        new COBWRA_Kiosk( $engine );

        // 4. Initialize Admin separate from Frontend UI
        new COBWRA_Admin();
    }
}

// Kick off the plugin
new COBWRA_Core();
