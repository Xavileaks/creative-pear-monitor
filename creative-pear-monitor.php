<?php
/**
 * Plugin Name: Creative Pear Monitor
 * Plugin URI: https://github.com/Xavileaks/creative-pear-monitor
 * Description: Envía inventario técnico y señales de salud al centro de control de Creative Pear.
 * Version: 1.5.0
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
    private const VERSION = '1.5.0';

    private const OPTION = 'creative_pear_monitor_settings';

    private const DASHBOARD_URL = 'https://status.creativepearagency.com';

    private const EVENT = 'creative_pear_monitor_report';

    private const UPDATE_CHECK_EVENT = 'creative_pear_monitor_update_check';

    private const LEGACY_UPDATE_EVENT = 'creative_pear_monitor_update';

    private const CONNECTION_HASH_OPTION = 'creative_pear_monitor_connection_hash';

    private const UPDATE_LOCK_OPTION = 'creative_pear_monitor_update_lock';

    private bool $report_queued = false;

    private Creative_Pear_Monitor_Updater $updater;

    public function __construct()
    {
        $this->updater = new Creative_Pear_Monitor_Updater(__FILE__, self::VERSION);
        add_filter('cron_schedules', [$this, 'schedule']);
        add_action(self::EVENT, [$this, 'send_report']);
        add_action(self::UPDATE_CHECK_EVENT, [$this->updater, 'refresh_update_notice']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('plugins_loaded', [$this, 'migrate_schedule']);
        add_action('plugins_loaded', [$this, 'enable_scoped_form_test'], PHP_INT_MAX);
        add_action('rest_api_init', [$this, 'register_form_test_route']);
        add_action('rest_api_init', [$this, 'register_update_route']);
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
        if (! wp_next_scheduled(self::UPDATE_CHECK_EVENT)) {
            wp_schedule_event(time() + 90, 'creative_pear_five_minutes', self::UPDATE_CHECK_EVENT);
        }
        update_option('creative_pear_monitor_version', self::VERSION, false);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::EVENT);
        wp_clear_scheduled_hook(self::UPDATE_CHECK_EVENT);
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
        wp_clear_scheduled_hook(self::UPDATE_CHECK_EVENT);
        wp_clear_scheduled_hook(self::LEGACY_UPDATE_EVENT);
        wp_schedule_event(time() + 10, 'creative_pear_five_minutes', self::EVENT);
        wp_schedule_event(time() + 30, 'creative_pear_five_minutes', self::UPDATE_CHECK_EVENT);
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

    public function register_form_test_route(): void
    {
        register_rest_route('creative-pear-monitor/v1', '/form-test-session', [
            'methods' => 'POST',
            'callback' => [$this, 'create_form_test_session'],
            'permission_callback' => [$this, 'authorize_form_test_session'],
        ]);
    }

    public function authorize_form_test_session(WP_REST_Request $request)
    {
        $settings = (array) get_option(self::OPTION, []);
        $site_id = absint($request->get_header('X-CP-Site-ID'));
        $timestamp = absint($request->get_header('X-CP-Timestamp'));
        $signature = sanitize_text_field($request->get_header('X-CP-Signature'));

        if (! $this->has_credentials($settings) || $site_id !== absint($settings['site_id'] ?? 0) || abs(time() - $timestamp) > 120) {
            return new WP_Error('cp_form_test_forbidden', 'Invalid form test request.', ['status' => 403]);
        }

        $expected = hash_hmac('sha256', $site_id.'|'.$timestamp, (string) $settings['key']);
        if (! hash_equals($expected, $signature)) {
            return new WP_Error('cp_form_test_forbidden', 'Invalid form test signature.', ['status' => 403]);
        }

        return true;
    }

    public function create_form_test_session(): WP_REST_Response
    {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            $token = wp_generate_password(64, false, false);
        }

        $settings = (array) get_option(self::OPTION, []);
        $token_hash = hash('sha256', $token);
        set_transient('creative_pear_form_test_'.$token_hash, [
            'token_hash' => $token_hash,
            'site_id' => absint($settings['site_id'] ?? 0),
            'created_at' => time(),
        ], 3 * MINUTE_IN_SECONDS);

        return new WP_REST_Response([
            'token' => $token,
            'expires_in' => 180,
            'supported_frameworks' => $this->supported_form_frameworks(),
        ], 201);
    }

    public function register_update_route(): void
    {
        register_rest_route('creative-pear-monitor/v1', '/update', [
            'methods' => 'POST',
            'callback' => [$this, 'update_plugin'],
            'permission_callback' => [$this, 'authorize_update_request'],
        ]);
    }

    public function authorize_update_request(WP_REST_Request $request)
    {
        $settings = (array) get_option(self::OPTION, []);
        $site_id = absint($request->get_header('X-CP-Site-ID'));
        $timestamp = absint($request->get_header('X-CP-Timestamp'));
        $request_id = sanitize_text_field($request->get_header('X-CP-Request-ID'));
        $signature = sanitize_text_field($request->get_header('X-CP-Signature'));

        if (
            ! $this->has_credentials($settings)
            || $site_id !== absint($settings['site_id'] ?? 0)
            || abs(time() - $timestamp) > 120
            || ! preg_match('/^[a-f0-9-]{36}$/i', $request_id)
        ) {
            return new WP_Error('cp_update_forbidden', 'Invalid plugin update request.', ['status' => 403]);
        }

        $expected = hash_hmac('sha256', $site_id.'|'.$timestamp.'|'.$request_id.'|update-agent', (string) $settings['key']);
        if (! hash_equals($expected, $signature)) {
            return new WP_Error('cp_update_forbidden', 'Invalid plugin update signature.', ['status' => 403]);
        }

        $request_key = 'creative_pear_update_request_'.hash('sha256', $request_id);
        if (get_transient($request_key)) {
            return new WP_Error('cp_update_replayed', 'Plugin update request already used.', ['status' => 409]);
        }
        set_transient($request_key, 1, 3 * MINUTE_IN_SECONDS);

        return true;
    }

    public function update_plugin(): WP_REST_Response
    {
        $lock_time = absint(get_option(self::UPDATE_LOCK_OPTION, 0));
        if ($lock_time && time() - $lock_time >= 5 * MINUTE_IN_SECONDS) {
            delete_option(self::UPDATE_LOCK_OPTION);
        }
        if (! add_option(self::UPDATE_LOCK_OPTION, time(), '', false)) {
            return new WP_REST_Response([
                'status' => 'busy',
                'version' => self::VERSION,
                'message' => $this->text('Ya hay una actualización del agente en curso.', 'An agent update is already running.'),
            ], 409);
        }

        try {
            if (! $this->updater->refresh_update_notice()) {
                return new WP_REST_Response([
                    'status' => 'failed',
                    'version' => self::VERSION,
                    'message' => $this->text('No se pudo consultar la última versión publicada.', 'The latest published version could not be checked.'),
                ], 502);
            }

            $plugin = plugin_basename(__FILE__);
            $updates = get_site_transient('update_plugins');
            if (! is_object($updates) || empty($updates->response[$plugin])) {
                return new WP_REST_Response([
                    'status' => 'current',
                    'version' => self::VERSION,
                    'message' => $this->text('El agente ya está actualizado.', 'The agent is already up to date.'),
                ]);
            }

            require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH.'wp-admin/includes/plugin.php';

            $skin = new Automatic_Upgrader_Skin;
            $upgrader = new Plugin_Upgrader($skin);
            $result = $upgrader->upgrade($plugin, ['clear_update_cache' => true]);

            if (is_wp_error($result)) {
                return new WP_REST_Response([
                    'status' => 'failed',
                    'version' => self::VERSION,
                    'message' => $result->get_error_message(),
                ], 502);
            }

            if ($result !== true) {
                $errors = $skin->get_errors();

                return new WP_REST_Response([
                    'status' => 'failed',
                    'version' => self::VERSION,
                    'message' => is_wp_error($errors) && $errors->has_errors()
                        ? $errors->get_error_message()
                        : $this->text('WordPress no pudo reemplazar los archivos del agente.', 'WordPress could not replace the agent files.'),
                ], 502);
            }

            wp_clean_plugins_cache(true);
            $plugin_data = get_plugin_data(__FILE__, false, false);
            $version = sanitize_text_field($plugin_data['Version'] ?? self::VERSION);

            return new WP_REST_Response([
                'status' => 'updated',
                'version' => $version,
                'message' => $this->text('Creative Pear Monitor se actualizó correctamente.', 'Creative Pear Monitor was updated successfully.'),
            ]);
        } finally {
            delete_option(self::UPDATE_LOCK_OPTION);
        }
    }

    public function enable_scoped_form_test(): void
    {
        if (! $this->is_scoped_form_test_request()) {
            return;
        }

        add_filter('wpforms_process_bypass_captcha', '__return_true', PHP_INT_MAX, 3);
        add_filter('wpcf7_spam', '__return_false', PHP_INT_MAX, 1);
        add_filter('gform_entry_is_spam', '__return_false', PHP_INT_MAX, 3);
        add_filter('hcap_protect_form', '__return_false', PHP_INT_MAX, 3);
        add_action('init', [$this, 'disable_scoped_elementor_captcha_validation'], PHP_INT_MAX);
        add_filter('gform_field_validation', function ($result, $value, $form, $field) {
            $type = is_object($field) ? (string) ($field->type ?? '') : '';
            if ($type === 'captcha') {
                $result['is_valid'] = true;
                $result['message'] = '';
            }

            return $result;
        }, PHP_INT_MAX, 4);
    }

    public function disable_scoped_elementor_captcha_validation(): void
    {
        remove_all_actions('elementor_pro/forms/validation/recaptcha');
        remove_all_actions('elementor_pro/forms/validation/recaptcha_v3');
        remove_all_actions('elementor_pro/forms/validation/hcaptcha');
        remove_all_actions('elementor_pro/forms/validation/turnstile');
        $this->remove_captcha_callbacks('elementor_pro/forms/validation');
    }

    private function is_scoped_form_test_request(): bool
    {
        $token = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_CREATIVE_PEAR_TEST'] ?? ''));
        if ($token === '') {
            return false;
        }

        $token_hash = hash('sha256', $token);
        $session = get_transient('creative_pear_form_test_'.$token_hash);
        $settings = (array) get_option(self::OPTION, []);

        return is_array($session)
            && hash_equals((string) ($session['token_hash'] ?? ''), $token_hash)
            && absint($session['site_id'] ?? 0) === absint($settings['site_id'] ?? 0);
    }

    private function supported_form_frameworks(): array
    {
        $active = array_merge(
            (array) get_option('active_plugins', []),
            array_keys((array) get_site_option('active_sitewide_plugins', []))
        );
        $supported = [];

        if (array_intersect($active, ['wpforms-lite/wpforms.php', 'wpforms/wpforms.php'])) {
            $supported[] = 'wpforms';
        }
        if (in_array('contact-form-7/wp-contact-form-7.php', $active, true)) {
            $supported[] = 'contact-form-7';
        }
        if (in_array('gravityforms/gravityforms.php', $active, true)) {
            $supported[] = 'gravity-forms';
        }
        if (in_array('elementor-pro/elementor-pro.php', $active, true)) {
            $supported[] = 'elementor-forms';
        }

        return $supported;
    }

    private function remove_captcha_callbacks(string $hook): void
    {
        global $wp_filter;

        if (empty($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
            return;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (is_array($function)) {
                    $owner = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];
                    $identity = $owner.'::'.(string) ($function[1] ?? '');
                } elseif (is_string($function)) {
                    $identity = $function;
                } else {
                    continue;
                }

                if (preg_match('/captcha|turnstile/i', $identity)) {
                    remove_filter($hook, $function, $priority);
                }
            }
        }
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
        $defender = $this->defender_details($plugins, $active);
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
                'agent' => ['version' => self::VERSION, 'remote_update' => true],
                'woocommerce' => $woocommerce,
                'woocommerce_details' => $woo_details,
                'defender' => $defender,
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
            'version' => defined('WC_VERSION') ? WC_VERSION : null,
            'sandbox' => count(array_filter($gateways, fn ($gateway) => $gateway['enabled'] && $gateway['test_mode'])) > 0,
            'gateways' => $gateways,
            'products' => function_exists('wp_count_posts') ? (int) (wp_count_posts('product')->publish ?? 0) : null,
            'failed_orders' => function_exists('wc_orders_count') ? (int) wc_orders_count('wc-failed') : null,
            'checkout_page' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('checkout') : null,
            'cart_page' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('cart') : null,
            'sales_30d' => $this->woocommerce_sales_snapshot(),
        ];
    }

    private function woocommerce_sales_snapshot(): array
    {
        $cached = get_transient('creative_pear_monitor_woocommerce_sales_30d');
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;

        $order_stats_table = $wpdb->prefix.'wc_order_stats';
        $product_lookup_table = $wpdb->prefix.'wc_order_product_lookup';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $order_stats_table)) !== $order_stats_table) {
            return [];
        }

        $since_timestamp = time() - (30 * DAY_IN_SECONDS);
        $since = gmdate('Y-m-d H:i:s', $since_timestamp);
        $paid_status_slugs = function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : ['processing', 'completed'];
        if (! $paid_status_slugs) {
            $paid_status_slugs = ['processing', 'completed'];
        }
        $paid_statuses = array_values(array_unique(array_map(function ($status) {
            $status = (string) $status;

            return strpos($status, 'wc-') === 0 ? $status : 'wc-'.$status;
        }, $paid_status_slugs)));
        $status_placeholders = implode(', ', array_fill(0, count($paid_statuses), '%s'));

        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(order_id) AS orders, COALESCE(SUM(net_total), 0) AS net_sales
             FROM {$order_stats_table}
             WHERE parent_id = 0 AND date_created_gmt >= %s AND status IN ({$status_placeholders})",
            array_merge([$since], $paid_statuses)
        ), ARRAY_A);

        $failed_orders = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(order_id) FROM {$order_stats_table}
             WHERE parent_id = 0 AND date_created_gmt >= %s AND status = %s",
            $since,
            'wc-failed'
        ));

        $top_product = null;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $product_lookup_table)) === $product_lookup_table) {
            $top_product_row = $wpdb->get_row($wpdb->prepare(
                "SELECT products.product_id, SUM(products.product_qty) AS quantity
                 FROM {$product_lookup_table} products
                 INNER JOIN {$order_stats_table} orders ON orders.order_id = products.order_id
                 WHERE orders.parent_id = 0 AND orders.date_created_gmt >= %s AND orders.status IN ({$status_placeholders})
                 GROUP BY products.product_id
                 ORDER BY quantity DESC
                 LIMIT 1",
                array_merge([$since], $paid_statuses)
            ), ARRAY_A);
            if ($top_product_row) {
                $product = function_exists('wc_get_product') ? wc_get_product((int) $top_product_row['product_id']) : null;
                $top_product = [
                    'name' => $product ? wp_strip_all_tags($product->get_name()) : sprintf('Producto #%d', (int) $top_product_row['product_id']),
                    'quantity' => (int) $top_product_row['quantity'],
                ];
            }
        }

        $gateway_usage = [];
        if (function_exists('wc_get_orders')) {
            $recent_order_ids = wc_get_orders([
                'limit' => 100,
                'return' => 'ids',
                'status' => $paid_status_slugs,
                'date_created' => '>='.$since_timestamp,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
            foreach ($recent_order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if (! $order) {
                    continue;
                }
                $gateway_id = (string) $order->get_payment_method();
                if ($gateway_id === '') {
                    continue;
                }
                if (! isset($gateway_usage[$gateway_id])) {
                    $gateway_usage[$gateway_id] = [
                        'id' => $gateway_id,
                        'title' => wp_strip_all_tags($order->get_payment_method_title() ?: $gateway_id),
                        'orders' => 0,
                    ];
                }
                $gateway_usage[$gateway_id]['orders']++;
            }
        }
        usort($gateway_usage, fn ($left, $right) => $right['orders'] <=> $left['orders']);

        $orders = (int) ($summary['orders'] ?? 0);
        $net_sales = round((float) ($summary['net_sales'] ?? 0), 2);
        $snapshot = [
            'period_days' => 30,
            'orders' => $orders,
            'net_sales' => $net_sales,
            'average_order_value' => $orders > 0 ? round($net_sales / $orders, 2) : 0,
            'failed_orders' => $failed_orders,
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
            'top_product' => $top_product,
            'primary_gateway' => $gateway_usage[0] ?? null,
            'reported_at' => gmdate('c'),
        ];
        set_transient('creative_pear_monitor_woocommerce_sales_30d', $snapshot, 10 * MINUTE_IN_SECONDS);

        return $snapshot;
    }

    private function defender_details(array $plugins, array $active_plugins): array
    {
        $plugin_file = null;
        foreach (array_keys($plugins) as $file) {
            if (in_array($file, ['defender-security/wp-defender.php', 'wp-defender/wp-defender.php'], true)) {
                $plugin_file = $file;
                break;
            }
        }

        if (! $plugin_file) {
            return ['installed' => false, 'active' => false, 'reported_at' => gmdate('c')];
        }

        $active = in_array($plugin_file, $active_plugins, true);
        $version = (string) ($plugins[$plugin_file]['Version'] ?? '');
        $summary = [
            'installed' => true,
            'active' => $active,
            'version' => $version ?: null,
            'reported_at' => gmdate('c'),
        ];

        if (! $active) {
            return $summary;
        }

        global $wpdb;
        $scan_table = $wpdb->base_prefix.'defender_scan';
        $items_table = $wpdb->base_prefix.'defender_scan_item';
        $lockout_table = $wpdb->base_prefix.'defender_lockout';
        $log_table = $wpdb->base_prefix.'defender_lockout_log';
        $quarantine_table = $wpdb->base_prefix.'defender_quarantine';

        $scan = null;
        if ($this->defender_table_exists($scan_table)) {
            $scan = $wpdb->get_row("SELECT id, status, date_start, date_end FROM {$scan_table} ORDER BY id DESC LIMIT 1", ARRAY_A);
        }

        $scan_counts = [];
        if ($scan && $this->defender_table_exists($items_table)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT type, status, COUNT(*) AS total FROM {$items_table} WHERE parent_id = %d GROUP BY type, status",
                (int) $scan['id']
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $scan_counts[$row['status']][$row['type']] = (int) $row['total'];
            }
        }

        $active_findings = (array) ($scan_counts['active'] ?? []);
        $ignored_findings = (array) ($scan_counts['ignore'] ?? []);
        $summary['scan'] = [
            'status' => $scan['status'] ?? 'never',
            'started_at' => $this->defender_mysql_date($scan['date_start'] ?? null),
            'completed_at' => $this->defender_mysql_date($scan['date_end'] ?? null),
            'findings' => array_sum($active_findings),
            'malware' => (int) ($active_findings['malware'] ?? 0),
            'vulnerabilities' => (int) ($active_findings['vulnerability'] ?? 0),
            'integrity' => (int) (($active_findings['core_integrity'] ?? 0) + ($active_findings['plugin_integrity'] ?? 0)),
            'abandoned' => (int) (($active_findings['plugin_closed'] ?? 0) + ($active_findings['plugin_outdated'] ?? 0)),
            'ignored' => array_sum($ignored_findings),
        ];

        $summary['quarantine'] = $this->defender_table_exists($quarantine_table)
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$quarantine_table}")
            : 0;
        $summary['firewall'] = $this->defender_firewall_details($lockout_table, $log_table);
        $summary['modules'] = $this->defender_modules();
        $summary['recommendations'] = $this->defender_recommendations();

        return $summary;
    }

    private function defender_firewall_details(string $lockout_table, string $log_table): array
    {
        global $wpdb;
        $now = time();
        $firewall_settings = $this->defender_option('wd_lockdown_settings');
        $details = [
            'blocked_now' => 0,
            'blocks_24h' => 0,
            'blocks_7d' => 0,
            'blocks_30d' => 0,
            'failed_logins_24h' => 0,
            'failed_logins_7d' => 0,
            'failed_logins_30d' => 0,
            'reasons' => ['login' => 0, 'not_found' => 0, 'bot' => 0, 'blacklist' => 0],
            'recent_blocks' => [],
            'retention_days' => max(1, min(90, (int) ($firewall_settings['storage_days'] ?? 30))),
            'spike' => false,
        ];

        if ($this->defender_table_exists($lockout_table)) {
            $details['blocked_now'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$lockout_table} WHERE status = %s AND (release_time = 0 OR release_time > %d)",
                'blocked',
                $now
            ));
        }

        if (! $this->defender_table_exists($log_table)) {
            return $details;
        }

        $types = ['auth_lock', '404_lockout', 'xss_lockout', 'plugin_lockout', 'theme_lockout', 'malicious_bot', 'fake_bot', 'ua_lockout', 'custom_lockout'];
        $placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $periods = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(date >= %d) AS day_count, SUM(date >= %d) AS week_count, COUNT(*) AS month_count FROM {$log_table} WHERE date >= %d AND type IN ({$placeholders})",
            ...array_merge([$now - DAY_IN_SECONDS, $now - WEEK_IN_SECONDS, $now - (30 * DAY_IN_SECONDS)], $types)
        ), ARRAY_A);
        $details['blocks_24h'] = (int) ($periods['day_count'] ?? 0);
        $details['blocks_7d'] = (int) ($periods['week_count'] ?? 0);
        $details['blocks_30d'] = (int) ($periods['month_count'] ?? 0);

        $failed = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(date >= %d) AS day_count, SUM(date >= %d) AS week_count, COUNT(*) AS month_count FROM {$log_table} WHERE date >= %d AND type = %s",
            $now - DAY_IN_SECONDS,
            $now - WEEK_IN_SECONDS,
            $now - (30 * DAY_IN_SECONDS),
            'auth_fail'
        ), ARRAY_A);
        $details['failed_logins_24h'] = (int) ($failed['day_count'] ?? 0);
        $details['failed_logins_7d'] = (int) ($failed['week_count'] ?? 0);
        $details['failed_logins_30d'] = (int) ($failed['month_count'] ?? 0);

        $reason_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT type, COUNT(*) AS total FROM {$log_table} WHERE date >= %d AND type IN ({$placeholders}) GROUP BY type",
            ...array_merge([$now - (30 * DAY_IN_SECONDS)], $types)
        ), ARRAY_A);
        foreach ((array) $reason_rows as $row) {
            $reason = $this->defender_lockout_reason((string) $row['type']);
            $details['reasons'][$reason] += (int) $row['total'];
        }

        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT ip, type, date, country_iso_code FROM {$log_table} WHERE type IN ({$placeholders}) ORDER BY date DESC LIMIT 12",
            ...$types
        ), ARRAY_A);
        foreach ((array) $recent as $row) {
            $details['recent_blocks'][] = [
                'ip' => $this->mask_ip((string) $row['ip']),
                'reason' => $this->defender_lockout_reason((string) $row['type']),
                'occurred_at' => gmdate('c', (int) $row['date']),
                'country' => preg_match('/^[A-Z]{2}$/', (string) $row['country_iso_code']) ? $row['country_iso_code'] : null,
            ];
        }

        $daily_baseline = (int) ceil($details['blocks_7d'] / 7);
        $details['spike'] = $details['blocks_24h'] >= max(50, $daily_baseline * 3);

        return $details;
    }

    private function defender_modules(): array
    {
        $scan = $this->defender_option('wd_scan_settings');
        $login = $this->defender_option('wd_login_lockout_settings');
        $not_found = $this->defender_option('wd_notfound_lockout_settings');
        $two_factor = $this->defender_option('wd_2auth_settings');
        $audit = $this->defender_option('wd_audit_settings');
        $mask_login = $this->defender_option('wd_masking_login_settings');

        return [
            'file_integrity' => ! empty($scan['integrity_check']),
            'malware_scan' => ! empty($scan['scan_malware']),
            'vulnerability_scan' => ! empty($scan['check_known_vuln']),
            'login_protection' => ! empty($login['enabled']),
            'not_found_protection' => ! empty($not_found['enabled']),
            'two_factor' => ! empty($two_factor['enabled']),
            'audit_log' => ! empty($audit['enabled']),
            'mask_login' => ! empty($mask_login['enabled']),
        ];
    }

    private function defender_recommendations(): array
    {
        $hardener = $this->defender_option('hardener_settings');

        return [
            'pending' => count((array) ($hardener['issues'] ?? [])),
            'fixed' => count((array) ($hardener['fixed'] ?? [])),
            'ignored' => count((array) ($hardener['ignore'] ?? [])),
        ];
    }

    private function defender_option(string $name): array
    {
        $value = get_option($name, []);
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }

    private function defender_table_exists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    private function defender_mysql_date(?string $date): ?string
    {
        if (! $date || $date === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($date.' UTC');

        return $timestamp ? gmdate('c', $timestamp) : null;
    }

    private function defender_lockout_reason(string $type): string
    {
        if ($type === 'auth_lock') {
            return 'login';
        }
        if (in_array($type, ['404_lockout', 'xss_lockout', 'plugin_lockout', 'theme_lockout'], true)) {
            return 'not_found';
        }
        if (in_array($type, ['malicious_bot', 'fake_bot', 'ua_lockout'], true)) {
            return 'bot';
        }

        return 'blacklist';
    }

    private function mask_ip(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = 'xxx';

            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = array_slice(explode(':', $ip), 0, 3);

            return implode(':', $parts).'::xxxx';
        }

        return 'oculta';
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
