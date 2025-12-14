<?php
/**
 * Plugin Name: Jezweb Email Double Opt-in
 * Plugin URI: https://github.com/mmhfarooque/jezweb-email-double-optin
 * Description: Email verification double opt-in system for WordPress and WooCommerce user registration with customizable email templates.
 * Version: 1.0.0
 * Author: Jezweb
 * Developer: Mahmud Farooque
 * Author URI: https://jezweb.com.au
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jezweb-email-double-optin
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.4
 *
 * GitHub Plugin URI: mmhfarooque/jezweb-email-double-optin
 * GitHub Branch: main
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('JEDO_VERSION', '1.0.0');
define('JEDO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JEDO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JEDO_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('JEDO_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
final class Jezweb_Email_Double_Optin {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once JEDO_PLUGIN_DIR . 'includes/class-jedo-activator.php';
        require_once JEDO_PLUGIN_DIR . 'includes/class-jedo-verification.php';
        require_once JEDO_PLUGIN_DIR . 'includes/class-jedo-email.php';
        require_once JEDO_PLUGIN_DIR . 'includes/class-jedo-admin.php';
        require_once JEDO_PLUGIN_DIR . 'includes/class-jedo-woocommerce.php';
        require_once JEDO_PLUGIN_DIR . 'includes/class-jedo-github-updater.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(JEDO_PLUGIN_FILE, array('JEDO_Activator', 'activate'));
        register_deactivation_hook(JEDO_PLUGIN_FILE, array('JEDO_Activator', 'deactivate'));

        // Initialize classes
        add_action('plugins_loaded', array($this, 'init_classes'));

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));

        // Declare WooCommerce HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_wc_compatibility'));
    }

    /**
     * Initialize plugin classes
     */
    public function init_classes() {
        JEDO_Verification::get_instance();
        JEDO_Email::get_instance();
        JEDO_Admin::get_instance();
        JEDO_GitHub_Updater::get_instance();

        // Initialize WooCommerce integration if WooCommerce is active
        if (class_exists('WooCommerce')) {
            JEDO_WooCommerce::get_instance();
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'jezweb-email-double-optin',
            false,
            dirname(JEDO_PLUGIN_BASENAME) . '/languages/'
        );
    }

    /**
     * Declare WooCommerce HPOS compatibility
     */
    public function declare_wc_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', JEDO_PLUGIN_FILE, true);
        }
    }
}

/**
 * Initialize the plugin
 */
function jedo_init() {
    return Jezweb_Email_Double_Optin::get_instance();
}

// Start the plugin
jedo_init();
