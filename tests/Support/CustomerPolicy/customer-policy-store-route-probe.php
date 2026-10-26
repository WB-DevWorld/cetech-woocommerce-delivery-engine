<?php

declare(strict_types=1);
require dirname(__DIR__,3).'/vendor/autoload.php';
use CetechDeliveryEngine\Infrastructure\Persistence\CustomerPolicyReceiptStore;
$GLOBALS['site']=1;$GLOBALS['site_reads']=0;
function get_current_blog_id():int{++$GLOBALS['site_reads'];return$GLOBALS['site'];}
class wpdb {public string$prefix='wp_';public string$options='wp_options';public string$charset='utf8mb4';public string$collate='';public string$host='127.0.0.1';public function parse_db_host(mixed$host):array{return[$this->host,3306,null,false];}}
$mode=$argv[1]??'';$s=CustomerPolicyReceiptStore::from_wordpress();$before=$GLOBALS['site_reads'];$first=null;$second=null;
if('construct-only'!==$mode){define('DB_HOST','owned-test-host');define('DB_USER','owned-test-user');define('DB_PASSWORD','owned-test-password');define('DB_NAME','owned-test-db');$GLOBALS['wpdb']=new wpdb();if('invalid-first'===$mode){$GLOBALS['wpdb']->prefix='bad prefix';}$first=$s->binding();if('replace-object'===$mode){$GLOBALS['wpdb']=clone$GLOBALS['wpdb'];}elseif('change-site'===$mode){$GLOBALS['site']=2;}elseif('change-prefix'===$mode){$GLOBALS['wpdb']->prefix='other_';$GLOBALS['wpdb']->options='other_options';}elseif('change-host'===$mode){$GLOBALS['wpdb']->host='192.0.2.1';}}
try{$second=$s->binding();$refused=false;}catch(Throwable){$refused=true;}
echo json_encode(['constructor_site_reads'=>$before,'first'=>$first,'second'=>$second,'refused'=>$refused],JSON_THROW_ON_ERROR);
