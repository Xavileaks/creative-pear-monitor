<?php
// Isolated backup policy contract. No WordPress installation or network access.
define('ABSPATH', __DIR__.'/fixtures/');
define('WP_CONTENT_DIR', __DIR__.'/fixtures/');
function add_action(...$args) {}
function add_filter(...$args) {}
function register_activation_hook(...$args) {}
function register_deactivation_hook(...$args) {}
function plugin_basename($file) { return basename($file); }
function get_option($key, $default = false) { return $GLOBALS['backup_options'][$key] ?? $default; }
function wp_get_theme() { return new class { public function parent() { return false; } }; }
class Ai1wmge_GDrive_Client {
    public function __construct(...$args) {}
    public function get_account_info() {
        if ($GLOBALS['account_error']) { throw new RuntimeException('Account cannot be read'); }
        return ['user'=>['emailAddress'=>$GLOBALS['account_email']]];
    }
}
require dirname(__DIR__).'/creative-pear-monitor.php';
$monitor = (new ReflectionClass(Creative_Pear_Monitor::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod(Creative_Pear_Monitor::class, 'site_hygiene_details');
$plugins = ['all-in-one-wp-migration/all-in-one-wp-migration.php'=>[],
    'all-in-one-wp-migration-gdrive-extension/all-in-one-wp-migration-gdrive-extension.php'=>[]];
$GLOBALS['backup_options'] = ['ai1wm_backups_path'=>__DIR__.'/nonexistent-backups',
    'ai1wmge_gdrive_token'=>'test-token', 'ai1wmge_gdrive_backups'=>3, 'ai1wmge_gdrive_total'=>'0MB', 'ai1wmge_gdrive_days'=>0];
function expect_backup($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
foreach (['web@creativepearagency.com'=>true, 'WEB@CreativePearAgency.com'=>true,
    'team@creativepearagency.com'=>false, 'someone@example.com'=>false] as $email=>$expected) {
    $GLOBALS['account_email'] = $email;
    $GLOBALS['account_error'] = false;
    $report = $method->invoke($monitor, $plugins, array_keys($plugins));
    expect_backup($report['ai1wm']['account_policy'] === 'web@creativepearagency.com', 'Missing policy identity');
    expect_backup($report['ai1wm']['agency_account'] === $expected, 'Wrong account accepted or web rejected');
    expect_backup($report['ai1wm']['retention_correct'] === true, 'Retention rules changed');
    expect_backup(!str_contains(json_encode($report), 'test-token') && !array_key_exists('connected_email', $report['ai1wm']), 'Private account data leaked');
}
$GLOBALS['account_error'] = true;
$report = $method->invoke($monitor, $plugins, array_keys($plugins));
expect_backup($report['ai1wm']['agency_account'] === null, 'Unreadable account reported as a mismatch');
echo "PASS: web account, case-insensitive match, other accounts rejected, unreadable account, retention preserved, no secrets\n";
