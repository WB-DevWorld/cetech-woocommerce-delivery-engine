<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

/** Native Woo session table and one unrelated monetary row in an exclusive fixture. */
final class QuoteCartProofDatabase {
	public static function install_sessions(\mysqli $database,string $prefix):void {
		DB::validate_prefix($prefix);
		DB::execute($database,"CREATE TABLE `{$prefix}woocommerce_sessions` (session_id bigint unsigned NOT NULL AUTO_INCREMENT,session_key char(32) NOT NULL,session_value longtext NOT NULL,session_expiry bigint unsigned NOT NULL,PRIMARY KEY(session_id),UNIQUE KEY session_key(session_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		$value=$database->real_escape_string(serialize(['cart'=>'KEEP','chosen_shipping'=>['native_method:1']]));DB::execute($database,"INSERT INTO `{$prefix}woocommerce_sessions` (session_key,session_value,session_expiry) VALUES ('native-customer-row','{$value}',1791352800)");
	}
	public static function sessions(\mysqli $database,string $prefix):array {
		DB::validate_prefix($prefix);$r=$database->query("SELECT * FROM `{$prefix}woocommerce_sessions` ORDER BY session_id");if(!$r instanceof \mysqli_result){throw new \RuntimeException('Native session proof read refused.');}return $r->fetch_all(MYSQLI_ASSOC);
	}
	/** Real native column names for the finite synthetic receipt's empty source sets. */
	public static function install_native_receipt_tables(\mysqli $database,string $prefix):void {
		DB::validate_prefix($prefix);$tables=[
			'woocommerce_tax_rates'=>"tax_rate_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,tax_rate_country varchar(2) NOT NULL DEFAULT '',tax_rate_state varchar(200) NOT NULL DEFAULT '',tax_rate varchar(8) NOT NULL DEFAULT '',tax_rate_name varchar(200) NOT NULL DEFAULT '',tax_rate_priority bigint NOT NULL DEFAULT 1,tax_rate_compound int NOT NULL DEFAULT 0,tax_rate_shipping int NOT NULL DEFAULT 1,tax_rate_order bigint NOT NULL DEFAULT 0,tax_rate_class varchar(200) NOT NULL DEFAULT ''",
			'wc_tax_rate_classes'=>"tax_rate_class_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,name varchar(200) NOT NULL,slug varchar(200) NOT NULL",
			'woocommerce_tax_rate_locations'=>"location_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,location_code varchar(200) NOT NULL,tax_rate_id bigint NOT NULL,location_type varchar(40) NOT NULL",
			'woocommerce_shipping_zone_methods'=>"zone_id bigint unsigned NOT NULL,instance_id bigint unsigned NOT NULL PRIMARY KEY,method_id varchar(200) NOT NULL,method_order bigint NOT NULL DEFAULT 0,is_enabled tinyint NOT NULL DEFAULT 1",
			'usermeta'=>"umeta_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id bigint unsigned NOT NULL DEFAULT 0,meta_key varchar(255) DEFAULT NULL,meta_value longtext DEFAULT NULL,KEY user_id(user_id),KEY meta_key(meta_key(191))"
		];foreach($tables as $suffix=>$columns){DB::execute($database,"CREATE TABLE `{$prefix}{$suffix}` ({$columns}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}
	}
}
