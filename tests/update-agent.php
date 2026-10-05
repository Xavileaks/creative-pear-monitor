<?php
// Standalone update contract test. No network requests or WordPress files are changed.
define('ABSPATH', __DIR__.'/fixtures/');
define('MINUTE_IN_SECONDS', 60);
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function plugin_basename($file) { return 'creative-pear-monitor/creative-pear-monitor.php'; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return (string) $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, ...$args) {
    if (isset($GLOBALS['options'][$key])) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_site_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_site_transient($key, $value, ...$args) { $GLOBALS['transients'][$key] = $value; }
function delete_site_transient($key) { unset($GLOBALS['transients'][$key]); }
function wp_remote_get(...$args) {
    return ['code' => 200, 'body' => json_encode([
        'tag_name' => $GLOBALS['release_version'],
        'assets' => [['name' => 'creative-pear-monitor.zip', 'browser_download_url' => 'https://example.invalid/agent.zip']],
    ])];
}
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function esc_url_raw($url) { return $url; }
function get_bloginfo($key) { return '7.1.2'; }
function get_locale() { return 'en_US'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function is_plugin_active($plugin) { return $GLOBALS['active']; }
function is_multisite() { return false; }
function wp_clean_plugins_cache(...$args) {}
function activate_plugin(...$args) {
    if ($GLOBALS['scenario'] === 'activation-error') { return new WP_Error('activation_error', 'Could not reactivate the agent.'); }
    $GLOBALS['active'] = true;
    return null;
}
function get_plugin_data(...$args) { return ['Version' => $GLOBALS['disk_version']]; }
class WP_Error {
    public function __construct(public $code = '', public $message = '') {}
    public function has_errors() { return $this->code !== ''; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status = 200) {}
}
// Deliberately lacks get_errors(), matching WordPress's automatic skin.
class Automatic_Upgrader_Skin {}
class WP_Ajax_Upgrader_Skin extends Automatic_Upgrader_Skin {
    public function get_errors() {
        return $GLOBALS['scenario'] === 'skin-error'
            ? new WP_Error('permission_error', 'Could not copy file.') : new WP_Error;
    }
    public function get_error_messages() { return '<strong>Could not copy file.</strong> permission denied'; }
}
class Plugin_Upgrader {
    public function __construct(public $skin) {}
    public function upgrade($plugin, $args) {
        expect($plugin === 'creative-pear-monitor/creative-pear-monitor.php', 'Wrong plugin targeted');
        expect($args === ['clear_update_cache' => true], 'Update options changed');
        if ($GLOBALS['scenario'] === 'exception') { throw new Error('Simulated updater exception'); }
        if ($GLOBALS['scenario'] === 'wp-error') { return new WP_Error('download_failed', 'Download failed.'); }
        if (in_array($GLOBALS['scenario'], ['skin-error', 'filesystem-required'], true)) { return false; }
        if ($GLOBALS['scenario'] !== 'unchanged') { $GLOBALS['disk_version'] = '1.6.99'; }
        $GLOBALS['active'] = false;
        return true;
    }
}
require dirname(__DIR__).'/creative-pear-monitor.php';
$monitor = new Creative_Pear_Monitor;
function expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function run_update($monitor, $scenario) {
    $GLOBALS['options'] = [];
    $GLOBALS['transients'] = [];
    $GLOBALS['scenario'] = $scenario;
    $GLOBALS['release_version'] = $scenario === 'current' ? '1.6.0' : '1.6.99';
    $GLOBALS['disk_version'] = '1.6.0';
    $GLOBALS['active'] = true;
    $response = $monitor->update_plugin();
    expect(!isset($GLOBALS['options']['creative_pear_monitor_update_lock']), 'Update lock was not released');
    return $response;
}
$response = run_update($monitor, 'skin-error');
expect($response->status === 502 && $response->data['message'] === 'Could not copy file. permission denied', 'Skin error was not returned as plain text');
$response = run_update($monitor, 'filesystem-required');
expect($response->status === 502 && str_contains($response->data['message'], 'Filesystem access'), 'Missing filesystem credentials not explained');
$response = run_update($monitor, 'wp-error');
expect($response->status === 502 && $response->data['message'] === 'Download failed.', 'WordPress error was lost');
$response = run_update($monitor, 'exception');
expect($response->status === 502 && $response->data['error_code'] === 'cp_agent_update_exception', 'Exception escaped the REST handler');
expect(!str_contains(json_encode($response->data), __DIR__) && !str_contains($response->data['message'], 'Simulated'), 'Exception details leaked to the client');
$response = run_update($monitor, 'success');
expect($response->status === 200 && $response->data['status'] === 'updated' && $response->data['version'] === '1.6.99' && $GLOBALS['active'], 'Successful update did not reactivate the agent');
$response = run_update($monitor, 'activation-error');
expect($response->status === 502 && $response->data['message'] === 'Could not reactivate the agent.', 'Reactivation failure not reported');
$response = run_update($monitor, 'unchanged');
expect($response->status === 502 && str_contains($response->data['message'], 'newer agent'), 'Unchanged files accepted as updated');
$response = run_update($monitor, 'current');
expect($response->status === 200 && $response->data['status'] === 'current', 'Current installation not recognized');
$GLOBALS['options']['creative_pear_monitor_update_lock'] = time();
$response = $monitor->update_plugin();
expect($response->status === 409 && isset($GLOBALS['options']['creative_pear_monitor_update_lock']), 'Concurrent update lock was bypassed');
echo "PASS: skin error, filesystem credentials, WordPress error, exception, success/reactivation, activation failure, version verification, current version, concurrent lock\n";
