<?php
/**
 * class-cobwra-admin.php
 * Admin Interface for Meeting Management (v48.4)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class COBWRA_Admin {
    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'cobwra_meeting_roster';
        
        // Register the Sidebar Menu
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        
        // Hook for Admin Actions
        add_action( 'admin_post_cobwra_sync_authority', array( $this, 'handle_sync_authority' ) );
        add_action( 'admin_post_cobwra_clear_roster', array( $this, 'handle_clear_roster' ) );
        add_action( 'admin_post_cobwra_import_rsvp', array( $this, 'handle_rsvp_import' ) );
    }

    public function add_admin_menu() {
        add_menu_page( 
            'COBWRA Meeting', 
            'Meeting Manager', 
            'manage_options', 
            'cobwra-dashboard', 
            array( $this, 'render_dashboard' ), 
            'dashicons-groups', 
            25 
        );
    }

    /**
     * Renders the UI with Sync and Clear buttons
     */
    public function render_dashboard() {
        ?>
        <div class="wrap">
            <h1>COBWRA Meeting Manager</h1>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px; margin-top: 20px;">
                <h2>Data Synchronization</h2>
                <p>Use these buttons to prepare the roster before the meeting begins.</p>
                
                <a href="<?php echo admin_url('admin-post.php?action=cobwra_sync_authority'); ?>" class="button button-primary">1. Synchronize from Master DBs</a>
                
                <a href="<?php echo admin_url('admin-post.php?action=cobwra_clear_roster'); ?>" class="button" onclick="return confirm('Wipe current meeting roster?')">Clear Roster</a>
                
                <hr>
                
                <h3>2. Import RSVPs (CSV)</h3>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="cobwra_import_rsvp">
                    <input type="file" name="rsvp_csv" accept=".csv" required>
                    <?php submit_button('Upload and Match RSVPs', 'secondary', 'submit', false); ?>
                </form>
            </div>
        </div>
        <?php
    }

    // ... Keep your existing handle_sync_authority, handle_rsvp_import, and handle_clear_roster methods ...
}
