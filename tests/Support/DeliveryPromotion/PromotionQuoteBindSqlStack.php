<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteBindService, FixedPromotionQuoteOrderEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteJson, QuoteOwner, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory, QuoteLifecycleProofPublication};

require_once __DIR__ . '/PromotionQuoteAcceptSqlStack.php';

/** Physical C03/quote/binding/ledger owners. All native/source/eligibility/order ports are synthetic. */
final class PromotionQuoteBindSqlStack {

	public readonly QuoteLifecycleProofFactory $physical;
	public readonly PromotionQuoteIssueSqlFactory $factory;
	public readonly PromotionQuoteAcceptEligibilityPort $eligibility;
	public readonly PromotionQuoteAcceptPhaseObserver $observer;
	public readonly QuoteLifecycleProofPublication $publication;
	public readonly PromotionQuoteBindOrderPort $order;
	public readonly FixedPromotionQuoteBindService $service;
	public bool $allow = true;

	public function __construct( string $prefix, public readonly array $fixture, FixedPromotionQuoteBindCommand $command, int $clock = QuoteLifecycleProofFactory::NOW + 2, ?callable $configure = null, int $site_id = 1 ) {
		$this->physical = new QuoteLifecycleProofFactory( $prefix, $site_id ); $this->physical->clock = $clock;
		$this->physical->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->factory = new PromotionQuoteIssueSqlFactory( $this->physical );
		$this->eligibility = new PromotionQuoteAcceptEligibilityPort(); $this->observer = new PromotionQuoteAcceptPhaseObserver(); $this->publication = new QuoteLifecycleProofPublication();
		$this->order = new PromotionQuoteBindOrderPort( $prefix, PromotionQuoteBindOrderPort::packet( $command->quote_record(), $command->binding()->row()['order_id'], $command->binding()->mapping() ) );
		$this->service = new FixedPromotionQuoteBindService( $this->factory, new SqlFixedPromotionBudgetStore(), $this->eligibility,
			fn( QuoteOwner $owner, string $operation ): bool => $this->allow && 1 === $owner->site_id() && 'delivery_quote.bind' === $operation,
			observer: $this->observer, control: new EmergencyControlStore( static fn(): bool => false ), publication: $this->publication );
	}
	public function inputs(): FixedPromotionQuoteBindInputs { return FixedPromotionQuoteBindInputs::from_sources( FixedPromotionQuoteAcceptInputs::from_sources( $this->fixture['original'], $this->fixture['fresh'] ), $this->order ); }
	public function ledger_writes(): array {
		$out = [];
		foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) {
			if ( 1 === preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $sql ) && 1 === preg_match( '/delivery_engine_(?:delivery_quotes|promotion_budget_accounts|promotion_budget_reservations|delivery_quote_budget_windows)/', $sql ) ) { $out[] = $sql; }
		} }
		return $out;
	}
	public function binding_inserts(): int {
		$count = 0; foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) { if ( str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { ++$count; } } } return $count;
	}
}

/**
 * Synthetic candidate-order adapter, deliberately outside WooCommerce.
 * Its fixture rows are physical InnoDB data so current locking reads can be tested;
 * they do not certify real Woo ownership, items, money, status or storage modes.
 */
final class PromotionQuoteBindOrderPort implements FixedPromotionQuoteOrderEvidenceGuard {
	public array $owners = [];
	public bool $allow = true;
	public bool $poison = false;
	public ?string $digest_override = null;
	public ?\Closure $on_proof = null;
	public int $digest_reads = 0;
	public function __construct( private readonly string $prefix, public readonly array $captured ) { DB::validate_prefix( $prefix ); }
	public static function table( string $prefix ): string { DB::validate_prefix( $prefix ); return $prefix . 'e13_candidate_orders'; }
	public static function install( \mysqli $db, string $prefix ): void {
		$table = self::table( $prefix );
		DB::execute( $db, "CREATE TABLE `{$table}` (site_id bigint unsigned NOT NULL,order_id bigint unsigned NOT NULL,packet_json longtext NOT NULL,PRIMARY KEY(site_id,order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
	}
	public static function put( \mysqli $db, string $prefix, array $packet ): void {
		$table = self::table( $prefix ); $site = $packet['site_id']; $order = $packet['order_id']; $json = QuoteJson::encode( $packet );
		$stmt = $db->prepare( "INSERT INTO `{$table}` (site_id,order_id,packet_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE packet_json=VALUES(packet_json)" );
		if ( false === $stmt || ! $stmt->bind_param( 'iis', $site, $order, $json ) || ! $stmt->execute() ) { throw new \RuntimeException( 'Owned synthetic candidate setup failed.' ); } $stmt->close();
	}
	public static function mapping( QuoteStoredRow $quote, int $order_id ): array {
		$context = $quote->context()->private_facts(); $lines = []; $index = 0;
		foreach ( $context['lines'] as $line ) { $lines[$line['line_key']] = [ 'line_key' => $line['line_key'], 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'parent_id' => $line['parent_id'], 'quantity' => $line['quantity'], 'item_id' => $order_id * 10 + ++$index ]; }
		$groups = []; foreach ( $context['groups'] as $group ) { $members = []; foreach ( $group['line_keys'] as $key ) { $members[] = $lines[$key]; } $groups[] = [ 'component_key' => $group['component_key'], 'lines' => $members ]; }
		return QuoteBinding::canonical_mapping( [ 'format_version' => 1, 'groups' => $groups ] );
	}
	public static function packet( QuoteStoredRow $quote, int $order_id, array $mapping ): array {
		return [ 'format_version' => 1, 'fixture' => 'synthetic_candidate_order_v1', 'site_id' => $quote->site_id(), 'order_id' => $order_id,
			'owner_digest' => $quote->header()->owner()->digest(), 'quote_uuid' => $quote->header()->id()->value(), 'accepted_body_digest' => $quote->header()->body_digest(),
			'mapping' => QuoteBinding::canonical_mapping( $mapping ), 'native_money_digest' => $quote->context()->private_facts()['tax']['native_money_digest'], 'state' => 'pending', 'paid_at' => null ];
	}
	public static function digest( array $packet ): string { return hash( 'sha256', 'cetech-e13-synthetic-candidate-order-v1:' . QuoteJson::encode( $packet ) ); }
	public function evidence_digest(): string { ++$this->digest_reads; if ( $this->poison ) { throw new \LogicException( 'History cannot inspect current candidate identity.' ); } return $this->digest_override ?? self::digest( $this->captured ); }
	public function tables( OperationSession $session ): array { if ( $this->poison ) { throw new \LogicException( 'History cannot request candidate tables.' ); } return [ self::table( $session->table_prefix() ) ]; }
	public function verify( OperationSession $session, QuoteStoredRow $accepted, QuoteBinding $candidate ): bool {
		$this->owners[] = $session;
		if ( $this->poison ) { throw new \LogicException( 'History cannot reevaluate candidate orders.' ); }
		if ( null !== $this->on_proof ) { ( $this->on_proof )( $session, count( $this->owners ) ); }
		if ( ! $this->allow || $session->is_retired() || ! $session->in_transaction() || $session->table_prefix() !== $this->prefix || $session->site_id() !== $accepted->site_id() ) { return false; }
		$expected = self::packet( $accepted, $candidate->row()['order_id'], $candidate->mapping() );
		if ( $expected !== $this->captured ) { return false; }
		$table = self::table( $this->prefix );
		$rows = $session->get_results( $session->prepare( "SELECT site_id,order_id,packet_json FROM `{$table}` WHERE site_id=%d AND order_id=%d LIMIT 2 FOR UPDATE", $accepted->site_id(), $candidate->row()['order_id'] ) );
		return is_array( $rows ) && 1 === count( $rows ) && (int) $rows[0]['site_id'] === $accepted->site_id() && (int) $rows[0]['order_id'] === $candidate->row()['order_id'] && $rows[0]['packet_json'] === QuoteJson::encode( $expected );
	}
}
