<?php
/**
 * Admin Settings Handler
 *
 * @package Jezweb_Email_Double_Optin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Class
 */
class JEDO_Admin {

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
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_jedo_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_jedo_send_test_email', array($this, 'ajax_send_test_email'));
        add_action('wp_ajax_jedo_get_stats', array($this, 'ajax_get_stats'));

        // Add settings link on plugins page
        add_filter('plugin_action_links_' . JEDO_PLUGIN_BASENAME, array($this, 'add_settings_link'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Email Double Opt-in', 'jezweb-email-double-optin'),
            __('Email Opt-in', 'jezweb-email-double-optin'),
            'manage_options',
            'jedo-settings',
            array($this, 'render_settings_page'),
            'dashicons-email-alt',
            80
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // General settings
        register_setting('jedo_settings', 'jedo_enable_wp_registration');
        register_setting('jedo_settings', 'jedo_enable_woocommerce');
        register_setting('jedo_settings', 'jedo_verification_expiry');
        register_setting('jedo_settings', 'jedo_redirect_after_verification');
        register_setting('jedo_settings', 'jedo_delete_unverified_after');

        // Email settings
        register_setting('jedo_settings', 'jedo_email_from_name');
        register_setting('jedo_settings', 'jedo_email_from_address');
        register_setting('jedo_settings', 'jedo_email_subject');
        register_setting('jedo_settings', 'jedo_email_heading');
        register_setting('jedo_settings', 'jedo_email_body');
        register_setting('jedo_settings', 'jedo_email_footer');
        register_setting('jedo_settings', 'jedo_email_button_text');
        register_setting('jedo_settings', 'jedo_email_button_color');

        // Messages
        register_setting('jedo_settings', 'jedo_message_verification_sent');
        register_setting('jedo_settings', 'jedo_message_verification_success');
        register_setting('jedo_settings', 'jedo_message_verification_failed');
        register_setting('jedo_settings', 'jedo_message_already_verified');
        register_setting('jedo_settings', 'jedo_message_not_verified');
        register_setting('jedo_settings', 'jedo_message_resend_success');

        // OTP settings
        register_setting('jedo_settings', 'jedo_verification_method');
        register_setting('jedo_settings', 'jedo_otp_length');
        register_setting('jedo_settings', 'jedo_otp_type');
        register_setting('jedo_settings', 'jedo_otp_expiry_minutes');
        register_setting('jedo_settings', 'jedo_otp_max_attempts');
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_jedo-settings') {
            return;
        }

        wp_enqueue_style(
            'jedo-admin-styles',
            JEDO_PLUGIN_URL . 'admin/css/admin-styles.css',
            array(),
            JEDO_VERSION
        );

        wp_enqueue_script(
            'jedo-admin-scripts',
            JEDO_PLUGIN_URL . 'admin/js/admin-scripts.js',
            array('jquery'),
            JEDO_VERSION,
            true
        );

        wp_localize_script('jedo-admin-scripts', 'jedoAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jedo_admin_nonce'),
            'strings' => array(
                'saving' => __('Saving...', 'jezweb-email-double-optin'),
                'saved' => __('Settings saved successfully!', 'jezweb-email-double-optin'),
                'error' => __('Error saving settings. Please try again.', 'jezweb-email-double-optin'),
                'sending' => __('Sending...', 'jezweb-email-double-optin'),
                'testSent' => __('Test email sent successfully!', 'jezweb-email-double-optin'),
                'testFailed' => __('Failed to send test email.', 'jezweb-email-double-optin'),
            )
        ));

        // Enqueue color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }

    /**
     * Add settings link
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=jedo-settings') . '">' . __('Settings', 'jezweb-email-double-optin') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Get current settings
        $settings = $this->get_all_settings();
        $woo_active = class_exists('WooCommerce');
        ?>
        <div class="jedo-admin-wrap">
            <div class="jedo-admin-header">
                <div class="jedo-header-content">
                    <h1><?php esc_html_e('Jezweb Email Double Opt-in', 'jezweb-email-double-optin'); ?></h1>
                    <p class="jedo-version">v<?php echo esc_html(JEDO_VERSION); ?></p>
                </div>
                <div class="jedo-header-actions">
                    <button type="button" class="jedo-btn jedo-btn-primary" id="jedo-save-settings">
                        <span class="dashicons dashicons-saved"></span>
                        <?php esc_html_e('Save Settings', 'jezweb-email-double-optin'); ?>
                    </button>
                </div>
            </div>

            <div class="jedo-admin-content">
                <div class="jedo-tabs">
                    <button class="jedo-tab active" data-tab="general">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e('General', 'jezweb-email-double-optin'); ?>
                    </button>
                    <button class="jedo-tab" data-tab="email">
                        <span class="dashicons dashicons-email"></span>
                        <?php esc_html_e('Email Template', 'jezweb-email-double-optin'); ?>
                    </button>
                    <button class="jedo-tab" data-tab="messages">
                        <span class="dashicons dashicons-format-chat"></span>
                        <?php esc_html_e('Messages', 'jezweb-email-double-optin'); ?>
                    </button>
                    <button class="jedo-tab" data-tab="stats">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <?php esc_html_e('Statistics', 'jezweb-email-double-optin'); ?>
                    </button>
                    <button class="jedo-tab" data-tab="system">
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php esc_html_e('System Status', 'jezweb-email-double-optin'); ?>
                    </button>
                </div>

                <div class="jedo-tab-content">
                    <!-- General Tab -->
                    <div class="jedo-tab-pane active" id="tab-general">
                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('Verification Settings', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label><?php esc_html_e('WordPress Registration', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Require email verification for new WordPress user registrations.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <label class="jedo-switch">
                                            <input type="checkbox" name="jedo_enable_wp_registration" value="yes" <?php checked($settings['enable_wp_registration'], 'yes'); ?>>
                                            <span class="jedo-slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label><?php esc_html_e('WooCommerce Registration', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc">
                                            <?php esc_html_e('Require email verification for WooCommerce account registrations.', 'jezweb-email-double-optin'); ?>
                                            <?php if (!$woo_active) : ?>
                                            <span class="jedo-badge jedo-badge-warning"><?php esc_html_e('WooCommerce not active', 'jezweb-email-double-optin'); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <label class="jedo-switch">
                                            <input type="checkbox" name="jedo_enable_woocommerce" value="yes" <?php checked($settings['enable_woocommerce'], 'yes'); ?> <?php disabled(!$woo_active); ?>>
                                            <span class="jedo-slider"></span>
                                        </label>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_verification_expiry"><?php esc_html_e('Link Expiry (hours)', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('How long verification links remain valid.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="number" id="jedo_verification_expiry" name="jedo_verification_expiry" value="<?php echo esc_attr($settings['verification_expiry']); ?>" min="1" max="168" class="jedo-input jedo-input-small">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_redirect_after_verification"><?php esc_html_e('Redirect After Verification', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('URL to redirect users to after successful verification. Leave empty for login page.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="url" id="jedo_redirect_after_verification" name="jedo_redirect_after_verification" value="<?php echo esc_url($settings['redirect_after_verification']); ?>" class="jedo-input" placeholder="<?php echo esc_url(wp_login_url()); ?>">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_delete_unverified_after"><?php esc_html_e('Auto-delete Unverified Users (days)', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Automatically delete users who haven\'t verified after this many days. Set to 0 to disable.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="number" id="jedo_delete_unverified_after" name="jedo_delete_unverified_after" value="<?php echo esc_attr($settings['delete_unverified_after']); ?>" min="0" max="365" class="jedo-input jedo-input-small">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_verification_method"><?php esc_html_e('Verification Method', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Choose how users verify their email: via verification link or one-time password code.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <select id="jedo_verification_method" name="jedo_verification_method" class="jedo-input">
                                            <option value="link" <?php selected($settings['verification_method'], 'link'); ?>><?php esc_html_e('Email Verification Link', 'jezweb-email-double-optin'); ?></option>
                                            <option value="otp" <?php selected($settings['verification_method'], 'otp'); ?>><?php esc_html_e('One-Time Password (OTP) Code', 'jezweb-email-double-optin'); ?></option>
                                        </select>
                                    </div>
                                </div>

                                <div class="jedo-setting-row jedo-otp-setting" style="<?php echo $settings['verification_method'] !== 'otp' ? 'display: none;' : ''; ?>">
                                    <div class="jedo-setting-label">
                                        <label><?php esc_html_e('OTP Length', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Choose the number of characters in the OTP code.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <div class="jedo-toggle-group">
                                            <label class="jedo-toggle-btn <?php echo $settings['otp_length'] === '4' ? 'active' : ''; ?>">
                                                <input type="radio" name="jedo_otp_length" value="4" <?php checked($settings['otp_length'], '4'); ?>>
                                                <span>4 Digits</span>
                                            </label>
                                            <label class="jedo-toggle-btn <?php echo $settings['otp_length'] === '6' ? 'active' : ''; ?>">
                                                <input type="radio" name="jedo_otp_length" value="6" <?php checked($settings['otp_length'], '6'); ?>>
                                                <span>6 Digits</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="jedo-setting-row jedo-otp-setting" style="<?php echo $settings['verification_method'] !== 'otp' ? 'display: none;' : ''; ?>">
                                    <div class="jedo-setting-label">
                                        <label><?php esc_html_e('OTP Type', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Choose whether OTP contains only numbers or letters and numbers.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <div class="jedo-toggle-group">
                                            <label class="jedo-toggle-btn <?php echo $settings['otp_type'] === 'numeric' ? 'active' : ''; ?>">
                                                <input type="radio" name="jedo_otp_type" value="numeric" <?php checked($settings['otp_type'], 'numeric'); ?>>
                                                <span>Numeric Only</span>
                                            </label>
                                            <label class="jedo-toggle-btn <?php echo $settings['otp_type'] === 'alphanumeric' ? 'active' : ''; ?>">
                                                <input type="radio" name="jedo_otp_type" value="alphanumeric" <?php checked($settings['otp_type'], 'alphanumeric'); ?>>
                                                <span>Alphanumeric</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="jedo-setting-row jedo-otp-setting" style="<?php echo $settings['verification_method'] !== 'otp' ? 'display: none;' : ''; ?>">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_otp_expiry_minutes"><?php esc_html_e('OTP Expiry (minutes)', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('How long OTP codes remain valid. Default: 5 minutes.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="number" id="jedo_otp_expiry_minutes" name="jedo_otp_expiry_minutes" value="<?php echo esc_attr($settings['otp_expiry_minutes']); ?>" min="1" max="30" class="jedo-input jedo-input-small">
                                    </div>
                                </div>

                                <div class="jedo-setting-row jedo-otp-setting" style="<?php echo $settings['verification_method'] !== 'otp' ? 'display: none;' : ''; ?>">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_otp_max_attempts"><?php esc_html_e('Max OTP Attempts', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Maximum incorrect attempts before requiring a new code. Default: 5.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="number" id="jedo_otp_max_attempts" name="jedo_otp_max_attempts" value="<?php echo esc_attr($settings['otp_max_attempts']); ?>" min="1" max="10" class="jedo-input jedo-input-small">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('Email Sender Settings', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_from_name"><?php esc_html_e('From Name', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('The name that appears in the "From" field.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="text" id="jedo_email_from_name" name="jedo_email_from_name" value="<?php echo esc_attr($settings['email_from_name']); ?>" class="jedo-input">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_from_address"><?php esc_html_e('From Email', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('The email address verification emails are sent from.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="email" id="jedo_email_from_address" name="jedo_email_from_address" value="<?php echo esc_attr($settings['email_from_address']); ?>" class="jedo-input">
                                    </div>
                                </div>

                                <div class="jedo-info-box">
                                    <span class="dashicons dashicons-info"></span>
                                    <div>
                                        <strong><?php esc_html_e('SMTP Compatibility', 'jezweb-email-double-optin'); ?></strong>
                                        <p><?php esc_html_e('This plugin works with WordPress default email, SMTP2GO, FluentSMTP, and any other SMTP plugin. Configure your SMTP settings in your preferred plugin, and verification emails will be sent through that service.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Email Template Tab -->
                    <div class="jedo-tab-pane" id="tab-email">
                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('Email Content', 'jezweb-email-double-optin'); ?></h2>
                                <button type="button" class="jedo-btn jedo-btn-secondary" id="jedo-send-test">
                                    <span class="dashicons dashicons-email"></span>
                                    <?php esc_html_e('Send Test Email', 'jezweb-email-double-optin'); ?>
                                </button>
                            </div>
                            <div class="jedo-card-body">
                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_subject"><?php esc_html_e('Email Subject', 'jezweb-email-double-optin'); ?></label>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <input type="text" id="jedo_email_subject" name="jedo_email_subject" value="<?php echo esc_attr($settings['email_subject']); ?>" class="jedo-input">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_heading"><?php esc_html_e('Email Heading', 'jezweb-email-double-optin'); ?></label>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <input type="text" id="jedo_email_heading" name="jedo_email_heading" value="<?php echo esc_attr($settings['email_heading']); ?>" class="jedo-input">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_body"><?php esc_html_e('Email Body', 'jezweb-email-double-optin'); ?></label>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_email_body" name="jedo_email_body" class="jedo-textarea" rows="8"><?php echo esc_textarea($settings['email_body']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_button_text"><?php esc_html_e('Button Text', 'jezweb-email-double-optin'); ?></label>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="text" id="jedo_email_button_text" name="jedo_email_button_text" value="<?php echo esc_attr($settings['email_button_text']); ?>" class="jedo-input">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_button_color"><?php esc_html_e('Button Color', 'jezweb-email-double-optin'); ?></label>
                                    </div>
                                    <div class="jedo-setting-control">
                                        <input type="text" id="jedo_email_button_color" name="jedo_email_button_color" value="<?php echo esc_attr($settings['email_button_color']); ?>" class="jedo-color-picker">
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_email_footer"><?php esc_html_e('Email Footer', 'jezweb-email-double-optin'); ?></label>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_email_footer" name="jedo_email_footer" class="jedo-textarea" rows="3"><?php echo esc_textarea($settings['email_footer']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-placeholders-box">
                                    <h4><?php esc_html_e('Available Placeholders', 'jezweb-email-double-optin'); ?></h4>
                                    <div class="jedo-placeholders-grid">
                                        <?php foreach (JEDO_Email::get_available_placeholders() as $placeholder => $description) : ?>
                                        <div class="jedo-placeholder-item">
                                            <code><?php echo esc_html($placeholder); ?></code>
                                            <span><?php echo esc_html($description); ?></span>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Messages Tab -->
                    <div class="jedo-tab-pane" id="tab-messages">
                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('User Messages', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_message_verification_sent"><?php esc_html_e('Verification Sent', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Shown after registration when verification email is sent.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_message_verification_sent" name="jedo_message_verification_sent" class="jedo-textarea" rows="2"><?php echo esc_textarea($settings['message_verification_sent']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_message_verification_success"><?php esc_html_e('Verification Success', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Shown when email is successfully verified.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_message_verification_success" name="jedo_message_verification_success" class="jedo-textarea" rows="2"><?php echo esc_textarea($settings['message_verification_success']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_message_verification_failed"><?php esc_html_e('Verification Failed', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Shown when verification link is invalid or expired.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_message_verification_failed" name="jedo_message_verification_failed" class="jedo-textarea" rows="2"><?php echo esc_textarea($settings['message_verification_failed']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_message_already_verified"><?php esc_html_e('Already Verified', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Shown when user clicks verification link again.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_message_already_verified" name="jedo_message_already_verified" class="jedo-textarea" rows="2"><?php echo esc_textarea($settings['message_already_verified']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_message_not_verified"><?php esc_html_e('Not Verified (Login Block)', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Shown when unverified user tries to login.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_message_not_verified" name="jedo_message_not_verified" class="jedo-textarea" rows="2"><?php echo esc_textarea($settings['message_not_verified']); ?></textarea>
                                    </div>
                                </div>

                                <div class="jedo-setting-row">
                                    <div class="jedo-setting-label">
                                        <label for="jedo_message_resend_success"><?php esc_html_e('Resend Success', 'jezweb-email-double-optin'); ?></label>
                                        <p class="jedo-setting-desc"><?php esc_html_e('Shown when verification email is resent.', 'jezweb-email-double-optin'); ?></p>
                                    </div>
                                    <div class="jedo-setting-control jedo-full-width">
                                        <textarea id="jedo_message_resend_success" name="jedo_message_resend_success" class="jedo-textarea" rows="2"><?php echo esc_textarea($settings['message_resend_success']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Tab -->
                    <div class="jedo-tab-pane" id="tab-stats">
                        <div class="jedo-stats-grid" id="jedo-stats-container">
                            <div class="jedo-stat-card">
                                <div class="jedo-stat-icon jedo-stat-icon-blue">
                                    <span class="dashicons dashicons-groups"></span>
                                </div>
                                <div class="jedo-stat-content">
                                    <span class="jedo-stat-number" id="stat-total">-</span>
                                    <span class="jedo-stat-label"><?php esc_html_e('Total Users', 'jezweb-email-double-optin'); ?></span>
                                </div>
                            </div>
                            <div class="jedo-stat-card">
                                <div class="jedo-stat-icon jedo-stat-icon-green">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                </div>
                                <div class="jedo-stat-content">
                                    <span class="jedo-stat-number" id="stat-verified">-</span>
                                    <span class="jedo-stat-label"><?php esc_html_e('Verified', 'jezweb-email-double-optin'); ?></span>
                                </div>
                            </div>
                            <div class="jedo-stat-card">
                                <div class="jedo-stat-icon jedo-stat-icon-orange">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="jedo-stat-content">
                                    <span class="jedo-stat-number" id="stat-pending">-</span>
                                    <span class="jedo-stat-label"><?php esc_html_e('Pending', 'jezweb-email-double-optin'); ?></span>
                                </div>
                            </div>
                            <div class="jedo-stat-card">
                                <div class="jedo-stat-icon jedo-stat-icon-purple">
                                    <span class="dashicons dashicons-chart-line"></span>
                                </div>
                                <div class="jedo-stat-content">
                                    <span class="jedo-stat-number" id="stat-rate">-</span>
                                    <span class="jedo-stat-label"><?php esc_html_e('Verification Rate', 'jezweb-email-double-optin'); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('Recent Verifications', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <table class="jedo-table" id="jedo-recent-verifications">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('User', 'jezweb-email-double-optin'); ?></th>
                                            <th><?php esc_html_e('Email', 'jezweb-email-double-optin'); ?></th>
                                            <th><?php esc_html_e('Status', 'jezweb-email-double-optin'); ?></th>
                                            <th><?php esc_html_e('Registered', 'jezweb-email-double-optin'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Populated via AJAX -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- System Status Tab -->
                    <div class="jedo-tab-pane" id="tab-system">
                        <?php $system_status = Jezweb_Email_Double_Optin::get_system_status(); ?>
                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('System Requirements', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <table class="jedo-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Requirement', 'jezweb-email-double-optin'); ?></th>
                                            <th><?php esc_html_e('Required', 'jezweb-email-double-optin'); ?></th>
                                            <th><?php esc_html_e('Current', 'jezweb-email-double-optin'); ?></th>
                                            <th><?php esc_html_e('Status', 'jezweb-email-double-optin'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><strong><?php esc_html_e('PHP Version', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td><?php echo esc_html($system_status['php']['required']); ?>+</td>
                                            <td><?php echo esc_html($system_status['php']['current']); ?></td>
                                            <td>
                                                <?php if ($system_status['php']['status']) : ?>
                                                    <span class="jedo-status-verified"><?php esc_html_e('OK', 'jezweb-email-double-optin'); ?></span>
                                                <?php else : ?>
                                                    <span class="jedo-status-pending"><?php esc_html_e('Update Required', 'jezweb-email-double-optin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('WordPress Version', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td><?php echo esc_html($system_status['wordpress']['required']); ?>+</td>
                                            <td><?php echo esc_html($system_status['wordpress']['current']); ?></td>
                                            <td>
                                                <?php if ($system_status['wordpress']['status']) : ?>
                                                    <span class="jedo-status-verified"><?php esc_html_e('OK', 'jezweb-email-double-optin'); ?></span>
                                                <?php else : ?>
                                                    <span class="jedo-status-pending"><?php esc_html_e('Update Required', 'jezweb-email-double-optin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('WooCommerce Version', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td><?php echo esc_html($system_status['woocommerce']['required']); ?>+ <?php esc_html_e('(optional)', 'jezweb-email-double-optin'); ?></td>
                                            <td>
                                                <?php if ($system_status['woocommerce']['installed']) : ?>
                                                    <?php echo esc_html($system_status['woocommerce']['current']); ?>
                                                <?php else : ?>
                                                    <?php esc_html_e('Not Installed', 'jezweb-email-double-optin'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$system_status['woocommerce']['installed']) : ?>
                                                    <span class="jedo-badge jedo-badge-warning"><?php esc_html_e('Optional', 'jezweb-email-double-optin'); ?></span>
                                                <?php elseif ($system_status['woocommerce']['status']) : ?>
                                                    <span class="jedo-status-verified"><?php esc_html_e('OK', 'jezweb-email-double-optin'); ?></span>
                                                <?php else : ?>
                                                    <span class="jedo-status-pending"><?php esc_html_e('Update Required', 'jezweb-email-double-optin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('SSL Certificate', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td><?php esc_html_e('Recommended', 'jezweb-email-double-optin'); ?></td>
                                            <td>
                                                <?php if ($system_status['ssl']['status']) : ?>
                                                    <?php esc_html_e('Active', 'jezweb-email-double-optin'); ?>
                                                <?php else : ?>
                                                    <?php esc_html_e('Not Active', 'jezweb-email-double-optin'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($system_status['ssl']['status']) : ?>
                                                    <span class="jedo-status-verified"><?php esc_html_e('OK', 'jezweb-email-double-optin'); ?></span>
                                                <?php else : ?>
                                                    <span class="jedo-badge jedo-badge-warning"><?php esc_html_e('Recommended', 'jezweb-email-double-optin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('Plugin Information', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <table class="jedo-table">
                                    <tbody>
                                        <tr>
                                            <td><strong><?php esc_html_e('Plugin Version', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td><?php echo esc_html(JEDO_VERSION); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('Author', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td>Jezweb</td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('Developer', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td>Mahmud Farooque</td>
                                        </tr>
                                        <tr>
                                            <td><strong><?php esc_html_e('License', 'jezweb-email-double-optin'); ?></strong></td>
                                            <td>GPL v2 or later</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="jedo-card">
                            <div class="jedo-card-header">
                                <h2><?php esc_html_e('Security Features', 'jezweb-email-double-optin'); ?></h2>
                            </div>
                            <div class="jedo-card-body">
                                <ul style="margin: 0; padding-left: 20px; line-height: 2;">
                                    <li><?php esc_html_e('Cryptographically secure verification tokens (256-bit)', 'jezweb-email-double-optin'); ?></li>
                                    <li><?php esc_html_e('Rate limiting on verification email resends (max 5/hour)', 'jezweb-email-double-optin'); ?></li>
                                    <li><?php esc_html_e('CSRF protection with WordPress nonces', 'jezweb-email-double-optin'); ?></li>
                                    <li><?php esc_html_e('Input sanitization and output escaping', 'jezweb-email-double-optin'); ?></li>
                                    <li><?php esc_html_e('Prepared SQL statements to prevent injection', 'jezweb-email-double-optin'); ?></li>
                                    <li><?php esc_html_e('Capability checks for admin functions', 'jezweb-email-double-optin'); ?></li>
                                    <li><?php esc_html_e('Token expiration and cleanup', 'jezweb-email-double-optin'); ?></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="jedo-toast" id="jedo-toast"></div>
        </div>
        <?php
    }

    /**
     * Get all settings
     */
    private function get_all_settings() {
        return array(
            'enable_wp_registration' => get_option('jedo_enable_wp_registration', 'yes'),
            'enable_woocommerce' => get_option('jedo_enable_woocommerce', 'yes'),
            'verification_expiry' => get_option('jedo_verification_expiry', 24),
            'redirect_after_verification' => get_option('jedo_redirect_after_verification', ''),
            'delete_unverified_after' => get_option('jedo_delete_unverified_after', 7),
            'email_from_name' => get_option('jedo_email_from_name', get_bloginfo('name')),
            'email_from_address' => get_option('jedo_email_from_address', get_bloginfo('admin_email')),
            'email_subject' => get_option('jedo_email_subject', __('Please verify your email address', 'jezweb-email-double-optin')),
            'email_heading' => get_option('jedo_email_heading', __('Verify Your Email', 'jezweb-email-double-optin')),
            'email_body' => get_option('jedo_email_body', ''),
            'email_footer' => get_option('jedo_email_footer', ''),
            'email_button_text' => get_option('jedo_email_button_text', __('Verify Email Address', 'jezweb-email-double-optin')),
            'email_button_color' => get_option('jedo_email_button_color', '#0073aa'),
            'message_verification_sent' => get_option('jedo_message_verification_sent', ''),
            'message_verification_success' => get_option('jedo_message_verification_success', ''),
            'message_verification_failed' => get_option('jedo_message_verification_failed', ''),
            'message_already_verified' => get_option('jedo_message_already_verified', ''),
            'message_not_verified' => get_option('jedo_message_not_verified', ''),
            'message_resend_success' => get_option('jedo_message_resend_success', ''),
            // OTP settings
            'verification_method' => get_option('jedo_verification_method', 'link'),
            'otp_length' => get_option('jedo_otp_length', '6'),
            'otp_type' => get_option('jedo_otp_type', 'alphanumeric'),
            'otp_expiry_minutes' => get_option('jedo_otp_expiry_minutes', 5),
            'otp_max_attempts' => get_option('jedo_otp_max_attempts', 5),
        );
    }

    /**
     * AJAX save settings
     */
    public function ajax_save_settings() {
        check_ajax_referer('jedo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-email-double-optin')));
        }

        $settings = isset($_POST['settings']) ? $_POST['settings'] : array();

        // Define allowed settings with their sanitization callbacks
        $allowed_settings = array(
            'jedo_enable_wp_registration' => 'sanitize_text_field',
            'jedo_enable_woocommerce' => 'sanitize_text_field',
            'jedo_verification_expiry' => 'absint',
            'jedo_redirect_after_verification' => 'esc_url_raw',
            'jedo_delete_unverified_after' => 'absint',
            'jedo_email_from_name' => 'sanitize_text_field',
            'jedo_email_from_address' => 'sanitize_email',
            'jedo_email_subject' => 'sanitize_text_field',
            'jedo_email_heading' => 'sanitize_text_field',
            'jedo_email_body' => 'wp_kses_post',
            'jedo_email_footer' => 'wp_kses_post',
            'jedo_email_button_text' => 'sanitize_text_field',
            'jedo_email_button_color' => 'sanitize_hex_color',
            'jedo_message_verification_sent' => 'sanitize_textarea_field',
            'jedo_message_verification_success' => 'sanitize_textarea_field',
            'jedo_message_verification_failed' => 'sanitize_textarea_field',
            'jedo_message_already_verified' => 'sanitize_textarea_field',
            'jedo_message_not_verified' => 'sanitize_textarea_field',
            'jedo_message_resend_success' => 'sanitize_textarea_field',
            // OTP settings
            'jedo_verification_method' => 'sanitize_text_field',
            'jedo_otp_length' => 'sanitize_text_field',
            'jedo_otp_type' => 'sanitize_text_field',
            'jedo_otp_expiry_minutes' => 'absint',
            'jedo_otp_max_attempts' => 'absint',
        );

        foreach ($settings as $key => $value) {
            if (isset($allowed_settings[$key])) {
                $sanitize_callback = $allowed_settings[$key];
                $sanitized_value = call_user_func($sanitize_callback, $value);
                update_option($key, $sanitized_value);
            }
        }

        // Handle checkbox fields (set to 'no' if not sent)
        $checkbox_fields = array('jedo_enable_wp_registration', 'jedo_enable_woocommerce');
        foreach ($checkbox_fields as $field) {
            if (!isset($settings[$field])) {
                update_option($field, 'no');
            }
        }

        wp_send_json_success(array('message' => __('Settings saved successfully!', 'jezweb-email-double-optin')));
    }

    /**
     * AJAX send test email
     */
    public function ajax_send_test_email() {
        check_ajax_referer('jedo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-email-double-optin')));
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (empty($email)) {
            $email = wp_get_current_user()->user_email;
        }

        $result = JEDO_Email::get_instance()->send_test_email($email);

        if ($result) {
            wp_send_json_success(array('message' => sprintf(__('Test email sent to %s', 'jezweb-email-double-optin'), $email)));
        } else {
            wp_send_json_error(array('message' => __('Failed to send test email. Check your email configuration.', 'jezweb-email-double-optin')));
        }
    }

    /**
     * AJAX get stats
     */
    public function ajax_get_stats() {
        check_ajax_referer('jedo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-email-double-optin')));
        }

        global $wpdb;

        // Get total users (excluding admins)
        $total_users = count(get_users(array(
            'role__not_in' => array('administrator'),
            'fields' => 'ID'
        )));

        // Get verified users
        $verified_users = count(get_users(array(
            'meta_key' => 'jedo_email_verified',
            'meta_value' => 'yes',
            'role__not_in' => array('administrator'),
            'fields' => 'ID'
        )));

        // Get pending users
        $pending_users = count(get_users(array(
            'meta_key' => 'jedo_verification_pending',
            'meta_value' => 'yes',
            'role__not_in' => array('administrator'),
            'fields' => 'ID'
        )));

        // Calculate verification rate
        $verification_rate = $total_users > 0 ? round(($verified_users / $total_users) * 100, 1) : 0;

        // Get recent users
        $recent_users = get_users(array(
            'role__not_in' => array('administrator'),
            'orderby' => 'registered',
            'order' => 'DESC',
            'number' => 10,
        ));

        $recent_data = array();
        foreach ($recent_users as $user) {
            $verified = get_user_meta($user->ID, 'jedo_email_verified', true);
            $recent_data[] = array(
                'username' => $user->user_login,
                'email' => $user->user_email,
                'status' => $verified === 'yes' ? 'verified' : 'pending',
                'registered' => human_time_diff(strtotime($user->user_registered), current_time('timestamp')) . ' ' . __('ago', 'jezweb-email-double-optin'),
            );
        }

        wp_send_json_success(array(
            'total' => $total_users,
            'verified' => $verified_users,
            'pending' => $pending_users,
            'rate' => $verification_rate . '%',
            'recent' => $recent_data,
        ));
    }
}
