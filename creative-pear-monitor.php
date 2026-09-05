<?php
/**
 * Plugin Name: Creative Pear Monitor
 * Plugin URI: https://github.com/Xavileaks/creative-pear-monitor
 * Description: Envía inventario técnico y señales de salud al centro de control de Creative Pear.
 * Version: 1.2.0
 * Author: Creative Pear
 * Author URI: https://creativepearagency.com
 * Update URI: https://github.com/Xavileaks/creative-pear-monitor
 * Text Domain: creative-pear-monitor
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

require_once __DIR__.'/includes/class-creative-pear-monitor-updater.php';

final class Creative_Pear_Monitor
{
    private const VERSION = '1.2.0';

    private const OPTION = 'creative_pear_monitor_settings';

    private const DASHBOARD_URL = 'https://status.creativepearagency.com';

    private const EVENT = 'creative_pear_monitor_report';

    private const LEGACY_UPDATE_EVENT = 'creative_pear_monitor_update';

    private const CONNECTION_HASH_OPTION = 'creative_pear_monitor_connection_hash';

    private bool $report_queued = false;

    private Creative_Pear_Monitor_Updater $updater;

    public function __construct()
    {
        $this->updater = new Creative_Pear_Monitor_Updater(__FILE__, self::VERSION);
        add_filter('cron_schedules', [$this, 'schedule']);
        add_action(self::EVENT, [$this, 'send_report']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('plugins_loaded', [$this, 'migrate_schedule']);
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), [$this, 'action_links']);
        add_filter('all_plugins', [$this, 'localize_plugin_data']);
        add_action('update_option_'.self::OPTION, [$this, 'queue_report'], 10, 2);
        add_action('activated_plugin', [$this, 'queue_report']);
        add_action('deactivated_plugin', [$this, 'queue_report']);
        add_action('switch_theme', [$this, 'queue_report']);
        add_action('upgrader_process_complete', [$this, 'queue_report'], 10, 2);
        add_action('user_register', [$this, 'queue_report']);
        add_action('profile_update', [$this, 'queue_report']);
        add_action('deleted_user', [$this, 'queue_report']);
        add_action('wp_mail_failed', fn () => update_option('creative_pear_mail_state', ['status' => 'failed', 'at' => time()], false));
        add_action('wp_mail_succeeded', fn () => update_option('creative_pear_mail_state', ['status' => 'ok', 'at' => time()], false));
        register_activation_hook(__FILE__, [self::class, 'activate']);
        register_deactivation_hook(__FILE__, [self::class, 'deactivate']);
    }

    public static function activate(): void
    {
        if (! wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + 60, 'creative_pear_five_minutes', self::EVENT);
        }
        update_option('creative_pear_monitor_version', self::VERSION, false);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::EVENT);
        wp_clear_scheduled_hook(self::LEGACY_UPDATE_EVENT);
    }

    public function schedule(array $schedules): array
    {
        $schedules['creative_pear_five_minutes'] = [
            'interval' => 300,
            'display' => $this->text('Cada 5 minutos', 'Every 5 minutes'),
        ];

        return $schedules;
    }

    public function menu(): void
    {
        add_options_page('Creative Pear Monitor', 'Creative Pear Monitor', 'manage_options', 'creative-pear-monitor', [$this, 'page']);
    }

    public function action_links(array $links): array
    {
        array_unshift($links, '<a href="'.esc_url(admin_url('options-general.php?page=creative-pear-monitor')).'">'.esc_html($this->text('Ajustes', 'Settings')).'</a>');

        return $links;
    }

    public function localize_plugin_data(array $plugins): array
    {
        $plugin_file = plugin_basename(__FILE__);

        if (isset($plugins[$plugin_file])) {
            $plugins[$plugin_file]['Description'] = $this->text(
                'Envía inventario técnico y señales de salud al centro de control de Creative Pear.',
                'Sends technical inventory and health signals to the Creative Pear control center.'
            );
        }

        return $plugins;
    }

    public function admin_assets(string $hook): void
    {
        if ($hook !== 'settings_page_creative-pear-monitor') {
            return;
        }

        wp_enqueue_style(
            'creative-pear-monitor-admin',
            plugin_dir_url(__FILE__).'assets/admin.css',
            [],
            self::VERSION
        );
    }

    public function migrate_schedule(): void
    {
        if (get_option('creative_pear_monitor_version') === self::VERSION) {
            return;
        }
        wp_clear_scheduled_hook(self::EVENT);
        wp_clear_scheduled_hook(self::LEGACY_UPDATE_EVENT);
        wp_schedule_event(time() + 10, 'creative_pear_five_minutes', self::EVENT);
        $settings = (array) get_option(self::OPTION, []);
        $normalized_settings = [
            'site_id' => absint($settings['site_id'] ?? 0),
            'key' => sanitize_text_field($settings['key'] ?? ''),
        ];
        if ($settings !== $normalized_settings) {
            update_option(self::OPTION, $normalized_settings, false);
            $settings = $normalized_settings;
        }
        if (get_option('creative_pear_monitor_last_success') && $this->has_credentials($settings)) {
            update_option(self::CONNECTION_HASH_OPTION, $this->connection_hash($settings), false);
        }
        update_option('creative_pear_monitor_version', self::VERSION, false);
    }

    public function queue_report(...$unused): void
    {
        if ($this->report_queued) {
            return;
        }
        $this->report_queued = true;
        add_action('shutdown', [$this, 'send_report'], 100);
    }

    public function register(): void
    {
        register_setting('creative_pear_monitor', self::OPTION, ['sanitize_callback' => function ($value) {
            return [
                'site_id' => absint($value['site_id'] ?? 0),
                'key' => sanitize_text_field($value['key'] ?? ''),
            ];
        }]);
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = (array) get_option(self::OPTION, ['site_id' => '', 'key' => '']);
        $notice = null;

        if (isset($_POST['creative_pear_send_now']) && check_admin_referer('creative_pear_send_now')) {
            $result = $this->send_report();
            $notice = [
                'class' => is_wp_error($result) ? 'notice-error' : 'notice-success',
                'message' => is_wp_error($result)
                    ? $result->get_error_message()
                    : $this->text('Reporte enviado correctamente.', 'Report sent successfully.'),
            ];
        }

        $last_success = (int) get_option('creative_pear_monitor_last_success', 0);
        $saved_hash = (string) get_option(self::CONNECTION_HASH_OPTION, '');
        $configured = $this->has_credentials($settings);
        $connected = $configured && $last_success > 0 && hash_equals($saved_hash, $this->connection_hash($settings));

        if ($connected) {
            $status_title = $this->text('Conectado correctamente', 'Connected successfully');
            $status_message = sprintf(
                $this->text(
                    'Este WordPress está enviando datos técnicos de forma segura. Última conexión correcta hace %s.',
                    'This WordPress site is securely sending technical data. Last successful connection was %s ago.'
                ),
                human_time_diff($last_success, time())
            );
        } elseif ($configured) {
            $status_title = $this->text('Conexión pendiente de verificación', 'Connection awaiting verification');
            $status_message = $this->text(
                'Guarda los datos o envía un reporte para comprobar el ID y la clave del agente.',
                'Save the details or send a report to verify the site ID and agent key.'
            );
        } else {
            $status_title = $this->text('El sitio todavía no está conectado', 'The site is not connected yet');
            $status_message = $this->text(
                'Introduce el ID del sitio y la clave del agente generados en Creative Pear Status.',
                'Enter the site ID and agent key generated in Creative Pear Status.'
            );
        }

        if ($notice) {
            echo '<div class="notice '.esc_attr($notice['class']).' is-dismissible"><p>'.esc_html($notice['message']).'</p></div>';
        }
        ?>
        <div class="wrap cp-monitor-settings">
            <h1>Creative Pear Monitor</h1>
            <p class="cp-monitor-intro">
                <?php echo esc_html($this->text(
                    'Conecta este WordPress con el centro de control. El agente solo envía datos técnicos; nunca envía contraseñas ni contenido privado.',
                    'Connect this WordPress site to the control center. The agent only sends technical data; it never sends passwords or private content.'
                )); ?>
            </p>

            <form id="cp-monitor-settings-form" method="post" action="options.php">
                <?php settings_fields('creative_pear_monitor'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="cp-site"><?php echo esc_html($this->text('ID del sitio', 'Site ID')); ?></label></th>
                        <td><input id="cp-site" name="<?php echo esc_attr(self::OPTION); ?>[site_id]" type="number" value="<?php echo esc_attr($settings['site_id'] ?? ''); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="cp-key"><?php echo esc_html($this->text('Clave del agente', 'Agent key')); ?></label></th>
                        <td><input class="large-text" id="cp-key" name="<?php echo esc_attr(self::OPTION); ?>[key]" type="password" autocomplete="off" value="<?php echo esc_attr($settings['key'] ?? ''); ?>" required></td>
                    </tr>
                </table>
            </form>

            <div class="cp-monitor-actions">
                <button class="button button-primary" type="submit" form="cp-monitor-settings-form">
                    <?php echo esc_html($this->text('Guardar conexión', 'Save connection')); ?>
                </button>
                <form method="post">
                    <?php wp_nonce_field('creative_pear_send_now'); ?>
                    <button class="button button-secondary" name="creative_pear_send_now" value="1">
                        <?php echo esc_html($this->text('Enviar reporte ahora', 'Send report now')); ?>
                    </button>
                </form>
            </div>

            <div class="cp-connection-status <?php echo $connected ? 'is-connected' : 'is-disconnected'; ?>" role="status">
                <span class="dashicons <?php echo $connected ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                <div>
                    <strong><?php echo esc_html($status_title); ?></strong>
                    <p><?php echo esc_html($status_message); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    public function send_report()
    {
        $settings = get_option(self::OPTION, []);
        if (! $this->has_credentials($settings)) {
            return new WP_Error(
                'cp_not_configured',
                $this->text('Configura el ID del sitio y la clave del agente.', 'Configure the site ID and agent key.')
            );
        }

        wp_update_plugins();
        wp_update_themes();
        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates = get_site_transient('update_themes');
        $admins = array_map(fn ($user) => ['id' => (string) $user->ID, 'username' => $user->user_login, 'email' => $user->user_email, 'display_name' => $user->display_name], get_users(['role' => 'administrator']));
        $mail = get_option('creative_pear_mail_state', ['status' => 'unknown']);
        $woocommerce = class_exists('WooCommerce');
        $checkout = 'not_applicable';
        if ($woocommerce) {
            $checkout_page = function_exists('wc_get_page_id') ? wc_get_page_id('checkout') : -1;
            $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->get_available_payment_gateways() : [];
            $checkout = $checkout_page > 0 && count($gateways) > 0 ? 'ok' : 'failed';
        }
        $active = (array) get_option('active_plugins', []);
        $plugins = get_plugins();
        $plugin_inventory = [];
        foreach ($plugins as $file => $plugin) {
            $plugin_inventory[] = [
                'file' => $file,
                'name' => $plugin['Name'] ?? $file,
                'version' => $plugin['Version'] ?? null,
                'active' => in_array($file, $active, true),
                'update_version' => is_object($plugin_updates) && isset($plugin_updates->response[$file]) ? ($plugin_updates->response[$file]->new_version ?? null) : null,
            ];
        }
        $theme_inventory = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $theme_inventory[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => wp_get_theme()->get_stylesheet() === $slug,
                'update_version' => is_object($theme_updates) && isset($theme_updates->response[$slug]) ? ($theme_updates->response[$slug]['new_version'] ?? null) : null,
            ];
        }
        $woo_details = $this->woocommerce_details($woocommerce);
        $database = $this->database_details();
        $inventory_hash = hash('sha256', wp_json_encode([$plugin_inventory, $theme_inventory, $admins]));
        $previous_hash = (string) get_option('creative_pear_monitor_inventory_hash', '');
        update_option('creative_pear_monitor_inventory_hash', $inventory_hash, false);
        $form_plugins = ['contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php', 'elementor-pro/elementor-pro.php', 'gravityforms/gravityforms.php'];
        $forms = count(array_intersect($active, $form_plugins)) ? 'ok' : 'unknown';
        $payload = [
            'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION,
            'plugins_outdated' => is_object($plugin_updates) ? count((array) $plugin_updates->response) : 0,
            'themes_outdated' => is_object($theme_updates) ? count((array) $theme_updates->response) : 0,
            'forms_status' => $forms, 'smtp_status' => $mail['status'], 'analytics_status' => 'unknown',
            'checkout_status' => $checkout, 'admins' => $admins,
            'metadata' => [
                'woocommerce' => $woocommerce,
                'woocommerce_details' => $woo_details,
                'active_plugins' => count($active),
                'plugins' => $plugin_inventory,
                'themes' => $theme_inventory,
                'elementor' => [
                    'active' => defined('ELEMENTOR_VERSION'),
                    'version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                    'pro_active' => defined('ELEMENTOR_PRO_VERSION'),
                    'pro_version' => defined('ELEMENTOR_PRO_VERSION') ? ELEMENTOR_PRO_VERSION : null,
                ],
                'database' => $database,
                'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
                'scheduled_events' => count(_get_cron_array() ?: []),
                'action_scheduler_pending' => function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['status' => 'pending', 'per_page' => 100], 'ids')) : null,
                'multisite' => is_multisite(),
                'debug' => defined('WP_DEBUG') && WP_DEBUG,
                'inventory_changed' => $previous_hash !== '' && ! hash_equals($previous_hash, $inventory_hash),
                'inventory_hash' => $inventory_hash,
            ],
        ];
        $url = trailingslashit(self::DASHBOARD_URL).'api/agent/'.absint($settings['site_id']).'/report';
        $response = wp_remote_post($url, ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CP-Agent-Key' => $settings['key']], 'body' => wp_json_encode($payload)]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'cp_remote_error',
                sprintf(
                    $this->text(
                        'El panel respondió con HTTP %d. Revisa el ID y la clave.',
                        'The control center returned HTTP %d. Check the site ID and agent key.'
                    ),
                    $code
                )
            );
        }

        update_option('creative_pear_monitor_last_success', time(), false);
        update_option(self::CONNECTION_HASH_OPTION, $this->connection_hash($settings), false);

        return true;
    }

    private function has_credentials(array $settings): bool
    {
        return ! empty($settings['site_id']) && ! empty($settings['key']);
    }

    private function connection_hash(array $settings): string
    {
        return hash('sha256', absint($settings['site_id'] ?? 0).'|'.sanitize_text_field($settings['key'] ?? ''));
    }

    private function text(string $spanish, string $english): string
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();

        return strpos(strtolower(str_replace('-', '_', $locale)), 'es') === 0 ? $spanish : $english;
    }

    private function woocommerce_details(bool $enabled): array
    {
        if (! $enabled || ! function_exists('WC')) {
            return ['active' => false, 'sandbox' => false, 'gateways' => []];
        }
        $gateways = [];
        $available = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        foreach ($available as $gateway) {
            $settings = (array) get_option('woocommerce_'.$gateway->id.'_settings', []);
            $test_value = strtolower((string) ($settings['testmode'] ?? $settings['test_mode'] ?? $settings['sandbox'] ?? $settings['environment'] ?? ''));
            $test_mode = in_array($test_value, ['yes', 'true', '1', 'test', 'sandbox'], true);
            $gateways[] = ['id' => $gateway->id, 'title' => wp_strip_all_tags($gateway->get_title()), 'enabled' => $gateway->enabled === 'yes', 'test_mode' => $test_mode];
        }

        return [
            'active' => true,
            'sandbox' => count(array_filter($gateways, fn ($gateway) => $gateway['enabled'] && $gateway['test_mode'])) > 0,
            'gateways' => $gateways,
            'products' => function_exists('wp_count_posts') ? (int) (wp_count_posts('product')->publish ?? 0) : null,
            'failed_orders' => function_exists('wc_orders_count') ? (int) wc_orders_count('wc-failed') : null,
            'checkout_page' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('checkout') : null,
            'cart_page' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('cart') : null,
        ];
    }

    private function database_details(): array
    {
        global $wpdb;
        $size = $wpdb->get_var($wpdb->prepare('SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s', DB_NAME));
        $autoload = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')");
        $transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");

        return ['size_bytes' => (int) $size, 'autoload_bytes' => (int) $autoload, 'transients' => (int) $transients];
    }
}

new Creative_Pear_Monitor;
