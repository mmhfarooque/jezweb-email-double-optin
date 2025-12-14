<?php
/**
 * GitHub Updater
 *
 * Enables automatic updates from GitHub releases
 *
 * @package Jezweb_Email_Double_Optin
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
    private $github_username = 'jezweb';

    /**
     * GitHub repository name
     */
    private $github_repo = 'jezweb-email-double-optin';

    /**
     * Plugin slug
     */
    private $plugin_slug;

    /**
     * Plugin file
     */
    private $plugin_file;

    /**
     * GitHub API response cache
     */
    private $github_response;

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
        $this->plugin_slug = plugin_basename(JEDO_PLUGIN_FILE);
        $this->plugin_file = JEDO_PLUGIN_FILE;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);

        // Add update info to plugin row
        add_action('in_plugin_update_message-' . $this->plugin_slug, array($this, 'plugin_update_message'), 10, 2);
    }

    /**
     * Get repository info from GitHub
     */
    private function get_repository_info() {
        if (!empty($this->github_response)) {
            return $this->github_response;
        }

        // Check transient first
        $transient_key = 'jedo_github_update_' . md5($this->github_repo);
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
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data) || isset($data->message)) {
            return false;
        }

        $this->github_response = $data;

        // Cache for 12 hours
        set_transient($transient_key, $data, 12 * HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Check for updates
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
            // Find the zip download URL
            $download_url = $this->get_download_url($github_data);

            if ($download_url) {
                $transient->response[$this->plugin_slug] = (object) array(
                    'slug' => dirname($this->plugin_slug),
                    'plugin' => $this->plugin_slug,
                    'new_version' => $github_version,
                    'url' => $github_data->html_url,
                    'package' => $download_url,
                    'icons' => array(),
                    'banners' => array(),
                    'banners_rtl' => array(),
                    'tested' => '',
                    'requires_php' => '7.4',
                    'compatibility' => new stdClass(),
                );
            }
        }

        return $transient;
    }

    /**
     * Get download URL from release
     */
    private function get_download_url($github_data) {
        // First, check for a zip asset in release assets
        if (!empty($github_data->assets)) {
            foreach ($github_data->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
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
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->plugin_slug)) {
            return $result;
        }

        $github_data = $this->get_repository_info();

        if (!$github_data) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->plugin_file);
        $github_version = ltrim($github_data->tag_name, 'v');

        $plugin_info = (object) array(
            'name' => $plugin_data['Name'],
            'slug' => dirname($this->plugin_slug),
            'version' => $github_version,
            'author' => $plugin_data['Author'],
            'author_profile' => $plugin_data['AuthorURI'],
            'homepage' => $plugin_data['PluginURI'],
            'requires' => '5.0',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'downloaded' => 0,
            'last_updated' => $github_data->published_at,
            'sections' => array(
                'description' => $plugin_data['Description'],
                'changelog' => $this->parse_changelog($github_data->body),
            ),
            'download_link' => $this->get_download_url($github_data),
        );

        return $plugin_info;
    }

    /**
     * Parse changelog from release notes
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return '<p>' . __('No changelog available.', 'jezweb-email-double-optin') . '</p>';
        }

        // Convert markdown to HTML (basic conversion)
        $changelog = $body;

        // Convert headers
        $changelog = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $changelog);
        $changelog = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $changelog);

        // Convert lists
        $changelog = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^- (.*)$/m', '<li>$1</li>', $changelog);

        // Wrap consecutive li elements in ul
        $changelog = preg_replace('/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $changelog);

        // Convert line breaks
        $changelog = nl2br($changelog);

        return $changelog;
    }

    /**
     * Post-install hook to rename directory
     */
    public function post_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Check if this is our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $result;
        }

        // GitHub downloads have a different directory name
        $plugin_folder = WP_PLUGIN_DIR . '/' . dirname($this->plugin_slug);
        $wp_filesystem->move($result['destination'], $plugin_folder);
        $result['destination'] = $plugin_folder;

        // Reactivate plugin
        if (is_plugin_active($this->plugin_slug)) {
            activate_plugin($this->plugin_slug);
        }

        return $result;
    }

    /**
     * Plugin update message
     */
    public function plugin_update_message($plugin_data, $response) {
        if (!empty($response->upgrade_notice)) {
            echo '<br /><strong>' . __('Upgrade Notice:', 'jezweb-email-double-optin') . '</strong> ';
            echo esc_html($response->upgrade_notice);
        }
    }

    /**
     * Force update check
     */
    public static function force_update_check() {
        delete_transient('jedo_github_update_' . md5('jezweb-email-double-optin'));
        delete_site_transient('update_plugins');
    }
}
