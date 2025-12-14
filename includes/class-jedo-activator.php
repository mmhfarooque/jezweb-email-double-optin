<?php
/**
 * Plugin Activator
 *
 * @package Jezweb_Email_Double_Optin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activator Class
 */
class JEDO_Activator {

    /**
     * Activate the plugin
     */
    public static function activate() {
        self::create_tables();
        self::set_default_options();
        self::create_verification_page();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Deactivate the plugin
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'jedo_verification_tokens';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            token varchar(64) NOT NULL,
            email varchar(255) NOT NULL,
            type varchar(50) NOT NULL DEFAULT 'registration',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            verified_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id),
            KEY email (email)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Store database version
        update_option('jedo_db_version', '1.0.0');
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        $defaults = array(
            // General Settings
            'enable_wp_registration' => 'yes',
            'enable_woocommerce' => 'yes',
            'verification_expiry' => 24, // hours
            'redirect_after_verification' => '',
            'delete_unverified_after' => 7, // days, 0 = never

            // Email Settings
            'email_from_name' => get_bloginfo('name'),
            'email_from_address' => get_bloginfo('admin_email'),
            'email_subject' => __('Please verify your email address', 'jezweb-email-double-optin'),
            'email_heading' => __('Verify Your Email', 'jezweb-email-double-optin'),
            'email_body' => self::get_default_email_body(),
            'email_footer' => self::get_default_email_footer(),
            'email_button_text' => __('Verify Email Address', 'jezweb-email-double-optin'),
            'email_button_color' => '#0073aa',

            // Messages
            'message_verification_sent' => __('A verification email has been sent to your email address. Please check your inbox and click the verification link.', 'jezweb-email-double-optin'),
            'message_verification_success' => __('Your email has been verified successfully! You can now log in.', 'jezweb-email-double-optin'),
            'message_verification_failed' => __('Email verification failed. The link may have expired or is invalid.', 'jezweb-email-double-optin'),
            'message_already_verified' => __('Your email has already been verified.', 'jezweb-email-double-optin'),
            'message_not_verified' => __('Please verify your email address before logging in. Check your inbox for the verification email.', 'jezweb-email-double-optin'),
            'message_resend_success' => __('Verification email has been resent. Please check your inbox.', 'jezweb-email-double-optin'),

            // Advanced
            'email_method' => 'wordpress', // wordpress, smtp2go, fluentsmtp
        );

        foreach ($defaults as $key => $value) {
            if (get_option('jedo_' . $key) === false) {
                update_option('jedo_' . $key, $value);
            }
        }
    }

    /**
     * Get default email body
     */
    private static function get_default_email_body() {
        return __('Hi {user_name},

Thank you for registering at {site_name}. To complete your registration, please verify your email address by clicking the button below.

This link will expire in {expiry_hours} hours.

If you did not create an account, please ignore this email.', 'jezweb-email-double-optin');
    }

    /**
     * Get default email footer
     */
    private static function get_default_email_footer() {
        return __('This email was sent from {site_name}. If you have any questions, please contact us at {admin_email}.', 'jezweb-email-double-optin');
    }

    /**
     * Create verification page
     */
    private static function create_verification_page() {
        $page_id = get_option('jedo_verification_page_id');

        if ($page_id && get_post($page_id)) {
            return;
        }

        $page_data = array(
            'post_title'     => __('Email Verification', 'jezweb-email-double-optin'),
            'post_content'   => '[jedo_email_verification]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_author'    => 1,
            'comment_status' => 'closed',
        );

        $page_id = wp_insert_post($page_data);

        if ($page_id && !is_wp_error($page_id)) {
            update_option('jedo_verification_page_id', $page_id);
        }
    }
}
