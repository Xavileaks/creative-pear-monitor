<?php

defined('ABSPATH') || exit;

final class Creative_Pear_Monitor_Defender
{
    private const ACTIONS = ['read', 'unblock', 'allow', 'remove_block', 'remove_allow'];
    private string $request_ip = '';

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wd_before_lockout', [$this, 'authorize_rescue_request']);
        add_filter('ip_lockout_default_whitelist_ip', [$this, 'allow_signed_request']);
    }

    public function register_routes(): void
    {
        register_rest_route('creative-pear-monitor/v1', '/defender-firewall', [
            'methods' => 'POST', 'callback' => [$this, 'handle'], 'permission_callback' => [$this, 'authorize'],
        ]);
    }

    private function valid_signature(array $headers, string $body): bool
    {
        $settings = (array) get_option('creative_pear_monitor_settings', []);
        $data = json_decode($body, true);
        if (!is_array($data) || strlen($body) > 2048 || empty($settings['key']) || empty($settings['site_id'])
            || (string) ($headers['site'] ?? '') !== (string) $settings['site_id']
            || !ctype_digit((string) ($headers['time'] ?? '')) || abs(time() - (int) $headers['time']) > 120
            || !preg_match('/^[a-f0-9-]{36}$/iD', (string) ($headers['id'] ?? ''))
            || !in_array($data['action'] ?? null, self::ACTIONS, true)) {
            return false;
        }
        $expected = hash_hmac('sha256', $headers['site'].'|'.$headers['time'].'|'.$headers['id'].'|defender-firewall|'.hash('sha256', $body), (string) $settings['key']);

        return is_string($headers['signature'] ?? null) && hash_equals($expected, $headers['signature']);
    }

    public function authorize_rescue_request(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) && $this->valid_rescue_request($_SERVER, (string) file_get_contents('php://input'))) {
            $this->request_ip = $ip;
        }
    }

    private function valid_rescue_request(array $server, string $body): bool
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $route = '/creative-pear-monitor/v1/defender-firewall';
        $endpoint = rest_url(ltrim($route, '/'));
        $expected_path = (string) parse_url($endpoint, PHP_URL_PATH);
        parse_str((string) parse_url($endpoint, PHP_URL_QUERY), $expected_query);
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST'
            || rtrim($path, '/') !== rtrim($expected_path, '/')
            || (isset($query['rest_route']) && $query['rest_route'] !== $route)
            || (isset($expected_query['rest_route']) && ($query['rest_route'] ?? '') !== $route)) {
            return false;
        }
        $headers = ['site' => $server['HTTP_X_CP_SITE_ID'] ?? '', 'time' => $server['HTTP_X_CP_TIMESTAMP'] ?? '',
            'id' => $server['HTTP_X_CP_REQUEST_ID'] ?? '', 'signature' => $server['HTTP_X_CP_SIGNATURE'] ?? ''];

        return $this->valid_signature($headers, $body);
    }

    public function allow_signed_request($ips): array
    {
        $ips = (array) $ips;
        if ($this->request_ip !== '') {
            $ips[] = $this->request_ip;
        }

        return array_values(array_unique($ips));
    }

    public function authorize(WP_REST_Request $request)
    {
        $headers = ['site' => $request->get_header('X-CP-Site-ID'), 'time' => $request->get_header('X-CP-Timestamp'),
            'id' => $request->get_header('X-CP-Request-ID'), 'signature' => $request->get_header('X-CP-Signature')];
        if (!$this->valid_signature($headers, $request->get_body())) {
            return new WP_Error('cp_firewall_forbidden', 'Invalid firewall request.', ['status' => 403]);
        }
        $key = 'creative_pear_firewall_request_'.hash('sha256', $headers['id']);
        if (!add_option($key, time(), '', false)) {
            return new WP_Error('cp_firewall_replayed', 'Firewall request already used.', ['status' => 409]);
        }
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like('creative_pear_firewall_request_').'%', time() - 300));

        return true;
    }

    private function settings()
    {
        $class = '\\WP_Defender\\Model\\Setting\\Blacklist_Lockout';
        if (is_multisite() || !class_exists($class) || !class_exists('\\WP_Defender\\Model\\Lockout_Ip')
            || !class_exists('\\WP_Defender\\Component\\Blacklist_Lockout')) {
            throw new RuntimeException('Defender local no está disponible o esta instalación requiere gestión desde WordPress.');
        }
        $settings = new $class();
        foreach (['get_list', 'add_to_list', 'remove_from_list', 'validate_ip'] as $method) {
            if (!is_callable([$settings, $method])) {
                throw new RuntimeException('Esta versión de Defender no ofrece una API compatible.');
            }
        }

        return $settings;
    }

    private function state(): array
    {
        global $wpdb;
        $settings = $this->settings();
        $table = $wpdb->base_prefix.'defender_lockout';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new RuntimeException('No se pudo consultar la tabla de bloqueos de Defender.');
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, ip, release_time, lock_time FROM {$table} WHERE status = %s AND release_time > %d ORDER BY id ASC LIMIT 501", 'blocked', time()), ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error) {
            throw new RuntimeException('No se pudo consultar el estado de los bloqueos.');
        }
        $allowlist = array_values($settings->get_list('allowlist'));
        $blocklist = array_values($settings->get_list('blocklist'));
        sort($allowlist); sort($blocklist);
        $revision = hash('sha256', json_encode([$allowlist, $blocklist, $rows]));

        return ['revision' => $revision, 'checked_at' => gmdate('c'),
            'allowlist' => array_slice($allowlist, 0, 500), 'blocklist' => array_slice($blocklist, 0, 500),
            'lockouts' => array_map(static fn ($row) => ['ip' => $row['ip'], 'expires_at' => gmdate('c', (int) $row['release_time']), 'blocked_at' => gmdate('c', (int) $row['lock_time'])], array_slice($rows, 0, 500)),
            'truncated' => count($allowlist) > 500 || count($blocklist) > 500 || count($rows) > 500];
    }

    private function release_ip(string $ip): void
    {
        foreach (\WP_Defender\Model\Lockout_Ip::get_bulk('blocked', [$ip]) as $model) {
            $model->status = 'normal';
            $model->release_time = 0;
            $model->attempt = 0;
            $model->attempt_404 = 0;
            $model->meta = ['nf' => [], 'login' => []];
            $model->save();
        }
        if (class_exists('\\Calotes\\Helper\\Array_Cache')) {
            \Calotes\Helper\Array_Cache::remove($ip, 'ip_lockout');
        }
    }

    public function handle(WP_REST_Request $request)
    {
        $action = $request->get_param('action');
        $target = $request->get_param('target');
        $expected = $request->get_param('revision');
        if (!in_array($action, self::ACTIONS, true) || !is_string($target) || !is_string($expected)) {
            return new WP_Error('cp_firewall_invalid', 'Invalid firewall payload.', ['status' => 422]);
        }
        $lock = 'creative_pear_firewall_control_lock';
        if (!add_option($lock, time(), '', false)) {
            if ((int) get_option($lock) < time() - 120) {
                delete_option($lock);
            }
            return new WP_Error('cp_firewall_busy', 'Hay otro cambio de firewall en curso. Actualiza y vuelve a intentarlo.', ['status' => 409]);
        }
        try {
            $before = $this->state();
            if ($action !== 'read') {
                if ($before['truncated'] || !hash_equals($before['revision'], $expected)) {
                    return new WP_Error('cp_firewall_stale', 'El firewall cambió. Actualiza las listas antes de continuar.', ['status' => 409]);
                }
                $settings = $this->settings();
                $single_ip = filter_var($target, FILTER_VALIDATE_IP) !== false;
                if (strlen($target) > 100 || !$settings->validate_ip($target) || (in_array($action, ['allow', 'unblock'], true) && !$single_ip)) {
                    return new WP_Error('cp_firewall_target', 'Escribe una IP completa y válida. No se permiten rangos para desbloquear o permitir.', ['status' => 422]);
                }
                if ($action === 'remove_block' && !in_array($target, $before['blocklist'], true)
                    || $action === 'remove_allow' && !in_array($target, $before['allowlist'], true)) {
                    return new WP_Error('cp_firewall_missing', 'La entrada ya no está en esa lista. Actualiza el panel.', ['status' => 409]);
                }
                $service = new \WP_Defender\Component\Blacklist_Lockout();
                if ($action === 'unblock' && !in_array($target, $before['blocklist'], true) && $service->is_blacklist($target)) {
                    return new WP_Error('cp_firewall_range', 'La IP pertenece a un rango bloqueado. Añádela a la lista blanca o revisa el rango en WordPress.', ['status' => 409]);
                }
                if (in_array($action, ['unblock', 'allow', 'remove_block'], true)) {
                    $settings->remove_from_list($target, 'blocklist');
                    if ($single_ip) {
                        $this->release_ip($target);
                    }
                }
                if ($action === 'allow') {
                    $settings->add_to_list($target, 'allowlist');
                } elseif ($action === 'remove_allow') {
                    $settings->remove_from_list($target, 'allowlist');
                }
                delete_transient('creative_pear_monitor_snapshot');
            }
            $after = $this->state();
            if ($action !== 'read') {
                $released = !in_array($target, array_column($after['lockouts'], 'ip'), true);
                $verified = $action === 'allow' ? in_array($target, $after['allowlist'], true) && $released
                    : ($action === 'remove_allow' ? !in_array($target, $after['allowlist'], true)
                    : !in_array($target, $after['blocklist'], true) && (!$single_ip || $released));
                if (!$verified) {
                    throw new RuntimeException('Defender no confirmó el cambio. Actualiza las listas antes de reintentar.');
                }
                if ($action === 'unblock' && function_exists('wd_di')
                    && wd_di()->get('\\WP_Defender\\Component\\Firewall')->is_blocklisted_ip($target)['result']) {
                    throw new RuntimeException('Otra regla de Defender sigue bloqueando esta IP. Revisa su lista blanca o las reglas globales antes de reintentar.');
                }
            }
            $settings = (array) get_option('creative_pear_monitor_settings', []);
            $result = ['protocol' => 1, 'site_id' => (int) $settings['site_id'], 'request_id' => $request->get_header('X-CP-Request-ID'),
                'generated_at' => time(), 'action' => $action, 'target' => $target, 'state' => $after];
            $result['signature'] = hash_hmac('sha256', json_encode($result), (string) $settings['key']);

            return new WP_REST_Response($result, 200, ['Cache-Control' => 'no-store, private']);
        } catch (Throwable $exception) {
            return new WP_Error('cp_firewall_unconfirmed', $exception->getMessage(), ['status' => 503]);
        } finally {
            delete_option($lock);
        }
    }
}
