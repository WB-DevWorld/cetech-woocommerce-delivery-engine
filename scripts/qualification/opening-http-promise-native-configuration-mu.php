<?php
/** Separate P05 disposable HTTP fixture. Production owns every quote/payment callback. */
declare(strict_types=1);
if ('1' !== getenv('CETECH_DE_HTTP_OPENING_QUALIFICATION') || '1' !== getenv('CETECH_DE_NATIVE_OPENING_QUALIFICATION')) { return; }
$site = getenv('CETECH_DE_HTTP_FIXTURE_SITE'); $support = getenv('CETECH_DE_HTTP_P04_SUPPORT');
if (!is_string($site) || !defined('ABSPATH') || realpath($site) !== realpath(ABSPATH) || !defined('DB_HOST') || !preg_match('/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST) || !defined('DB_NAME') || !preg_match('/\Acetech_wp_opening_qualification_[a-z0-9]+\z/D', DB_NAME) || !is_string($support) || !is_file($support) || is_link($support) || 'opening-http-promise-handoff-support.php' !== basename($support)) { throw new RuntimeException('P05 MU refuses unowned site/source.'); }
if ('1' !== (string)get_option('cetech_opening_qualification_disposable') || !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON || 'http://127.0.0.1:8085' !== get_option('home') || 'http://127.0.0.1:8085' !== get_option('siteurl')) { throw new RuntimeException('P05 MU requires the marked native loopback fixture.'); }
add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);
if (!(defined('WP_CLI') && WP_CLI) && isset($_GET['cetech_opening_http_probe'])) {
    $token = getenv('CETECH_DE_HTTP_PROBE_TOKEN'); $received = $_SERVER['HTTP_X_CETECH_OPENING_PROBE'] ?? null;
    if ('1' !== $_GET['cetech_opening_http_probe'] || !is_string($token) || !preg_match('/\A[a-f0-9]{48}\z/D', $token) || !is_string($received) || !hash_equals($token, $received)) { http_response_code(403); exit; }
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $all_ini=ini_get_all(null,false);ksort($all_ini);$original_ini=$all_ini;$original_ini['opcache.jit']='1235';$extensions=[];foreach(get_loaded_extensions()as$name){$extensions[$name]=phpversion($name);}ksort($extensions);$opcache=function_exists('opcache_get_status')?opcache_get_status(false):false;
    $runtime=['sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION,'binary_sha256'=>hash_file('sha256',PHP_BINARY),'ini_sha256'=>hash('sha256',json_encode($all_ini,JSON_THROW_ON_ERROR)),'original_ini_sha256'=>hash('sha256',json_encode($original_ini,JSON_THROW_ON_ERROR)),'extensions_sha256'=>hash('sha256',json_encode($extensions,JSON_THROW_ON_ERROR)),'jit_disabled_with_opcache'=>ini_get('opcache.jit')==='disable'&&is_array($opcache)&&true===($opcache['opcache_enabled']??null)&&false===($opcache['jit']['enabled']??null)&&false===($opcache['jit']['on']??null)];
    echo json_encode(['format'=>'cetech-opening-http-owned-listener-v1','probe_sha256'=>hash('sha256',$token),'site_path_sha256'=>hash('sha256',realpath(ABSPATH)),'database_name_sha256'=>hash('sha256',DB_NAME),'source_head'=>getenv('CETECH_DE_QUALIFICATION_HEAD'),'candidate_head'=>getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'),'source_tree'=>getenv('CETECH_DE_QUALIFICATION_TREE'),'runtime'=>$runtime], JSON_THROW_ON_ERROR); exit;
}
require_once dirname($support) . '/opening-http-quote-cart-mu.php';
add_action('init', static function() use ($support): void { if (defined('WP_CLI') && WP_CLI) { return; } require_once $support; CetechPromiseHandoffHttpFixture::register(); }, 102);

$p05_support=getenv('CETECH_DE_HTTP_P05_SUPPORT');
if(!is_string($p05_support)||!is_file($p05_support)||is_link($p05_support)||'opening-http-promise-native-configuration-support.php'!==basename($p05_support))throw new RuntimeException('P05 MU requires exact fixture source.');

add_action('init',static function()use($p05_support):void{if(defined('WP_CLI')&&WP_CLI)return;require_once $p05_support;CetechPromiseNativeConfigurationHttpFixture::register();},103);
