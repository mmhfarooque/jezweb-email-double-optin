<?php
/**
 * WooCommerce Integration
 *
 * @package Jezweb_Email_Double_Optin
 * @since 1.0.0
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
     * Order status for pending verification
     */
    const PENDING_VERIFICATION_STATUS = 'verification-pending';

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
        // Register custom order status
        add_action('init', array($this, 'register_order_status'));
        add_filter('wc_order_statuses', array($this, 'add_order_status'));

        // Registration hooks - priority 5 to run before order creation
        add_action('woocommerce_created_customer', array($this, 'handle_wc_registration'), 5, 3);

        // Login check
        add_filter('woocommerce_process_login_errors', array($this, 'check_login_verification'), 10, 3);

        // CRITICAL: Intercept checkout to create user first and block order if unverified
        // Classic checkout hook
        add_action('woocommerce_after_checkout_validation', array($this, 'intercept_checkout_for_verification'), 10, 2);

        // WooCommerce Blocks checkout hooks
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'blocks_checkout_validation'), 10, 2);
        add_action('woocommerce_blocks_checkout_update_order_from_request', array($this, 'blocks_checkout_validation'), 10, 2);

        // Prevent order processing for unverified users
        add_filter('woocommerce_order_is_pending_statuses', array($this, 'add_verification_pending_status'));

        // My account messages
        add_action('woocommerce_before_customer_login_form', array($this, 'display_verification_notice'));

        // Registration form notice
        add_action('woocommerce_register_form_end', array($this, 'add_registration_notice'));

        // Show verification notice on checkout page (classic)
        add_action('woocommerce_before_checkout_form', array($this, 'checkout_verification_notice'), 5);

        // Add verification status to my account
        add_action('woocommerce_account_dashboard', array($this, 'show_verification_status'));

        // Handle email change verification
        add_action('woocommerce_save_account_details', array($this, 'handle_email_change'), 10, 1);

        // Process order when email is verified
        add_action('jedo_email_verified', array($this, 'process_held_orders'), 10, 3);

        // Add admin notice for verification pending orders
        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'admin_order_verification_notice'));

        // Add order note when status changes
        add_action('woocommerce_order_status_changed', array($this, 'order_status_changed_note'), 10, 4);

        // Customize order status display
        add_filter('woocommerce_order_is_paid_statuses', array($this, 'exclude_from_paid_statuses'));

        // Email notification for verification pending
        add_action('woocommerce_order_status_verification-pending', array($this, 'send_verification_pending_email'), 10, 2);

        // Add refresh check script for verified status (classic checkout)
        add_action('woocommerce_after_checkout_form', array($this, 'add_verification_check_script'));

        // AJAX handler to check verification status
        add_action('wp_ajax_jedo_check_verification_status', array($this, 'ajax_check_verification_status'));
        add_action('wp_ajax_nopriv_jedo_check_verification_status', array($this, 'ajax_check_verification_status'));

        // AJAX handlers for inline email verification on checkout
        add_action('wp_ajax_jedo_request_checkout_verification', array($this, 'ajax_request_checkout_verification'));
        add_action('wp_ajax_nopriv_jedo_request_checkout_verification', array($this, 'ajax_request_checkout_verification'));
        add_action('wp_ajax_jedo_check_email_verified', array($this, 'ajax_check_email_verified'));
        add_action('wp_ajax_nopriv_jedo_check_email_verified', array($this, 'ajax_check_email_verified'));

        // AJAX handler for OTP verification on checkout
        add_action('wp_ajax_jedo_verify_checkout_otp', array($this, 'ajax_verify_checkout_otp'));
        add_action('wp_ajax_nopriv_jedo_verify_checkout_otp', array($this, 'ajax_verify_checkout_otp'));

        // WooCommerce Blocks integration
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_integration'));

        // Add inline email verification script to checkout
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_email_verification_script'));

        // Handle checkout email verification link
        add_action('init', array($this, 'handle_checkout_email_verification'));

        // Move email field to first position in checkout
        add_filter('woocommerce_checkout_fields', array($this, 'reorder_checkout_fields'), 9999);
        add_filter('woocommerce_billing_fields', array($this, 'reorder_billing_fields'), 9999);
    }

    /**
     * Reorder checkout fields to put email first
     *
     * @param array $fields Checkout fields.
     * @return array
     */
    public function reorder_checkout_fields($fields) {
        if (isset($fields['billing']['billing_email'])) {
            // Set email to be first with priority 1
            $fields['billing']['billing_email']['priority'] = 1;

            // Ensure other fields come after
            if (isset($fields['billing']['billing_first_name'])) {
                $fields['billing']['billing_first_name']['priority'] = 10;
            }
            if (isset($fields['billing']['billing_last_name'])) {
                $fields['billing']['billing_last_name']['priority'] = 20;
            }
            if (isset($fields['billing']['billing_company'])) {
                $fields['billing']['billing_company']['priority'] = 30;
            }
            if (isset($fields['billing']['billing_country'])) {
                $fields['billing']['billing_country']['priority'] = 40;
            }
            if (isset($fields['billing']['billing_address_1'])) {
                $fields['billing']['billing_address_1']['priority'] = 50;
            }
            if (isset($fields['billing']['billing_address_2'])) {
                $fields['billing']['billing_address_2']['priority'] = 60;
            }
            if (isset($fields['billing']['billing_city'])) {
                $fields['billing']['billing_city']['priority'] = 70;
            }
            if (isset($fields['billing']['billing_state'])) {
                $fields['billing']['billing_state']['priority'] = 80;
            }
            if (isset($fields['billing']['billing_postcode'])) {
                $fields['billing']['billing_postcode']['priority'] = 90;
            }
            if (isset($fields['billing']['billing_phone'])) {
                $fields['billing']['billing_phone']['priority'] = 100;
            }
        }

        return $fields;
    }

    /**
     * Reorder billing fields specifically
     *
     * @param array $fields Billing fields.
     * @return array
     */
    public function reorder_billing_fields($fields) {
        if (isset($fields['billing_email'])) {
            $fields['billing_email']['priority'] = 1;
        }
        return $fields;
    }

    /**
     * Handle checkout email verification link
     */
    public function handle_checkout_email_verification() {
        if (!isset($_GET['jedo_checkout_verify']) || $_GET['jedo_checkout_verify'] !== '1') {
            return;
        }

        $email = isset($_GET['email']) ? sanitize_email(urldecode($_GET['email'])) : '';
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        if (empty($email) || empty($token)) {
            wp_die(__('Invalid verification link.', 'jezweb-email-double-optin'));
            return;
        }

        // Check pending verification transient
        $transient_key = 'jedo_pending_checkout_' . md5($email);
        $pending_data = get_transient($transient_key);

        if (!$pending_data || !hash_equals($pending_data['token'], $token)) {
            wp_die(__('Invalid or expired verification link.', 'jezweb-email-double-optin'));
            return;
        }

        // Mark as verified
        $pending_data['verified'] = true;
        set_transient($transient_key, $pending_data, HOUR_IN_SECONDS);

        // Show a simple "verified" page instead of redirecting to checkout
        // This prevents having two checkout tabs open
        $this->display_verification_success_page($email);
        exit;
    }

    /**
     * Display a simple verification success page
     * User can close this tab and continue in the original checkout tab
     *
     * @param string $email The verified email address.
     */
    private function display_verification_success_page($email) {
        $site_name = get_bloginfo('name');
        $checkout_url = wc_get_checkout_url();

        // Get theme colors
        $button_color = get_option('jedo_email_button_color', '#0073aa');

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('Email Verified', 'jezweb-email-double-optin') . ' - ' . esc_html($site_name); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                .verification-container {
                    background: #ffffff;
                    border-radius: 16px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    padding: 50px 40px;
                    text-align: center;
                    max-width: 450px;
                    width: 100%;
                    animation: slideUp 0.5s ease-out;
                }
                @keyframes slideUp {
                    from {
                        opacity: 0;
                        transform: translateY(30px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                .success-icon {
                    width: 80px;
                    height: 80px;
                    background: #d4edda;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 25px;
                    animation: scaleIn 0.5s ease-out 0.2s both;
                }
                @keyframes scaleIn {
                    from {
                        transform: scale(0);
                    }
                    to {
                        transform: scale(1);
                    }
                }
                .success-icon svg {
                    width: 40px;
                    height: 40px;
                    color: #28a745;
                }
                .checkmark {
                    stroke: #28a745;
                    stroke-width: 3;
                    stroke-linecap: round;
                    stroke-linejoin: round;
                    fill: none;
                    animation: checkmark 0.5s ease-out 0.5s both;
                }
                @keyframes checkmark {
                    from {
                        stroke-dasharray: 100;
                        stroke-dashoffset: 100;
                    }
                    to {
                        stroke-dashoffset: 0;
                    }
                }
                h1 {
                    color: #28a745;
                    font-size: 28px;
                    margin-bottom: 15px;
                    font-weight: 600;
                }
                .email-text {
                    color: #666;
                    font-size: 16px;
                    margin-bottom: 10px;
                }
                .email-address {
                    color: #333;
                    font-weight: 600;
                    font-size: 18px;
                    margin-bottom: 25px;
                    word-break: break-all;
                }
                .instruction-box {
                    background: #f8f9fa;
                    border-radius: 10px;
                    padding: 20px;
                    margin-bottom: 25px;
                }
                .instruction-box p {
                    color: #555;
                    font-size: 15px;
                    line-height: 1.6;
                    margin: 0;
                }
                .instruction-box strong {
                    color: #333;
                }
                .close-hint {
                    background: #e3f2fd;
                    border: 1px solid #90caf9;
                    border-radius: 8px;
                    padding: 15px;
                    margin-bottom: 25px;
                }
                .close-hint p {
                    color: #1565c0;
                    font-size: 14px;
                    margin: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                }
                .btn-close-tab {
                    display: inline-block;
                    background: <?php echo esc_attr($button_color); ?>;
                    color: #ffffff;
                    padding: 14px 35px;
                    font-size: 16px;
                    font-weight: 600;
                    text-decoration: none;
                    border-radius: 8px;
                    border: none;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    margin-right: 10px;
                }
                .btn-close-tab:hover {
                    opacity: 0.9;
                    transform: translateY(-2px);
                    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
                }
                .btn-secondary {
                    display: inline-block;
                    background: transparent;
                    color: #666;
                    padding: 14px 25px;
                    font-size: 14px;
                    text-decoration: none;
                    border-radius: 8px;
                    border: 1px solid #ddd;
                    transition: all 0.3s ease;
                }
                .btn-secondary:hover {
                    background: #f5f5f5;
                    color: #333;
                }
                .site-name {
                    color: #999;
                    font-size: 13px;
                    margin-top: 30px;
                }
                .buttons-row {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                    flex-wrap: wrap;
                }
            </style>
        </head>
        <body>
            <div class="verification-container">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24">
                        <path class="checkmark" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>

                <h1><?php esc_html_e('Email Verified!', 'jezweb-email-double-optin'); ?></h1>

                <p class="email-text"><?php esc_html_e('Successfully verified:', 'jezweb-email-double-optin'); ?></p>
                <p class="email-address"><?php echo esc_html($email); ?></p>

                <div class="instruction-box">
                    <p>
                        <?php esc_html_e('Your email has been verified successfully. You can now complete your order.', 'jezweb-email-double-optin'); ?>
                    </p>
                </div>

                <div class="close-hint">
                    <p>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <strong><?php esc_html_e('Go back to your checkout tab', 'jezweb-email-double-optin'); ?></strong> - <?php esc_html_e('it will automatically update.', 'jezweb-email-double-optin'); ?>
                    </p>
                </div>

                <div class="buttons-row">
                    <button type="button" class="btn-close-tab" onclick="window.close();">
                        <?php esc_html_e('Close This Tab', 'jezweb-email-double-optin'); ?>
                    </button>
                    <a href="<?php echo esc_url($checkout_url); ?>" class="btn-secondary">
                        <?php esc_html_e('Go to Checkout', 'jezweb-email-double-optin'); ?>
                    </a>
                </div>

                <p class="site-name"><?php echo esc_html($site_name); ?></p>
            </div>

            <script>
                // Try to close the tab after a delay if opened via JavaScript
                // This won't work if the tab wasn't opened by JS, but we provide the button as fallback
                setTimeout(function() {
                    // Focus the original window if possible
                    if (window.opener && !window.opener.closed) {
                        window.opener.focus();
                    }
                }, 1000);
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Register custom order status
     */
    public function register_order_status() {
        register_post_status('wc-' . self::PENDING_VERIFICATION_STATUS, array(
            'label' => _x('Verification Pending', 'Order status', 'jezweb-email-double-optin'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count' => _n_noop(
                'Verification Pending <span class="count">(%s)</span>',
                'Verification Pending <span class="count">(%s)</span>',
                'jezweb-email-double-optin'
            ),
        ));
    }

    /**
     * Add order status to WooCommerce
     *
     * @param array $statuses Order statuses.
     * @return array Modified statuses.
     */
    public function add_order_status($statuses) {
        $new_statuses = array();

        foreach ($statuses as $key => $status) {
            $new_statuses[$key] = $status;
            if ('wc-pending' === $key) {
                $new_statuses['wc-' . self::PENDING_VERIFICATION_STATUS] = _x('Verification Pending', 'Order status', 'jezweb-email-double-optin');
            }
        }

        return $new_statuses;
    }

    /**
     * Add verification pending to pending statuses
     *
     * @param array $statuses Pending statuses.
     * @return array Modified statuses.
     */
    public function add_verification_pending_status($statuses) {
        $statuses[] = self::PENDING_VERIFICATION_STATUS;
        return $statuses;
    }

    /**
     * Exclude from paid statuses
     *
     * @param array $statuses Paid statuses.
     * @return array Modified statuses.
     */
    public function exclude_from_paid_statuses($statuses) {
        return array_diff($statuses, array(self::PENDING_VERIFICATION_STATUS));
    }

    /**
     * Handle WooCommerce registration
     *
     * @param int   $customer_id      Customer ID.
     * @param array $new_customer_data Customer data.
     * @param bool  $password_generated Whether password was generated.
     */
    public function handle_wc_registration($customer_id, $new_customer_data, $password_generated) {
        // Check if already handled by checkout interception or WordPress registration
        $verification_pending = get_user_meta($customer_id, 'jedo_verification_pending', true);
        $checkout_pending = get_user_meta($customer_id, 'jedo_checkout_pending', true);

        if ($verification_pending === 'yes' || $checkout_pending === 'yes') {
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

        // Add notice for the user (only if not during checkout)
        if (!is_checkout()) {
            wc_add_notice(get_option('jedo_message_verification_sent'), 'notice');
        }
    }

    /**
     * Check login verification
     *
     * @param WP_Error $validation_error Validation error.
     * @param string   $username         Username.
     * @param string   $password         Password.
     * @return WP_Error Modified error.
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
     * Intercept checkout to handle verification before order creation
     * This runs AFTER validation but BEFORE order creation
     *
     * @param array    $data   Posted data.
     * @param WP_Error $errors Validation errors.
     */
    public function intercept_checkout_for_verification($data, $errors) {
        // If there are already errors, don't add more
        if ($errors->get_error_codes()) {
            return;
        }

        // Check if user is creating an account during checkout
        $creating_account = !empty($data['createaccount']) ||
                           (!is_user_logged_in() && 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout') && !empty($data['account_password']));

        // For logged-in users, check verification status
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();

            // Skip administrators
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

                $errors->add(
                    'jedo_email_not_verified',
                    sprintf(
                        /* translators: %s: resend link */
                        __('<strong>Email Verification Required:</strong> You must verify your email address before placing an order. Please check your inbox and click the verification link. <a href="%s">Resend verification email</a>', 'jezweb-email-double-optin'),
                        esc_url($resend_url)
                    )
                );
                return;
            }
        }

        // For guests creating an account, we need to create the user first, then block
        if ($creating_account && !is_user_logged_in()) {
            // Check if email already exists
            $email = sanitize_email($data['billing_email']);

            if (email_exists($email)) {
                // User exists, they should log in
                return; // WooCommerce will handle this error
            }

            // Create the user account early
            $username = '';
            if ('yes' === get_option('woocommerce_registration_generate_username') || empty($data['account_username'])) {
                $username = wc_create_new_customer_username($email, array(
                    'first_name' => isset($data['billing_first_name']) ? $data['billing_first_name'] : '',
                    'last_name'  => isset($data['billing_last_name']) ? $data['billing_last_name'] : '',
                ));
            } else {
                $username = sanitize_user($data['account_username']);
            }

            $password = '';
            if ('yes' === get_option('woocommerce_registration_generate_password')) {
                $password = wp_generate_password();
            } elseif (!empty($data['account_password'])) {
                $password = $data['account_password'];
            }

            // Create the customer
            $customer_id = wc_create_new_customer($email, $username, $password, array(
                'first_name' => isset($data['billing_first_name']) ? $data['billing_first_name'] : '',
                'last_name'  => isset($data['billing_last_name']) ? $data['billing_last_name'] : '',
            ));

            if (is_wp_error($customer_id)) {
                $errors->add('registration-error', $customer_id->get_error_message());
                return;
            }

            // Mark user as unverified (the woocommerce_created_customer hook will send verification email)
            update_user_meta($customer_id, 'jedo_email_verified', 'no');
            update_user_meta($customer_id, 'jedo_verification_pending', 'yes');
            update_user_meta($customer_id, 'jedo_checkout_pending', 'yes'); // Flag that they tried to checkout

            // Send verification email
            JEDO_Email::get_instance()->send_verification_email($customer_id, $email, 'checkout');

            // Log the user in so they can see their verification status
            wc_set_customer_auth_cookie($customer_id);

            // Store cart in session for when they return after verification
            WC()->session->set('jedo_pending_checkout_user', $customer_id);

            // Add error to block order creation but keep user on checkout
            $errors->add(
                'jedo_verification_required',
                sprintf(
                    '<strong>%s</strong><br><br>%s<br><br>%s<br><br><em>%s</em>',
                    __('Account Created - Email Verification Required!', 'jezweb-email-double-optin'),
                    __('Your account has been created. A verification email has been sent to your email address.', 'jezweb-email-double-optin'),
                    __('Please check your inbox (and spam folder), click the verification link, then return to this page to complete your order.', 'jezweb-email-double-optin'),
                    __('This page will automatically detect when you verify your email.', 'jezweb-email-double-optin')
                )
            );
        }
    }

    /**
     * Show verification notice at top of checkout page
     */
    public function checkout_verification_notice() {
        // For logged-in unverified users
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();

            // Skip administrators
            if (user_can($user_id, 'manage_options')) {
                return;
            }

            $verified = get_user_meta($user_id, 'jedo_email_verified', true);

            if ($verified !== 'yes') {
                $user = get_userdata($user_id);
                $resend_url = add_query_arg(array(
                    'action' => 'jedo_resend_checkout',
                    'user_id' => $user_id,
                    'nonce' => wp_create_nonce('jedo_resend_checkout_' . $user_id)
                ), wc_get_checkout_url());

                // Handle resend request
                if (isset($_GET['action']) && $_GET['action'] === 'jedo_resend_checkout' && isset($_GET['nonce'])) {
                    if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['nonce'])), 'jedo_resend_checkout_' . $user_id)) {
                        JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'checkout_resend');
                        wc_add_notice(__('Verification email has been resent. Please check your inbox.', 'jezweb-email-double-optin'), 'success');
                    }
                }

                ?>
                <div class="woocommerce-info jedo-verification-required" style="background: #fff3cd; border-color: #ffc107; color: #856404; margin-bottom: 20px;">
                    <h4 style="margin-top: 0; color: #856404;">
                        <span class="dashicons dashicons-warning" style="margin-right: 5px;"></span>
                        <?php esc_html_e('Email Verification Required', 'jezweb-email-double-optin'); ?>
                    </h4>
                    <p>
                        <?php esc_html_e('You must verify your email address before placing an order.', 'jezweb-email-double-optin'); ?>
                        <?php esc_html_e('Please check your inbox for the verification email and click the link.', 'jezweb-email-double-optin'); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url($resend_url); ?>" class="button" style="background: #856404; color: #fff; border: none;">
                            <?php esc_html_e('Resend Verification Email', 'jezweb-email-double-optin'); ?>
                        </a>
                    </p>
                    <p><small><?php esc_html_e('This page will automatically update when you verify your email.', 'jezweb-email-double-optin'); ?></small></p>
                </div>
                <?php
            }
        }
    }

    /**
     * Add JavaScript to check verification status and refresh
     */
    public function add_verification_check_script() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();

        // Skip if admin or already verified
        if (user_can($user_id, 'manage_options')) {
            return;
        }

        $verified = get_user_meta($user_id, 'jedo_email_verified', true);

        if ($verified === 'yes') {
            return;
        }

        ?>
        <script type="text/javascript">
        (function() {
            var checkInterval = setInterval(function() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success && response.data.verified) {
                                clearInterval(checkInterval);
                                // Show success message and refresh
                                var notice = document.querySelector('.jedo-verification-required');
                                if (notice) {
                                    notice.innerHTML = '<p style="color: #155724; background: #d4edda; padding: 15px; border-left: 4px solid #28a745;"><strong>✓ Email Verified!</strong> Refreshing page...</p>';
                                }
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                            }
                        } catch (e) {}
                    }
                };
                xhr.send('action=jedo_check_verification_status&user_id=<?php echo esc_js($user_id); ?>&nonce=<?php echo esc_js(wp_create_nonce('jedo_check_verification')); ?>');
            }, 5000); // Check every 5 seconds
        })();
        </script>
        <?php
    }

    /**
     * AJAX handler to check verification status
     */
    public function ajax_check_verification_status() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'jedo_check_verification')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : get_current_user_id();

        if (!$user_id) {
            wp_send_json_error(array('message' => 'No user'));
            return;
        }

        $verified = get_user_meta($user_id, 'jedo_email_verified', true);

        wp_send_json_success(array(
            'verified' => ($verified === 'yes'),
            'user_id' => $user_id
        ));
    }

    /**
     * Display verification notice on my account page
     */
    public function display_verification_notice() {
        // Handle resend request
        if (isset($_GET['action']) && $_GET['action'] === 'jedo_resend_wc' && isset($_GET['user_id']) && isset($_GET['nonce'])) {
            $user_id = absint($_GET['user_id']);
            $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));

            if (wp_verify_nonce($nonce, 'jedo_resend_wc_' . $user_id)) {
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
        <p class="jedo-wc-registration-notice" style="margin-top: 15px; padding: 12px 15px; background: #fff3cd; border-left: 4px solid #ffc107; font-size: 13px; color: #856404;">
            <strong><?php esc_html_e('Important:', 'jezweb-email-double-optin'); ?></strong>
            <?php esc_html_e('After registration, you must verify your email address before you can place orders or access your account.', 'jezweb-email-double-optin'); ?>
        </p>
        <?php
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
            <div class="woocommerce-message woocommerce-message--error" style="background: #f8d7da; border-color: #f5c6cb; color: #721c24; margin-bottom: 20px;">
                <strong><?php esc_html_e('Action Required:', 'jezweb-email-double-optin'); ?></strong>
                <?php esc_html_e('Your email address is not verified. You cannot place orders until you verify your email.', 'jezweb-email-double-optin'); ?>
                <br><br>
                <a href="<?php echo esc_url($resend_url); ?>" class="button" style="background: #721c24; color: #fff; border: none;">
                    <?php esc_html_e('Resend Verification Email', 'jezweb-email-double-optin'); ?>
                </a>
            </div>
            <?php

            // Show pending orders
            $pending_orders = get_user_meta($user_id, 'jedo_pending_orders', true);
            if (!empty($pending_orders) && is_array($pending_orders)) {
                ?>
                <div class="woocommerce-message woocommerce-message--info" style="background: #fff3cd; border-color: #ffc107; color: #856404; margin-bottom: 20px;">
                    <strong><?php esc_html_e('Pending Orders:', 'jezweb-email-double-optin'); ?></strong>
                    <?php
                    printf(
                        /* translators: %d: number of orders */
                        esc_html(_n(
                            'You have %d order waiting for email verification. It will be processed after you verify your email.',
                            'You have %d orders waiting for email verification. They will be processed after you verify your email.',
                            count($pending_orders),
                            'jezweb-email-double-optin'
                        )),
                        count($pending_orders)
                    );
                    ?>
                </div>
                <?php
            }
        } else {
            ?>
            <div class="woocommerce-message woocommerce-message--info" style="background: #d1fae5; border-color: #10b981; color: #065f46; margin-bottom: 20px;">
                <span class="dashicons dashicons-yes-alt" style="margin-right: 10px;"></span>
                <?php esc_html_e('Your email address is verified.', 'jezweb-email-double-optin'); ?>
            </div>
            <?php
        }
    }

    /**
     * Handle email change
     *
     * @param int $user_id User ID.
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
                __('Your email address has been changed. Please check your inbox for a verification email. You cannot place orders until verified.', 'jezweb-email-double-optin'),
                'notice'
            );
        }
    }

    /**
     * Process when email is verified - clear checkout pending flag
     *
     * @param int    $user_id User ID.
     * @param string $email   Email address.
     * @param string $type    Verification type.
     */
    public function process_held_orders($user_id, $email, $type) {
        // Update last verified email
        update_user_meta($user_id, 'jedo_last_verified_email', $email);

        // Clear checkout pending flag so user can complete order
        delete_user_meta($user_id, 'jedo_checkout_pending');

        // Process any legacy held orders (from older version or manual status changes)
        $pending_orders = get_user_meta($user_id, 'jedo_pending_orders', true);

        if (empty($pending_orders) || !is_array($pending_orders)) {
            return;
        }

        foreach ($pending_orders as $order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                continue;
            }

            // Only process if still in verification pending status
            if ($order->get_status() === self::PENDING_VERIFICATION_STATUS) {
                // Add order note
                $order->add_order_note(
                    __('Customer email verified. Order released from verification hold.', 'jezweb-email-double-optin')
                );

                // Set to pending payment so normal WooCommerce flow continues
                $order->set_status('pending', __('Email verified - order ready for payment/processing.', 'jezweb-email-double-optin'));
                $order->save();

                // Trigger payment processing if needed
                do_action('woocommerce_order_status_pending', $order_id, $order);
            }
        }

        // Clear the pending orders meta
        delete_user_meta($user_id, 'jedo_pending_orders');
    }

    /**
     * Admin order verification notice
     *
     * @param WC_Order $order Order object.
     */
    public function admin_order_verification_notice($order) {
        if ($order->get_status() !== self::PENDING_VERIFICATION_STATUS) {
            return;
        }

        $user_id = $order->get_customer_id();
        $verified = $user_id ? get_user_meta($user_id, 'jedo_email_verified', true) : 'no';
        ?>
        <div class="notice notice-warning inline" style="margin: 10px 0;">
            <p>
                <strong><?php esc_html_e('Email Verification Required', 'jezweb-email-double-optin'); ?></strong><br>
                <?php esc_html_e('This order is on hold because the customer has not verified their email address.', 'jezweb-email-double-optin'); ?>
                <br>
                <?php
                printf(
                    /* translators: %s: verification status */
                    esc_html__('Customer verification status: %s', 'jezweb-email-double-optin'),
                    $verified === 'yes'
                        ? '<span style="color: green;">' . esc_html__('Verified', 'jezweb-email-double-optin') . '</span>'
                        : '<span style="color: red;">' . esc_html__('Not Verified', 'jezweb-email-double-optin') . '</span>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Order status changed note
     *
     * @param int      $order_id   Order ID.
     * @param string   $old_status Old status.
     * @param string   $new_status New status.
     * @param WC_Order $order      Order object.
     */
    public function order_status_changed_note($order_id, $old_status, $new_status, $order) {
        if ($new_status === self::PENDING_VERIFICATION_STATUS) {
            // Send email to customer about verification requirement
            $this->send_verification_pending_email($order_id, $order);
        }
    }

    /**
     * Thank you page verification message
     *
     * @param int $order_id Order ID.
     */
    public function thankyou_verification_message($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        if ($order->get_status() === self::PENDING_VERIFICATION_STATUS) {
            ?>
            <div class="woocommerce-message woocommerce-message--warning" style="background: #fff3cd; border-color: #ffc107; color: #856404; padding: 15px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
                <h3 style="margin-top: 0; color: #856404;"><?php esc_html_e('Email Verification Required', 'jezweb-email-double-optin'); ?></h3>
                <p>
                    <?php esc_html_e('Your order has been received but is currently on hold.', 'jezweb-email-double-optin'); ?>
                    <strong><?php esc_html_e('Please verify your email address to complete your order.', 'jezweb-email-double-optin'); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('Check your inbox for the verification email and click the verification link. Once verified, your order will be processed automatically.', 'jezweb-email-double-optin'); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Send verification pending email
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object.
     */
    public function send_verification_pending_email($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return;
        }

        // Send a new verification email
        $user = get_userdata($user_id);
        if ($user) {
            JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'order_verification');
        }
    }

    /**
     * WooCommerce Blocks checkout validation
     * This runs when order is being processed through the Store API (Blocks checkout)
     *
     * @param WC_Order        $order   The order being processed.
     * @param WP_REST_Request $request The REST request.
     */
    public function blocks_checkout_validation($order, $request) {
        // Get billing email from order
        $billing_email = $order->get_billing_email();

        // First check if this email was verified via checkout verification (transient)
        if ($billing_email) {
            $pending_data = get_transient('jedo_pending_checkout_' . md5($billing_email));
            if ($pending_data && isset($pending_data['verified']) && $pending_data['verified']) {
                // Email is verified via checkout flow - allow order
                return;
            }
        }

        // Get user ID from order
        $user_id = $order->get_customer_id();

        // If no user, check if email was verified
        if (!$user_id) {
            if (!$billing_email) {
                return; // No email to check
            }

            // Check if email exists as a user
            $existing_user = get_user_by('email', $billing_email);
            if ($existing_user) {
                $verified = get_user_meta($existing_user->ID, 'jedo_email_verified', true);
                if ($verified === 'yes') {
                    return; // Verified user
                }
            }

            // Email not verified - block order
            $this->throw_blocks_checkout_error(
                __('Email verification required. Please verify your email address before placing an order. Enter your email above and click "Send Verification Email".', 'jezweb-email-double-optin')
            );
            return;
        }

        // Skip administrators
        if (user_can($user_id, 'manage_options')) {
            return;
        }

        $verified = get_user_meta($user_id, 'jedo_email_verified', true);

        if ($verified !== 'yes') {
            // User is not verified - throw exception to block order
            $this->throw_blocks_checkout_error(
                __('Email verification required. Please verify your email address before placing an order. Check your inbox for the verification email.', 'jezweb-email-double-optin')
            );
        }
    }

    /**
     * Throw an error for blocks checkout
     *
     * @param string $message Error message.
     */
    private function throw_blocks_checkout_error($message) {
        // Check if RouteException class exists (WooCommerce Blocks)
        if (class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                'jedo_email_verification_required',
                $message,
                400
            );
        }

        // Fallback for older versions
        throw new \Exception($message);
    }

    /**
     * Register WooCommerce Blocks integration
     */
    public function register_blocks_integration() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
            return;
        }

        // Register the integration
        add_action(
            'woocommerce_blocks_checkout_block_registration',
            function($integration_registry) {
                $integration_registry->register(new JEDO_Blocks_Integration());
            }
        );
    }

    /**
     * Enqueue inline email verification script for checkout
     * This watches the email field and triggers verification when email is entered
     */
    public function enqueue_checkout_email_verification_script() {
        // Only on checkout page
        if (!is_checkout()) {
            return;
        }

        // Add the inline styles
        wp_add_inline_style('wp-block-library', $this->get_email_verification_styles());

        // Add the JavaScript
        wp_enqueue_script('jquery');
        add_action('wp_footer', array($this, 'output_email_verification_script'), 99);
    }

    /**
     * Output the email verification JavaScript in footer
     */
    public function output_email_verification_script() {
        if (!is_checkout()) {
            return;
        }
        ?>
        <script type="text/javascript">
        (function() {
            'use strict';

            var jedoVerification = {
                ajaxUrl: '<?php echo esc_js(admin_url('admin-ajax.php')); ?>',
                nonce: '<?php echo esc_js(wp_create_nonce('jedo_checkout_verification')); ?>',
                verifiedEmails: {},
                currentEmail: '',
                checkInterval: null,
                verificationToken: '',
                isOtpMode: <?php echo wp_json_encode(JEDO_Verification::is_otp_enabled()); ?>,
                otpLength: <?php echo wp_json_encode(JEDO_Verification::get_otp_length()); ?>,
                otpType: <?php echo wp_json_encode(JEDO_Verification::get_otp_type()); ?>,
                initialized: false,
                mutationObserver: null,
                retryCount: 0,
                maxRetries: 60,
                fieldsDisabled: false,

                init: function() {
                    var self = this;

                    // Prevent double initialization
                    if (self.initialized) {
                        return;
                    }

                    // Wait for checkout to load
                    self.waitForEmailField();

                    // Setup MutationObserver for dynamically loaded content (WPBakery, Be Builder, AJAX)
                    self.setupMutationObserver();

                    // Listen for common AJAX events from page builders
                    self.setupAjaxListeners();
                },

                setupMutationObserver: function() {
                    var self = this;

                    if (self.mutationObserver) {
                        return;
                    }

                    self.mutationObserver = new MutationObserver(function(mutations) {
                        mutations.forEach(function(mutation) {
                            if (mutation.addedNodes.length > 0) {
                                // Check if email field was added
                                var emailField = self.findEmailField();
                                if (emailField && !self.initialized) {
                                    self.attachEmailListener(emailField);
                                }
                            }
                        });
                    });

                    // Observe the entire body for changes
                    self.mutationObserver.observe(document.body, {
                        childList: true,
                        subtree: true
                    });
                },

                setupAjaxListeners: function() {
                    var self = this;

                    // WooCommerce AJAX events
                    if (typeof jQuery !== 'undefined') {
                        jQuery(document.body).on('updated_checkout', function() {
                            self.reinitialize();
                        });

                        jQuery(document.body).on('init_checkout', function() {
                            self.reinitialize();
                        });

                        // WPBakery/Visual Composer AJAX complete
                        jQuery(document).on('vc-full-width-row-single', function() {
                            self.reinitialize();
                        });

                        // Be Builder/BeTheme AJAX events
                        jQuery(document).on('mfn-builder-init mfn-scripts-loaded', function() {
                            self.reinitialize();
                        });

                        // Generic AJAX complete handler for page builders
                        jQuery(document).ajaxComplete(function(event, xhr, settings) {
                            // Check if this is a checkout-related AJAX call
                            if (settings.url && (
                                settings.url.indexOf('wc-ajax') !== -1 ||
                                settings.url.indexOf('checkout') !== -1 ||
                                settings.url.indexOf('mfn') !== -1 ||
                                settings.url.indexOf('vc_') !== -1 ||
                                settings.url.indexOf('wpb_') !== -1
                            )) {
                                setTimeout(function() {
                                    self.reinitialize();
                                }, 100);
                            }
                        });
                    }
                },

                reinitialize: function() {
                    var self = this;

                    // Reset state
                    self.initialized = false;
                    self.retryCount = 0;

                    // Try to find and attach to email field
                    var emailField = self.findEmailField();
                    if (emailField) {
                        self.attachEmailListener(emailField);
                    }
                },

                findEmailField: function() {
                    // Extended selectors for WPBakery, Be Builder, and other page builders
                    var selectors = [
                        // Standard WooCommerce
                        '#billing_email',
                        '#email',
                        'input[name="billing_email"]',
                        'input[name="email"]',
                        // WooCommerce Blocks
                        '.wc-block-components-text-input input[type="email"]',
                        '.wc-block-checkout__email input[type="email"]',
                        // Generic email inputs
                        'input[autocomplete="email"]',
                        'input[type="email"]',
                        // WPBakery/Visual Composer
                        '.vc_row input[type="email"]',
                        '.wpb_column input[type="email"]',
                        '.vc-checkout-form input[type="email"]',
                        // Be Builder/BeTheme
                        '.mfn-checkout input[type="email"]',
                        '.mfn-builder-content input[type="email"]',
                        '.mcb-section input[type="email"]',
                        '.mcb-wrap input[type="email"]',
                        // Elementor (bonus compatibility)
                        '.elementor-widget-woocommerce-checkout-page input[type="email"]',
                        '.elementor-checkout input[type="email"]',
                        // Fallback patterns
                        'input[id*="email"]:not([type="hidden"])',
                        'input[name*="email"]:not([type="hidden"])',
                        '.woocommerce-checkout input[type="email"]',
                        'form.checkout input[type="email"]'
                    ];

                    for (var i = 0; i < selectors.length; i++) {
                        var field = document.querySelector(selectors[i]);
                        if (field && self.isVisibleField(field)) {
                            return field;
                        }
                    }

                    return null;
                },

                isVisibleField: function(field) {
                    // Check if field is visible and usable
                    if (!field) return false;
                    var rect = field.getBoundingClientRect();
                    var style = window.getComputedStyle(field);
                    return rect.width > 0 &&
                           rect.height > 0 &&
                           style.display !== 'none' &&
                           style.visibility !== 'hidden' &&
                           field.type !== 'hidden';
                },

                waitForEmailField: function() {
                    var self = this;

                    var emailField = self.findEmailField();

                    if (!emailField) {
                        self.retryCount++;
                        if (self.retryCount < self.maxRetries) {
                            setTimeout(function() { self.waitForEmailField(); }, 500);
                        }
                        return;
                    }

                    self.attachEmailListener(emailField);
                },

                attachEmailListener: function(emailField) {
                    var self = this;

                    // Prevent attaching multiple listeners
                    if (emailField.hasAttribute('data-jedo-attached')) {
                        return;
                    }
                    emailField.setAttribute('data-jedo-attached', 'true');

                    // Create verification container
                    self.createVerificationContainer(emailField);

                    // Listen for email changes
                    emailField.addEventListener('blur', function() {
                        self.handleEmailChange(this.value);
                    });

                    emailField.addEventListener('change', function() {
                        self.handleEmailChange(this.value);
                    });

                    // Also listen to input for real-time validation on some builders
                    emailField.addEventListener('input', self.debounce(function() {
                        if (self.isValidEmail(this.value)) {
                            self.handleEmailChange(this.value);
                        }
                    }, 1000));

                    // Check if email already has value
                    if (emailField.value) {
                        self.handleEmailChange(emailField.value);
                    }

                    self.initialized = true;
                },

                debounce: function(func, wait) {
                    var timeout;
                    return function() {
                        var context = this, args = arguments;
                        clearTimeout(timeout);
                        timeout = setTimeout(function() {
                            func.apply(context, args);
                        }, wait);
                    };
                },

                createVerificationContainer: function(emailField) {
                    // Check if container already exists
                    if (document.getElementById('jedo-email-verification-container')) {
                        return;
                    }

                    // Find the best parent container (extended for page builders)
                    var parent = emailField.closest('.wc-block-components-text-input') ||
                                emailField.closest('.wc-block-components-address-form__email') ||
                                emailField.closest('.form-row') ||
                                emailField.closest('.woocommerce-billing-fields__field-wrapper') ||
                                // WPBakery/Visual Composer
                                emailField.closest('.vc_column-inner') ||
                                emailField.closest('.wpb_wrapper') ||
                                emailField.closest('.vc_column_container') ||
                                // Be Builder/BeTheme
                                emailField.closest('.mcb-column-inner') ||
                                emailField.closest('.mcb-wrap-inner') ||
                                emailField.closest('.mfn-form-row') ||
                                // Elementor
                                emailField.closest('.elementor-field-group') ||
                                emailField.closest('.elementor-widget-container') ||
                                // Generic fallbacks
                                emailField.closest('.form-group') ||
                                emailField.closest('.field-wrapper') ||
                                emailField.closest('p') ||
                                emailField.parentNode;

                    var container = document.createElement('div');
                    container.id = 'jedo-email-verification-container';
                    container.className = 'jedo-email-verification-container';
                    container.style.display = 'none';

                    // Insert after the parent element
                    if (parent && parent.parentNode) {
                        parent.parentNode.insertBefore(container, parent.nextSibling);
                    } else {
                        // Fallback: insert after the email field itself
                        emailField.parentNode.insertBefore(container, emailField.nextSibling);
                    }
                },

                handleEmailChange: function(email) {
                    var self = this;

                    if (!self.isValidEmail(email)) {
                        self.hideVerificationNotice();
                        return;
                    }

                    // If email is same as current and already being processed, skip
                    if (email === self.currentEmail) {
                        return;
                    }

                    self.currentEmail = email;

                    // Check if this email is already verified in this session
                    if (self.verifiedEmails[email]) {
                        self.showVerifiedState();
                        return;
                    }

                    // Check email verification status
                    self.checkEmailStatus(email);
                },

                isValidEmail: function(email) {
                    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                },

                disableCheckoutFields: function() {
                    var self = this;
                    if (self.fieldsDisabled) return;

                    // Find all checkout form inputs except email
                    var checkoutForm = document.querySelector('form.checkout, form.woocommerce-checkout, .wc-block-checkout');
                    if (!checkoutForm) return;

                    var emailField = self.findEmailField();
                    var inputs = checkoutForm.querySelectorAll('input:not([type="hidden"]), select, textarea, button[type="submit"]');

                    inputs.forEach(function(input) {
                        // Skip the email field itself
                        if (input === emailField) return;
                        // Skip verification-related elements
                        if (input.closest('#jedo-email-verification-container')) return;

                        input.setAttribute('data-jedo-original-disabled', input.disabled ? 'true' : 'false');
                        input.disabled = true;
                        input.style.opacity = '0.5';
                        input.style.pointerEvents = 'none';
                    });

                    // Add overlay to checkout sections
                    var billingSection = document.querySelector('.woocommerce-billing-fields, .wc-block-components-address-form');
                    if (billingSection && !document.getElementById('jedo-checkout-overlay')) {
                        var overlay = document.createElement('div');
                        overlay.id = 'jedo-checkout-overlay';
                        overlay.innerHTML = '<div class="jedo-overlay-message"><?php echo esc_js(__('Please verify your email first', 'jezweb-email-double-optin')); ?></div>';
                        billingSection.style.position = 'relative';
                        billingSection.appendChild(overlay);
                    }

                    self.fieldsDisabled = true;
                },

                enableCheckoutFields: function() {
                    var self = this;
                    if (!self.fieldsDisabled) return;

                    var checkoutForm = document.querySelector('form.checkout, form.woocommerce-checkout, .wc-block-checkout');
                    if (!checkoutForm) return;

                    var inputs = checkoutForm.querySelectorAll('input:not([type="hidden"]), select, textarea, button[type="submit"]');

                    inputs.forEach(function(input) {
                        var wasDisabled = input.getAttribute('data-jedo-original-disabled') === 'true';
                        input.disabled = wasDisabled;
                        input.style.opacity = '';
                        input.style.pointerEvents = '';
                        input.removeAttribute('data-jedo-original-disabled');
                    });

                    // Remove overlay
                    var overlay = document.getElementById('jedo-checkout-overlay');
                    if (overlay) {
                        overlay.remove();
                    }

                    self.fieldsDisabled = false;
                },

                checkEmailStatus: function(email) {
                    var self = this;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', self.ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    if (response.data.verified) {
                                        self.verifiedEmails[email] = true;
                                        self.showVerifiedState();
                                    } else if (response.data.pending) {
                                        self.verificationToken = response.data.token || '';
                                        self.showPendingState(email);
                                    } else {
                                        self.showVerificationRequired(email);
                                    }
                                }
                            } catch (e) {
                                console.error('JEDO: Parse error', e);
                            }
                        }
                    };
                    xhr.send('action=jedo_check_email_verified&email=' + encodeURIComponent(email) + '&nonce=' + self.nonce);
                },

                showVerificationRequired: function(email) {
                    var self = this;
                    var container = document.getElementById('jedo-email-verification-container');
                    if (!container) return;

                    // Disable other checkout fields until verification is complete
                    self.disableCheckoutFields();

                    // Show sending state immediately
                    container.innerHTML =
                        '<div class="jedo-email-verify-box jedo-sending">' +
                            '<div class="jedo-verify-icon"><span class="jedo-spinner"></span></div>' +
                            '<div class="jedo-verify-content">' +
                                '<strong><?php echo esc_js(__('Sending Verification...', 'jezweb-email-double-optin')); ?></strong>' +
                                '<p><?php echo esc_js(__('Please wait while we send the verification code to your email.', 'jezweb-email-double-optin')); ?></p>' +
                            '</div>' +
                        '</div>';

                    container.style.display = 'block';

                    // Auto-send verification email immediately (no button click required)
                    self.sendVerificationEmail(email);
                },

                sendVerificationEmail: function(email) {
                    var self = this;
                    var btn = document.getElementById('jedo-send-verification');

                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<?php echo esc_js(__('Sending...', 'jezweb-email-double-optin')); ?>';
                    }

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', self.ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    self.verificationToken = response.data.token || '';
                                    self.showPendingState(email);
                                } else {
                                    alert(response.data.message || '<?php echo esc_js(__('Failed to send verification email. Please try again.', 'jezweb-email-double-optin')); ?>');
                                    if (btn) {
                                        btn.disabled = false;
                                        btn.innerHTML = '<?php echo esc_js(__('Send Verification Email', 'jezweb-email-double-optin')); ?>';
                                    }
                                }
                            } catch (e) {
                                console.error('JEDO: Parse error', e);
                            }
                        }
                    };
                    xhr.send('action=jedo_request_checkout_verification&email=' + encodeURIComponent(email) + '&nonce=' + self.nonce);
                },

                showPendingState: function(email) {
                    var self = this;
                    var container = document.getElementById('jedo-email-verification-container');
                    if (!container) return;

                    if (self.isOtpMode) {
                        // OTP Mode - Show code input
                        // Generate placeholder based on settings
                        var placeholder;
                        if (self.otpType === 'numeric') {
                            placeholder = (self.otpLength === 4) ? '2345' : '234567';
                        } else {
                            placeholder = (self.otpLength === 4) ? 'A1B2' : 'A1B2C3';
                        }

                        // Generate instruction text based on settings
                        var codeType = (self.otpType === 'numeric') ?
                            '<?php echo esc_js(__('digit', 'jezweb-email-double-optin')); ?>' :
                            '<?php echo esc_js(__('character', 'jezweb-email-double-optin')); ?>';
                        var instructionText = '<?php echo esc_js(__('We sent a', 'jezweb-email-double-optin')); ?> ' +
                            self.otpLength + '-' + codeType + ' <?php echo esc_js(__('code to your email. Enter it below:', 'jezweb-email-double-optin')); ?>';

                        container.innerHTML =
                            '<div class="jedo-email-verify-box jedo-pending jedo-otp-mode">' +
                                '<div class="jedo-verify-icon">🔐</div>' +
                                '<div class="jedo-verify-content">' +
                                    '<strong><?php echo esc_js(__('Enter Verification Code', 'jezweb-email-double-optin')); ?></strong>' +
                                    '<p>' + instructionText + '</p>' +
                                    '<div class="jedo-otp-input-wrapper">' +
                                        '<input type="text" id="jedo-otp-input" class="jedo-otp-input" maxlength="' + self.otpLength + '" placeholder="' + placeholder + '" autocomplete="off" inputmode="' + (self.otpType === 'numeric' ? 'numeric' : 'text') + '">' +
                                        '<button type="button" class="jedo-verify-btn jedo-otp-verify-btn" id="jedo-verify-otp-btn">' +
                                            '<?php echo esc_js(__('Verify Code', 'jezweb-email-double-optin')); ?>' +
                                        '</button>' +
                                    '</div>' +
                                    '<div class="jedo-otp-feedback" id="jedo-otp-feedback"></div>' +
                                    '<button type="button" class="jedo-resend-btn" id="jedo-resend-verification">' +
                                        '<?php echo esc_js(__('Resend Code', 'jezweb-email-double-optin')); ?>' +
                                    '</button>' +
                                '</div>' +
                            '</div>';

                        container.style.display = 'block';

                        // Filter input based on OTP type
                        document.getElementById('jedo-otp-input').addEventListener('input', function() {
                            if (self.otpType === 'numeric') {
                                this.value = this.value.replace(/[^0-9]/g, '');
                            } else {
                                this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                            }
                        });

                        // Verify button handler
                        document.getElementById('jedo-verify-otp-btn').addEventListener('click', function() {
                            self.verifyOtpCode(email);
                        });

                        // Allow Enter key to submit
                        document.getElementById('jedo-otp-input').addEventListener('keypress', function(e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                self.verifyOtpCode(email);
                            }
                        });

                        // Resend handler
                        document.getElementById('jedo-resend-verification').addEventListener('click', function() {
                            self.sendVerificationEmail(email);
                        });

                        // Focus the input
                        document.getElementById('jedo-otp-input').focus();

                    } else {
                        // Link Mode - existing behavior
                        container.innerHTML =
                            '<div class="jedo-email-verify-box jedo-pending">' +
                                '<div class="jedo-verify-icon">✉️</div>' +
                                '<div class="jedo-verify-content">' +
                                    '<strong><?php echo esc_js(__('Verification Email Sent!', 'jezweb-email-double-optin')); ?></strong>' +
                                    '<p><?php echo esc_js(__('We have sent a verification email to your inbox. Please click the link in the email to verify.', 'jezweb-email-double-optin')); ?></p>' +
                                    '<p class="jedo-waiting"><span class="jedo-spinner"></span> <?php echo esc_js(__('Waiting for verification...', 'jezweb-email-double-optin')); ?></p>' +
                                    '<button type="button" class="jedo-resend-btn" id="jedo-resend-verification">' +
                                        '<?php echo esc_js(__('Resend Email', 'jezweb-email-double-optin')); ?>' +
                                    '</button>' +
                                '</div>' +
                            '</div>';

                        container.style.display = 'block';

                        // Attach resend handler
                        document.getElementById('jedo-resend-verification').addEventListener('click', function() {
                            self.sendVerificationEmail(email);
                        });

                        // Start polling for verification
                        self.startPolling(email);
                    }
                },

                verifyOtpCode: function(email) {
                    var self = this;
                    var otpInput = document.getElementById('jedo-otp-input');
                    var verifyBtn = document.getElementById('jedo-verify-otp-btn');
                    var feedback = document.getElementById('jedo-otp-feedback');
                    var otp = otpInput.value.trim().toUpperCase();

                    if (otp.length !== self.otpLength) {
                        var codeType = (self.otpType === 'numeric') ? '<?php echo esc_js(__('digit', 'jezweb-email-double-optin')); ?>' : '<?php echo esc_js(__('character', 'jezweb-email-double-optin')); ?>';
                        feedback.innerHTML = '<span class="jedo-otp-error"><?php echo esc_js(__('Please enter a', 'jezweb-email-double-optin')); ?> ' + self.otpLength + '-' + codeType + ' <?php echo esc_js(__('code.', 'jezweb-email-double-optin')); ?></span>';
                        return;
                    }

                    verifyBtn.disabled = true;
                    verifyBtn.innerHTML = '<?php echo esc_js(__('Verifying...', 'jezweb-email-double-optin')); ?>';
                    feedback.innerHTML = '';

                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', self.ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    self.verifiedEmails[email] = true;
                                    self.showVerifiedState();
                                } else {
                                    feedback.innerHTML = '<span class="jedo-otp-error">' + (response.data.message || '<?php echo esc_js(__('Invalid code. Please try again.', 'jezweb-email-double-optin')); ?>') + '</span>';
                                    verifyBtn.disabled = false;
                                    verifyBtn.innerHTML = '<?php echo esc_js(__('Verify Code', 'jezweb-email-double-optin')); ?>';
                                    otpInput.value = '';
                                    otpInput.focus();
                                }
                            } catch (e) {
                                feedback.innerHTML = '<span class="jedo-otp-error"><?php echo esc_js(__('An error occurred. Please try again.', 'jezweb-email-double-optin')); ?></span>';
                                verifyBtn.disabled = false;
                                verifyBtn.innerHTML = '<?php echo esc_js(__('Verify Code', 'jezweb-email-double-optin')); ?>';
                            }
                        }
                    };
                    xhr.send('action=jedo_verify_checkout_otp&email=' + encodeURIComponent(email) + '&otp_code=' + encodeURIComponent(otp) + '&nonce=' + self.nonce);
                },

                startPolling: function(email) {
                    var self = this;

                    // Clear any existing interval
                    if (self.checkInterval) {
                        clearInterval(self.checkInterval);
                    }

                    // Poll every 3 seconds
                    self.checkInterval = setInterval(function() {
                        self.pollVerificationStatus(email);
                    }, 3000);
                },

                pollVerificationStatus: function(email) {
                    var self = this;
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', self.ajaxUrl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success && response.data.verified) {
                                    self.verifiedEmails[email] = true;
                                    self.showVerifiedState();

                                    // Stop polling
                                    if (self.checkInterval) {
                                        clearInterval(self.checkInterval);
                                    }
                                }
                            } catch (e) {}
                        }
                    };
                    xhr.send('action=jedo_check_email_verified&email=' + encodeURIComponent(email) + '&token=' + encodeURIComponent(self.verificationToken) + '&nonce=' + self.nonce);
                },

                showVerifiedState: function() {
                    var self = this;
                    var container = document.getElementById('jedo-email-verification-container');
                    if (!container) return;

                    // Enable checkout fields now that email is verified
                    self.enableCheckoutFields();

                    container.innerHTML =
                        '<div class="jedo-email-verify-box jedo-verified">' +
                            '<div class="jedo-verify-icon">✅</div>' +
                            '<div class="jedo-verify-content">' +
                                '<strong><?php echo esc_js(__('Email Verified!', 'jezweb-email-double-optin')); ?></strong>' +
                                '<p><?php echo esc_js(__('Your email has been verified. You can now proceed with checkout.', 'jezweb-email-double-optin')); ?></p>' +
                            '</div>' +
                        '</div>';

                    container.style.display = 'block';

                    // Hide after 3 seconds
                    setTimeout(function() {
                        container.style.display = 'none';
                    }, 3000);
                },

                hideVerificationNotice: function() {
                    var container = document.getElementById('jedo-email-verification-container');
                    if (container) {
                        container.style.display = 'none';
                    }
                }
            };

            // Initialize when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    jedoVerification.init();
                });
            } else {
                jedoVerification.init();
            }
        })();
        </script>
        <?php
    }

    /**
     * Get CSS styles for email verification
     *
     * @return string CSS code.
     */
    private function get_email_verification_styles() {
        return "
        #jedo-email-verification-container {
            margin: 15px 0;
        }
        .jedo-email-verify-box {
            display: flex;
            align-items: flex-start;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            color: #856404;
        }
        .jedo-email-verify-box.jedo-pending {
            background: #e3f2fd;
            border-color: #2196f3;
            color: #1565c0;
        }
        .jedo-email-verify-box.jedo-verified {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .jedo-verify-icon {
            font-size: 24px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .jedo-verify-content {
            flex: 1;
        }
        .jedo-verify-content strong {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
        }
        .jedo-verify-content p {
            margin: 5px 0;
            font-size: 13px;
        }
        .jedo-verify-btn {
            display: inline-block;
            background: #ffc107;
            color: #856404;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            font-size: 14px;
        }
        .jedo-verify-btn:hover {
            background: #e0a800;
        }
        .jedo-verify-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .jedo-resend-btn {
            display: inline-block;
            background: transparent;
            color: #1565c0;
            padding: 5px 10px;
            border: 1px solid #1565c0;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
            font-size: 12px;
        }
        .jedo-resend-btn:hover {
            background: #1565c0;
            color: #fff;
        }
        .jedo-waiting {
            display: flex;
            align-items: center;
        }
        .jedo-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #1565c0;
            border-top-color: transparent;
            border-radius: 50%;
            animation: jedo-spin 1s linear infinite;
            margin-right: 8px;
        }
        @keyframes jedo-spin {
            to { transform: rotate(360deg); }
        }
        /* OTP Input Styles */
        .jedo-otp-input-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .jedo-otp-input {
            text-transform: uppercase;
            letter-spacing: 4px;
            font-size: 18px;
            text-align: center;
            padding: 12px;
            width: 140px;
            border: 2px solid #1565c0;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #1565c0;
        }
        .jedo-otp-input:focus {
            outline: none;
            border-color: #0d47a1;
            box-shadow: 0 0 0 3px rgba(21, 101, 192, 0.2);
        }
        .jedo-otp-input::placeholder {
            color: #b0bec5;
            letter-spacing: 4px;
        }
        .jedo-otp-verify-btn {
            background: #1565c0;
            color: #fff;
        }
        .jedo-otp-verify-btn:hover {
            background: #0d47a1;
        }
        .jedo-otp-feedback {
            margin-top: 10px;
            font-size: 13px;
        }
        .jedo-otp-error {
            color: #dc3545;
        }
        .jedo-email-verify-box.jedo-otp-mode {
            background: #e8f5e9;
            border-color: #4caf50;
            color: #2e7d32;
        }
        .jedo-email-verify-box.jedo-sending {
            background: #e3f2fd;
            border-color: #2196f3;
            color: #1565c0;
        }
        /* Checkout overlay to block fields until verified */
        #jedo-checkout-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
        }
        .jedo-overlay-message {
            background: #fff3cd;
            color: #856404;
            padding: 20px 30px;
            border-radius: 8px;
            border: 2px solid #ffc107;
            font-weight: 600;
            font-size: 14px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        ";
    }

    /**
     * AJAX handler to request checkout email verification
     * Creates a pending verification and sends email
     */
    public function ajax_request_checkout_verification() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'jedo_checkout_verification')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'jezweb-email-double-optin')));
            return;
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        // Rate limiting - max 5 requests per email per hour
        $rate_limit_key = 'jedo_checkout_rate_' . md5($email);
        $rate_data = get_transient($rate_limit_key);

        if ($rate_data && isset($rate_data['count']) && $rate_data['count'] >= 5) {
            wp_send_json_error(array('message' => __('Too many verification requests. Please wait before trying again.', 'jezweb-email-double-optin')));
            return;
        }

        // Update rate limit counter
        if (!$rate_data) {
            $rate_data = array('count' => 1, 'first_request' => time());
        } else {
            $rate_data['count']++;
        }
        set_transient($rate_limit_key, $rate_data, HOUR_IN_SECONDS);

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => __('Invalid email address.', 'jezweb-email-double-optin')));
            return;
        }

        // Check if user already exists with this email
        $existing_user = get_user_by('email', $email);

        if ($existing_user) {
            // User exists - check if verified
            $verified = get_user_meta($existing_user->ID, 'jedo_email_verified', true);

            if ($verified === 'yes') {
                wp_send_json_success(array(
                    'verified' => true,
                    'message' => __('Email already verified.', 'jezweb-email-double-optin')
                ));
                return;
            }

            // User exists but not verified - send verification email
            $token = JEDO_Email::get_instance()->send_verification_email($existing_user->ID, $email, 'checkout_existing');

            wp_send_json_success(array(
                'verified' => false,
                'pending' => true,
                'token' => $token,
                'message' => __('Verification email sent.', 'jezweb-email-double-optin')
            ));
            return;
        }

        // New email - create pending verification without creating user yet
        // Generate a unique token for this email
        $token = bin2hex(random_bytes(32));

        // Store pending verification in transient (expires in 1 hour)
        $pending_data = array(
            'email' => $email,
            'token' => $token,
            'created' => time(),
            'verified' => false
        );

        set_transient('jedo_pending_checkout_' . md5($email), $pending_data, HOUR_IN_SECONDS);

        // Send verification email using our email class (without user)
        $this->send_checkout_verification_email($email, $token);

        wp_send_json_success(array(
            'verified' => false,
            'pending' => true,
            'token' => $token,
            'message' => __('Verification email sent.', 'jezweb-email-double-optin')
        ));
    }

    /**
     * Send verification email for checkout (without user account)
     *
     * @param string $email Email address.
     * @param string $token Verification token.
     */
    private function send_checkout_verification_email($email, $token) {
        // Build verification URL
        $verification_url = add_query_arg(array(
            'jedo_checkout_verify' => '1',
            'email' => urlencode($email),
            'token' => $token
        ), home_url());

        // Get email template settings
        $subject = get_option('jedo_email_subject', __('Verify your email address', 'jezweb-email-double-optin'));
        $heading = get_option('jedo_email_heading', __('Verify Your Email', 'jezweb-email-double-optin'));
        $body = get_option('jedo_email_body', __('Thank you for your interest. Please click the button below to verify your email address.', 'jezweb-email-double-optin'));
        $button_text = get_option('jedo_email_button_text', __('Verify Email Address', 'jezweb-email-double-optin'));
        $button_color = get_option('jedo_email_button_color', '#0073aa');
        $footer = get_option('jedo_email_footer', __('If you did not request this verification, please ignore this email.', 'jezweb-email-double-optin'));

        // Replace placeholders
        $site_name = get_bloginfo('name');
        $subject = str_replace('{site_name}', $site_name, $subject);
        $body = str_replace(array('{user_name}', '{site_name}'), array($email, $site_name), $body);

        // Build email HTML
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>' . esc_html($subject) . '</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: #f9f9f9; padding: 30px; border-radius: 5px;">
                <h2 style="color: #333; margin-bottom: 20px;">' . esc_html($heading) . '</h2>
                <p style="margin-bottom: 20px;">' . nl2br(esc_html($body)) . '</p>
                <p style="margin-bottom: 30px;">
                    <a href="' . esc_url($verification_url) . '" style="display: inline-block; background-color: ' . esc_attr($button_color) . '; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">' . esc_html($button_text) . '</a>
                </p>
                <p style="font-size: 12px; color: #666;">' . esc_html($footer) . '</p>
            </div>
        </body>
        </html>';

        // Send email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );

        wp_mail($email, $subject, $message, $headers);
    }

    /**
     * AJAX handler to check if email is verified
     */
    public function ajax_check_email_verified() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'jedo_checkout_verification')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'jezweb-email-double-optin')));
            return;
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => __('Invalid email address.', 'jezweb-email-double-optin')));
            return;
        }

        // Check if user exists with this email
        $existing_user = get_user_by('email', $email);

        if ($existing_user) {
            $verified = get_user_meta($existing_user->ID, 'jedo_email_verified', true);

            wp_send_json_success(array(
                'verified' => ($verified === 'yes'),
                'pending' => ($verified !== 'yes'),
                'user_exists' => true
            ));
            return;
        }

        // Check pending verification transient
        $pending_data = get_transient('jedo_pending_checkout_' . md5($email));

        if ($pending_data && isset($pending_data['verified']) && $pending_data['verified']) {
            wp_send_json_success(array(
                'verified' => true,
                'pending' => false,
                'user_exists' => false
            ));
            return;
        }

        if ($pending_data) {
            wp_send_json_success(array(
                'verified' => false,
                'pending' => true,
                'user_exists' => false
            ));
            return;
        }

        // No record found - email needs verification
        wp_send_json_success(array(
            'verified' => false,
            'pending' => false,
            'user_exists' => false
        ));
    }

    /**
     * AJAX handler to verify OTP code on checkout
     */
    public function ajax_verify_checkout_otp() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'jedo_checkout_verification')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'jezweb-email-double-optin')));
            return;
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $otp_code = isset($_POST['otp_code']) ? strtoupper(sanitize_text_field($_POST['otp_code'])) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error(array('message' => __('Invalid email address.', 'jezweb-email-double-optin')));
            return;
        }

        if (empty($otp_code)) {
            wp_send_json_error(array('message' => __('OTP code is required.', 'jezweb-email-double-optin')));
            return;
        }

        $verification = JEDO_Verification::get_instance();
        $result = $verification->verify_otp($email, $otp_code);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
}

/**
 * WooCommerce Blocks Integration Class
 */
if (class_exists('Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface')) {
    class JEDO_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
        /**
         * Get the name of the integration.
         *
         * @return string
         */
        public function get_name() {
            return 'jedo-email-verification';
        }

        /**
         * Initialize the integration.
         */
        public function initialize() {
            // Scripts and data are handled by the main class
        }

        /**
         * Get script handles.
         *
         * @return array
         */
        public function get_script_handles() {
            return array();
        }

        /**
         * Get editor script handles.
         *
         * @return array
         */
        public function get_editor_script_handles() {
            return array();
        }

        /**
         * Get script data.
         *
         * @return array
         */
        public function get_script_data() {
            return array(
                'isVerified' => is_user_logged_in() ? get_user_meta(get_current_user_id(), 'jedo_email_verified', true) === 'yes' : true,
            );
        }
    }
}

// Hook to update last verified email when verified
add_action('jedo_email_verified', function($user_id, $email, $type) {
    update_user_meta($user_id, 'jedo_last_verified_email', $email);
}, 10, 3);
