<?php

declare(strict_types=1);

require_once __DIR__ . '/opening-quote-provider-support.php';

use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteService;
use CetechDeliveryEngine\Application\DeliveryQuote\CartQuoteEnvironment;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartDraft;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartCurrentEvidence;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuotePreparedCapture;
use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment;
use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteSessionStore;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeOwnerResolver;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePreparationGate;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory as FactoryContract;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;

/** Disposable actual-main-prefix transport. Faults happen after real SQL acknowledgment only. */
final class CetechQuoteCartTransport implements OperationConnectionTransport {
	private bool $quote_effect = false;
	private bool $budget_effect = false;
	public function __construct( private OperationConnectionTransport $native, private CetechQuoteCartFactory $factory, private string $prefix ) {}
	public function execute( string $sql ): OperationConnectionResult {
		if ( preg_match( '/\A\s*START\s+TRANSACTION\b/i', $sql ) ) { $this->quote_effect = false; $this->budget_effect = false; }
		$result = $this->native->execute( $sql );
		if ( $result->acknowledged && preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE)\s+`?' . preg_quote( $this->prefix . 'delivery_engine_delivery_quotes', '/' ) . '`?\b/i', $sql ) ) { $this->quote_effect = true; ++$this->factory->quote_writes; }
		if ( $result->acknowledged && preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE)\s+`?' . preg_quote( $this->prefix . 'delivery_engine_delivery_quote_budget_windows', '/' ) . '`?\b/i', $sql ) ) { $this->budget_effect = true; ++$this->factory->budget_writes; }
		if ( $result->acknowledged && preg_match( '/\A\s*SELECT\b/i', $sql ) && preg_match( '/\bFROM\s+`?' . preg_quote( $this->prefix, '/' ) . '(?:posts|postmeta|delivery_engine_(?:rate_cards|product_delivery_rules|destination_zones|destination_rules|configuration_scopes))`?\b/i', $sql ) ) { $this->factory->timeline[] = 'owned_source_read'; ++$this->factory->source_reads; }
		if ( $result->acknowledged && preg_match( '/\A\s*COMMIT\b/i', $sql ) ) {
			if ( $this->budget_effect ) { $this->factory->timeline[] = 'budget_commit'; }
			if ( $this->quote_effect ) {
				++$this->factory->sent_quote_commits;
				if ( $this->factory->mask_next_quote_ack ) { $this->factory->mask_next_quote_ack = false; ++$this->factory->masked_quote_acks; $this->factory->fault_connection = $this->native->connection_id(); return new OperationConnectionResult( false, true, errno: 2013 ); }
			}
		}
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { return $this->native->close(); }
}

final class CetechQuoteCartFactory implements FactoryContract {
	private array $sessions = [];
	public array $timeline = [];
	public int $source_reads = 0;
	public int $quote_writes = 0;
	public int $budget_writes = 0;
	public int $sent_quote_commits = 0;
	public int $masked_quote_acks = 0;
	public bool $mask_next_quote_ack = false;
	public ?int $fault_connection = null;
	public ?QuoteTime $clock = null;
	public int $verified_clocks = 0;
	public function __construct( private wpdb $db ) {
		if ( '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) ) { throw new RuntimeException( 'Quote cart fixture refused its database.' ); }
	}
	public function open(): OperationSession {
		$host = $this->db->parse_db_host( DB_HOST ); if ( ! is_array( $host ) ) { throw new RuntimeException( 'Quote cart fixture connection unavailable.' ); }
		$transport = OperationConnectionMysqliTransport::connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2], $this->db->charset ?: 'utf8mb4', defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 );
		if ( null !== $this->clock ) {
			$epoch = $this->clock->epoch_microseconds(); $timestamp = intdiv( $epoch, 1000000 ) . '.' . str_pad( (string) ( $epoch % 1000000 ), 6, '0', STR_PAD_LEFT );
			$set = $transport->execute( 'SET timestamp=' . $timestamp ); $read = $transport->execute( 'SELECT UTC_TIMESTAMP(6) AS utc' );
			if ( ! $set->acknowledged || ! $read->acknowledged || [ [ 'utc' => $this->clock->sql() ] ] !== $read->rows ) { $transport->close(); throw new RuntimeException( 'Quote cart fixture SQL clock unavailable.' ); } ++$this->verified_clocks;
		}
		$session = new OperationConnection( get_current_blog_id(), $this->db->prefix, new CetechQuoteCartTransport( $transport, $this, $this->db->prefix ), $this->db->charset ?: 'utf8mb4', $this->db->collate ?: '' ); $this->sessions[$transport->connection_id()] = $session; return $session;
	}
	public function close_all(): bool { $ok = true; foreach ( $this->sessions as $session ) { if ( $session->in_transaction() ) { $ok = $session->rollback() && $ok; } $ok = $session->retire() && $ok; } return $ok; }
	public function all_retired(): bool { foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() ) { return false; } } return true; }
	public function fault_retired(): bool { return null !== $this->fault_connection && isset( $this->sessions[$this->fault_connection] ) && $this->sessions[$this->fault_connection]->is_retired(); }
}

/** Observe a genuine native preparation without replacing its draft, provider or facts. */
final class CetechQuoteCartEnvironmentObservation implements CartQuoteEnvironment {
	private const FOLLOWUP_STAGES = [ 'input_ready', 'environment_same_draft', 'environment_matches_original', 'environment_authorized', 'control_observed', 'cached_shipping_restored', 'preparation_matches_original', 'source_captured', 'source_bound', 'packages_restored', 'native_captured', 'native_bound', 'context_digest_matches', 'source_applicable', 'native_unchanged', 'source_local_unchanged', 'final_same_draft', 'final_authorized', 'control_confirmed' ];
	public array $diagnostics = [ 'prepare_entered' => false, 'prepare_returned' => false, 'prepare_error_class' => null, 'prepare_refusal_site' => null, 'prepare_refusal_line' => null, 'evidence_called' => false, 'evidence_returned' => false, 'native_shipping_debug_enabled' => null, 'native_chosen_cache_present' => false, 'native_totals_cache_present' => false, 'native_shipping_cache_present' => false ];
	public ?array $source_registration_probe = null;
	private ?QuoteIssueCommand $evidence_original = null;
	private ?QuoteHeader $evidence_header = null;
	private ?QuoteCartDraft $evidence_draft = null;
	public function __construct( private NativeCartQuoteEnvironment $native ) {}
	public function draft(): ?QuoteCartDraft { return $this->native->draft(); }
	public function authorize( QuoteOwner $owner, string $operation ): bool { return $this->native->authorize( $owner, $operation ); }
	public function prepare( QuoteCartDraft $draft ): LegacyQuotePreparedCapture {
		$this->diagnostics['prepare_entered'] = true; $this->cache_presence();
		try { $prepared = $this->native->prepare( $draft ); $this->diagnostics['prepare_returned'] = true; return $prepared; }
		catch ( Throwable $error ) { $this->diagnostics['prepare_error_class'] = self::safe_error_class( $error ); [ $site, $line ] = self::verified_refusal( $error ); $this->diagnostics['prepare_refusal_site'] = $site; $this->diagnostics['prepare_refusal_line'] = $line; if ( 'source_local_binding' === $site ) { $this->source_registration_probe = self::source_registration_counterfactual(); } throw $error; }
	}
	public function evidence( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence {
		$this->evidence_original = $original; $this->evidence_header = $header; $this->evidence_draft = $draft;
		$this->diagnostics['evidence_called'] = true; $this->cache_presence(); $result = $this->native->evidence( $original, $header, $draft ); $this->diagnostics['evidence_returned'] = null !== $result; return $result;
	}
	public function has_evidence_input(): bool { return null !== $this->evidence_original && null !== $this->evidence_header && null !== $this->evidence_draft; }
	/** Failure-only follow-up on the same objects. Never admitted cart preparation, reprice, issue or save. */
	public function readonly_followup(): array {
		$stages = self::FOLLOWUP_STAGES;
		$report = [ 'observation' => 'followup_readonly_not_original_timing', 'failed_stage' => null, 'error_class' => null, 'refusal_site' => null, 'refusal_line' => null, ...array_fill_keys( $stages, null ) ];
		$stage = 'input_ready'; $known_false = false;
		$step = static function ( string $name, callable $read ) use ( &$report, &$stage, &$known_false ): void {
			$stage = $name; $value = $read(); $report[$name] = true === $value;
			if ( true !== $value ) { $known_false = true; throw new LogicException( 'The read-only follow-up guard refused.' ); }
		};
		try {
			$original = $this->evidence_original; $header = $this->evidence_header; $draft = $this->evidence_draft;
			$step( 'input_ready', static fn (): bool => null !== $original && null !== $header && null !== $draft );
			$preparation = ( new ReflectionProperty( NativeCartQuoteEnvironment::class, 'preparation' ) )->getValue( $this->native );
			$access = ( new ReflectionProperty( NativeCartQuoteEnvironment::class, 'current_access' ) )->getValue( $this->native );
			$same_draft = new ReflectionMethod( NativeCartQuoteEnvironment::class, 'same_draft' );
			$owner = $draft->owner(); $context = $original->context();
			$step( 'environment_same_draft', fn (): bool => $same_draft->invoke( $this->native, $draft ) );
			$step( 'environment_matches_original', static fn (): bool => $preparation->matches_original( $original, $header, $draft ) );
			$step( 'environment_authorized', fn (): bool => $this->native->authorize( $owner, 'delivery_quote.read' ) );
			$revision = null;
			$step( 'control_observed', static function () use ( $access, $owner, &$revision ): bool { $revision = $access->observe( $owner ); return null !== $revision; } );
			$step( 'cached_shipping_restored', static function (): bool { CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping::restore_cached_calculation(); return true; } );
			$step( 'preparation_matches_original', static fn (): bool => $preparation->matches_original( $original, $header, $draft ) );
			$sources = ( new ReflectionProperty( CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::class, 'sources' ) )->getValue( $preparation ); $source = null;
			$step( 'source_captured', static function () use ( $sources, $owner, $context, &$source ): bool { $source = $sources->prepare( $owner, $context ); return $source instanceof CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot; } );
			$source_context = null; $current_source = new ReflectionMethod( CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::class, 'current_source' );
			$step( 'source_bound', static function () use ( &$source, $context, &$source_context, $current_source ): bool { $source = $current_source->invoke( null, $source, $context ); $source_context = $source->context(); return $source instanceof CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot; } );
			$packages = null; $package_facts = new ReflectionMethod( CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::class, 'package_facts' );
			$step( 'packages_restored', static function () use ( $package_facts, $draft, &$packages ): bool { $packages = $package_facts->invoke( null, WC()->shipping()->get_packages(), $draft ); return is_array( $packages ) && [] !== $packages; } );
			$native = null; $native_receipt = new ReflectionMethod( CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::class, 'native_receipt' );
			$step( 'native_captured', static function () use ( $native_receipt, $preparation, $owner, $packages, &$native ): bool { $native = $native_receipt->invoke( $preparation, $owner, $packages ); return $native instanceof CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeReceipt; } );
			$current = null;
			$step( 'native_bound', static function () use ( $native, $source_context, &$current ): bool { $current = $native->bind_context( $source_context ); return $current instanceof CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext; } );
			$step( 'context_digest_matches', static fn (): bool => hash_equals( $source_context->digest(), $current->digest() ) );
			$step( 'source_applicable', static fn (): bool => $source->applicable_at( QuoteTime::now() ) );
			$step( 'native_unchanged', static fn (): bool => $native->unchanged() );
			$step( 'source_local_unchanged', static fn (): bool => $source->local_state_unchanged() );
			$step( 'final_same_draft', fn (): bool => $same_draft->invoke( $this->native, $draft ) );
			$step( 'final_authorized', fn (): bool => $this->native->authorize( $owner, 'delivery_quote.read' ) );
			$step( 'control_confirmed', static fn (): bool => $access->confirm( $owner, $revision ) );
		} catch ( Throwable $error ) {
			$report['failed_stage'] = $stage;
			if ( ! $known_false ) { $report['error_class'] = self::safe_error_class( $error ); [ $report['refusal_site'], $report['refusal_line'] ] = self::verified_refusal( $error ); }
		}
		return $report;
	}
	public static function valid_evidence_followup( mixed $value ): bool {
		$keys = [ 'observation', 'failed_stage', 'error_class', 'refusal_site', 'refusal_line', ...self::FOLLOWUP_STAGES, 'source_read_delta', 'quote_write_delta', 'budget_write_delta' ];
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) || [] !== array_diff( $keys, array_keys( $value ) ) || 'followup_readonly_not_original_timing' !== $value['observation'] || ! in_array( $value['failed_stage'], [ null, ...self::FOLLOWUP_STAGES ], true ) || ! in_array( $value['error_class'], [ null, 'RuntimeException', 'InvalidArgumentException', 'Error' ], true ) || ! in_array( $value['refusal_site'], [ null, 'native_environment', 'native_shipping', 'native_preparation', 'legacy_source', 'source_local_binding', 'native_context', 'native_receipt', 'source_snapshot' ], true ) ) { return false; }
		if ( null === $value['refusal_site'] ? null !== $value['refusal_line'] : ( null === $value['error_class'] || ! is_int( $value['refusal_line'] ) || $value['refusal_line'] < 1 || $value['refusal_line'] > 100000 ) ) { return false; }
		foreach ( self::FOLLOWUP_STAGES as $key ) { if ( null !== $value[$key] && ! is_bool( $value[$key] ) ) { return false; } }
		foreach ( [ 'source_read_delta', 'quote_write_delta', 'budget_write_delta' ] as $key ) { if ( ! is_int( $value[$key] ) || $value[$key] < 0 || $value[$key] > 1000000 ) { return false; } }
		return true;
	}
	private function cache_presence(): void {
		try {
			$raw = static function ( object $object, string $name ): mixed { $property = new ReflectionProperty( $object, $name ); if ( $property->isStatic() || ! $property->isInitialized( $object ) ) { throw new LogicException( 'Raw native cache presence is unavailable.' ); } return method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $object ) : $property->getValue( $object ); };
			$wc = $GLOBALS['woocommerce'] ?? null; if ( ! is_object( $wc ) ) { return; } $session = $raw( $wc, 'session' ); if ( ! is_object( $session ) ) { return; } $data = $raw( $session, '_data' );
			if ( is_array( $data ) ) { $this->diagnostics['native_chosen_cache_present'] = isset( $data['chosen_shipping_methods'] ); $this->diagnostics['native_totals_cache_present'] = isset( $data['cart_totals'] ); $this->diagnostics['native_shipping_cache_present'] = isset( $data['shipping_for_package_0'] ); }
			$cache = $GLOBALS['wp_object_cache'] ?? null; if ( ! is_object( $cache ) || 'WP_Object_Cache' !== get_class( $cache ) ) { return; } $data = $raw( $cache, 'cache' ); $prefix = $raw( $cache, 'blog_prefix' ); $multisite = $raw( $cache, 'multisite' ); $groups = $raw( $cache, 'global_groups' );
			if ( ! is_array( $data ) || ! is_string( $prefix ) || ! is_bool( $multisite ) || ! is_array( $groups ) ) { return; } $key = $multisite && ! isset( $groups['options'] ) ? $prefix : ''; $options = $data['options'] ?? []; $all = $options[$key . 'alloptions'] ?? []; $value = is_array( $all ) && array_key_exists( 'woocommerce_shipping_debug_mode', $all ) ? $all['woocommerce_shipping_debug_mode'] : ( $options[$key . 'woocommerce_shipping_debug_mode'] ?? null ); if ( is_string( $value ) ) { $this->diagnostics['native_shipping_debug_enabled'] = 'yes' === $value; }
		} catch ( Throwable ) { /* Presence remains unknown; no fallback native getter or query. */ }
	}
	public static function safe_error_class( Throwable $error ): string { return $error instanceof Error ? 'Error' : ( $error instanceof InvalidArgumentException ? 'InvalidArgumentException' : 'RuntimeException' ); }
	/** Pure registration counterfactual only; never retries the original preparation. */
	public static function source_registration_counterfactual(): ?array {
		$report = [ 'observation' => 'pure_registration_counterfactual_original_attempt_not_retried', 'native_query_singleton_present' => false, 'native_query_tuple_count' => 0, 'pre_get_posts_callback_count' => 0, 'capture_without_exact_tuple' => null, 'original_hook_restored' => true ];
		try {
			$wc = $GLOBALS['woocommerce'] ?? null;
			if ( ! is_object( $wc ) || 'WooCommerce' !== get_class( $wc ) ) { return $report; }
			$query_property = new ReflectionProperty( $wc, 'query' );
			if ( $query_property->isStatic() || ! $query_property->isInitialized( $wc ) ) { return $report; }
			$query = method_exists( $query_property, 'getRawValue' ) ? $query_property->getRawValue( $wc ) : $query_property->getValue( $wc );
			if ( ! is_object( $query ) || 'WC_Query' !== get_class( $query ) ) { return $report; }
			$report['native_query_singleton_present'] = true;
			$filters = $GLOBALS['wp_filter'] ?? [];
			if ( ! is_array( $filters ) ) { return null; }
			$entry = $filters['pre_get_posts'] ?? null;
			if ( null === $entry ) { return $report; }
			if ( ! is_object( $entry ) || 'WP_Hook' !== get_class( $entry ) ) { return null; }
			$property = new ReflectionProperty( $entry, 'callbacks' );
			if ( $property->isStatic() || ! $property->isInitialized( $entry ) || 'WP_Hook' !== $property->getDeclaringClass()->getName() ) { return null; }
			$callbacks = method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $entry ) : $property->getValue( $entry );
			if ( ! is_array( $callbacks ) || count( $callbacks ) > 256 ) { return null; }
			$matched_key = null;
			foreach ( $callbacks as $priority => $items ) {
				if ( ! is_int( $priority ) || ! is_array( $items ) || count( $items ) > 256 - $report['pre_get_posts_callback_count'] ) { return null; }
				$report['pre_get_posts_callback_count'] += count( $items );
				foreach ( $items as $key => $item ) {
					if ( ! is_array( $item ) ) { return null; }
					$fn = $item['function'] ?? null;
					if ( 10 === $priority && 1 === ( $item['accepted_args'] ?? null ) && is_array( $fn ) && array_is_list( $fn ) && 2 === count( $fn ) && $fn[0] === $query && 'pre_get_posts' === $fn[1] ) { ++$report['native_query_tuple_count']; $matched_key = $key; }
				}
			}
			if ( 1 !== $report['native_query_tuple_count'] ) { return $report; }
			$without = $callbacks; unset( $without[10][$matched_key] ); if ( [] === $without[10] ) { unset( $without[10] ); }
			try {
				$property->setValue( $entry, $without );
				try { CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceLocalBinding::capture(); $report['capture_without_exact_tuple'] = true; }
				catch ( Throwable ) { $report['capture_without_exact_tuple'] = false; }
			} finally {
				$property->setValue( $entry, $callbacks );
				$restored = method_exists( $property, 'getRawValue' ) ? $property->getRawValue( $entry ) : $property->getValue( $entry );
				$report['original_hook_restored'] = $callbacks === $restored;
			}
			return $report;
		} catch ( Throwable ) { return null; }
	}
	public static function valid_source_registration_probe( mixed $value ): bool {
		$keys = [ 'observation', 'native_query_singleton_present', 'native_query_tuple_count', 'pre_get_posts_callback_count', 'capture_without_exact_tuple', 'original_hook_restored' ];
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) || [] !== array_diff( $keys, array_keys( $value ) ) || 'pure_registration_counterfactual_original_attempt_not_retried' !== $value['observation'] || ! is_bool( $value['native_query_singleton_present'] ) || ! is_bool( $value['original_hook_restored'] ) || ( null !== $value['capture_without_exact_tuple'] && ! is_bool( $value['capture_without_exact_tuple'] ) ) ) { return false; }
		foreach ( [ 'native_query_tuple_count', 'pre_get_posts_callback_count' ] as $key ) { if ( ! is_int( $value[$key] ) || $value[$key] < 0 || $value[$key] > 256 ) { return false; } }
		return $value['native_query_tuple_count'] <= $value['pre_get_posts_callback_count'] && ( $value['native_query_singleton_present'] || 0 === $value['native_query_tuple_count'] ) && ( 1 === $value['native_query_tuple_count'] || null === $value['capture_without_exact_tuple'] );
	}
	/** A finite installed class code and integer source line; paths, messages and traces remain private. */
	public static function verified_refusal( Throwable $error ): array {
		$known = [
			CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment::class => [ 'native_environment', 'fail' ],
			CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping::class => [ 'native_shipping', 'fail' ],
			CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation::class => [ 'native_preparation', 'fail' ],
			CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteNativeSourcePreparer::class => [ 'legacy_source', 'fail' ],
			CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceLocalBinding::class => [ 'source_local_binding', 'unavailable' ],
			CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeWooSource::class => [ 'native_context', 'refuse' ],
		]; $best = [ null, null ];
		for ( $depth = 0; $depth < 4 && null !== $error; ++$depth, $error = $error->getPrevious() ) {
			foreach ( array_slice( $error->getTrace(), 0, 16 ) as $frame ) {
				$class = $frame['class'] ?? null;
				if ( CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape::class === $class && 'invalid' === ( $frame['function'] ?? null ) ) {
					foreach ( [ CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeReceipt::class => 'native_receipt', CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot::class => 'source_snapshot' ] as $caller => $code ) {
						$method = new ReflectionMethod( $caller, 'bind_context' );
						if ( $method->getFileName() === ( $frame['file'] ?? null ) && is_int( $frame['line'] ?? null ) && $frame['line'] >= $method->getStartLine() && $frame['line'] <= $method->getEndLine() ) { $best = [ $code, $frame['line'] ]; break; }
					}
				}
				if ( ! is_string( $class ) || ! isset( $known[$class] ) || ( $frame['function'] ?? null ) !== $known[$class][1] ) { continue; }
				$reflection = new ReflectionClass( $class ); if ( $reflection->getFileName() === ( $frame['file'] ?? null ) && is_int( $frame['line'] ?? null ) && $frame['line'] >= $reflection->getStartLine() && $frame['line'] <= $reflection->getEndLine() ) { $best = [ $known[$class][0], $frame['line'] ]; break; }
			}
		}
		return $best;
	}
}

/** Real retained cart fixtures are setup only; quote preparation remains inside the early gate. */
final class CetechQuoteCartFixture {
	public CetechNativeQuoteProviderFixture $native;
	public CetechQuoteCartFactory $factory;
	public NativeCartQuoteSessionStore $sessions;
	public NativeCartQuoteEnvironment $environment;
	public CetechQuoteCartEnvironmentObservation $observed_environment;
	public CartQuoteService $service;
	private array $auxiliary_keys = [];
	private array $initial_history = [];
	public function __construct( private wpdb $db ) {
		$this->native = new CetechNativeQuoteProviderFixture( $db ); $this->factory = new CetechQuoteCartFactory( $db );
	}
	public function prepare(): void {
		$this->native->install(); $this->native->set_option( 'woocommerce_shipping_debug_mode', 'no' ); $this->native->recalculate(); $this->initial_history = $this->native->history();
		$this->environment = new NativeCartQuoteEnvironment( $this->factory );
		$this->observed_environment = new CetechQuoteCartEnvironmentObservation( $this->environment );
		$this->sessions = new NativeCartQuoteSessionStore( $this->factory, [ $this->environment, 'authorize' ] );
		$this->service = new CartQuoteService( $this->observed_environment, new QuotePreparationGate( $this->factory, [ $this->environment, 'authorize' ] ), $this->sessions, $this->factory );
		$this->track_session();
	}
	public function owner(): QuoteOwner { return ( new QuoteNativeOwnerResolver() )->current(); }
	public function track_session(): void { $this->auxiliary_keys[] = $this->sessions->key_for( $this->owner() ); }
	public function history(): array { return $this->native->history(); }
	public function choice_digest(): string {
		$items = []; foreach ( WC()->cart->get_cart() as $key => $item ) { $items[$key] = [ 'quantity' => $item['quantity'], 'selection' => $item[CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture::CART_SELECTION_KEY], 'context' => $item[CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::CART_KEY] ]; } ksort( $items, SORT_STRING );
		return hash( 'sha256', json_encode( $items, JSON_THROW_ON_ERROR ) );
	}
	public function row( string $uuid ): array { $row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( 'delivery_quotes' ) . '` WHERE site_id=%d AND quote_uuid=%s', get_current_blog_id(), $uuid ), ARRAY_A ); if ( ! is_array( $row ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'Quote cart physical row unavailable.' ); } return $row; }
	/** Exactly one original C03 publication marker may finish after a committed lost ACK. */
	public static function accept_retry_delta( array $before, array $after, CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand $original ): array {
		$out = [ 'no_second_quote_mutation_or_audit' => false, 'one_original_accept_publication_marker' => false, 'all_other_history_unchanged' => false ];
		try {
			$stores = [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ];
			if ( count( $before ) !== count( $stores ) || count( $after ) !== count( $stores ) || [] !== array_diff( $stores, array_keys( $before ) ) || [] !== array_diff( $stores, array_keys( $after ) ) || 'delivery_quote.accept' !== $original->identity->operation ) { return $out; }
			$effect_equal = true; foreach ( array_slice( $stores, 1 ) as $store ) { $effect_equal = $effect_equal && is_array( $before[$store] ) && $before[$store] === $after[$store]; } $out['no_second_quote_mutation_or_audit'] = $effect_equal;
			$find = static function ( array $rows ) use ( $original ): array {
				$matches = []; foreach ( $rows as $index => $row ) { if ( is_array( $row ) && ( $row['namespace_hash'] ?? null ) === $original->identity->namespace_digest() && CetechDeliveryEngine\Domain\Operation\OperationRecord::positive_integer( $row['site_id'] ?? null ) === $original->identity->site_id ) { $matches[] = [ $index, $row ]; } } return $matches;
			};
			$old = $find( $before['operation_records'] ); $new = $find( $after['operation_records'] ); if ( 1 !== count( $old ) || 1 !== count( $new ) ) { return $out; }
			[ $old_index, $old_row ] = $old[0]; [ $new_index, $new_row ] = $new[0];
			$event_matches = []; foreach ( $before['operation_changes'] as $event ) { if ( CetechDeliveryEngine\Domain\Operation\OperationRecord::positive_integer( $event['id'] ?? null ) === CetechDeliveryEngine\Domain\Operation\OperationRecord::positive_integer( $old_row['audit_id'] ?? null ) ) { $event_matches[] = $event; } } if ( 1 !== count( $event_matches ) ) { return $out; }
			$profile = CetechDeliveryEngine\Application\DeliveryQuote\QuoteOperationProfile::registry()->get( 'delivery_quote.accept', 1 );
			$old_record = CetechDeliveryEngine\Domain\Operation\OperationRecord::from_row( $old_row, $profile, $event_matches[0] ); $new_record = CetechDeliveryEngine\Domain\Operation\OperationRecord::from_row( $new_row, $profile, $event_matches[0] );
			if ( ! $old_record->matches( $original->identity, $original->intent() ) || ! $new_record->matches( $original->identity, $original->intent() ) || $old_record->id !== $new_record->id ) { return $out; }
			$immutable = $new_row; foreach ( [ 'publication_state', 'row_version', 'updated_at' ] as $field ) { $immutable[$field] = $old_row[$field]; }
			$out['one_original_accept_publication_marker'] = 'accepted' === $old_record->state && 'accepted' === $new_record->state && 'pending' === $old_record->publication_state && 'published' === $new_record->publication_state && $old_record->row_version < PHP_INT_MAX && $new_record->row_version === $old_record->row_version + 1 && $new_record->updated_at >= $old_record->updated_at && $immutable === $old_row;
			$normalized = $after; $normalized['operation_records'][$new_index] = $old_row;
			$out['all_other_history_unchanged'] = $old_index === $new_index && $before === $normalized;
		} catch ( Throwable ) { /* Corrupt or unrelated facts never satisfy the exact permitted delta. */ }
		return $out;
	}
	public static function public_safe( array $facts ): bool {
		$json = json_encode( $facts, JSON_THROW_ON_ERROR );
		foreach ( [ 'acceptance_handle', 'owner_digest', 'session_hash', 'principal_hash', 'body_digest', 'material_digest', 'cost_provider', 'origin_id', 'supplier', 'rate_card', 'PRIVATE-', 'issue_context_json', 'review_token' ] as $private ) { if ( str_contains( $json, $private ) ) { return false; } }
		return array_keys( $facts ) === [ 'contract_version', 'status', 'generation', 'quote', 'can_refresh', 'can_confirm', 'can_retry', 'message_code', 'correlation_id' ];
	}
	public function cleanup(): array {
		$this->factory->clock = null;
		$ok = $this->factory->close_all();
		if ( [] === $this->initial_history ) { $cleanup = $this->native->cleanup(); $cleanup['cleanup_restored'] = $cleanup['cleanup_restored'] && $ok; return $cleanup; }
		$envelope = isset( $this->sessions ) ? $this->sessions->load( $this->owner() ) : null;
		if ( null !== $envelope ) { $namespaces = ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'owned_namespaces' ) )->getValue( $this->native ); $namespaces[] = $envelope->preparation()->identity()->namespace_digest(); if ( null !== $envelope->header() ) { $namespaces = [ ...$namespaces, ...array_values( $envelope->header()->namespace_hashes() ) ]; } ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'owned_namespaces' ) )->setValue( $this->native, $namespaces ); }
		$old_budget_ids = array_column( $this->initial_history['delivery_quote_budget_windows'] ?? [], 'id' ); $namespaces = ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'owned_namespaces' ) )->getValue( $this->native );
		foreach ( $this->history()['delivery_quote_budget_windows'] as $row ) { if ( ! in_array( $row['id'], $old_budget_ids, true ) && 'admission' === $row['slot_kind'] && $row['principal_hash'] === $this->owner()->facts()['principal_hash'] ) { $namespaces[] = $row['admission_namespace_hash']; } }
		( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'owned_namespaces' ) )->setValue( $this->native, array_unique( $namespaces ) );
		$before_ids = array_column( $this->initial_history['delivery_quotes'] ?? [], 'id' );
		foreach ( $this->history()['delivery_quotes'] as $row ) { if ( ! in_array( $row['id'], $before_ids, true ) ) { $typed = QuoteStoredRow::from_row( $row ); if ( ! $typed->header()->owner()->equals( $this->owner() ) ) { throw new RuntimeException( 'Quote cart cleanup refuses a foreign quote.' ); } $this->native->track( (object) [ 'command' => null, 'quote' => $typed ] ); } }
		foreach ( array_unique( $this->auxiliary_keys ) as $key ) { $ok = false !== $this->db->delete( $this->db->prefix . 'woocommerce_sessions', [ 'session_key' => $key ] ) && $ok; }
		$ok = $this->factory->close_all() && $ok; $cleanup = $this->native->cleanup();
		$cleanup['cleanup_restored'] = $cleanup['cleanup_restored'] && $ok && $this->factory->all_retired(); $cleanup['all_owned_connections_retired'] = $cleanup['all_owned_connections_retired'] && $this->factory->all_retired();
		return $cleanup;
	}
}

/** Private cross-request fixture bookkeeping; never a product or quote transport. */
final class CetechQuoteCartHttpFixture {
	private const SCALARS = [ 'suffix', 'tax_class', 'offer', 'zone', 'rate', 'shipping_instance', 'alternate_supplier', 'alternate_profile', 'alternate_origin', 'products', 'rules', 'tax_rates', 'extra_products', 'variation_id', 'variation_parent', 'alternate_parent' ];
	private const PRIVATE_FACTS = [ 'entities', 'original_options', 'domain_before', 'native_before', 'term_before', 'owned_namespaces', 'owned_quotes', 'session_id', 'installed', 'tax_class_created' ];
	public static function export_native( CetechNativeQuoteProviderFixture $fixture ): array {
		$facts = []; foreach ( [ ...self::SCALARS, ...self::PRIVATE_FACTS ] as $name ) { $property = new ReflectionProperty( CetechNativeQuoteProviderFixture::class, $name ); $facts[$name] = $property->getValue( $fixture ); }
		// The exact scalar/row whitelist is serializable. Native WC objects, handles,
		// closures and hook registrations belong to their request and are never saved.
		json_encode( $facts, JSON_THROW_ON_ERROR ); return $facts;
	}
	public static function hydrate_native( wpdb $db, array $facts ): CetechNativeQuoteProviderFixture {
		if ( array_keys( $facts ) !== [ ...self::SCALARS, ...self::PRIVATE_FACTS ] ) { throw new RuntimeException( 'Q05 private fixture facts are invalid.' ); }
		$fixture = new CetechNativeQuoteProviderFixture( $db );
		foreach ( $facts as $name => $value ) { ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, $name ) )->setValue( $fixture, $value ); }
		$objects = []; foreach ( [ 'session', 'cart', 'customer' ] as $name ) { $objects[$name] = WC()->$name ?? null; } $objects['shipping'] = WC()->shipping();
		( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'wc_before' ) )->setValue( $fixture, $objects );
		$globals = []; foreach ( [ 'current_user', 'user_ID' ] as $name ) { $globals[$name] = [ array_key_exists( $name, $GLOBALS ), $GLOBALS[$name] ?? null ]; } ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'globals_before' ) )->setValue( $fixture, $globals );
		$hooks = []; foreach ( $GLOBALS['wp_filter'] as $name => $callbacks ) { $hooks[$name] = is_object( $callbacks ) ? clone $callbacks : $callbacks; } ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'hooks_before' ) )->setValue( $fixture, $hooks );
		return $fixture;
	}
	public static function service( wpdb $db ): array {
		$factory = new CetechQuoteCartFactory( $db ); $environment = new NativeCartQuoteEnvironment( $factory ); $sessions = new NativeCartQuoteSessionStore( $factory, [ $environment, 'authorize' ] );
		$observed = new CetechQuoteCartEnvironmentObservation( $environment );
		return [ new CartQuoteService( $observed, new QuotePreparationGate( $factory, [ $environment, 'authorize' ] ), $sessions, $factory ), $sessions, $factory, $observed ];
	}
	/** Copy original failure facts before any separately labelled read-only follow-up. */
	public static function failure_observation( CetechQuoteCartEnvironmentObservation $observed, CetechQuoteCartFactory $factory ): ?array {
		$facts = $observed->diagnostics;
		$booleans = [ 'prepare_entered', 'prepare_returned', 'evidence_called', 'evidence_returned', 'native_chosen_cache_present', 'native_totals_cache_present', 'native_shipping_cache_present' ];
		$keys = [ 'prepare_entered', 'prepare_returned', 'prepare_error_class', 'prepare_refusal_site', 'prepare_refusal_line', 'evidence_called', 'evidence_returned', 'native_shipping_debug_enabled', 'native_chosen_cache_present', 'native_totals_cache_present', 'native_shipping_cache_present' ];
		if ( count( $facts ) !== count( $keys ) || [] !== array_diff( $keys, array_keys( $facts ) ) ) { return null; }
		foreach ( $booleans as $key ) { if ( ! is_bool( $facts[$key] ) ) { return null; } }
		if ( null !== $facts['native_shipping_debug_enabled'] && ! is_bool( $facts['native_shipping_debug_enabled'] ) ) { return null; }
		if ( ! in_array( $facts['prepare_error_class'], [ null, 'RuntimeException', 'InvalidArgumentException', 'Error' ], true ) || ! in_array( $facts['prepare_refusal_site'], [ null, 'native_environment', 'native_shipping', 'native_preparation', 'legacy_source', 'source_local_binding', 'native_context', 'native_receipt', 'source_snapshot' ], true ) ) { return null; }
		if ( null === $facts['prepare_refusal_site'] ? null !== $facts['prepare_refusal_line'] : ( ! is_int( $facts['prepare_refusal_line'] ) || $facts['prepare_refusal_line'] < 1 || $facts['prepare_refusal_line'] > 100000 ) ) { return null; }
		if ( ! ( ( true === $facts['prepare_entered'] && false === $facts['prepare_returned'] ) || ( true === $facts['evidence_called'] && false === $facts['evidence_returned'] ) ) ) { return null; }
		$counts = [ 'source_reads' => $factory->source_reads, 'quote_writes' => $factory->quote_writes, 'budget_writes' => $factory->budget_writes ];
		foreach ( $counts as $value ) { if ( $value < 0 || $value > 1000000 ) { return null; } }
		$probe = $observed->source_registration_probe;
		if ( null !== $probe && ( 'source_local_binding' !== $facts['prepare_refusal_site'] || ! CetechQuoteCartEnvironmentObservation::valid_source_registration_probe( $probe ) ) ) { return null; }
		$failure = [ 'observation' => 'original_native_attempt', ...$facts, ...$counts, ...( null !== $probe ? [ 'source_registration_probe' => $probe ] : [] ) ];
		if ( true === $facts['evidence_called'] && false === $facts['evidence_returned'] && $observed->has_evidence_input() ) {
			$followup = [ ...$observed->readonly_followup(), 'source_read_delta' => $factory->source_reads - $counts['source_reads'], 'quote_write_delta' => $factory->quote_writes - $counts['quote_writes'], 'budget_write_delta' => $factory->budget_writes - $counts['budget_writes'] ];
			if ( CetechQuoteCartEnvironmentObservation::valid_evidence_followup( $followup ) ) { $failure['current_evidence_followup'] = $followup; }
		}
		return $failure;
	}
	public static function track_owner( array &$state, NativeCartQuoteSessionStore $sessions ): QuoteOwner {
		$owner = ( new QuoteNativeOwnerResolver() )->current(); $key = $sessions->key_for( $owner ); $session_id = WC()->session->get_customer_id();
		if ( ! is_string( $session_id ) || '' === $session_id || strlen( $session_id ) > 128 ) { throw new RuntimeException( 'Q05 native session tracking failed.' ); }
		$state['owners'][$owner->digest()] = [ 'owner' => $owner->facts(), 'auxiliary_key' => $key, 'native_session_key' => $session_id ]; return $owner;
	}
	public static function choice_digest(): string {
		$facts = []; foreach ( WC()->cart->get_cart() as $key => $item ) { $facts[$key] = [ 'quantity' => $item['quantity'], 'selection' => $item[CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture::CART_SELECTION_KEY] ?? null, 'context' => $item[CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::CART_KEY] ?? null ]; } ksort( $facts, SORT_STRING ); return hash( 'sha256', json_encode( $facts, JSON_THROW_ON_ERROR ) );
	}
	public static function counts( wpdb $db ): array {
		$rows = []; foreach ( [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $suffix ) { $rows[$suffix] = CetechNativeQuoteProviderFixture::rows( TableNames::for( $suffix ) ); }
		return [ 'records' => count( $rows['operation_records'] ), 'events' => count( $rows['operation_changes'] ), 'quotes' => count( $rows['delivery_quotes'] ), 'accepted' => count( array_filter( $rows['delivery_quotes'], static fn ( array $row ): bool => 'accepted' === $row['state'] ) ), 'bindings' => count( $rows['delivery_quote_bindings'] ), 'budget' => count( $rows['delivery_quote_budget_windows'] ) ];
	}
	public static function placement_counts( wpdb $db ): array {
		$count = $db->get_var( "SELECT COUNT(*) FROM `{$db->posts}` WHERE post_type IN ('shop_order','shop_order_placehold')" ); if ( ! is_string( $count ) || ! ctype_digit( $count ) || '' !== $db->last_error ) { throw new RuntimeException( 'Q05 order observation failed.' ); } $orders = (int) $count;
		$hpos = $db->prefix . 'wc_orders'; $found = $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $hpos ) ) ); if ( '' !== $db->last_error ) { throw new RuntimeException( 'Q05 order table observation failed.' ); } if ( $hpos === $found ) { $count = $db->get_var( "SELECT COUNT(*) FROM `{$hpos}`" ); if ( ! is_string( $count ) || ! ctype_digit( $count ) || '' !== $db->last_error ) { throw new RuntimeException( 'Q05 HPOS order observation failed.' ); } $orders += (int) $count; }
		return [ 'orders_count' => $orders, 'gateway_count' => (int) get_option( 'cetech_opening_c07_gateway_count', 0 ) ];
	}
	/** Delete only exact tracked owners/keys absent from the original physical snapshot. */
	public static function cleanup( wpdb $db, array $state ): array {
		$fixture = self::hydrate_native( $db, $state['native'] ); $before = $state['native']['domain_before']; $owners = [];
		foreach ( $state['owners'] as $facts ) { $owner = QuoteOwner::from_array( $facts['owner'] ); $owners[$owner->digest()] = $owner; }
		$namespaces = $state['native']['owned_namespaces']; $quotes = $state['native']['owned_quotes']; $old_quote_ids = array_column( $before['delivery_quotes'], 'id' );
		foreach ( CetechNativeQuoteProviderFixture::rows( TableNames::for( 'delivery_quotes' ) ) as $row ) { if ( in_array( $row['id'], $old_quote_ids, true ) ) { continue; } $typed = QuoteStoredRow::from_row( $row ); if ( ! isset( $owners[$typed->header()->owner()->digest()] ) ) { continue; } $quotes[] = $typed->header()->id()->value(); $namespaces = [ ...$namespaces, ...array_values( $typed->header()->namespace_hashes() ) ]; }
		$old_budget_ids = array_column( $before['delivery_quote_budget_windows'], 'id' ); $budget_table = TableNames::for( 'delivery_quote_budget_windows' );
		foreach ( CetechNativeQuoteProviderFixture::rows( $budget_table ) as $row ) {
			$owned = (int) $row['site_id'] === $state['site_id'] && $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::site_slot_key( $state['site_id'] );
			foreach ( $owners as $owner ) { $owned = $owned || ( (int) $row['site_id'] === $state['site_id'] && ( $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::session_slot_key( $owner ) || ( 'admission' === $row['slot_kind'] && $row['principal_hash'] === $owner->facts()['principal_hash'] ) ) ); }
			if ( $owned ) { if ( 'admission' === $row['slot_kind'] ) { $namespaces[] = $row['admission_namespace_hash']; } if ( ! in_array( $row['id'], $old_budget_ids, true ) && false === $db->delete( $budget_table, [ 'id' => $row['id'], 'site_id' => $state['site_id'] ] ) ) { throw new RuntimeException( 'Q05 budget cleanup failed.' ); } }
		}
		foreach ( $before['delivery_quote_budget_windows'] as $row ) { $owned = $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::site_slot_key( $state['site_id'] ); foreach ( $owners as $owner ) { $owned = $owned || $row['slot_key'] === CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot::session_slot_key( $owner ); } if ( $owned && false === $db->replace( $budget_table, $row ) ) { throw new RuntimeException( 'Q05 prior budget restoration failed.' ); } }
		( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'owned_namespaces' ) )->setValue( $fixture, array_values( array_unique( $namespaces ) ) ); ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'owned_quotes' ) )->setValue( $fixture, array_values( array_unique( $quotes ) ) );
		$native_table = $db->prefix . 'woocommerce_sessions'; $keys = [ $state['native']['session_id'] ]; foreach ( $state['owners'] as $facts ) { $keys[] = $facts['auxiliary_key']; $keys[] = $facts['native_session_key']; }
		foreach ( array_unique( array_filter( $keys, 'is_string' ) ) as $key ) { if ( false === $db->delete( $native_table, [ 'session_key' => $key ] ) ) { throw new RuntimeException( 'Q05 session cleanup failed.' ); } }
		foreach ( $state['native']['native_before']['woocommerce_sessions'] as $row ) { if ( in_array( $row['session_key'], $keys, true ) && false === $db->replace( $native_table, $row ) ) { throw new RuntimeException( 'Q05 prior session restoration failed.' ); } }
		foreach ( $state['page_ids'] as $id ) { wp_delete_post( $id, true ); }
		if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; } wp_delete_user( $state['user_id'] );
		$cleanup = $fixture->cleanup(); $cleanup['owned_review_users_pages_removed'] = false === get_userdata( $state['user_id'] ); foreach ( $state['page_ids'] as $id ) { $cleanup['owned_review_users_pages_removed'] = $cleanup['owned_review_users_pages_removed'] && null === get_post( $id ); } $cleanup['cleanup_restored'] = $cleanup['cleanup_restored'] && $cleanup['owned_review_users_pages_removed']; return $cleanup;
	}
}
