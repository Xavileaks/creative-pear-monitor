<?php
// Standalone authentication contract test. Never connects to WordPress or a remote site.
define('ABSPATH', __DIR__.'/');
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function plugin_basename($file) { return basename($file); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return (string) $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_option($key, $value, ...$args) {
    if (isset($GLOBALS['options'][$key])) { return false; }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function update_option($key, $value, ...$args) { $GLOBALS['options'][$key] = $value; }
function get_transient($key) { return $GLOBALS['payload']; }
function wp_json_encode($value) { return json_encode($value); }
class WP_Error {
    public function __construct(public $code, public $message, public $data) {}
}
class WP_REST_Request {
    public function __construct(public array $headers) {}
    public function get_header($name) { return $this->headers[$name] ?? ''; }
}
class WP_REST_Response {
    public function __construct(public $data, public $status, public $headers) {}
}
$GLOBALS['wpdb'] = new class {
    public $options = 'wp_options';
    public function query(...$args) {}
    public function prepare(...$args) { return ''; }
    public function esc_like($value) { return $value; }
};
$GLOBALS['options'] = ['creative_pear_monitor_settings'=>['site_id'=>14,'key'=>str_repeat('a',64)]];
$GLOBALS['payload'] = ['wp_version'=>'7.1.2','metadata'=>['plugins'=>[['name'=>'Español / Cache']]]];
require dirname(__DIR__).'/creative-pear-monitor.php';
$monitor = (new ReflectionClass(Creative_Pear_Monitor::class))->newInstanceWithoutConstructor();
function expect($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function request($id, $time = null) {
    $time = $time ?? time();
    return new WP_REST_Request(['X-CP-Site-ID'=>'14','X-CP-Timestamp'=>(string)$time,'X-CP-Request-ID'=>$id,
        'X-CP-Signature'=>hash_hmac('sha256','14|'.$time.'|'.$id.'|read-snapshot',str_repeat('a',64))]);
}
$valid = request('e94ca97c-d70c-4e34-abee-eef543c41d86');
expect($monitor->authorize_snapshot($valid) === true, 'Valid request rejected');
expect($monitor->authorize_snapshot($valid)->code === 'cp_snapshot_replayed', 'Replay accepted');
expect($monitor->authorize_snapshot(request('e94ca97c-d70c-4e34-abee-eef543c41d87',time()-121))->code === 'cp_snapshot_forbidden', 'Expired request accepted');
$bad = request('e94ca97c-d70c-4e34-abee-eef543c41d88');
$bad->headers['X-CP-Signature'] = str_repeat('0',64);
expect($monitor->authorize_snapshot($bad)->code === 'cp_snapshot_forbidden', 'Bad signature accepted');
$wrong = request('e94ca97c-d70c-4e34-abee-eef543c41d89');
$wrong->headers['X-CP-Site-ID'] = '15';
expect($monitor->authorize_snapshot($wrong)->code === 'cp_snapshot_forbidden', 'Wrong site accepted');
$response = $monitor->snapshot($valid);
$expected = hash_hmac('sha256',$response->data['request_id'].'|'.$response->data['generated_at'].'|'.hash('sha256',json_encode($response->data['payload'])),str_repeat('a',64));
expect(hash_equals($expected,$response->data['signature']), 'Response signature mismatch');
expect($response->headers['Cache-Control'] === 'no-store, private', 'Snapshot can be cached publicly');
echo "PASS: valid, replay, expiration, tampering, site isolation, response signature, private cache\n";
