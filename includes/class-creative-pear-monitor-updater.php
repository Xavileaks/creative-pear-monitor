<?php

defined('ABSPATH') || exit;

final class Creative_Pear_Monitor_Updater
{
    private const SLUG = 'creative-pear-monitor';
    private const REPOSITORY = 'Xavileaks/creative-pear-monitor';
    private const CACHE_KEY = 'creative_pear_monitor_github_release';

    private string $plugin_file;
    private string $plugin_basename;
    private string $current_version;

    public function __construct(string $plugin_file, string $current_version)
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->current_version = $current_version;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_information'], 20, 3);
        add_filter('auto_update_plugin', [$this, 'enable_automatic_update'], 10, 2);
    }

    public function inject_update($transient)
    {
        if (! is_object($transient)) return $transient;

        $release = $this->latest_release();
        if (! $release || ! version_compare($this->current_version, $release['version'], '<')) {
            return $transient;
        }

        $transient->response[$this->plugin_basename] = (object) [
            'id' => 'github.com/'.self::REPOSITORY,
            'slug' => self::SLUG,
            'plugin' => $this->plugin_basename,
            'new_version' => $release['version'],
            'url' => $release['homepage'],
            'package' => $release['package'],
            'requires' => '6.2',
            'requires_php' => '7.4',
            'tested' => get_bloginfo('version'),
            'icons' => [],
            'banners' => [],
            'autoupdate' => true,
        ];

        return $transient;
    }

    public function plugin_information($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::SLUG) return $result;
        $release = $this->latest_release();
        if (! $release) return $result;

        return (object) [
            'name' => 'Creative Pear Monitor',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://creativepearagency.com">Creative Pear</a>',
            'homepage' => $release['homepage'],
            'requires' => '6.2',
            'requires_php' => '7.4',
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Conecta WordPress con el centro de control técnico de Creative Pear.',
                'changelog' => ! empty($release['notes']) ? wpautop(esc_html($release['notes'])) : 'Mejoras y correcciones automáticas.',
            ],
        ];
    }

    public function enable_automatic_update($enabled, $item)
    {
        if (is_object($item) && (($item->plugin ?? '') === $this->plugin_basename || ($item->slug ?? '') === self::SLUG)) {
            return true;
        }
        return $enabled;
    }

    public function install_available_update(): void
    {
        if (! function_exists('wp_update_plugins')) require_once ABSPATH.'wp-admin/includes/update.php';
        delete_site_transient('update_plugins');
        delete_site_transient(self::CACHE_KEY);
        wp_update_plugins();

        $updates = get_site_transient('update_plugins');
        if (! is_object($updates) || empty($updates->response[$this->plugin_basename])) return;

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($this->plugin_basename, ['clear_update_cache' => true]);
        if (is_wp_error($result)) error_log('Creative Pear Monitor update error: '.$result->get_error_message());
    }

    private function latest_release(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && ! empty($cached['version'])) return $cached;

        $response = wp_remote_get('https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest', [
            'timeout' => 12,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Creative-Pear-Monitor/'.$this->current_version,
            ],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($data) || empty($data['tag_name']) || empty($data['assets'])) return null;

        $package = null;
        foreach ($data['assets'] as $asset) {
            if (($asset['name'] ?? '') === self::SLUG.'.zip') {
                $package = $asset['browser_download_url'] ?? null;
                break;
            }
        }
        if (! $package) return null;

        $release = [
            'version' => ltrim((string) $data['tag_name'], 'vV'),
            'package' => esc_url_raw($package),
            'homepage' => esc_url_raw($data['html_url'] ?? 'https://github.com/'.self::REPOSITORY),
            'notes' => (string) ($data['body'] ?? ''),
        ];
        set_site_transient(self::CACHE_KEY, $release, 5 * MINUTE_IN_SECONDS);
        return $release;
    }
}
