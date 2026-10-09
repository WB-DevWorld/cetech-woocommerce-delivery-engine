<?php

declare(strict_types=1);

use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderInterface;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceGuard;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePrivatePublication;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionReferenceInspector;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory as NativeQuoteFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;

/** Count actual fixture captures. These terms are not native Woo price capture. */
final class CetechNativeQuoteLifecycleProvider implements QuoteProviderInterface {
	public int $captures = 0;
	public bool $outside_owned_unit = true;
	public function __construct( private readonly QuoteContext $context, private readonly ?CetechNativeQuoteLifecycleFactory $factory = null ) {}
	public function code(): string { return 'fixture_v1'; }
	public function version(): int { return 1; }
	public function profile(): string { return 'fixture_v1'; }
	public function profile_version(): int { return 1; }
	public function evidence_providers(): array { return [ 'fixture_none_v1' => [ 1 ] ]; }
	public function capture( QuoteContext $context ): QuoteTerms {
		++$this->captures;
		$this->outside_owned_unit = $this->outside_owned_unit && ( null === $this->factory || ! $this->factory->has_active_owner() );
		if ( ! hash_equals( $this->context->digest(), $context->digest() ) ) { throw new RuntimeException( 'Native synthetic quote capture context changed.' ); }
		return QuoteFixtures::terms();
	}
}

/** Exact current synthetic source fence; no native Woo capture is implied. */
final class CetechNativeQuoteLifecycleEvidence implements QuoteCurrentEvidenceGuard {
	public const OPTION = 'cetech_native_q03_current_evidence';
	public function tables( OperationSession $session ): array { return [ $session->table_prefix() . 'options' ]; }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool {
		$table = $this->tables( $session )[0];
		$rows = $session->get_results( $session->prepare( "SELECT option_name,option_value FROM `{$table}` WHERE option_name=%s LIMIT 2 FOR UPDATE", self::OPTION ) );
		return is_array( $rows ) && 1 === count( $rows ) && self::OPTION === $rows[0]['option_name'] && hash_equals( self::value( $owner, $context ), $rows[0]['option_value'] );
	}
	public static function value( QuoteOwner $owner, QuoteContext $context ): string { return hash( 'sha256', 'native-synthetic-current-source:' . $owner->digest() . ':' . $context->digest() ); }
}

final class CetechNativeQuoteLifecyclePublication implements QuotePrivatePublication {
	public bool $accept = false;
	public int $calls = 0;
	public function invalidate( int $site_id, string $owner_digest, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId $quote_id ): bool { ++$this->calls; return $this->accept && ( new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdvisoryPublication() )->invalidate( $site_id, $owner_digest, $quote_id ); }
}

/** Explicit synthetic binding-only reference scope; not an HPOS/history reader. */
final class CetechNativeQuoteLifecycleReferences implements QuoteRetentionReferenceInspector {
	public function __construct( private readonly QuoteOwner $owner ) {}
	public function policy_digest(): string { return hash( 'sha256', 'native-synthetic-quote-binding-only-v1' ); }
	public function transactional_tables( OperationSession $session ): array { return [ $session->table_prefix() . 'delivery_engine_delivery_quote_bindings' ]; }
	public function authorize_owner( QuoteOwner $owner ): bool { return $this->owner->equals( $owner ); }
	public function inspect( OperationSession $session, QuoteStoredRow $quote ): string {
		if ( $quote->site_id() !== $session->site_id() || ! $this->owner->equals( $quote->header()->owner() ) || 'fixture_v1' !== $quote->header()->profile() ) { return 'unknown'; }
		$table = $this->transactional_tables( $session )[0];
		$rows = $session->get_results( $session->prepare( "SELECT id FROM `{$table}` WHERE site_id=%d AND quote_uuid=%s LIMIT 2 FOR UPDATE", $session->site_id(), $quote->header()->id()->value() ) );
		return false === $rows ? 'unknown' : ( [] === $rows ? 'absent' : 'protected' );
	}
}

/** Native quote effects are observed before the fault seam can mask a COMMIT. */
final class CetechNativeQuoteLifecycleTransport implements OperationConnectionTransport {
	private bool $quote_effect = false;
	public function __construct( private readonly OperationConnectionTransport $native, private readonly CetechNativeQuoteLifecycleFactory $factory, private readonly string $prefix ) {}
	public function execute( string $sql ): OperationConnectionResult {
		if ( 1 === preg_match( '/\A\s*START\s+TRANSACTION\b/i', $sql ) ) { $this->quote_effect = false; }
		if ( $this->factory->fault_available( 'reject_quote_audit' ) && $this->quote_effect && 1 === preg_match( '/\A\s*INSERT\s+INTO\s+`?' . preg_quote( $this->prefix . 'delivery_engine_operation_changes', '/' ) . '`?\b/i', $sql ) ) {
			$this->factory->consume_fault( $this->native->connection_id() ); ++$this->factory->rejected_audits;
			return new OperationConnectionResult( false, false, errno: 45000 );
		}
		if ( $this->quote_effect && 1 === preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			if ( $this->factory->fault_available( 'unsent_quote_commit' ) ) { $this->factory->consume_fault( $this->native->connection_id() ); ++$this->factory->unsent_quote_commits; return new OperationConnectionResult( false, false, errno: 45000 ); }
			$result = $this->native->execute( $sql );
			if ( $result->acknowledged ) { ++$this->factory->acknowledged_quote_commits; }
			if ( $result->acknowledged && $this->factory->fault_available( 'lost_quote_ack' ) ) { $this->factory->consume_fault( $this->native->connection_id() ); ++$this->factory->masked_sent_quote_commits; return new OperationConnectionResult( false, true, errno: 2013 ); }
			return $result;
		}
		$result = $this->native->execute( $sql );
		if ( $result->acknowledged && 1 === preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE)\s+`?' . preg_quote( $this->prefix . 'delivery_engine_delivery_quotes', '/' ) . '`?\b/i', $sql ) ) { $this->quote_effect = true; ++$this->factory->quote_mutation_statements; }
		if ( $result->acknowledged && 1 === preg_match( '/\A\s*ROLLBACK\b/i', $sql ) ) { ++$this->factory->acknowledged_rollbacks; }
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { $closed = $this->native->close(); if ( $closed ) { ++$this->factory->confirmed_closes; } return $closed; }
}

/** Trusted fixture configuration only, fresh dedicated native owner on every open. */
final class CetechNativeQuoteLifecycleFactory implements NativeQuoteFactory {
	private array $sessions = [];
	private string $fault = '';
	private bool $fault_consumed = false;
	private ?int $fault_connection = null;
	private ?QuoteTime $session_clock = null;
	public int $quote_mutation_statements = 0;
	public int $acknowledged_quote_commits = 0;
	public int $masked_sent_quote_commits = 0;
	public int $unsent_quote_commits = 0;
	public int $rejected_audits = 0;
	public int $acknowledged_rollbacks = 0;
	public int $confirmed_closes = 0;
	public int $verified_fixture_clocks = 0;
	public function __construct( private readonly wpdb $authority, public readonly int $site, public readonly string $prefix ) {
		if ( 99176 !== $site || 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_\z/D', $prefix ) ) { throw new RuntimeException( 'Native quote lifecycle route is invalid.' ); }
	}
	public function arm( string $fault ): void {
		if ( ! in_array( $fault, [ 'reject_quote_audit', 'unsent_quote_commit', 'lost_quote_ack' ], true ) ) { throw new RuntimeException( 'Native quote lifecycle fault is invalid.' ); }
		$this->fault = $fault; $this->fault_consumed = false; $this->fault_connection = null;
	}
	public function fault_available( string $fault ): bool { return ! $this->fault_consumed && $this->fault === $fault; }
	public function consume_fault( int $connection ): void { $this->fault_consumed = true; $this->fault_connection = $connection; }
	public function fault_owner_retired(): bool { return null !== $this->fault_connection && isset( $this->sessions[$this->fault_connection] ) && $this->sessions[$this->fault_connection]->is_retired(); }
	/** Test-only native SQL session clock; never a global database/runtime setting. */
	public function fixture_time( ?QuoteTime $time ): void { $this->session_clock = $time; }
	public function open(): OperationSession {
		$host = $this->authority->parse_db_host( DB_HOST );
		if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native quote lifecycle connection is unavailable.' ); }
		$transport = OperationConnectionMysqliTransport::connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2], $this->authority->charset ?: 'utf8mb4', defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 );
		if ( null !== $this->session_clock ) {
			$epoch = $this->session_clock->epoch_microseconds();
			$timestamp = intdiv( $epoch, 1000000 ) . '.' . str_pad( (string) ( $epoch % 1000000 ), 6, '0', STR_PAD_LEFT );
			$set = $transport->execute( 'SET timestamp=' . $timestamp ); $read = $transport->execute( 'SELECT UTC_TIMESTAMP(6) AS utc' );
			if ( ! $set->acknowledged || ! $read->acknowledged || [ [ 'utc' => $this->session_clock->sql() ] ] !== $read->rows ) { $transport->close(); throw new RuntimeException( 'Native quote fixture SQL clock was not verified.' ); }
			++$this->verified_fixture_clocks;
		}
		$owner = new OperationConnection( $this->site, $this->prefix, new CetechNativeQuoteLifecycleTransport( $transport, $this, $this->prefix ), $this->authority->charset ?: 'utf8mb4', $this->authority->collate ?: '' );
		$this->sessions[$transport->connection_id()] = $owner; return $owner;
	}
	public function close_all(): bool {
		$closed = true;
		foreach ( $this->sessions as $session ) { try { if ( $session->in_transaction() ) { $closed = $session->rollback() && $closed; } $closed = $session->retire() && $closed; } catch ( Throwable ) { $closed = false; } }
		$this->sessions = []; return $closed;
	}
	public function has_active_owner(): bool { foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() && $session->in_transaction() ) { return true; } } return false; }
}

/** Whole source-defined stores, isolated synthetic server context, no main-site mutation. */
final class CetechNativeQuoteLifecycleFixture {
	public readonly string $prefix;
	public readonly int $site;
	public readonly mysqli $physical;
	public readonly CetechNativeQuoteLifecycleFactory $factory;
	public ?wpdb $selected = null;
	private array $tables = [];
	/** Exact validated keys and string values; canonical JSON map order is immaterial. */
	public static function original_namespaces_match( \CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand $issue, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader $header ): bool {
		$expected = $issue->namespace_hashes( $header->id() ); $actual = $header->namespace_hashes();
		ksort( $expected, SORT_STRING ); ksort( $actual, SORT_STRING );
		return $expected === $actual;
	}
	public function __construct( private readonly wpdb $main ) {
		$this->site = 99176; $this->prefix = 'gc6_' . bin2hex( random_bytes( 6 ) ) . '_';
		$host = $main->parse_db_host( DB_HOST );
		if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native quote lifecycle authority is unavailable.' ); }
		try { $this->physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] ); if ( $this->physical->connect_errno || ! $this->physical->set_charset( $main->charset ?: 'utf8mb4' ) ) { throw new RuntimeException(); } }
		catch ( Throwable ) { throw new RuntimeException( 'Native quote lifecycle database is unavailable.' ); }
		$this->factory = new CetechNativeQuoteLifecycleFactory( $main, $this->site, $this->prefix );
	}
	public function install(): void {
		if ( 0 !== $this->table_count() || 35 !== count( DataLifecycleManifest::RETAINED_QUOTE_DOMAIN_TABLE_SUFFIXES ) ) { throw new RuntimeException( 'Native quote lifecycle namespace is unavailable.' ); }
		foreach ( DataLifecycleManifest::RETAINED_QUOTE_DOMAIN_TABLE_SUFFIXES as $suffix ) { $this->clone_table( $this->prefix . 'delivery_engine_' . $suffix, $this->main->prefix . 'delivery_engine_' . $suffix ); }
		$this->clone_table( $this->prefix . 'options', $this->main->options );
		// Retained Q03 profile remains an explicit historical schema9 fixture.
		$source = $this->rows( "SELECT option_value FROM `{$this->main->options}` WHERE option_name=" . $this->literal( SchemaVersion::OPTION_NAME ) );
		if ( 1 !== count( $source ) || SchemaVersion::TARGET !== $source[0]['option_value'] || '10' !== SchemaVersion::TARGET ) { throw new RuntimeException( 'Native quote lifecycle source publication is unavailable.' ); }
		$this->write_option( SchemaVersion::OPTION_NAME, '9' );
		$this->write_option( MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'success', 'from_version' => '8', 'to_version' => '9', 'migration_id' => \CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness::MIGRATION_ID ] ) );
	}
	private function clone_table( string $table, string $source ): void {
		foreach ( [ $table, $source ] as $name ) { if ( strlen( $name ) > 64 || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/D', $name ) ) { throw new RuntimeException( 'Native quote lifecycle table identity is invalid.' ); } }
		if ( ! str_starts_with( $table, $this->prefix ) ) { throw new RuntimeException( 'Native quote lifecycle table is not owned.' ); }
		$this->tables[] = $table; $this->execute( "CREATE TABLE `{$table}` LIKE `{$source}`" );
	}
	public function select(): void {
		$this->selected = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $this->selected->set_prefix( $this->prefix ); $this->selected->suppress_errors( true ); $this->selected->hide_errors();
		$GLOBALS['wpdb'] = $this->selected; $GLOBALS['blog_id'] = $this->site; $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	}
	public function write_option( string $name, string $value, string $autoload = 'off' ): void {
		$statement = $this->physical->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value,autoload) VALUES (?,?,?)" );
		if ( false === $statement || ! $statement->bind_param( 'sss', $name, $value, $autoload ) || ! $statement->execute() ) { throw new RuntimeException( 'Native quote lifecycle option setup failed.' ); }
		$statement->close();
	}
	public function literal( string $value ): string { return "'" . $this->physical->real_escape_string( $value ) . "'"; }
	public function execute( string $sql ): void { if ( false === $this->physical->query( $sql ) ) { throw new RuntimeException( 'Native quote lifecycle physical write failed.' ); } }
	public function rows( string $sql ): array {
		$result = $this->physical->query( $sql ); if ( ! $result instanceof mysqli_result ) { throw new RuntimeException( 'Native quote lifecycle physical read failed.' ); }
		$rows = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); return $rows;
	}
	public function table_count(): int { return (int) $this->rows( "SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix ) . ")='{$this->prefix}'" )[0]['total']; }
	public function quote_history(): array {
		$out = [];
		foreach ( [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = $this->rows( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); }
		return $out;
	}
	/** Bounded private child, with a finite public diagnostic vocabulary. */
	public static function child( array $config ): array {
		$path = tempnam( sys_get_temp_dir(), 'q03-native-process-' );
		if ( false === $path ) { throw new RuntimeException( 'Native quote child setup failed.' ); }
		$process = null; $pipes = [];
		try {
			if ( ! chmod( $path, 0600 ) || false === file_put_contents( $path, json_encode( $config, JSON_THROW_ON_ERROR ) ) ) { throw new RuntimeException( 'Native quote child setup failed.' ); }
			$process = proc_open( [ PHP_BINARY, __DIR__ . '/opening-quote-lifecycle-reader.php', $path ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
			if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Native quote child could not start.' ); }
			fclose( $pipes[0] ); stream_set_blocking( $pipes[1], false ); stream_set_blocking( $pipes[2], false );
			$output = ''; $errors = ''; $deadline = microtime( true ) + 10; $exit = -1; $status = [ 'running' => true ]; $transport = 'completed';
			do {
				$output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); $status = proc_get_status( $process );
				if ( ! $status['running'] ) { $exit = $status['exitcode']; break; }
				if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { $transport = 'output_limit'; break; }
				usleep( 10000 );
			} while ( microtime( true ) < $deadline );
			if ( $status['running'] ) { $transport = 'completed' === $transport ? 'timeout' : $transport; proc_terminate( $process, 9 ); }
			$output .= stream_get_contents( $pipes[1] ); $errors .= stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $pipes = [];
			$closed = proc_close( $process ); $process = null; $decoded = json_decode( $output, true ); $exit = $exit >= 0 ? $exit : $closed;
			if ( strlen( $output ) > 4096 || strlen( $errors ) > 16384 ) { $transport = 'output_limit'; }
			elseif ( 'completed' === $transport && 0 !== $exit ) { $transport = 'nonzero_exit'; }
			elseif ( 'completed' === $transport && ! is_array( $decoded ) ) { $transport = 'invalid_json'; }
			$phases = [ 'configuration', 'wp_load', 'fixture_authority', 'installed_autoload', 'reconstruct_original', 'reconcile', 'current_read', 'comparison', 'retirement', 'complete' ];
			$classes = [ 'RuntimeException', 'Error', 'TypeError', 'JsonException', 'InvalidArgumentException', 'LogicException', 'OperationStorageException', 'other' ];
			$safe = [ 'status' => 'completed' === $transport && 'PASS' === ( $decoded['status'] ?? null ) ? 'PASS' : 'FAIL', 'phase' => in_array( $decoded['phase'] ?? null, $phases, true ) ? $decoded['phase'] : 'unreported', 'error_class' => in_array( $decoded['error_class'] ?? null, $classes, true ) ? $decoded['error_class'] : null, 'process_id' => is_int( $decoded['process_id'] ?? null ) && $decoded['process_id'] > 0 ? $decoded['process_id'] : null, 'transport_code' => $transport, 'exit_code' => $exit >= -1 && $exit <= 255 ? $exit : -1, 'signal' => is_int( $status['termsig'] ?? null ) && $status['termsig'] >= 0 && $status['termsig'] <= 64 ? $status['termsig'] : 0, 'stderr_present' => '' !== $errors ];
			foreach ( [ 'wp_load', 'default_object_cache', 'installed_candidate_autoload', 'schema9', 'exact_completion', 'replayed', 'exact_row', 'unchanged_history', 'capture_not_called', 'no_capture_capability', 'all_owners_retired' ] as $key ) { $safe[$key] = is_bool( $decoded[$key] ?? null ) ? $decoded[$key] : null; }
			return $safe;
		} finally {
			foreach ( $pipes as $pipe ) { if ( is_resource( $pipe ) ) { fclose( $pipe ); } }
			if ( is_resource( $process ) ) { proc_terminate( $process, 9 ); proc_close( $process ); }
			unlink( $path );
		}
	}
	public function cleanup(): bool {
		$clean = $this->factory->close_all();
		try { if ( $this->selected instanceof wpdb ) { remove_filter( 'query', [ $this->selected, 'remove_placeholder_escape' ], 0 ); $clean = $this->selected->close() && $clean; $clean = false === has_filter( 'query', [ $this->selected, 'remove_placeholder_escape' ] ) && $clean; } } catch ( Throwable ) { $clean = false; }
		foreach ( array_reverse( $this->tables ) as $table ) { try { $this->execute( "DROP TABLE IF EXISTS `{$table}`" ); } catch ( Throwable ) { $clean = false; } }
		try { $clean = 0 === $this->table_count() && $clean; $this->physical->close(); } catch ( Throwable ) { $clean = false; }
		return $clean;
	}
}
