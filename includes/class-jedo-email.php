<?php
/**
 * Email Handler
 *
 * @package Jezweb_Email_Double_Optin
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email Class
 */
class JEDO_Email {

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
        // Add filters for email customization
        add_filter('wp_mail_from', array($this, 'custom_mail_from'), 100);
        add_filter('wp_mail_from_name', array($this, 'custom_mail_from_name'), 100);
    }

    /**
     * Custom mail from
     */
    public function custom_mail_from($from) {
        $custom_from = get_option('jedo_email_from_address');
        if (!empty($custom_from) && is_email($custom_from)) {
            return $custom_from;
        }
        return $from;
    }

    /**
     * Custom mail from name
     */
    public function custom_mail_from_name($name) {
        $custom_name = get_option('jedo_email_from_name');
        if (!empty($custom_name)) {
            return $custom_name;
        }
        return $name;
    }

    /**
     * Send verification email
     *
     * @param int    $user_id User ID (can be 0 for guest checkout).
     * @param string $email   Email address.
     * @param string $type    Verification type.
     * @return bool|array True/false for link mode, array with token/otp for OTP mode
     */
    public function send_verification_email($user_id, $email, $type = 'registration') {
        $user = get_userdata($user_id);

        // For guest checkout, create a minimal user object
        if (!$user && $user_id === 0) {
            $user = (object) array(
                'ID' => 0,
                'user_login' => $email,
                'user_email' => $email,
                'display_name' => $email,
                'first_name' => '',
                'last_name' => '',
            );
        } elseif (!$user) {
            return false;
        }

        $verification = JEDO_Verification::get_instance();
        $is_otp = JEDO_Verification::is_otp_enabled();

        if ($is_otp) {
            // OTP mode - generate token with OTP code
            $token_data = $verification->generate_otp_token($user_id, $email, $type);
            $otp_code = $token_data['otp_code'];

            // Build OTP email
            $subject = $this->replace_placeholders(get_option('jedo_email_subject'), $user, '', $otp_code);
            $message = $this->build_otp_email_html($user, $otp_code);
        } else {
            // Link mode - existing behavior
            $token = $verification->generate_token($user_id, $email, $type);
            $verification_url = $verification->get_verification_url($token);

            $subject = $this->replace_placeholders(get_option('jedo_email_subject'), $user, $verification_url);
            $message = $this->build_email_html($user, $verification_url);
        }

        // Send email
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        $from_name = get_option('jedo_email_from_name', get_bloginfo('name'));
        $from_email = get_option('jedo_email_from_address', get_bloginfo('admin_email'));

        if (!empty($from_name) && !empty($from_email)) {
            $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
        }

        // Log email attempt for debugging
        do_action('jedo_before_send_email', $email, $subject, $user_id, $type);

        $sent = wp_mail($email, $subject, $message, $headers);

        // Log result
        do_action('jedo_after_send_email', $sent, $email, $subject, $user_id, $type);

        if ($is_otp) {
            return array(
                'sent' => $sent,
                'token' => $token_data['token'],
            );
        }

        return $sent;
    }

    /**
     * Build email HTML
     */
    private function build_email_html($user, $verification_url) {
        $heading = $this->replace_placeholders(get_option('jedo_email_heading'), $user, $verification_url);
        $body = $this->replace_placeholders(get_option('jedo_email_body'), $user, $verification_url);
        $footer = $this->replace_placeholders(get_option('jedo_email_footer'), $user, $verification_url);
        $button_text = get_option('jedo_email_button_text', __('Verify Email Address', 'jezweb-email-double-optin'));
        $button_color = get_option('jedo_email_button_color', '#0073aa');

        // Convert line breaks to HTML
        $body = nl2br(esc_html($body));

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($heading); ?></title>
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; background-color: #f5f5f5;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f5f5f5;">
                <tr>
                    <td style="padding: 40px 20px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto;">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: <?php echo esc_attr($button_color); ?>; padding: 30px 40px; text-align: center; border-radius: 8px 8px 0 0;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        <?php echo esc_html($heading); ?>
                                    </h1>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style="background-color: #ffffff; padding: 40px;">
                                    <div style="color: #333333; font-size: 16px; line-height: 1.6;">
                                        <?php echo $body; ?>
                                    </div>

                                    <!-- Button -->
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 30px 0;">
                                        <tr>
                                            <td style="text-align: center;">
                                                <a href="<?php echo esc_url($verification_url); ?>"
                                                   style="display: inline-block; background-color: <?php echo esc_attr($button_color); ?>; color: #ffffff; padding: 16px 40px; font-size: 16px; font-weight: 600; text-decoration: none; border-radius: 6px; transition: background-color 0.2s;">
                                                    <?php echo esc_html($button_text); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    </table>

                                    <!-- Alternative link -->
                                    <p style="color: #666666; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eeeeee;">
                                        <?php esc_html_e('If the button above doesn\'t work, copy and paste this link into your browser:', 'jezweb-email-double-optin'); ?>
                                    </p>
                                    <p style="color: #0073aa; font-size: 14px; word-break: break-all;">
                                        <a href="<?php echo esc_url($verification_url); ?>" style="color: <?php echo esc_attr($button_color); ?>;">
                                            <?php echo esc_url($verification_url); ?>
                                        </a>
                                    </p>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f9f9f9; padding: 30px 40px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #eeeeee;">
                                    <p style="margin: 0; color: #999999; font-size: 13px; line-height: 1.5;">
                                        <?php echo nl2br(esc_html($footer)); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Build OTP email HTML
     *
     * @param object $user     User object.
     * @param string $otp_code OTP code.
     * @return string Email HTML.
     */
    private function build_otp_email_html($user, $otp_code) {
        $heading = $this->replace_placeholders(get_option('jedo_email_heading'), $user, '', $otp_code);
        $body = $this->replace_placeholders(get_option('jedo_email_body'), $user, '', $otp_code);
        $footer = $this->replace_placeholders(get_option('jedo_email_footer'), $user, '', $otp_code);
        $button_color = get_option('jedo_email_button_color', '#0073aa');
        $expiry_minutes = absint(get_option('jedo_otp_expiry_minutes', 5));

        // Convert line breaks to HTML
        $body = nl2br(esc_html($body));

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($heading); ?></title>
        </head>
        <body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; background-color: #f5f5f5;">
            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #f5f5f5;">
                <tr>
                    <td style="padding: 40px 20px;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width: 600px; margin: 0 auto;">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: <?php echo esc_attr($button_color); ?>; padding: 30px 40px; text-align: center; border-radius: 8px 8px 0 0;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        <?php echo esc_html($heading); ?>
                                    </h1>
                                </td>
                            </tr>
                            <!-- Body -->
                            <tr>
                                <td style="background-color: #ffffff; padding: 40px;">
                                    <div style="color: #333333; font-size: 16px; line-height: 1.6;">
                                        <?php echo $body; ?>
                                    </div>

                                    <!-- OTP Code Display -->
                                    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 30px 0;">
                                        <tr>
                                            <td style="text-align: center;">
                                                <div style="background-color: #f8f9fa; border: 2px dashed <?php echo esc_attr($button_color); ?>; border-radius: 8px; padding: 25px; display: inline-block;">
                                                    <p style="margin: 0 0 10px; color: #666666; font-size: 14px;">
                                                        <?php esc_html_e('Your verification code is:', 'jezweb-email-double-optin'); ?>
                                                    </p>
                                                    <p style="margin: 0; font-size: 36px; font-weight: 700; letter-spacing: 8px; color: <?php echo esc_attr($button_color); ?>; font-family: 'Courier New', monospace;">
                                                        <?php echo esc_html($otp_code); ?>
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>

                                    <!-- Expiry notice -->
                                    <p style="color: #666666; font-size: 14px; text-align: center; margin-top: 20px;">
                                        <strong><?php
                                        printf(
                                            /* translators: %d: number of minutes until OTP expires */
                                            esc_html__('This code will expire in %d minutes.', 'jezweb-email-double-optin'),
                                            $expiry_minutes
                                        );
                                        ?></strong>
                                    </p>

                                    <p style="color: #666666; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eeeeee;">
                                        <?php esc_html_e('Enter this code on the verification page to complete your registration.', 'jezweb-email-double-optin'); ?>
                                    </p>
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f9f9f9; padding: 30px 40px; text-align: center; border-radius: 0 0 8px 8px; border-top: 1px solid #eeeeee;">
                                    <p style="margin: 0; color: #999999; font-size: 13px; line-height: 1.5;">
                                        <?php echo nl2br(esc_html($footer)); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Replace placeholders in text
     *
     * @param string $text            Text with placeholders.
     * @param object $user            User object.
     * @param string $verification_url Verification URL (optional).
     * @param string $otp_code        OTP code (optional).
     * @return string
     */
    private function replace_placeholders($text, $user, $verification_url = '', $otp_code = '') {
        $expiry_hours = get_option('jedo_verification_expiry', 24);
        $expiry_minutes = get_option('jedo_otp_expiry_minutes', 5);

        $replacements = array(
            '{user_name}' => $user->display_name ?: $user->user_login,
            '{user_login}' => $user->user_login,
            '{user_email}' => $user->user_email,
            '{first_name}' => $user->first_name ?: $user->user_login,
            '{last_name}' => $user->last_name,
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => home_url(),
            '{admin_email}' => get_bloginfo('admin_email'),
            '{verification_url}' => $verification_url,
            '{expiry_hours}' => $expiry_hours,
            '{otp_code}' => $otp_code,
            '{expiry_minutes}' => $expiry_minutes,
        );

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    /**
     * Get available placeholders
     */
    public static function get_available_placeholders() {
        return array(
            '{user_name}' => __('User display name or username', 'jezweb-email-double-optin'),
            '{user_login}' => __('User login/username', 'jezweb-email-double-optin'),
            '{user_email}' => __('User email address', 'jezweb-email-double-optin'),
            '{first_name}' => __('User first name', 'jezweb-email-double-optin'),
            '{last_name}' => __('User last name', 'jezweb-email-double-optin'),
            '{site_name}' => __('Website name', 'jezweb-email-double-optin'),
            '{site_url}' => __('Website URL', 'jezweb-email-double-optin'),
            '{admin_email}' => __('Admin email address', 'jezweb-email-double-optin'),
            '{verification_url}' => __('Verification link URL (link mode)', 'jezweb-email-double-optin'),
            '{expiry_hours}' => __('Hours until link expires', 'jezweb-email-double-optin'),
            '{otp_code}' => __('One-Time Password code (OTP mode)', 'jezweb-email-double-optin'),
            '{expiry_minutes}' => __('Minutes until OTP expires', 'jezweb-email-double-optin'),
        );
    }

    /**
     * Send test email
     */
    public function send_test_email($email) {
        // Create a fake user object for testing
        $current_user = wp_get_current_user();

        $test_user = (object) array(
            'ID' => $current_user->ID,
            'user_login' => $current_user->user_login,
            'user_email' => $email,
            'display_name' => $current_user->display_name,
            'first_name' => $current_user->first_name ?: 'Test',
            'last_name' => $current_user->last_name ?: 'User',
        );

        $is_otp = JEDO_Verification::is_otp_enabled();

        if ($is_otp) {
            // OTP mode - generate a sample OTP for testing
            $test_otp = 'A1B2C3';
            $subject = '[TEST] ' . $this->replace_placeholders(get_option('jedo_email_subject'), $test_user, '', $test_otp);
            $message = $this->build_otp_email_html($test_user, $test_otp);
        } else {
            // Link mode
            $verification_url = home_url('/?token=test_token_12345');
            $subject = '[TEST] ' . $this->replace_placeholders(get_option('jedo_email_subject'), $test_user, $verification_url);
            $message = $this->build_email_html($test_user, $verification_url);
        }

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        return wp_mail($email, $subject, $message, $headers);
    }
}
