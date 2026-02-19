<?php
/**
 * Plugin Name: COBWRA Meeting Manager
 * Description: Expert WordPress Developer - Staging Table & Kiosk Loader (v48.2)
 * Author: Philip Levine / SFLWA Coding
 * Version: 48.2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define Plugin Constants
define( 'COBWRA_VERSION', '48.2' );
define( 'COBWRA_PATH', plugin_dir_path( __FILE__ ) );
define( 'COBWRA_URL', plugin_dir_url( __FILE__ ) );

// Load Required Classes
require_once COBWRA_PATH . 'class-cobwra-database.php';
require_once COBWRA_PATH . 'class-cobwra-admin.php';
require_once COBWRA_PATH . 'class-cobwra-engine.php';

/**
 * Plugin Activation: Create the Staging Table
 */
register_activation_hook( __FILE__, 'cobwra_plugin_activate' );
function cobwra_plugin_activate() {
    $db = new COBWRA_Database();
    $db->create_table(); // Uses dbDelta for safe schema updates
}

/**
 * Initialize Admin & Kiosk Assets
 */
class COBWRA_Core {
    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init_components' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_kiosk_assets' ) );
    }

    public function init_components() {
        new COBWRA_Admin();
    }

    /**
     * Enqueues the Kiosk JS/CSS only on the check-in page
     */
    public function enqueue_kiosk_assets() {
        // Only load on your specific check-in page slug
        if ( is_page( 'checkin' ) ) {
            // Load Kiosk CSS
            wp_enqueue_style( 
                'cobwra-kiosk-style', 
                COBWRA_URL . 'assets/css/kiosk-style.css', 
                array(), 
                COBWRA_VERSION 
            );

            // Load Kiosk JS Engine
            wp_enqueue_script( 
                'cobwra-kiosk-engine', 
                COBWRA_URL . 'assets/js/cobwra-kiosk.js', 
                array( 'jquery' ), 
                COBWRA_VERSION, 
                true 
            );

            // Pass Ajax URL to the Kiosk script
            wp_localize_script( 'cobwra-kiosk-engine', 'cobwra_params', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'cobwra_kiosk_nonce' )
            ));
        }
    }
}

new COBWRA_Core();
