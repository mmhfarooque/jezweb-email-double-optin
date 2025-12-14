<?php
/**
 * Uninstall Script
 *
 * Fired when the plugin is uninstalled.
 *
 * @package Jezweb_Email_Double_Optin
 */

// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
$options = array(
    'jedo_enable_wp_registration',
    'jedo_enable_woocommerce',
    'jedo_verification_expiry',
    'jedo_redirect_after_verification',
    'jedo_delete_unverified_after',
    'jedo_email_from_name',
    'jedo_email_from_address',
    'jedo_email_subject',
    'jedo_email_heading',
    'jedo_email_body',
    'jedo_email_footer',
    'jedo_email_button_text',
    'jedo_email_button_color',
    'jedo_message_verification_sent',
    'jedo_message_verification_success',
    'jedo_message_verification_failed',
    'jedo_message_already_verified',
    'jedo_message_not_verified',
    'jedo_message_resend_success',
    'jedo_verification_page_id',
    'jedo_db_version',
);

foreach ($options as $option) {
    delete_option($option);
}

// Delete database table
global $wpdb;
$table_name = $wpdb->prefix . 'jedo_verification_tokens';
// Table name is safe - prefix is from WordPress and suffix is hardcoded
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query("DROP TABLE IF EXISTS `" . esc_sql($table_name) . "`");

// Delete user meta
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'jedo_%'");

// Delete verification page
$page_id = get_option('jedo_verification_page_id');
if ($page_id) {
    wp_delete_post($page_id, true);
}

// Clear scheduled events
wp_clear_scheduled_hook('jedo_cleanup_tokens');

// Clear transients
delete_transient('jedo_github_update_' . md5('jezweb-email-double-optin'));
