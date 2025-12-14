<?php
/**
 * GitHub Updater
 *
 * Enables automatic updates from GitHub releases
 *
 * @package Jezweb_Email_Double_Optin
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Updater Class
 */
class JEDO_GitHub_Updater {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * GitHub username
     */
    private $github_username = 'mmhfarooque';

    /**
     * GitHub repository name
     */
    private $github_repo = 'jezweb-email-double-optin';

    /**
     * Plugin slug
     */
    private $plugin_slug;

    /**
     * Plugin basename
     */
    private $plugin_basename;

    /**
     * Plugin file
     */
    private $plugin_file;

    /**
     * GitHub API response cache
     */
    private $github_response = null;

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
        $this->plugin_file = JEDO_PLUGIN_FILE;
        $this->plugin_basename = JEDO_PLUGIN_BASENAME;
        $this->plugin_slug = dirname($this->plugin_basename);

        // Hook into the update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);

        // Enable auto-updates support
        add_filter('auto_update_plugin', array($this, 'auto_update_plugin'), 10, 2);
        add_filter('plugin_auto_update_setting_html', array($this, 'auto_update_setting_html'), 10, 3);

        // Add "Check for updates" link
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);

        // Handle manual update check
        add_action('admin_init', array($this, 'handle_manual_update_check'));
    }

    /**
     * Get repository info from GitHub
     *
     * @return object|false GitHub release data or false on failure.
     */
    private function get_repository_info() {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        // Check transient first
        $transient_key = 'jedo_github_response';
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            $this->github_response = $cached;
            return $cached;
        }

        // Fetch from GitHub API
        $request_uri = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repo
        );

        $response = wp_remote_get($request_uri, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data) || !is_object($data) || isset($data->message)) {
            return false;
        }

        $this->github_response = $data;

        // Cache for 6 hours
        set_transient($transient_key, $data, 6 * HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Check for updates
     *
     * @param object $transient Update transient.
     * @return object Modified transient.
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $github_data = $this->get_repository_info();

        if (!$github_data) {
            return $transient;
        }

        // Get version from tag (remove 'v' prefix if present)
        $github_version = ltrim($github_data->tag_name, 'v');
        $current_version = JEDO_VERSION;

        // Compare versions
        if (version_compare($github_version, $current_version, '>')) {
            $download_url = $this->get_download_url($github_data);

            if ($download_url) {
                $plugin_data = array(
                    'id' => $this->plugin_basename,
                    'slug' => $this->plugin_slug,
                    'plugin' => $this->plugin_basename,
                    'new_version' => $github_version,
                    'url' => $github_data->html_url,
                    'package' => $download_url,
                    'icons' => array(),
                    'banners' => array(),
                    'banners_rtl' => array(),
                    'tested' => get_bloginfo('version'),
                    'requires_php' => JEDO_MIN_PHP_VERSION,
                    'compatibility' => new stdClass(),
                );

                $transient->response[$this->plugin_basename] = (object) $plugin_data;
            }
        } else {
            // No update available - add to no_update for proper display
            $plugin_data = array(
                'id' => $this->plugin_basename,
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $current_version,
                'url' => 'https://github.com/' . $this->github_username . '/' . $this->github_repo,
                'package' => '',
                'icons' => array(),
                'banners' => array(),
                'banners_rtl' => array(),
                'tested' => get_bloginfo('version'),
                'requires_php' => JEDO_MIN_PHP_VERSION,
                'compatibility' => new stdClass(),
            );

            $transient->no_update[$this->plugin_basename] = (object) $plugin_data;
        }

        return $transient;
    }

    /**
     * Get download URL from release
     *
     * @param object $github_data GitHub release data.
     * @return string|false Download URL or false.
     */
    private function get_download_url($github_data) {
        // First, check for a zip asset in release assets
        if (!empty($github_data->assets) && is_array($github_data->assets)) {
            foreach ($github_data->assets as $asset) {
                if (isset($asset->name) && substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fall back to zipball URL
        if (!empty($github_data->zipball_url)) {
            return $github_data->zipball_url;
        }

        return false;
    }

    /**
     * Plugin info for the update details popup
     *
     * @param mixed  $result Result.
     * @param string $action Action.
     * @param object $args   Arguments.
     * @return mixed Modified result.
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $github_data = $this->get_repository_info();

        if (!$github_data) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->plugin_file);
        $github_version = ltrim($github_data->tag_name, 'v');

        $plugin_info = array(
            'name' => $plugin_data['Name'],
            'slug' => $this->plugin_slug,
            'version' => $github_version,
            'author' => '<a href="' . esc_url($plugin_data['AuthorURI']) . '">' . esc_html($plugin_data['Author']) . '</a>',
            'author_profile' => $plugin_data['AuthorURI'],
            'homepage' => $plugin_data['PluginURI'],
            'requires' => JEDO_MIN_WP_VERSION,
            'tested' => get_bloginfo('version'),
            'requires_php' => JEDO_MIN_PHP_VERSION,
            'downloaded' => 0,
            'last_updated' => isset($github_data->published_at) ? $github_data->published_at : '',
            'sections' => array(
                'description' => $plugin_data['Description'],
                'changelog' => $this->parse_changelog($github_data->body),
                'installation' => $this->get_installation_instructions(),
            ),
            'download_link' => $this->get_download_url($github_data),
            'banners' => array(),
        );

        return (object) $plugin_info;
    }

    /**
     * Get installation instructions
     *
     * @return string Installation HTML.
     */
    private function get_installation_instructions() {
        return '<ol>
            <li>Download the plugin zip file</li>
            <li>Go to WordPress Admin > Plugins > Add New > Upload Plugin</li>
            <li>Upload the zip file and click "Install Now"</li>
            <li>Activate the plugin</li>
            <li>Go to "Email Opt-in" in your admin menu to configure</li>
        </ol>';
    }

    /**
     * Parse changelog from release notes
     *
     * @param string $body Release body.
     * @return string Parsed HTML.
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return '<p>' . esc_html__('No changelog available.', 'jezweb-email-double-optin') . '</p>';
        }

        // Convert markdown to HTML (basic conversion)
        $changelog = esc_html($body);

        // Convert headers
        $changelog = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $changelog);
        $changelog = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $changelog);

        // Convert bold
        $changelog = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $changelog);

        // Convert lists
        $changelog = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^- (.*)$/m', '<li>$1</li>', $changelog);

        // Wrap consecutive li elements in ul
        $changelog = preg_replace('/(<li>.*<\/li>\s*)+/s', '<ul>$0</ul>', $changelog);

        // Convert line breaks
        $changelog = nl2br($changelog);

        return $changelog;
    }

    /**
     * Post-install hook to rename directory
     *
     * @param bool  $response   Response.
     * @param array $hook_extra Hook extra data.
     * @param array $result     Result.
     * @return array Modified result.
     */
    public function post_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Check if this is our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        // The plugin may have been extracted to a different directory name
        $proper_destination = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

        // If the destination is different, move it
        if ($result['destination'] !== $proper_destination) {
            $wp_filesystem->move($result['destination'], $proper_destination);
            $result['destination'] = $proper_destination;
        }

        // Reactivate plugin if it was active
        if (is_plugin_active($this->plugin_basename)) {
            activate_plugin($this->plugin_basename);
        }

        return $result;
    }

    /**
     * Enable auto-updates for this plugin
     *
     * @param bool   $update Whether to update.
     * @param object $item   Plugin item.
     * @return bool Whether to update.
     */
    public function auto_update_plugin($update, $item) {
        if (isset($item->plugin) && $item->plugin === $this->plugin_basename) {
            // Check if auto-updates are enabled for this plugin
            $auto_updates = (array) get_site_option('auto_update_plugins', array());
            return in_array($this->plugin_basename, $auto_updates, true);
        }
        return $update;
    }

    /**
     * Custom auto-update setting HTML for our plugin
     *
     * @param string $html   Current HTML.
     * @param string $plugin Plugin basename.
     * @param array  $plugin_data Plugin data.
     * @return string Modified HTML.
     */
    public function auto_update_setting_html($html, $plugin, $plugin_data) {
        if ($plugin !== $this->plugin_basename) {
            return $html;
        }

        // Get current auto-update status
        $auto_updates = (array) get_site_option('auto_update_plugins', array());
        $is_enabled = in_array($this->plugin_basename, $auto_updates, true);

        // Build the toggle link
        $action = $is_enabled ? 'disable' : 'enable';
        $action_text = $is_enabled
            ? __('Disable auto-updates', 'jezweb-email-double-optin')
            : __('Enable auto-updates', 'jezweb-email-double-optin');

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => $action . '-auto-update',
                    'plugin' => urlencode($this->plugin_basename),
                    'paged' => isset($_GET['paged']) ? absint($_GET['paged']) : 1,
                ),
                admin_url('plugins.php')
            ),
            'updates'
        );

        $html = sprintf(
            '<a href="%s" class="toggle-auto-update" data-wp-action="%s">%s</a>',
            esc_url($url),
            esc_attr($action),
            esc_html($action_text)
        );

        return $html;
    }

    /**
     * Add "Check for updates" link to plugin row
     *
     * @param array  $links Plugin links.
     * @param string $file  Plugin file.
     * @return array Modified links.
     */
    public function plugin_row_meta($links, $file) {
        if ($file !== $this->plugin_basename) {
            return $links;
        }

        $check_url = wp_nonce_url(
            add_query_arg(
                array(
                    'jedo_check_update' => '1',
                ),
                admin_url('plugins.php')
            ),
            'jedo_check_update'
        );

        $links[] = '<a href="' . esc_url($check_url) . '">' . esc_html__('Check for updates', 'jezweb-email-double-optin') . '</a>';

        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_update_check() {
        if (!isset($_GET['jedo_check_update']) || !current_user_can('update_plugins')) {
            return;
        }

        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'jedo_check_update')) {
            return;
        }

        // Clear caches
        $this->force_update_check();

        // Add admin notice
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>';
            esc_html_e('Jezweb Email Double Opt-in: Update check completed.', 'jezweb-email-double-optin');
            echo '</p></div>';
        });

        // Redirect to remove query args
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    /**
     * Force update check by clearing caches
     */
    public function force_update_check() {
        $this->github_response = null;
        delete_transient('jedo_github_response');
        delete_site_transient('update_plugins');

        // Trigger a fresh check
        wp_update_plugins();
    }

    /**
     * Static method to force update check
     */
    public static function clear_update_cache() {
        delete_transient('jedo_github_response');
        delete_site_transient('update_plugins');
    }
}
