<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuotePlacementActivation,QuotePlacementPolicyFence,QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession};

/** SQLite option/CAS model only; never a native current-read proof. */

final class QuoteActivationGuard implements QuotePlacementSavedEvidenceGuard {
	public int $calls = 0; public function __construct( public bool $allowed ) {}
	public function tables( OperationSession $s ): array { return [ $s->table_prefix() . 'options' ]; }
	public function verify( OperationSession $s, QuoteBinding $binding ): bool { ++$this->calls; return $this->allowed; }
}
final class QuoteActivationFactory implements OperationConnectionFactory {
	public \PDO $pdo; public int $opens = 0; public array $sql = []; public OperationCommitResult $fault = OperationCommitResult::Acknowledged; public bool $refuse_write = false; public bool $wrong_site = false;
	public function __construct() { $this->pdo = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] ); $this->pdo->exec( 'CREATE TABLE activation_options(option_id INTEGER PRIMARY KEY AUTOINCREMENT,option_name TEXT UNIQUE,option_value TEXT,autoload TEXT)' ); $insert = $this->pdo->prepare( 'INSERT INTO activation_options(option_name,option_value,autoload) VALUES(?,?,?)' ); foreach ( QuotePlacementPolicyFence::names() as $name ) { if ( QuotePlacementActivation::OPTION !== $name ) { $insert->execute( [ $name, '1', 'no' ] ); } } }
	public function adoption_rows(): int { return (int) $this->pdo->query( "SELECT COUNT(*) FROM activation_options WHERE option_name='" . QuotePlacementActivation::OPTION . "'" )->fetchColumn(); }
	public function open(): OperationSession { ++$this->opens; return new QuoteActivationSession( $this ); }
}
final class QuoteActivationSession implements OperationSession {
	private bool $retired = false; public function __construct( private QuoteActivationFactory $f ) {}
	public function site_id(): int { return $this->f->wrong_site ? 2 : 1; } public function table_prefix(): string { return 'activation_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { return ! $this->retired && $this->f->pdo->beginTransaction(); }
	public function commit(): OperationCommitResult { $result = $this->f->fault; $this->f->fault = OperationCommitResult::Acknowledged; if ( OperationCommitResult::NotSent !== $result ) { $this->f->pdo->commit(); } return $result; }
	public function rollback(): bool { return $this->f->pdo->inTransaction() && $this->f->pdo->rollBack(); }
	public function retire(): bool { if ( $this->f->pdo->inTransaction() ) { $this->f->pdo->rollBack(); } $this->retired = true; return true; }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return ! $this->retired && $this->f->pdo->inTransaction(); }
	public function validate_tables( array $tables ): bool { return [ 'activation_options' ] === $tables; }
	public function query( string $sql ): int|false { $this->f->sql[] = $sql; if ( $this->f->refuse_write ) { return false; } return $this->f->pdo->exec( $this->translated( $sql ) ); }
	public function get_results( string $sql ): array|false { $this->f->sql[] = $sql; return $this->f->pdo->query( $this->translated( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); }
	public function get_row( string $sql ): array|null|false { $rows = $this->get_results( $sql ); return $rows[0] ?? null; }
	public function prepare( string $sql, mixed ...$args ): string { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } $i = 0; return preg_replace_callback( '/%[ds]/', function( array $match ) use ( &$i, $args ): string { $value = $args[$i++]; return '%d' === $match[0] ? (string) (int) $value : $this->f->pdo->quote( (string) $value ); }, $sql ); }
	public function errno(): int { return 0; } public function insert_id(): int { return (int) $this->f->pdo->lastInsertId(); }
	private function translated( string $sql ): string { return str_replace( [ 'LEFT(option_value,1025)', 'LEFT(option_value,65537)', ' FOR UPDATE', 'BINARY ' ], [ 'substr(option_value,1,1025)', 'substr(option_value,1,65537)', '', '' ], $sql ); }
}
