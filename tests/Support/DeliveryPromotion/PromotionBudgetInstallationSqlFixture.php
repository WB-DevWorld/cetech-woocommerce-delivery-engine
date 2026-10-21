<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetSchema as BudgetSchema, PromiseStorageSchema, ShipmentPromiseReadiness, ShipmentPromiseSchema};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

/** Only source-defined tables in an explicitly disposable database and marked namespace. */
final class PromotionBudgetInstallationSqlFixture {
	public const MIGRATION_FILE = '20261021213000_create_promotion_budget_tables.php';
	public static function base( \mysqli $db, string $prefix, bool $retained = false ): void {
		DB::validate_prefix( $prefix );
		if ( $retained ) {
			DB::install( $db, $prefix );
			foreach ( [ PromiseStorageSchema::class, ShipmentPromiseSchema::class ] as $schema ) { foreach ( $schema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $sql ) { DB::execute( $db, $sql ); } }
			DB::execute( $db, "INSERT INTO `{$prefix}delivery_engine_delivery_offers` (internal_code,internal_name,route) VALUES ('owned-history','Owned synthetic history','local_delivery')" );
			DB::execute( $db, "INSERT INTO `{$prefix}delivery_engine_shipments` (order_id,shipment_number,idempotency_key,delivery_group_id,status,currency_code,customer_paid_shipping_amount,eta_original,eta_current) VALUES (70001,'OWNED-70001','owned-installation-history','owned-group','processing','GHS',12.50,'Original retained ETA','Original retained ETA')" );
			DB::execute( $db, "INSERT INTO `{$prefix}delivery_engine_shipment_items` (shipment_id,order_id,order_item_id,product_id,quantity,product_name_snapshot) VALUES (1,70001,71001,10,2,'Owned synthetic product')" );
		} else { DB::execute( $db, "CREATE TABLE `{$prefix}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,option_name varchar(191) NOT NULL UNIQUE,option_value longtext NOT NULL,autoload varchar(20) NOT NULL DEFAULT 'off') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" ); }
		self::option( $db, $prefix, SchemaVersion::OPTION_NAME, '11' );
		self::option( $db, $prefix, MigrationStatus::OPTION_NAME, [ 'status' => 'success', 'to_version' => '11', 'migration_id' => ShipmentPromiseReadiness::MIGRATION_ID ] );
		self::option( $db, $prefix, 'owned_installation_control', [ 'preserve' => 'synthetic bytes', 'revision' => 7 ] );
	}
	public static function option( \mysqli $db, string $prefix, string $name, mixed $value ): void {
		DB::validate_prefix( $prefix ); $bytes = is_array( $value ) ? serialize( $value ) : (string) $value;
		$stmt = $db->prepare( "INSERT INTO `{$prefix}options` (option_name,option_value,autoload) VALUES (?,?,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='off'" );
		if ( ! $stmt || ! $stmt->bind_param( 'ss', $name, $bytes ) || ! $stmt->execute() ) { throw new \RuntimeException( 'Owned migration option write failed.' ); } $stmt->close();
	}
	public static function install_budgets( \mysqli $db, string $prefix, ?string $only = null ): void { foreach ( BudgetSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $suffix => $sql ) { if ( null === $only || $only === $suffix ) { DB::execute( $db, $sql ); } } }
	public static function insert( \mysqli $db, string $prefix, string $suffix, array $row ): void {
		DB::validate_prefix( $prefix ); $table = BudgetSchema::table( $prefix, $suffix );
		if ( array_diff( array_keys( $row ), array_keys( BudgetSchema::columns( $suffix ) ) ) ) { throw new \InvalidArgumentException(); }
		$values = array_map( static fn( mixed $value ): string => "'" . $db->real_escape_string( (string) $value ) . "'", array_values( $row ) );
		DB::execute( $db, "INSERT INTO `{$table}` (`" . implode( '`,`', array_keys( $row ) ) . '`) VALUES (' . implode( ',', $values ) . ')' );
	}
	public static function domain_tables( \mysqli $db, string $prefix ): array {
		DB::validate_prefix( $prefix ); $stem = $prefix . 'delivery_engine_'; $escaped = $db->real_escape_string( $stem );
		$rows = $db->query( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $stem ) . ")='{$escaped}' ORDER BY TABLE_NAME" );
		if ( ! $rows instanceof \mysqli_result ) { throw new \RuntimeException(); } $tables = array_column( $rows->fetch_all( MYSQLI_ASSOC ), 'TABLE_NAME' ); sort( $tables, SORT_STRING ); return $tables;
	}
	/** DDL, exact raw rows and native checksums cover all retained source stores. */
	public static function snapshot( \mysqli $db, array $tables ): array {
		$out = [];
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_[a-z0-9_]+\z/D', $table ) ) { throw new \InvalidArgumentException(); }
			$ddl = DB::row( $db, "SHOW CREATE TABLE `{$table}`" ); $rows = $db->query( "SELECT * FROM `{$table}` ORDER BY id" ); $checksum = DB::row( $db, "CHECKSUM TABLE `{$table}`" );
			if ( ! $rows instanceof \mysqli_result || null === $ddl || null === $checksum || null === $checksum['Checksum'] ) { throw new \RuntimeException( 'Owned migration snapshot failed.' ); }
			$out[$table] = [ 'ddl' => array_values( $ddl )[1], 'rows' => $rows->fetch_all( MYSQLI_ASSOC ), 'checksum' => (string) $checksum['Checksum'] ];
		}
		return $out;
	}
}

/** Native wpdb-shaped reader for isolated migration children; not a WordPress emulator. */
final class PromotionBudgetInstallationWpdb {
	public string $last_error = ''; public string $options; public int $ddl_calls = 0; public int $ddl_creates = 0;
	public function __construct( public readonly \mysqli $db, public readonly string $prefix, public readonly string $fault = '' ) { DB::validate_prefix( $prefix ); $this->options = $prefix . 'options'; }
	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function prepare( string $sql, mixed ...$args ): string {
		$position = 0;
		$out = preg_replace_callback( '/%%|%[sd]/', function( array $m ) use ( &$position, $args ): string {
			if ( '%%' === $m[0] ) { return '%'; } if ( ! array_key_exists( $position, $args ) ) { throw new \InvalidArgumentException(); } $arg = $args[$position++];
			if ( '%d' === $m[0] ) { if ( ! is_int( $arg ) ) { throw new \InvalidArgumentException(); } return (string) $arg; }
			if ( ! is_string( $arg ) && ! is_int( $arg ) ) { throw new \InvalidArgumentException(); } return "'" . $this->db->real_escape_string( (string) $arg ) . "'";
		}, $sql );
		if ( null === $out || $position !== count( $args ) ) { throw new \InvalidArgumentException(); } return $out;
	}
	public function get_row( string $sql, mixed $output = null ): array|null|false { $rows = $this->get_results( $sql, $output ); return false === $rows ? false : ( $rows[0] ?? null ); }
	public function get_results( string $sql, mixed $output = null ): array|false { $this->last_error = ''; $result = $this->db->query( $sql ); if ( ! $result instanceof \mysqli_result ) { $this->last_error = 'Owned migration read failed.'; return false; } return $result->fetch_all( MYSQLI_ASSOC ); }
	public function option( string $name, mixed $default ): mixed { $row = $this->get_row( $this->prepare( "SELECT option_value FROM `{$this->options}` WHERE option_name=%s LIMIT 1", $name ) ); if ( false === $row ) { throw new \RuntimeException(); } if ( null === $row ) { return $default; } $bytes = $row['option_value']; return str_starts_with( $bytes, 'a:' ) ? unserialize( $bytes, [ 'allowed_classes' => false ] ) : $bytes; }
	public function update_option( string $name, mixed $value ): bool {
		if ( 'deny_version' === $this->fault && SchemaVersion::OPTION_NAME === $name || 'deny_success' === $this->fault && MigrationStatus::OPTION_NAME === $name && is_array( $value ) && 'success' === ( $value['status'] ?? null ) ) { return false; }
		PromotionBudgetInstallationSqlFixture::option( $this->db, $this->prefix, $name, $value ); return true;
	}
	/** Source DDL execution shim. Native WordPress dbDelta is covered separately by the WP CI lane. */
	public function delta( string $sql ): void {
		++$this->ddl_calls;
		if ( 1 !== preg_match( '/\ACREATE TABLE ([a-zA-Z0-9_]+) \(/', $sql, $match ) || ! in_array( $match[1], BudgetSchema::tables( $this->prefix ), true ) ) { throw new \RuntimeException( 'Unexpected owned migration DDL.' ); }
		$existing = DB::scalar( $this->db, $this->prepare( 'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $match[1] ) );
		if ( 0 === (int) $existing ) { DB::execute( $this->db, $sql ); ++$this->ddl_creates; }
		if ( 'interrupt_after_first' === $this->fault && 1 === $this->ddl_calls ) { throw new \RuntimeException( 'Owned migration interruption after first durable DDL.' ); }
	}
}
