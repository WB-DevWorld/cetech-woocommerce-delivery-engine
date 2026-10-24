<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\ReturnPolicy;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCommitResult, OperationConnectionFactory, OperationPhaseObserver, OperationSession};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyContent, ReturnPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleLifecycleCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{OperationStoreSchema, RuleLifecycleSchema};

/** Owned in-memory C01/C03 protocol model. It proves neither MariaDB locks nor independent connections. */
final class ReturnPolicyCatalogFixture {

	public mixed $scope_allowed = true;
	public mixed $author_allowed = true;
	public mixed $binding_allowed = true;
	public mixed $catalog_allowed = true;
	public ?\Closure $on_scope = null;
	public ?\Closure $on_author = null;
	public ?\Closure $on_binding = null;
	public ?\Closure $on_catalog = null;
	/** Explicit trusted delegation grant; callbacks are never inferred from principal text. */
	public ?\Closure $binding_grant = null;
	public array $calls = [ 'scope' => 0, 'author' => 0, 'binding' => 0, 'catalog' => 0 ];
	public readonly ReturnPolicyFamily $family;
	public readonly ReturnPolicyCatalogUnitFactory $factory;

	public function __construct() {
		$this->family = new ReturnPolicyFamily(
			function ( OperationIdentity $identity, array $scope ): mixed {
				$this->invoke( 'scope', $identity, $scope );
				return true === $this->scope_allowed ? 1 === $identity->site_id : $this->scope_allowed;
			},
			function ( int $site_id, int $author, array $scope ): mixed {
				$this->invoke( 'author', $site_id, $author, $scope );
				return true === $this->author_allowed ? 1 === $site_id && $author > 0 : $this->author_allowed;
			},
			function ( OperationIdentity $identity, int $author, array $scope ): mixed {
				$this->invoke( 'binding', $identity, $author, $scope );
				if ( null !== $this->binding_grant ) { return ( $this->binding_grant )( $identity, $author, $scope ); }
				return true === $this->binding_allowed ? 'wordpress' === $identity->authority && 'staff:' . $author === $identity->principal : $this->binding_allowed;
			}
		);
		$this->factory = new ReturnPolicyCatalogUnitFactory();
	}

	public function service( ?OperationPhaseObserver $observer = null ): ReturnPolicyCatalogService {
		return new ReturnPolicyCatalogService( $this->family, $this->factory, function ( OperationIdentity $identity ): mixed {
			$this->invoke( 'catalog', $identity );
			return $this->catalog_allowed;
		}, new ReturnPolicyCatalogUnitReadiness(), $observer );
	}

	public static function uuid( int $id ): string { return sprintf( '00000000-0000-4000-8000-%012x', $id ); }

	public function payload( array $content_changes = [], int $logical = 101, int $version = 1 ): array {
		return [
			'family' => ReturnPolicyContent::FAMILY, 'logical_uuid' => self::uuid( $logical ), 'version_uuid' => self::uuid( $version ),
			'scope' => [ 'policy_uuid' => self::uuid( $logical ) ], 'payload' => ReturnPolicyFixture::content( $content_changes )->to_rule_payload(),
			'start_mode' => 'immediate', 'effective_from' => null, 'effective_until' => null, 'priority' => 0, 'author_user_id' => 9,
			'change_reason' => 'PRIVATE_RETURN_CATALOG_REASON', 'predecessor_uuid' => null,
			'preconditions' => [ 'family_revision' => 0, 'logical_id' => 0, 'logical_revision' => 0, 'version_id' => 0, 'version_revision' => 0, 'predecessor_id' => 0, 'predecessor_revision' => 0 ],
		];
	}

	public function identity( array $payload, string $operation = 'rule.draft.create', string $token = 'original', int $site = 1, string $principal = 'staff:9' ): OperationIdentity {
		return new OperationIdentity( $site, 'wordpress', $principal, $operation, 1,
			RuleLifecycleCommand::make_target_key( $payload['family'], RuleContent::scope_hash( $this->family, $payload['scope'] ), $payload['logical_uuid'] ), $token );
	}

	public function attempt( string $operation, array $payload, string $token = 'original' ): OperationAttemptResult {
		return $this->service()->attempt( $this->identity( $payload, $operation, $token ), $payload, RequestContext::create() );
	}

	/** The original currently opened envelope, including real stored IDs and revisions. */
	public function opened( ?string $logical_uuid = null, ?string $version_uuid = null ): array {
		$db = $this->factory->inspection();
		$logical = $this->select_uuid( 'logical_rules', 'logical_uuid', $logical_uuid );
		$version = $this->select_uuid( 'rule_versions', 'version_uuid', $version_uuid );
		$guard = $db->query( 'SELECT * FROM ' . $this->table( 'rule_family_guards' ) . ' WHERE id=' . (int) $logical['family_guard_id'] )->fetch( \PDO::FETCH_ASSOC );
		$prior = null === $version['supersedes_version_id'] ? null : $db->query( 'SELECT * FROM ' . $this->table( 'rule_versions' ) . ' WHERE id=' . (int) $version['supersedes_version_id'] )->fetch( \PDO::FETCH_ASSOC );
		return [
			'family' => $guard['family_code'], 'logical_uuid' => $logical['logical_uuid'], 'version_uuid' => $version['version_uuid'],
			'scope' => json_decode( $logical['scope_json'], true, 8, JSON_THROW_ON_ERROR ), 'payload' => json_decode( $version['payload_json'], true, 8, JSON_THROW_ON_ERROR ),
			'start_mode' => $version['start_mode'], 'effective_from' => $version['effective_from'], 'effective_until' => $version['effective_until'],
			'priority' => (int) $version['priority'], 'author_user_id' => (int) $version['author_user_id'], 'change_reason' => $version['change_reason'], 'predecessor_uuid' => $prior['version_uuid'] ?? null,
			'preconditions' => [ 'family_revision' => (int) $guard['revision'], 'logical_id' => (int) $logical['id'], 'logical_revision' => (int) $logical['revision'],
				'version_id' => (int) $version['id'], 'version_revision' => (int) $version['row_revision'], 'predecessor_id' => (int) ( $prior['id'] ?? 0 ), 'predecessor_revision' => (int) ( $prior['row_revision'] ?? 0 ) ],
		];
	}

	public function table( string $suffix ): string { return $this->factory->table( $suffix ); }
	public function rows( string $suffix ): array { return $this->factory->inspection()->query( 'SELECT * FROM ' . $this->table( $suffix ) . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); }
	public function row( string $suffix ): array { return $this->rows( $suffix )[0] ?? throw new \RuntimeException( 'Owned return-policy fixture row is absent.' ); }
	public function count( string $suffix ): int { return (int) $this->factory->inspection()->query( 'SELECT COUNT(*) FROM ' . $this->table( $suffix ) )->fetchColumn(); }
	public function close(): void { $this->factory->close(); }

	private function invoke( string $grant, mixed ...$args ): void {
		++$this->calls[$grant]; $hook = $this->{ 'on_' . $grant };
		if ( null !== $hook ) { $hook( ...$args ); }
	}
	private function select_uuid( string $suffix, string $column, ?string $uuid ): array {
		$sql = 'SELECT * FROM ' . $this->table( $suffix );
		if ( null !== $uuid ) { $sql .= ' WHERE ' . $column . '=' . $this->factory->inspection()->quote( $uuid ); }
		return $this->factory->inspection()->query( $sql . ' ORDER BY id DESC LIMIT 1' )->fetch( \PDO::FETCH_ASSOC ) ?: throw new \RuntimeException( 'Owned return-policy fixture row is absent.' );
	}
}

final class ReturnPolicyCatalogUnitReadiness implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} }

/** Sequential in-memory transport adapter; domain and operation behavior remain production code. */
final class ReturnPolicyCatalogUnitFactory implements OperationConnectionFactory {
	public const PREFIX = 'return_catalog_unit_';
	private ?\PDO $database;
	public string $clock = '2026-10-24 10:00:00.000001';
	public int $opens = 0;
	public int $commits = 0;
	public int $rollbacks = 0;
	public int $retirements = 0;
	public bool $reject_audit = false;
	public bool $reject_completion = false;
	public ?int $lost_ack_at = null;
	public ?int $not_sent_at = null;
	public ?\Closure $on_open = null;
	public ?\Closure $on_query = null;
	public ?\Closure $on_commit = null;
	public array $sessions = [];
	public array $sql = [];

	public function __construct() {
		$options = [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ];
		$this->database = method_exists( \PDO::class, 'connect' ) ? \PDO::connect( 'sqlite::memory:', null, null, $options ) : new \PDO( 'sqlite::memory:', null, null, $options );
		$clock = fn (): string => $this->clock;
		if ( method_exists( $this->database, 'createFunction' ) ) { $this->database->createFunction( 'UTC_NOW', $clock ); }
		else { $this->database->sqliteCreateFunction( 'UTC_NOW', $clock ); }
		foreach ( [ OperationStoreSchema::class, RuleLifecycleSchema::class ] as $schema ) {
			foreach ( $schema::SUFFIXES as $suffix ) {
				$columns = [];
				foreach ( $schema::columns( $suffix ) as $name => $facts ) {
					if ( 'id' === $name ) { $columns[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; continue; }
					$column = $name . ( str_contains( $facts[0], 'int' ) ? ' INTEGER' : ' TEXT' ) . ( $facts[1] ? '' : ' NOT NULL' );
					if ( null !== $facts[2] ) { $column .= ' DEFAULT ' . $this->database->quote( $facts[2] ); } $columns[] = $column;
				}
				$table = $this->table( $suffix ); $this->database->exec( 'CREATE TABLE ' . $table . ' (' . implode( ',', $columns ) . ')' );
				foreach ( $schema::indexes( $suffix ) as $name => $facts ) {
					if ( 'PRIMARY' !== $name ) { $this->database->exec( 'CREATE ' . ( $facts['unique'] ? 'UNIQUE ' : '' ) . 'INDEX ' . $table . '_' . $name . ' ON ' . $table . '(' . implode( ',', $facts['columns'] ) . ')' ); }
				}
			}
		}
	}
	public function inspection(): \PDO { return $this->database ?? throw new \RuntimeException( 'Owned return-policy unit model is closed.' ); }
	public function table( string $suffix ): string {
		if ( ! in_array( $suffix, array_merge( OperationStoreSchema::SUFFIXES, RuleLifecycleSchema::SUFFIXES ), true ) ) { throw new \InvalidArgumentException( 'Unsupported unit-model table.' ); }
		return self::PREFIX . 'delivery_engine_' . $suffix;
	}
	public function open(): OperationSession {
		++$this->opens; if ( null !== $this->on_open ) { ( $this->on_open )( $this ); }
		$session = new ReturnPolicyCatalogUnitSession( $this ); $this->sessions[] = $session; return $session;
	}
	public function close(): void { foreach ( $this->sessions as $session ) { $session->retire(); } $this->database = null; }
}

final class ReturnPolicyCatalogUnitSession implements OperationSession {
	private ?\PDO $db;
	private bool $retired = false;
	private int $error = 0;
	private array $tables = [];
	public function __construct( private readonly ReturnPolicyCatalogUnitFactory $factory ) { $this->db = $factory->inspection(); }
	public function site_id(): int { return 1; }
	public function table_prefix(): string { return ReturnPolicyCatalogUnitFactory::PREFIX; }
	public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { $this->tables = []; return ! $this->retired && ! $this->in_transaction() && $this->db->beginTransaction(); }
	public function commit(): OperationCommitResult {
		++$this->factory->commits;
		if ( $this->factory->not_sent_at === $this->factory->commits ) { $this->factory->not_sent_at = null; return OperationCommitResult::NotSent; }
		$this->db->commit(); if ( null !== $this->factory->on_commit ) { ( $this->factory->on_commit )( $this->factory ); }
		if ( $this->factory->lost_ack_at === $this->factory->commits ) { $this->factory->lost_ack_at = null; $this->retire(); return OperationCommitResult::Unconfirmed; }
		return OperationCommitResult::Acknowledged;
	}
	public function rollback(): bool { ++$this->factory->rollbacks; return ! $this->retired && $this->db->inTransaction() && $this->db->rollBack(); }
	public function retire(): bool {
		if ( null !== $this->db && $this->db->inTransaction() ) { $this->db->rollBack(); }
		$this->db = null; if ( ! $this->retired ) { ++$this->factory->retirements; } $this->retired = true; return true;
	}
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return ! $this->retired && null !== $this->db && $this->db->inTransaction(); }
	public function validate_tables( array $tables ): bool {
		if ( ! $this->in_transaction() ) { return false; }
		foreach ( $tables as $table ) {
			if ( ! str_starts_with( $table, ReturnPolicyCatalogUnitFactory::PREFIX ) || false === $this->db->query( 'SELECT 1 FROM ' . $table . ' LIMIT 0' ) ) { return false; }
			$this->tables[$table] = true;
		}
		return true;
	}
	public function query( string $sql ): int|false {
		if ( ! $this->in_transaction() ) { return false; } $this->error = 0; $this->record( $sql );
		if ( $this->factory->reject_audit && str_starts_with( $sql, 'INSERT INTO `' . $this->factory->table( 'operation_changes' ) . '`' ) ) { return false; }
		if ( $this->factory->reject_completion && str_starts_with( $sql, 'UPDATE `' . $this->factory->table( 'operation_records' ) . '`' ) && str_contains( $sql, 'completion_json=' ) ) { return false; }
		if ( 1 === preg_match( '/^(?:INSERT INTO|UPDATE) `([^`]+)`/', $sql, $match ) && ! isset( $this->tables[$match[1]] ) ) { return false; }
		try { return $this->db->exec( $this->sql( $sql ) ); }
		catch ( \PDOException $e ) { $this->error = str_contains( $e->getMessage(), 'UNIQUE constraint' ) ? 1062 : 1; return false; }
	}
	public function get_row( string $sql ): array|null|false { $rows = $this->get_results( $sql ); return false === $rows ? false : ( $rows[0] ?? null ); }
	public function get_results( string $sql ): array|false {
		if ( ! $this->in_transaction() ) { return false; } $this->record( $sql );
		try { return $this->db->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); }
		catch ( \PDOException ) { $this->error = 1; return false; }
	}
	public function prepare( string $sql, mixed ...$args ): string {
		$index = 0; return preg_replace_callback( '/%([ds])/', function ( array $m ) use ( $args, &$index ): string { $value = $args[$index++]; return 'd' === $m[1] ? (string) $value : $this->db->quote( $value ); }, $sql );
	}
	public function errno(): int { return $this->error; }
	public function insert_id(): int { return (int) $this->db->lastInsertId(); }
	private function record( string $sql ): void { $this->factory->sql[] = $sql; if ( null !== $this->factory->on_query ) { ( $this->factory->on_query )( $sql, $this->factory ); } }
	private function sql( string $sql ): string { return str_replace( [ ' FOR UPDATE', 'UTC_TIMESTAMP(6)' ], [ '', 'UTC_NOW()' ], $sql ); }
}
