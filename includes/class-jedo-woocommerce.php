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

        // WooCommerce Blocks integration
        add_action('woocommerce_blocks_loaded', array($this, 'register_blocks_integration'));

        // Add script to checkout block page
        add_action('wp_enqueue_scripts', array($this, 'enqueue_blocks_checkout_script'));
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
        // Get user ID from order
        $user_id = $order->get_customer_id();

        // If no user (guest checkout not allowed but somehow got through), skip
        if (!$user_id) {
            // Check if we need to create a user from the request
            $create_account = false;
            $request_data = $request->get_json_params();

            if (isset($request_data['create_account']) && $request_data['create_account']) {
                $create_account = true;
            }

            // If creating account and no user yet, the user will be created by WooCommerce
            // We need to check after user creation
            if ($create_account) {
                // Store flag to check after user creation
                $order->update_meta_data('_jedo_needs_verification_check', 'yes');
                $order->save();
            }
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
     * Enqueue scripts for blocks checkout
     */
    public function enqueue_blocks_checkout_script() {
        // Only on checkout page
        if (!is_checkout()) {
            return;
        }

        // Check if blocks checkout is being used
        if (!$this->is_blocks_checkout()) {
            return;
        }

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

        // Add inline script for verification check
        wp_add_inline_script('wc-checkout', $this->get_blocks_verification_script($user_id));

        // Also enqueue our custom style for the notice
        wp_add_inline_style('wc-blocks-style', $this->get_blocks_verification_styles());
    }

    /**
     * Check if blocks checkout is being used
     *
     * @return bool
     */
    private function is_blocks_checkout() {
        // Check if the checkout page contains the checkout block
        global $post;

        if (!$post) {
            return false;
        }

        // Check for Gutenberg blocks in content
        if (has_block('woocommerce/checkout', $post)) {
            return true;
        }

        // Check if WooCommerce Blocks is handling checkout
        if (class_exists('Automattic\WooCommerce\Blocks\Package') &&
            method_exists('Automattic\WooCommerce\Blocks\Package', 'feature_flags')) {
            return true;
        }

        return false;
    }

    /**
     * Get JavaScript for blocks checkout verification
     *
     * @param int $user_id User ID.
     * @return string JavaScript code.
     */
    private function get_blocks_verification_script($user_id) {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('jedo_check_verification');
        $resend_url = add_query_arg(array(
            'action' => 'jedo_resend_checkout',
            'user_id' => $user_id,
            'nonce' => wp_create_nonce('jedo_resend_checkout_' . $user_id)
        ), wc_get_checkout_url());

        return "
        (function() {
            'use strict';

            // Add verification notice to blocks checkout
            function addVerificationNotice() {
                var checkoutForm = document.querySelector('.wc-block-checkout');
                if (!checkoutForm) {
                    setTimeout(addVerificationNotice, 500);
                    return;
                }

                // Check if notice already exists
                if (document.querySelector('.jedo-blocks-verification-notice')) {
                    return;
                }

                var notice = document.createElement('div');
                notice.className = 'jedo-blocks-verification-notice';
                notice.innerHTML = '<div class=\"jedo-notice-content\">' +
                    '<h4><span class=\"dashicons dashicons-warning\"></span> " . esc_js(__('Email Verification Required', 'jezweb-email-double-optin')) . "</h4>' +
                    '<p>" . esc_js(__('You must verify your email address before placing an order. Please check your inbox for the verification email and click the link.', 'jezweb-email-double-optin')) . "</p>' +
                    '<p><a href=\"" . esc_js($resend_url) . "\" class=\"jedo-resend-btn\">" . esc_js(__('Resend Verification Email', 'jezweb-email-double-optin')) . "</a></p>' +
                    '<p class=\"jedo-auto-refresh\"><small>" . esc_js(__('This page will automatically update when you verify your email.', 'jezweb-email-double-optin')) . "</small></p>' +
                    '</div>';

                checkoutForm.parentNode.insertBefore(notice, checkoutForm);
            }

            // Check verification status
            function checkVerificationStatus() {
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '" . esc_js($ajax_url) . "', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success && response.data.verified) {
                                // Email verified - reload page
                                var notice = document.querySelector('.jedo-blocks-verification-notice');
                                if (notice) {
                                    notice.innerHTML = '<div class=\"jedo-notice-success\"><p><strong>✓ " . esc_js(__('Email Verified!', 'jezweb-email-double-optin')) . "</strong> " . esc_js(__('Refreshing page...', 'jezweb-email-double-optin')) . "</p></div>';
                                }
                                setTimeout(function() {
                                    location.reload();
                                }, 1500);
                                return;
                            }
                        } catch (e) {}
                    }
                };
                xhr.send('action=jedo_check_verification_status&user_id=" . intval($user_id) . "&nonce=" . esc_js($nonce) . "');
            }

            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                addVerificationNotice();
                // Check verification status every 5 seconds
                setInterval(checkVerificationStatus, 5000);
            });

            // Also run immediately in case DOM is already loaded
            if (document.readyState !== 'loading') {
                addVerificationNotice();
                setInterval(checkVerificationStatus, 5000);
            }
        })();
        ";
    }

    /**
     * Get CSS styles for blocks checkout verification notice
     *
     * @return string CSS code.
     */
    private function get_blocks_verification_styles() {
        return "
        .jedo-blocks-verification-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-left: 4px solid #ffc107;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            color: #856404;
        }
        .jedo-blocks-verification-notice h4 {
            margin: 0 0 10px 0;
            color: #856404;
            font-size: 16px;
        }
        .jedo-blocks-verification-notice h4 .dashicons {
            margin-right: 8px;
        }
        .jedo-blocks-verification-notice p {
            margin: 10px 0;
        }
        .jedo-blocks-verification-notice .jedo-resend-btn {
            display: inline-block;
            background: #856404;
            color: #fff;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
        }
        .jedo-blocks-verification-notice .jedo-resend-btn:hover {
            background: #6d5303;
            color: #fff;
        }
        .jedo-blocks-verification-notice .jedo-auto-refresh {
            color: #666;
        }
        .jedo-notice-success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
            padding: 15px;
            border-radius: 4px;
        }
        ";
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
