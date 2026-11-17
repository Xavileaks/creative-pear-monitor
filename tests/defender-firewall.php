<?php
// Standalone contracts. Fixtures only; no WordPress installation or network is touched.
namespace WP_Defender\Model\Setting {
    class Blacklist_Lockout {
        public function get_list($list) { return $GLOBALS['lists'][$list]; }
        public function validate_ip($ip) { return filter_var($ip, FILTER_VALIDATE_IP) !== false || $ip === '203.0.113.0/24'; }
        public function remove_from_list($ip,$list) { $GLOBALS['lists'][$list] = array_values(array_diff($this->get_list($list),[$ip])); }
        public function add_to_list($ip,$list) { $GLOBALS['lists'][$list] = array_values(array_unique(array_merge($this->get_list($list),[$ip]))); }
    }
}
namespace WP_Defender\Component {
    class Blacklist_Lockout {
        public function is_blacklist($ip) { return in_array($ip,$GLOBALS['lists']['blocklist'],true) || (in_array('203.0.113.0/24',$GLOBALS['lists']['blocklist'],true) && str_starts_with($ip,'203.0.113.')); }
    }
}
namespace WP_Defender\Model {
    class Lockout_Ip {
        public function __construct(public $id,public $ip,public $status,public $release_time,public $lock_time,public $attempt=4,public $attempt_404=4,public $meta=[]) {}
        public static function get_bulk($status,$ips) { return array_values(array_filter($GLOBALS['rows'],fn($r)=>in_array($r->ip,$ips,true))); }
        public function save() { $GLOBALS['rows'][$this->id]=$this; }
    }
}
namespace Calotes\Helper { class Array_Cache { public static function remove($ip,$group) { $GLOBALS['cleared'][]=[$ip,$group]; } } }
namespace {
    define('ABSPATH',__DIR__.'/');
    function add_action(...$args) {}
    function add_filter(...$args) {}
    function register_rest_route(...$args) {}
    function get_option($key,$default=false) { return $GLOBALS['options'][$key]??$default; }
    function add_option($key,$value,...$args) { if(isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key]=$value; return true; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); }
    function delete_transient($key) { $GLOBALS['deleted_transients'][]=$key; }
    function is_multisite() { return $GLOBALS['multisite']??false; }
    class WP_Error { public function __construct(public $code, public $message, public $data) {} }
    class WP_REST_Response { public function __construct(public $data,public $status=200,public $headers=[]) {} }
    class WP_REST_Request {
        public function __construct(public $headers,public $body) {}
        public function get_header($key) { return $this->headers[$key]??''; }
        public function get_body() { return $this->body; }
        public function get_param($key) { return json_decode($this->body,true)[$key]??null; }
    }
    $GLOBALS['wpdb']=new class {
        public $base_prefix='wp_'; public $options='wp_options'; public $last_error='';
        public function prepare($sql,...$params) { return $sql; }
        public function esc_like($value) { return $value; }
        public function query($sql) { return 1; }
        public function get_var($sql) { return 'wp_defender_lockout'; }
        public function get_results($sql,...$args) {
            return array_map(fn($r)=>['id'=>$r->id,'ip'=>$r->ip,'release_time'=>$r->release_time,'lock_time'=>$r->lock_time],array_values(array_filter($GLOBALS['rows'],fn($r)=>$r->status==='blocked' && $r->release_time>time())));
        }
    };
    define('ARRAY_A','ARRAY_A');
    require dirname(__DIR__).'/includes/class-creative-pear-monitor-defender.php';
    $agent=new Creative_Pear_Monitor_Defender();
    function expect($value,$message) { if(!$value) throw new \RuntimeException($message); }
    function reset_fixture() {
        $GLOBALS['options']=['creative_pear_monitor_settings'=>['site_id'=>7,'key'=>str_repeat('k',64)]];
        $GLOBALS['lists']=['allowlist'=>['127.0.0.1'],'blocklist'=>['198.51.100.4']];
        $GLOBALS['rows']=[1=>new \WP_Defender\Model\Lockout_Ip(1,'198.51.100.4','blocked',time()+3600,100),2=>new \WP_Defender\Model\Lockout_Ip(2,'198.51.100.5','blocked',time()+3600,100)];
        $GLOBALS['cleared']=[]; $GLOBALS['deleted_transients']=[]; $GLOBALS['multisite']=false;
    }
    function request($action='read',$target='',$revision='') {
        static $number=0;
        $id=sprintf('00000000-0000-4000-8000-%012d',++$number); $timestamp=(string)time();
        $body=json_encode(compact('action','target','revision'));
        return new WP_REST_Request(['X-CP-Site-ID'=>'7','X-CP-Timestamp'=>$timestamp,'X-CP-Request-ID'=>$id,'X-CP-Signature'=>hash_hmac('sha256','7|'.$timestamp.'|'.$id.'|defender-firewall|'.hash('sha256',$body),str_repeat('k',64))],$body);
    }
    function state() { global $agent; return $agent->handle(request())->data['state']; }
    reset_fixture();
    $valid=request();
    expect($agent->authorize($valid)===true,'Valid signature rejected');
    expect($agent->authorize($valid)->code==='cp_firewall_replayed','Replay accepted');
    $bad=request();$bad->body=json_encode(['action'=>'allow','target'=>'198.51.100.9','revision'=>'']);
    expect($agent->authorize($bad)->code==='cp_firewall_forbidden','Tampered body accepted');
    $wrong=request();$wrong->headers['X-CP-Site-ID']='8';
    expect($agent->authorize($wrong)->code==='cp_firewall_forbidden','Wrong site accepted');
    $expired=request();$expired->headers['X-CP-Timestamp']=(string)(time()-121);
    expect($agent->authorize($expired)->code==='cp_firewall_forbidden','Expired timestamp accepted');
    $response=$agent->handle(request());$signed=$response->data;unset($signed['signature']);
    expect(hash_equals(hash_hmac('sha256',json_encode($signed),str_repeat('k',64)),$response->data['signature']),'Unsigned response');
    expect($response->headers['Cache-Control']==='no-store, private','IP lists may be cached');
    $revision=state()['revision'];
    $result=$agent->handle(request('unblock','198.51.100.4',$revision));
    expect($result instanceof WP_REST_Response,'Unblock failed');
    expect(!in_array('198.51.100.4',$result->data['state']['blocklist'],true),'Exact block not removed');
    expect($GLOBALS['rows'][1]->status==='normal' && $GLOBALS['rows'][1]->attempt===0 && $GLOBALS['rows'][1]->attempt_404===0,'Temporary lock/counters retained');
    expect($GLOBALS['rows'][2]->status==='blocked','Unrelated IP changed');
    expect(in_array(['198.51.100.4','ip_lockout'],$GLOBALS['cleared'],true),'Defender request cache retained');
    expect(in_array('creative_pear_monitor_snapshot',$GLOBALS['deleted_transients'],true),'Monitor snapshot cache retained');
    reset_fixture();$revision=state()['revision'];
    $result=$agent->handle(request('allow','198.51.100.4',$revision));
    expect(in_array('198.51.100.4',$result->data['state']['allowlist'],true),'Allowlist not persisted');
    expect(!in_array('198.51.100.4',$result->data['state']['blocklist'],true),'Allowlist conflicts with exact block');
    $result=$agent->handle(request('remove_allow','198.51.100.4',$result->data['state']['revision']));
    expect(!in_array('198.51.100.4',$result->data['state']['allowlist'],true),'Allowlist removal failed');
    reset_fixture();$revision=state()['revision'];$GLOBALS['lists']['blocklist'][]='203.0.113.1';
    expect($agent->handle(request('allow','198.51.100.4',$revision))->code==='cp_firewall_stale','Stale revision overwrote another change');
    expect(!in_array('198.51.100.4',$GLOBALS['lists']['allowlist'],true),'Stale action mutated settings');
    reset_fixture();$GLOBALS['lists']['blocklist'][]='203.0.113.0/24';
    expect($agent->handle(request('unblock','203.0.113.9',state()['revision']))->code==='cp_firewall_range','Unblock silently removed range');
    expect($agent->handle(request('allow','203.0.113.0/24',state()['revision']))->code==='cp_firewall_target','Broad allowlist accepted');
    $result=$agent->handle(request('remove_block','203.0.113.0/24',state()['revision']));
    expect(!in_array('203.0.113.0/24',$result->data['state']['blocklist'],true),'Exact range removal failed');
    expect($agent->handle(request('allow','bad-ip',state()['revision']))->code==='cp_firewall_target','Invalid IP accepted');
    $GLOBALS['options']['creative_pear_firewall_control_lock']=time();
    expect($agent->handle(request())->code==='cp_firewall_busy','Concurrent action accepted');unset($GLOBALS['options']['creative_pear_firewall_control_lock']);
    $rescue=new \ReflectionMethod($agent,'valid_rescue_request');
    $read=request();$server=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/subdir/wp-json/creative-pear-monitor/v1/defender-firewall'];
    foreach(['X-CP-Site-ID','X-CP-Timestamp','X-CP-Request-ID','X-CP-Signature'] as $key) $server['HTTP_'.str_replace('-','_',strtoupper($key))]=$read->headers[$key];
    expect($rescue->invoke($agent,$server,$read->body),'Signed rescue request rejected');
    $server['REQUEST_URI']='/wp-admin/';expect(!$rescue->invoke($agent,$server,$read->body),'Rescue bypass applies to admin pages');
    $server['REQUEST_URI']='/wp-json/creative-pear-monitor/v1/defender-firewall';$server['REQUEST_METHOD']='GET';
    expect(!$rescue->invoke($agent,$server,$read->body),'Rescue bypass applies to another HTTP method');
    expect($agent->allow_signed_request(['127.0.0.1'])===['127.0.0.1'],'Normal visitors received bypass');
    reset_fixture();$GLOBALS['multisite']=true;
    expect($agent->handle(request())->code==='cp_firewall_unconfirmed','Unsupported multisite did not fail closed');
    echo "PASS: signatures, response verification, replay/expiry/isolation, unblock, counters/cache, allowlist, exact range removal, stale/concurrent requests, narrow rescue exemption, unsupported installations\n";
}
