<?php
/**
 * WooCommerce Integration
 *
 * @package Jezweb_Email_Double_Optin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Class
 */
class JEDO_WooCommerce {

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
        if (get_option('jedo_enable_woocommerce') !== 'yes') {
            return;
        }

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Registration hooks
        add_action('woocommerce_created_customer', array($this, 'handle_wc_registration'), 10, 3);

        // Login check
        add_filter('woocommerce_process_login_errors', array($this, 'check_login_verification'), 10, 3);

        // Checkout registration check
        add_action('woocommerce_checkout_process', array($this, 'check_checkout_registration'));

        // My account messages
        add_action('woocommerce_before_customer_login_form', array($this, 'display_verification_notice'));

        // Registration form notice
        add_action('woocommerce_register_form_end', array($this, 'add_registration_notice'));

        // Block unverified checkout
        add_action('woocommerce_before_checkout_form', array($this, 'block_unverified_checkout'));

        // Add verification status to my account
        add_action('woocommerce_account_dashboard', array($this, 'show_verification_status'));

        // Handle email change verification
        add_action('woocommerce_save_account_details', array($this, 'handle_email_change'), 10, 1);
    }

    /**
     * Handle WooCommerce registration
     */
    public function handle_wc_registration($customer_id, $new_customer_data, $password_generated) {
        // Check if already handled by WordPress registration hook
        $verification_pending = get_user_meta($customer_id, 'jedo_verification_pending', true);

        if ($verification_pending === 'yes') {
            return; // Already handled
        }

        $user = get_userdata($customer_id);
        if (!$user) {
            return;
        }

        // Mark user as unverified
        update_user_meta($customer_id, 'jedo_email_verified', 'no');
        update_user_meta($customer_id, 'jedo_verification_pending', 'yes');

        // Send verification email
        JEDO_Email::get_instance()->send_verification_email($customer_id, $user->user_email, 'woocommerce');

        // Add notice for the user
        wc_add_notice(get_option('jedo_message_verification_sent'), 'notice');
    }

    /**
     * Check login verification
     */
    public function check_login_verification($validation_error, $username, $password) {
        if (is_wp_error($validation_error) && $validation_error->get_error_code()) {
            return $validation_error;
        }

        $user = get_user_by('login', $username);
        if (!$user) {
            $user = get_user_by('email', $username);
        }

        if (!$user) {
            return $validation_error;
        }

        // Skip check for administrators
        if (user_can($user, 'manage_options')) {
            return $validation_error;
        }

        $verified = get_user_meta($user->ID, 'jedo_email_verified', true);

        if ($verified !== 'yes') {
            $message = get_option('jedo_message_not_verified', __('Please verify your email address before logging in.', 'jezweb-email-double-optin'));

            // Add resend link
            $resend_url = add_query_arg(array(
                'action' => 'jedo_resend_wc',
                'user_id' => $user->ID,
                'nonce' => wp_create_nonce('jedo_resend_wc_' . $user->ID)
            ), wc_get_page_permalink('myaccount'));

            $message .= ' <a href="' . esc_url($resend_url) . '">' . __('Resend verification email', 'jezweb-email-double-optin') . '</a>';

            return new WP_Error('jedo_email_not_verified', $message);
        }

        return $validation_error;
    }

    /**
     * Check checkout registration
     */
    public function check_checkout_registration() {
        // Only check if creating account during checkout
        if (!isset($_POST['createaccount']) || !$_POST['createaccount']) {
            return;
        }

        // Check if email is already registered
        $email = isset($_POST['billing_email']) ? sanitize_email($_POST['billing_email']) : '';

        if (email_exists($email)) {
            $user = get_user_by('email', $email);

            if ($user && !user_can($user, 'manage_options')) {
                $verified = get_user_meta($user->ID, 'jedo_email_verified', true);

                if ($verified !== 'yes') {
                    wc_add_notice(
                        __('This email is registered but not verified. Please verify your email or use a different email address.', 'jezweb-email-double-optin'),
                        'error'
                    );
                }
            }
        }
    }

    /**
     * Display verification notice
     */
    public function display_verification_notice() {
        // Handle resend request
        if (isset($_GET['action']) && $_GET['action'] === 'jedo_resend_wc' && isset($_GET['user_id']) && isset($_GET['nonce'])) {
            $user_id = absint($_GET['user_id']);

            if (wp_verify_nonce($_GET['nonce'], 'jedo_resend_wc_' . $user_id)) {
                $user = get_userdata($user_id);

                if ($user) {
                    JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'woocommerce');
                    wc_add_notice(get_option('jedo_message_resend_success'), 'success');
                }
            }
        }

        // Display verification success message from URL
        if (isset($_GET['jedo_verified']) && $_GET['jedo_verified'] === '1') {
            wc_add_notice(get_option('jedo_message_verification_success'), 'success');
        }
    }

    /**
     * Add registration notice
     */
    public function add_registration_notice() {
        ?>
        <p class="jedo-wc-registration-notice" style="margin-top: 15px; padding: 12px 15px; background: #f0f6fc; border-left: 4px solid #0073aa; font-size: 13px; color: #1f2937;">
            <?php esc_html_e('After registration, you will receive an email to verify your address before you can log in.', 'jezweb-email-double-optin'); ?>
        </p>
        <?php
    }

    /**
     * Block unverified checkout
     */
    public function block_unverified_checkout() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();

        // Skip check for administrators
        if (user_can($user_id, 'manage_options')) {
            return;
        }

        $verified = get_user_meta($user_id, 'jedo_email_verified', true);

        if ($verified !== 'yes') {
            // Get resend URL
            $resend_url = add_query_arg(array(
                'action' => 'jedo_resend_wc',
                'user_id' => $user_id,
                'nonce' => wp_create_nonce('jedo_resend_wc_' . $user_id)
            ), wc_get_page_permalink('myaccount'));

            wc_add_notice(
                sprintf(
                    __('Please verify your email address before checking out. <a href="%s">Resend verification email</a>', 'jezweb-email-double-optin'),
                    esc_url($resend_url)
                ),
                'error'
            );
        }
    }

    /**
     * Show verification status on my account
     */
    public function show_verification_status() {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return;
        }

        // Skip for administrators
        if (user_can($user_id, 'manage_options')) {
            return;
        }

        $verified = get_user_meta($user_id, 'jedo_email_verified', true);

        if ($verified !== 'yes') {
            $resend_url = add_query_arg(array(
                'action' => 'jedo_resend_wc',
                'user_id' => $user_id,
                'nonce' => wp_create_nonce('jedo_resend_wc_' . $user_id)
            ), wc_get_page_permalink('myaccount'));

            ?>
            <div class="woocommerce-message woocommerce-message--info jedo-verification-status" style="background: #fef3c7; border-color: #f59e0b; color: #92400e;">
                <span class="dashicons dashicons-warning" style="margin-right: 10px;"></span>
                <?php esc_html_e('Your email address is not verified.', 'jezweb-email-double-optin'); ?>
                <a href="<?php echo esc_url($resend_url); ?>" style="color: #92400e; font-weight: 600;">
                    <?php esc_html_e('Resend verification email', 'jezweb-email-double-optin'); ?>
                </a>
            </div>
            <?php
        } else {
            ?>
            <div class="woocommerce-message woocommerce-message--info jedo-verification-status" style="background: #d1fae5; border-color: #10b981; color: #065f46;">
                <span class="dashicons dashicons-yes-alt" style="margin-right: 10px;"></span>
                <?php esc_html_e('Your email address is verified.', 'jezweb-email-double-optin'); ?>
            </div>
            <?php
        }
    }

    /**
     * Handle email change
     */
    public function handle_email_change($user_id) {
        $user = get_userdata($user_id);

        if (!$user) {
            return;
        }

        // Check if email was changed
        $old_email = get_user_meta($user_id, 'jedo_last_verified_email', true);

        if ($old_email && $old_email !== $user->user_email) {
            // Email was changed, require re-verification
            update_user_meta($user_id, 'jedo_email_verified', 'no');
            update_user_meta($user_id, 'jedo_verification_pending', 'yes');

            // Send verification email
            JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'email_change');

            wc_add_notice(
                __('Your email address has been changed. Please check your inbox for a verification email.', 'jezweb-email-double-optin'),
                'notice'
            );
        }
    }
}

// Hook to update last verified email when verified
add_action('jedo_email_verified', function($user_id, $email, $type) {
    update_user_meta($user_id, 'jedo_last_verified_email', $email);
}, 10, 3);
