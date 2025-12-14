<?php
/**
 * Verification Handler
 *
 * @package Jezweb_Email_Double_Optin
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verification Class
 */
class JEDO_Verification {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Rate limit for resend emails (in seconds)
     */
    const RESEND_RATE_LIMIT = 60;

    /**
     * Maximum resend attempts per hour
     */
    const MAX_RESEND_PER_HOUR = 5;

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
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // WordPress registration hooks
        if (get_option('jedo_enable_wp_registration') === 'yes') {
            add_action('user_register', array($this, 'handle_user_registration'), 10, 1);
            add_filter('wp_authenticate_user', array($this, 'check_user_verification'), 10, 2);
            add_action('register_form', array($this, 'add_registration_notice'));
        }

        // Shortcode for verification page
        add_shortcode('jedo_email_verification', array($this, 'verification_shortcode'));

        // Handle verification link
        add_action('init', array($this, 'handle_verification_request'));

        // Handle resend verification
        add_action('wp_ajax_nopriv_jedo_resend_verification', array($this, 'ajax_resend_verification'));
        add_action('wp_ajax_jedo_resend_verification', array($this, 'ajax_resend_verification'));

        // Cleanup expired tokens
        add_action('jedo_cleanup_tokens', array($this, 'cleanup_expired_tokens'));
        if (!wp_next_scheduled('jedo_cleanup_tokens')) {
            wp_schedule_event(time(), 'daily', 'jedo_cleanup_tokens');
        }
    }

    /**
     * Handle user registration
     *
     * @param int $user_id User ID.
     */
    public function handle_user_registration($user_id) {
        // Validate user ID
        $user_id = absint($user_id);
        if (!$user_id) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }

        // Mark user as unverified
        update_user_meta($user_id, 'jedo_email_verified', 'no');
        update_user_meta($user_id, 'jedo_verification_pending', 'yes');

        // Send verification email
        JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'registration');
    }

    /**
     * Check user verification on login
     *
     * @param WP_User|WP_Error $user     User object or error.
     * @param string          $password Password.
     * @return WP_User|WP_Error
     */
    public function check_user_verification($user, $password) {
        if (is_wp_error($user)) {
            return $user;
        }

        // Skip check for administrators
        if (user_can($user, 'manage_options')) {
            return $user;
        }

        $verified = get_user_meta($user->ID, 'jedo_email_verified', true);

        if ($verified !== 'yes') {
            $message = get_option('jedo_message_not_verified', __('Please verify your email address before logging in.', 'jezweb-email-double-optin'));

            // Add resend link with secure nonce
            $resend_url = add_query_arg(array(
                'action' => 'jedo_resend',
                'user_id' => $user->ID,
                'nonce' => wp_create_nonce('jedo_resend_' . $user->ID)
            ), wp_login_url());

            $message .= '<br><br><a href="' . esc_url($resend_url) . '">' . esc_html__('Resend verification email', 'jezweb-email-double-optin') . '</a>';

            return new WP_Error('email_not_verified', $message);
        }

        return $user;
    }

    /**
     * Add registration notice
     */
    public function add_registration_notice() {
        echo '<p class="jedo-registration-notice" style="margin-bottom: 16px; padding: 10px; background: #f0f6fc; border-left: 4px solid #0073aa; font-size: 13px;">';
        echo esc_html__('After registration, you will receive an email to verify your address.', 'jezweb-email-double-optin');
        echo '</p>';
    }

    /**
     * Handle verification request
     */
    public function handle_verification_request() {
        // Handle resend from login page
        if (isset($_GET['action']) && $_GET['action'] === 'jedo_resend' && isset($_GET['user_id']) && isset($_GET['nonce'])) {
            $user_id = absint($_GET['user_id']);
            $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));

            if (wp_verify_nonce($nonce, 'jedo_resend_' . $user_id)) {
                // Check rate limiting
                if ($this->is_rate_limited($user_id)) {
                    wp_redirect(add_query_arg('jedo_error', 'rate_limited', wp_login_url()));
                    exit;
                }

                $user = get_userdata($user_id);
                if ($user) {
                    // Record this attempt
                    $this->record_resend_attempt($user_id);

                    JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'registration');
                    wp_redirect(add_query_arg('jedo_resent', '1', wp_login_url()));
                    exit;
                }
            }
        }

        // Show resend success message on login page
        if (isset($_GET['jedo_resent'])) {
            add_filter('login_message', function($message) {
                $success_message = get_option('jedo_message_resend_success', __('Verification email has been resent.', 'jezweb-email-double-optin'));
                return '<p class="message">' . esc_html($success_message) . '</p>' . $message;
            });
        }

        // Show rate limit error
        if (isset($_GET['jedo_error']) && $_GET['jedo_error'] === 'rate_limited') {
            add_filter('login_message', function($message) {
                return '<p class="message" style="border-left-color: #dc3545;">' . esc_html__('Please wait before requesting another verification email.', 'jezweb-email-double-optin') . '</p>' . $message;
            });
        }
    }

    /**
     * Check if user is rate limited
     *
     * @param int $user_id User ID.
     * @return bool
     */
    private function is_rate_limited($user_id) {
        $user_id = absint($user_id);

        // Check last resend time
        $last_resend = get_user_meta($user_id, 'jedo_last_resend', true);
        if ($last_resend && (time() - intval($last_resend)) < self::RESEND_RATE_LIMIT) {
            return true;
        }

        // Check hourly limit
        $resend_count = get_user_meta($user_id, 'jedo_resend_count', true);
        $resend_hour = get_user_meta($user_id, 'jedo_resend_hour', true);

        if ($resend_hour === gmdate('YmdH')) {
            if (intval($resend_count) >= self::MAX_RESEND_PER_HOUR) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record resend attempt for rate limiting
     *
     * @param int $user_id User ID.
     */
    private function record_resend_attempt($user_id) {
        $user_id = absint($user_id);
        $current_hour = gmdate('YmdH');

        update_user_meta($user_id, 'jedo_last_resend', time());

        $resend_hour = get_user_meta($user_id, 'jedo_resend_hour', true);
        if ($resend_hour !== $current_hour) {
            update_user_meta($user_id, 'jedo_resend_hour', $current_hour);
            update_user_meta($user_id, 'jedo_resend_count', 1);
        } else {
            $count = intval(get_user_meta($user_id, 'jedo_resend_count', true));
            update_user_meta($user_id, 'jedo_resend_count', $count + 1);
        }
    }

    /**
     * Verification shortcode
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function verification_shortcode($atts) {
        ob_start();

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        if (empty($token)) {
            // Show verification pending message or form
            $this->render_verification_page();
        } else {
            // Validate token format (64 hex characters)
            if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
                $this->render_verification_result(array(
                    'success' => false,
                    'message' => __('Invalid verification link.', 'jezweb-email-double-optin')
                ));
            } else {
                // Process verification
                $result = $this->verify_token($token);
                $this->render_verification_result($result);
            }
        }

        return ob_get_clean();
    }

    /**
     * Render verification page
     */
    private function render_verification_page() {
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        $nonce = wp_create_nonce('jedo_resend_ajax');
        ?>
        <div class="jedo-verification-container">
            <div class="jedo-verification-box">
                <h2><?php esc_html_e('Email Verification', 'jezweb-email-double-optin'); ?></h2>
                <p><?php echo esc_html(get_option('jedo_message_verification_sent')); ?></p>

                <?php if ($user_id) : ?>
                <div class="jedo-resend-section">
                    <p><?php esc_html_e("Didn't receive the email?", 'jezweb-email-double-optin'); ?></p>
                    <button type="button" class="jedo-resend-btn" data-user-id="<?php echo esc_attr($user_id); ?>">
                        <?php esc_html_e('Resend Verification Email', 'jezweb-email-double-optin'); ?>
                    </button>
                    <div class="jedo-resend-message"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .jedo-verification-container { max-width: 500px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
            .jedo-verification-box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
            .jedo-verification-box h2 { margin: 0 0 20px; color: #1e1e1e; }
            .jedo-verification-box p { color: #646970; line-height: 1.6; }
            .jedo-resend-section { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
            .jedo-resend-btn { background: #0073aa; color: #fff; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-size: 14px; transition: background 0.2s; }
            .jedo-resend-btn:hover { background: #005a87; }
            .jedo-resend-btn:disabled { background: #ccc; cursor: not-allowed; }
            .jedo-resend-message { margin-top: 15px; padding: 10px; border-radius: 4px; }
            .jedo-resend-message.success { background: #d4edda; color: #155724; }
            .jedo-resend-message.error { background: #f8d7da; color: #721c24; }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.querySelector('.jedo-resend-btn');
            if (btn) {
                btn.addEventListener('click', function() {
                    var userId = this.getAttribute('data-user-id');
                    var msgDiv = document.querySelector('.jedo-resend-message');
                    var btnEl = this;
                    btnEl.disabled = true;
                    btnEl.textContent = <?php echo wp_json_encode(__('Sending...', 'jezweb-email-double-optin')); ?>;

                    var formData = new FormData();
                    formData.append('action', 'jedo_resend_verification');
                    formData.append('user_id', userId);
                    formData.append('nonce', <?php echo wp_json_encode($nonce); ?>);

                    fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        msgDiv.className = 'jedo-resend-message ' + (data.success ? 'success' : 'error');
                        msgDiv.textContent = data.data.message;
                        btnEl.disabled = false;
                        btnEl.textContent = <?php echo wp_json_encode(__('Resend Verification Email', 'jezweb-email-double-optin')); ?>;
                    })
                    .catch(function() {
                        msgDiv.className = 'jedo-resend-message error';
                        msgDiv.textContent = <?php echo wp_json_encode(__('An error occurred. Please try again.', 'jezweb-email-double-optin')); ?>;
                        btnEl.disabled = false;
                        btnEl.textContent = <?php echo wp_json_encode(__('Resend Verification Email', 'jezweb-email-double-optin')); ?>;
                    });
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Render verification result
     *
     * @param array $result Verification result.
     */
    private function render_verification_result($result) {
        $success = isset($result['success']) ? (bool) $result['success'] : false;
        $message = isset($result['message']) ? $result['message'] : '';
        $redirect_url = get_option('jedo_redirect_after_verification');

        if (empty($redirect_url)) {
            $redirect_url = wp_login_url();
        }
        ?>
        <div class="jedo-verification-container">
            <div class="jedo-verification-box <?php echo $success ? 'success' : 'error'; ?>">
                <div class="jedo-icon">
                    <?php if ($success) : ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <?php else : ?>
                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="15" y1="9" x2="9" y2="15"></line>
                        <line x1="9" y1="9" x2="15" y2="15"></line>
                    </svg>
                    <?php endif; ?>
                </div>
                <h2><?php echo $success ? esc_html__('Email Verified!', 'jezweb-email-double-optin') : esc_html__('Verification Failed', 'jezweb-email-double-optin'); ?></h2>
                <p><?php echo esc_html($message); ?></p>
                <?php if ($success) : ?>
                <a href="<?php echo esc_url($redirect_url); ?>" class="jedo-btn"><?php esc_html_e('Continue to Login', 'jezweb-email-double-optin'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .jedo-verification-container { max-width: 500px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; }
            .jedo-verification-box { background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
            .jedo-verification-box.success { border-top: 4px solid #28a745; }
            .jedo-verification-box.error { border-top: 4px solid #dc3545; }
            .jedo-icon { margin-bottom: 20px; }
            .jedo-verification-box h2 { margin: 0 0 15px; color: #1e1e1e; }
            .jedo-verification-box p { color: #646970; line-height: 1.6; margin-bottom: 25px; }
            .jedo-btn { display: inline-block; background: #0073aa; color: #fff; padding: 12px 30px; border-radius: 4px; text-decoration: none; font-weight: 500; transition: background 0.2s; }
            .jedo-btn:hover { background: #005a87; color: #fff; }
        </style>
        <?php
    }

    /**
     * Verify token
     *
     * @param string $token Verification token.
     * @return array
     */
    public function verify_token($token) {
        global $wpdb;

        // Sanitize token
        $token = sanitize_text_field($token);

        // Validate token format
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return array(
                'success' => false,
                'message' => __('Invalid verification link.', 'jezweb-email-double-optin')
            );
        }

        $table_name = $wpdb->prefix . 'jedo_verification_tokens';

        // Use prepared statement to prevent SQL injection
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table_name}` WHERE token = %s LIMIT 1",
            $token
        ));

        if (!$record) {
            return array(
                'success' => false,
                'message' => get_option('jedo_message_verification_failed', __('Verification failed. Invalid token.', 'jezweb-email-double-optin'))
            );
        }

        // Check if already verified
        if ($record->verified_at !== null) {
            return array(
                'success' => true,
                'message' => get_option('jedo_message_already_verified', __('Your email has already been verified.', 'jezweb-email-double-optin'))
            );
        }

        // Check if expired
        if (strtotime($record->expires_at) < time()) {
            return array(
                'success' => false,
                'message' => get_option('jedo_message_verification_failed', __('Verification link has expired.', 'jezweb-email-double-optin'))
            );
        }

        // Mark as verified
        $wpdb->update(
            $table_name,
            array('verified_at' => current_time('mysql')),
            array('id' => absint($record->id)),
            array('%s'),
            array('%d')
        );

        // Update user meta
        $user_id = absint($record->user_id);
        update_user_meta($user_id, 'jedo_email_verified', 'yes');
        update_user_meta($user_id, 'jedo_verification_pending', 'no');
        update_user_meta($user_id, 'jedo_verified_at', current_time('mysql'));

        // Trigger action for other plugins
        do_action('jedo_email_verified', $user_id, $record->email, $record->type);

        return array(
            'success' => true,
            'message' => get_option('jedo_message_verification_success', __('Your email has been verified successfully!', 'jezweb-email-double-optin')),
            'user_id' => $user_id
        );
    }

    /**
     * Generate verification token
     *
     * @param int    $user_id User ID.
     * @param string $email   Email address.
     * @param string $type    Verification type.
     * @return string
     */
    public function generate_token($user_id, $email, $type = 'registration') {
        global $wpdb;

        $user_id = absint($user_id);
        $email = sanitize_email($email);
        $type = sanitize_key($type);

        $table_name = $wpdb->prefix . 'jedo_verification_tokens';

        // Generate cryptographically secure token
        $token = bin2hex(random_bytes(32));

        $expiry_hours = absint(get_option('jedo_verification_expiry', 24));
        if ($expiry_hours < 1) {
            $expiry_hours = 24;
        }

        // Delete any existing tokens for this user/email/type
        $wpdb->delete(
            $table_name,
            array(
                'user_id' => $user_id,
                'email' => $email,
                'type' => $type
            ),
            array('%d', '%s', '%s')
        );

        // Insert new token
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'token' => $token,
                'email' => $email,
                'type' => $type,
                'created_at' => current_time('mysql'),
                'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+' . $expiry_hours . ' hours'))
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        return $token;
    }

    /**
     * Get verification URL
     *
     * @param string $token Verification token.
     * @return string
     */
    public function get_verification_url($token) {
        $page_id = get_option('jedo_verification_page_id');

        if ($page_id && get_post($page_id)) {
            $url = get_permalink($page_id);
        } else {
            $url = home_url('/');
        }

        return add_query_arg('token', rawurlencode($token), $url);
    }

    /**
     * AJAX resend verification
     */
    public function ajax_resend_verification() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'jedo_resend_ajax')) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'jezweb-email-double-optin')));
        }

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (!$user_id) {
            wp_send_json_error(array('message' => __('Invalid request.', 'jezweb-email-double-optin')));
        }

        // Check rate limiting
        if ($this->is_rate_limited($user_id)) {
            wp_send_json_error(array('message' => __('Please wait before requesting another verification email.', 'jezweb-email-double-optin')));
        }

        $user = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(array('message' => __('User not found.', 'jezweb-email-double-optin')));
        }

        // Record this attempt
        $this->record_resend_attempt($user_id);

        $result = JEDO_Email::get_instance()->send_verification_email($user_id, $user->user_email, 'registration');

        if ($result) {
            wp_send_json_success(array('message' => get_option('jedo_message_resend_success', __('Verification email has been resent.', 'jezweb-email-double-optin'))));
        } else {
            wp_send_json_error(array('message' => __('Failed to send email. Please try again.', 'jezweb-email-double-optin')));
        }
    }

    /**
     * Cleanup expired tokens
     */
    public function cleanup_expired_tokens() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'jedo_verification_tokens';

        // Delete expired tokens that were never verified
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table_name}` WHERE expires_at < %s AND verified_at IS NULL",
            current_time('mysql')
        ));

        // Optionally delete unverified users
        $delete_after_days = absint(get_option('jedo_delete_unverified_after', 0));

        if ($delete_after_days > 0) {
            $cutoff_date = gmdate('Y-m-d H:i:s', strtotime('-' . $delete_after_days . ' days'));

            $unverified_users = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta}
                WHERE meta_key = 'jedo_verification_pending'
                AND meta_value = 'yes'
                AND user_id IN (
                    SELECT ID FROM {$wpdb->users} WHERE user_registered < %s
                )",
                $cutoff_date
            ));

            foreach ($unverified_users as $user_id) {
                $user_id = absint($user_id);
                // Don't delete admins
                if ($user_id && !user_can($user_id, 'manage_options')) {
                    wp_delete_user($user_id);
                }
            }
        }
    }

    /**
     * Check if user is verified
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public static function is_user_verified($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) {
            return false;
        }
        return get_user_meta($user_id, 'jedo_email_verified', true) === 'yes';
    }
}
