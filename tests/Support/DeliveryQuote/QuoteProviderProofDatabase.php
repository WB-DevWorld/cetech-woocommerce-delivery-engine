<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase;

/** Only finite physical fixture DML; legacy DDL remains captured from production migrations. */
final class QuoteProviderProofDatabase {
	public static function insert(\mysqli $database,string $prefix,string $suffix,array $values):int {
		DataLifecycleProofDatabase::validate_prefix($prefix);
		if(!in_array($suffix,['rate_cards','delivery_offers','destination_zones','destination_rules','origins','suppliers','logistics_profiles','product_delivery_rules','configuration_scopes','configuration_fields','configuration_collections'],true)){throw new \InvalidArgumentException('Unsupported provider fixture table.');}
		$fields=[];$escaped=[];
		foreach($values as $key=>$value){if(!is_string($key)||1!==preg_match('/\A[a-z_]+\z/D',$key)||(!is_string($value)&&!is_int($value)&&null!==$value)){throw new \InvalidArgumentException('Unsupported provider fixture field.');}$fields[]='`'.$key.'`';$escaped[]=null===$value?'NULL':"'".$database->real_escape_string((string)$value)."'";}
		DataLifecycleProofDatabase::execute($database,'INSERT INTO `'.$prefix.'delivery_engine_'.$suffix.'` ('.implode(',',$fields).') VALUES ('.implode(',',$escaped).')');return (int)$database->insert_id;
	}
	public static function rate(\mysqli $database,string $prefix,array $overrides=[]):int {
		return self::insert($database,$prefix,'rate_cards',array_replace(['internal_code'=>'native_fixed','delivery_offer_id'=>20,'destination_zone_id'=>50,'origin_id'=>40,'supplier_id'=>null,'logistics_profile_id'=>null,'charge_type'=>'fixed_per_shipment','base_amount'=>'12.5000','base_currency'=>'GHS','priority'=>100,'status'=>'active','created_at'=>'2026-10-07 05:00:00','updated_at'=>'2026-10-07 05:00:00'],$overrides));
	}
	public static function offer(\mysqli $database,string $prefix,array $overrides=[]):int {
		return self::insert($database,$prefix,'delivery_offers',array_replace(['id'=>20,'internal_code'=>'native_offer','internal_name'=>'Fixture delivery','public_label'=>'Fixture delivery','route'=>'local_delivery','service_level'=>'standard','status'=>'active'],$overrides));
	}
	public static function zone(\mysqli $database,string $prefix,array $overrides=[]):int {
		return self::insert($database,$prefix,'destination_zones',array_replace(['id'=>50,'internal_code'=>'native_zone','internal_name'=>'Fixture zone','public_label'=>'Fixture zone','status'=>'active'],$overrides));
	}
	public static function origin(\mysqli $database,string $prefix,array $overrides=[]):int {
		return self::insert($database,$prefix,'origins',array_replace(['id'=>40,'supplier_id'=>60,'internal_code'=>'native_origin','internal_name'=>'Fixture origin','country_code'=>'GH','status'=>'active'],$overrides));
	}
	/** Physical WordPress source subsets, with the real ownership/index column names. */
	public static function install_wordpress_sources(\mysqli $database,string $prefix):void {
		DataLifecycleProofDatabase::validate_prefix($prefix);
		$ddl=[
			'posts'=>"ID bigint unsigned NOT NULL AUTO_INCREMENT,post_author bigint unsigned NOT NULL DEFAULT 0,post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',post_date_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',post_content longtext NOT NULL,post_title text NOT NULL,post_excerpt text NOT NULL,post_status varchar(20) NOT NULL DEFAULT 'publish',comment_status varchar(20) NOT NULL DEFAULT 'open',ping_status varchar(20) NOT NULL DEFAULT 'open',post_password varchar(255) NOT NULL DEFAULT '',post_name varchar(200) NOT NULL DEFAULT '',to_ping text NOT NULL,pinged text NOT NULL,post_modified datetime NOT NULL DEFAULT '0000-00-00 00:00:00',post_modified_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',post_content_filtered longtext NOT NULL,post_parent bigint unsigned NOT NULL DEFAULT 0,guid varchar(255) NOT NULL DEFAULT '',menu_order int NOT NULL DEFAULT 0,post_type varchar(20) NOT NULL DEFAULT 'post',post_mime_type varchar(100) NOT NULL DEFAULT '',comment_count bigint NOT NULL DEFAULT 0,PRIMARY KEY(ID),KEY post_parent(post_parent),KEY type_status_date(post_type,post_status,post_date,ID)",
			'postmeta'=>"meta_id bigint unsigned NOT NULL AUTO_INCREMENT,post_id bigint unsigned NOT NULL DEFAULT 0,meta_key varchar(255) DEFAULT NULL,meta_value longtext DEFAULT NULL,PRIMARY KEY(meta_id),KEY post_id(post_id),KEY meta_key(meta_key(191))",
			'term_relationships'=>"object_id bigint unsigned NOT NULL DEFAULT 0,term_taxonomy_id bigint unsigned NOT NULL DEFAULT 0,term_order int NOT NULL DEFAULT 0,PRIMARY KEY(object_id,term_taxonomy_id),KEY term_taxonomy_id(term_taxonomy_id)",
			'term_taxonomy'=>"term_taxonomy_id bigint unsigned NOT NULL AUTO_INCREMENT,term_id bigint unsigned NOT NULL DEFAULT 0,taxonomy varchar(32) NOT NULL DEFAULT '',description longtext NOT NULL,parent bigint unsigned NOT NULL DEFAULT 0,count bigint NOT NULL DEFAULT 0,PRIMARY KEY(term_taxonomy_id),UNIQUE KEY term_id_taxonomy(term_id,taxonomy),KEY taxonomy(taxonomy)"
		];
		foreach($ddl as $suffix=>$columns){DataLifecycleProofDatabase::execute($database,'CREATE TABLE `'.$prefix.$suffix.'` ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');}
		self::product($database,$prefix,10);
		DataLifecycleProofDatabase::execute($database,"INSERT INTO `{$prefix}postmeta`(post_id,meta_key,meta_value) VALUES(10,'_stock_status','instock'),(10,'_manage_stock','no'),(10,'_tax_status','taxable'),(10,'_tax_class','')");
	}
	public static function product(\mysqli $database,string $prefix,int $id,string $type='product',int $parent=0):void {
		DataLifecycleProofDatabase::validate_prefix($prefix);if($id<1||$parent<0||!in_array($type,['product','product_variation'],true)){throw new \InvalidArgumentException('Invalid physical product fixture.');}
		DataLifecycleProofDatabase::execute($database,"INSERT INTO `{$prefix}posts` (ID,post_type,post_parent,post_status,post_content,post_title,post_excerpt,to_ping,pinged,post_content_filtered,post_modified,post_modified_gmt) VALUES ({$id},'{$type}',{$parent},'publish','','Fixture product','','','','','2026-10-07 05:00:00','2026-10-07 05:00:00')");
	}
}
