<?php
/**
 * Plugin Name: Jezweb Email Double Opt-in
 * Plugin URI: https://github.com/mmhfarooque/jezweb-email-double-optin
 * Description: Email verification double opt-in system for WordPress and WooCommerce user registration with customizable email templates.
 * Version: 1.8.1
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
define('JEDO_VERSION', '1.8.1');
define('JEDO_MIN_PHP_VERSION', '7.4');
define('JEDO_MIN_WP_VERSION', '5.0');
define('JEDO_MIN_WC_VERSION', '5.0');
define('JEDO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JEDO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JEDO_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('JEDO_PLUGIN_FILE', __FILE__);

/**
 * Check minimum requirements before loading plugin
 */
function jedo_check_requirements() {
    $errors = array();

    // Check PHP version
    if (version_compare(PHP_VERSION, JEDO_MIN_PHP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current PHP version, 2: Required PHP version */
            __('Jezweb Email Double Opt-in requires PHP version %2$s or higher. You are running PHP %1$s.', 'jezweb-email-double-optin'),
            PHP_VERSION,
            JEDO_MIN_PHP_VERSION
        );
    }

    // Check WordPress version
    global $wp_version;
    if (version_compare($wp_version, JEDO_MIN_WP_VERSION, '<')) {
        $errors[] = sprintf(
            /* translators: 1: Current WordPress version, 2: Required WordPress version */
            __('Jezweb Email Double Opt-in requires WordPress version %2$s or higher. You are running WordPress %1$s.', 'jezweb-email-double-optin'),
            $wp_version,
            JEDO_MIN_WP_VERSION
        );
    }

    return $errors;
}

/**
 * Display admin notice for requirements not met
 */
function jedo_requirements_notice() {
    $errors = jedo_check_requirements();
    if (!empty($errors)) {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('Jezweb Email Double Opt-in', 'jezweb-email-double-optin') . '</strong></p>';
        foreach ($errors as $error) {
            echo '<p>' . esc_html($error) . '</p>';
        }
        echo '</div>';
    }
}

// Check requirements before loading
$jedo_errors = jedo_check_requirements();
if (!empty($jedo_errors)) {
    add_action('admin_notices', 'jedo_requirements_notice');
    return;
}

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

    /**
     * Get system status for admin display
     */
    public static function get_system_status() {
        global $wp_version;

        $status = array(
            'php' => array(
                'current' => PHP_VERSION,
                'required' => JEDO_MIN_PHP_VERSION,
                'status' => version_compare(PHP_VERSION, JEDO_MIN_PHP_VERSION, '>='),
            ),
            'wordpress' => array(
                'current' => $wp_version,
                'required' => JEDO_MIN_WP_VERSION,
                'status' => version_compare($wp_version, JEDO_MIN_WP_VERSION, '>='),
            ),
            'woocommerce' => array(
                'installed' => class_exists('WooCommerce'),
                'current' => class_exists('WooCommerce') ? WC()->version : 'N/A',
                'required' => JEDO_MIN_WC_VERSION,
                'status' => !class_exists('WooCommerce') || version_compare(WC()->version, JEDO_MIN_WC_VERSION, '>='),
            ),
            'ssl' => array(
                'status' => is_ssl(),
            ),
        );

        return $status;
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
