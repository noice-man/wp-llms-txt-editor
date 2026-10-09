<?php
error_reporting(E_ALL);
define('ABSPATH', __DIR__.'/');
define('MINUTE_IN_SECONDS',60);
define('HOUR_IN_SECONDS',3600);
$GLOBALS['root'] = sys_get_temp_dir() . '/llms-wp-test-' . bin2hex(random_bytes(4));
mkdir($GLOBALS['root'],0700,true);
$GLOBALS['o'] = []; $GLOBALS['t'] = []; $GLOBALS['remote_mode']='ok'; $GLOBALS['heads'] = 'text/plain';
class WP_Error { private $id; private $msg; function __construct($id,$msg){$this->id=$id;$this->msg=$msg;} function get_error_message(){return $this->msg;} }
function is_wp_error($o){return $o instanceof WP_Error;}
function get_home_path(){return $GLOBALS['root'].'/';}
function trailingslashit($x){return rtrim($x,'/').'/';}
function add_action($a,$b,$p=10){} function register_activation_hook($a,$b){} function register_deactivation_hook($a,$b){}
function get_option($n,$default=false){return array_key_exists($n,$GLOBALS['o'])?$GLOBALS['o'][$n]:$default;}
function update_option($n,$v,$autoload=null){$GLOBALS['o'][$n]=$v;return true;}
function get_transient($n){return $GLOBALS['t'][$n]??false;}
function set_transient($n,$v,$time){$GLOBALS['t'][$n]=$v;return true;}
function delete_transient($n){unset($GLOBALS['t'][$n]);return true;}
function wp_strip_all_tags($x){return strip_tags($x);}
function get_bloginfo($n){return $n==='name'?'Пример':'Описание';}
function home_url($u){return 'https://example.test'.$u;}
function seems_utf8($s){return preg_match('//u',$s)===1;}
function add_query_arg($q,$url){return $url.'?'.http_build_query($q);}
function wp_generate_password($length=12){return substr(str_repeat('abc123def456',10),0,$length);}
function wp_parse_url($url,$field){return parse_url($url,$field);}
function wp_unslash($x){return stripslashes($x);}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_header($r,$n){return $r['headers'][$n]??'';}
function wp_remote_retrieve_body($r){return $r['body']??'';}
function wp_remote_head($url,$options){return ['code'=>200,'headers'=>['content-type'=>$GLOBALS['heads']],'body'=>''];}
function wp_remote_get($url,$options){
 $mode=$GLOBALS['remote_mode'];
 if(false !== strpos($url, '/__llms-editor-probe-')){
   preg_match('/__llms-editor-probe-([a-f0-9]{32})\.txt/',$url,$m);
   if($mode==='failed-probe')return ['code'=>404,'headers'=>['content-type'=>'text/html'],'body'=>'not found'];
   return ['code'=>200,'headers'=>['x-llms-editor-mode'=>'probe','content-type'=>'text/plain; charset=UTF-8'],'body'=>'LLMS-EDITOR-PROBE-'.$m[1]];
 }
 if($mode==='failed-verify')return ['code'=>404,'headers'=>['content-type'=>'text/html'],'body'=>'not found'];
 if(get_option('vm_llms_mode')!=='virtual')return ['code'=>200,'headers'=>['content-type'=>'text/plain'],'body'=>'file'];
 return ['code'=>200,'headers'=>['x-llms-editor-mode'=>'virtual','content-type'=>'text/plain; charset=UTF-8'],'body'=>'# hello'];
}
function insert_with_markers($a,$b,$c){return true;}
function status_header($a){} function wp_die($s){throw new Exception($s);}
require dirname(__DIR__) . '/llms-txt-editor.php';
function assert_true($v,$desc){if(!$v)throw new Exception('FAILED: '.$desc);echo "PASS: $desc\n";}
$path=vm_llms_file_path();
assert_true(vm_llms_ensure_file()===true,'create physical file');
assert_true(false !== strpos(file_get_contents($path), 'Пример'),'russian text encoded utf8');
assert_true(vm_llms_write_file("# Тест\nПривет")===true,'physical write');
assert_true(vm_llms_http_check(true)['status']==='missing','missing charset is detected');
$GLOBALS['heads']='text/plain; charset=UTF-8';
assert_true(vm_llms_http_check(true)['status']==='ok','proper charset is detected');
$GLOBALS['heads']='text/html; charset=UTF-8';
assert_true(vm_llms_http_check(true)['status']==='missing','html response not considered llms txt');
$GLOBALS['remote_mode']='failed-probe';
assert_true(is_wp_error(vm_llms_enable_virtual_mode()),'rejects when Nginx blocks missing TXT');
assert_true(file_exists($path) && !vm_llms_virtual_mode(),'failed probe does not delete physical file');
$GLOBALS['remote_mode']='failed-verify';
assert_true(is_wp_error(vm_llms_enable_virtual_mode()),'rejects when final virtual URL cannot be served');
assert_true(file_exists($path) && !vm_llms_virtual_mode() && file_get_contents($path)==="# Тест\nПривет",'rollback restores byte-for-byte file');
$GLOBALS['remote_mode']='ok';
assert_true(vm_llms_enable_virtual_mode()===true,'enable virtual when server forwards TXT route');
assert_true(vm_llms_virtual_mode() && !file_exists($path),'virtual mode removes static obstruction');
assert_true(vm_llms_current_content()==="# Тест\nПривет",'virtual content preserved');
assert_true(vm_llms_save_content("# Обновление\nТекст")===true,'save edits to database in virtual mode');
assert_true(vm_llms_ensure_file()===true && !file_exists($path),'admin view does not recreate physical file');
assert_true(vm_llms_disable_virtual_mode()===true,'restore physical mode');
assert_true(file_get_contents($path)==="# Обновление\nТекст",'restored file contains latest content');
assert_true(is_wp_error(vm_llms_write_file("Bad\xFF")),'invalid utf8 rejected');
assert_true(file_get_contents($path)==="# Обновление\nТекст",'invalid write does not overwrite');
unlink($path); rmdir($GLOBALS['root']);
echo "ALL SMOKE TESTS PASSED\n";
