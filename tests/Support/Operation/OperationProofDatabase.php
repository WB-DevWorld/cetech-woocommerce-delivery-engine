<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;

/** Disposable physical database support. Never connects to a live-site database. */
final class OperationProofDatabase {

	public static function connect(): \mysqli {
		if ( ! extension_loaded( 'mysqli' ) ) {
			throw new \RuntimeException( 'Operation proof requires the mysqli extension.' );
		}
		$name = (string) ( getenv( 'CETECH_DE_REAL_DB_NAME' ) ?: '' );
		if ( 'cetech_geo11' !== $name && 1 !== preg_match( '/\Acetech_operation_[a-z0-9_]+\z/D', $name ) ) {
			throw new \RuntimeException( 'Operation proof refuses a non-disposable database.' );
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		try {
			$connection = new \mysqli(
				(string) ( getenv( 'CETECH_DE_REAL_DB_HOST' ) ?: '127.0.0.1' ),
				(string) ( getenv( 'CETECH_DE_REAL_DB_USER' ) ?: 'root' ),
				(string) ( getenv( 'CETECH_DE_REAL_DB_PASSWORD' ) ?: '' ),
				$name,
				(int) ( getenv( 'CETECH_DE_REAL_DB_PORT' ) ?: 3306 )
			);
			if ( 0 !== $connection->connect_errno || ! $connection->set_charset( 'utf8mb4' ) ) {
				throw new \RuntimeException();
			}
			return $connection;
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'Disposable operation database is not reachable.' );
		}
	}

	public static function prefix(): string {
		return 'op_proof_' . getmypid() . '_' . bin2hex( random_bytes( 4 ) ) . '_';
	}

	public static function validate_prefix( string $prefix ): void {
		if ( 1 !== preg_match( '/\Aop_proof_[0-9]+_[a-f0-9]{8}_\z/D', $prefix ) ) {
			throw new \InvalidArgumentException( 'Invalid disposable operation prefix.' );
		}
	}

	public static function execute( \mysqli $connection, string $sql ): void {
		if ( false === $connection->query( $sql ) ) {
			throw new \RuntimeException( 'Disposable operation fixture statement failed.' );
		}
	}

	public static function row( \mysqli $connection, string $sql ): ?array {
		$result = $connection->query( $sql );
		if ( ! $result instanceof \mysqli_result ) {
			throw new \RuntimeException( 'Disposable operation fixture read failed.' );
		}
		$row = $result->fetch_assoc();
		$result->free();
		return $row;
	}

	public static function scalar( \mysqli $connection, string $sql ): mixed {
		$row = self::row( $connection, $sql );
		return null === $row ? null : reset( $row );
	}

	public static function install_resource( \mysqli $connection, string $prefix ): void {
		self::validate_prefix( $prefix );
		self::execute( $connection, "CREATE TABLE `{$prefix}operation_fixture_counter` (id bigint unsigned NOT NULL PRIMARY KEY, revision bigint unsigned NOT NULL, value bigint NOT NULL, published_revision bigint unsigned NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		self::execute( $connection, "INSERT INTO `{$prefix}operation_fixture_counter` (id,revision,value) VALUES (1,1,0)" );
	}

	public static function install( \mysqli $connection, string $prefix ): void {
		self::validate_prefix( $prefix );
		foreach ( OperationStoreSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $sql ) {
			self::execute( $connection, $sql );
		}
		self::execute( $connection, "CREATE TABLE `{$prefix}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, option_name varchar(191) NOT NULL UNIQUE, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'off') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		self::option( $connection, $prefix, SchemaVersion::OPTION_NAME, '7' );
		self::option( $connection, $prefix, MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'to_version' => '7', 'migration_id' => OperationStoreReadiness::MIGRATION_ID ] ) );
		self::install_resource( $connection, $prefix );
	}

	public static function option( \mysqli $connection, string $prefix, string $name, string $value ): void {
		self::validate_prefix( $prefix );
		$statement = $connection->prepare( "INSERT INTO `{$prefix}options` (option_name,option_value) VALUES (?,?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		if ( false === $statement || ! $statement->bind_param( 'ss', $name, $value ) || ! $statement->execute() ) {
			throw new \RuntimeException( 'Disposable operation option setup failed.' );
		}
		$statement->close();
	}

	public static function resource( \mysqli $connection, string $prefix ): array {
		self::validate_prefix( $prefix );
		$row = self::row( $connection, "SELECT id,revision,value,published_revision FROM `{$prefix}operation_fixture_counter` WHERE id=1" );
		if ( null === $row ) { throw new \RuntimeException( 'Disposable operation resource is missing.' ); }
		return array_map( 'intval', $row );
	}

	public static function cleanup( \mysqli $connection, string $prefix ): void {
		self::validate_prefix( $prefix );
		foreach ( [ 'delivery_engine_operation_changes', 'delivery_engine_operation_records', 'operation_fixture_counter', 'options' ] as $suffix ) {
			self::execute( $connection, "DROP TABLE IF EXISTS `{$prefix}{$suffix}`" );
		}
	}
}
