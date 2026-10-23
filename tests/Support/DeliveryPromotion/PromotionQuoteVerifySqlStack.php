<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindInputs, FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyInputs, FixedPromotionQuoteVerifyService, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteJson, QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteLifecycleProofFactory, QuoteLifecycleProofPublication};

require_once __DIR__ . '/PromotionQuoteBindSqlStack.php';

/** Physical C03/binding/quote/ledger owners. Native/source/order/saved evidence ports are synthetic. */
final class PromotionQuoteVerifySqlStack {
	public readonly QuoteLifecycleProofFactory $physical;
	public readonly PromotionQuoteIssueSqlFactory $factory;
	public readonly PromotionQuoteAcceptEligibilityPort $eligibility;
	public readonly PromotionQuoteAcceptPhaseObserver $observer;
	public readonly QuoteLifecycleProofPublication $publication;
	public readonly PromotionQuoteBindOrderPort $order;
	public readonly PromotionQuoteVerifySavedPort $saved;
	public readonly FixedPromotionQuoteVerifyService $service;
	public bool $allow = true;

	public function __construct( string $prefix, public readonly array $fixture, FixedPromotionQuoteVerifyCommand $command, int $clock = QuoteLifecycleProofFactory::NOW + 3, ?callable $configure = null, int $site_id = 1 ) {
		$this->physical = new QuoteLifecycleProofFactory( $prefix, $site_id ); $this->physical->clock = $clock;
		$this->physical->configure = null === $configure ? null : \Closure::fromCallable( $configure );
		$this->factory = new PromotionQuoteIssueSqlFactory( $this->physical );
		$this->eligibility = new PromotionQuoteAcceptEligibilityPort(); $this->observer = new PromotionQuoteAcceptPhaseObserver(); $this->publication = new QuoteLifecycleProofPublication();
		$original = $command->binding_command();
		$this->order = new PromotionQuoteBindOrderPort( $prefix, PromotionQuoteBindOrderPort::packet( $original->quote_record(), $original->binding()->row()['order_id'], $original->binding()->mapping() ) );
		$this->saved = new PromotionQuoteVerifySavedPort( $prefix, PromotionQuoteVerifySavedPort::packet( $command->binding() ) );
		$this->service = new FixedPromotionQuoteVerifyService( $this->factory, new SqlFixedPromotionBudgetStore(), $this->eligibility,
			fn( QuoteOwner $owner, string $operation ): bool => $this->allow && 1 === $owner->site_id() && 'delivery_quote.verify_binding' === $operation,
			observer: $this->observer, control: new EmergencyControlStore( static fn(): bool => false ), publication: $this->publication );
	}
	public function inputs(): FixedPromotionQuoteVerifyInputs {
		return FixedPromotionQuoteVerifyInputs::from_sources(
			FixedPromotionQuoteBindInputs::from_sources( FixedPromotionQuoteAcceptInputs::from_sources( $this->fixture['original'], $this->fixture['fresh'] ), $this->order ),
			$this->saved
		);
	}
	public function protected_writes(): array {
		$out = [];
		foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) {
			if ( 1 === preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b/i', $sql )
				&& ( 1 === preg_match( '/delivery_engine_(?:delivery_quotes|promotion_budget_accounts|promotion_budget_reservations|delivery_quote_budget_windows)/', $sql ) || str_contains( $sql, 'e13_candidate_orders' ) || str_contains( $sql, 'e14_saved_snapshots' ) ) ) { $out[] = $sql; }
		} }
		return $out;
	}
	public function binding_updates(): int {
		$count = 0; foreach ( $this->physical->transports as $transport ) { foreach ( $transport->sql as $sql ) { if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { ++$count; } } } return $count;
	}
}

/**
 * Synthetic saved-snapshot adapter, explicitly outside WooCommerce.
 * Its immutable packet is physically stored in InnoDB for current-locking proof.
 * No real Woo ownership, snapshot writer, items, money or storage-mode certification.
 */
final class PromotionQuoteVerifySavedPort implements QuotePlacementSavedEvidenceGuard {
	public array $owners = [];
	public bool $allow = true;
	public bool $poison = false;
	public ?\Closure $on_proof = null;
	public function __construct( private readonly string $prefix, public readonly array $captured ) { DB::validate_prefix( $prefix ); }
	public static function table( string $prefix ): string { DB::validate_prefix( $prefix ); return $prefix . 'e14_saved_snapshots'; }
	public static function install( \mysqli $db, string $prefix ): void {
		$table = self::table( $prefix );
		DB::execute( $db, "CREATE TABLE {$table} (site_id bigint unsigned NOT NULL,order_id bigint unsigned NOT NULL,packet_json longtext NOT NULL,PRIMARY KEY(site_id,order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
	}
	public static function put( \mysqli $db, string $prefix, array $packet ): void {
		$table = self::table( $prefix ); $site = $packet['site_id']; $order = $packet['order_id']; $json = QuoteJson::encode( $packet );
		$stmt = $db->prepare( "INSERT INTO {$table} (site_id,order_id,packet_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE packet_json=VALUES(packet_json)" );
		if ( false === $stmt || ! $stmt->bind_param( 'iis', $site, $order, $json ) || ! $stmt->execute() ) { throw new \RuntimeException( 'Owned synthetic saved snapshot setup failed.' ); } $stmt->close();
	}
	public static function packet( QuoteBinding $binding ): array {
		$b = $binding->row();
		return [ 'format_version' => 1, 'fixture' => 'synthetic_saved_snapshot_v1', 'site_id' => $b['site_id'], 'order_id' => $b['order_id'],
			'quote_uuid' => $b['quote_uuid'], 'placement_uuid' => $b['placement_uuid'], 'binding_id' => $b['id'], 'mapping' => $binding->mapping(),
			'manifest_digest' => $b['managed_group_manifest_digest'], 'native_money_digest' => $b['native_money_digest'], 'accepted_body_digest' => $b['accepted_body_digest'],
			'snapshot_digest' => $b['snapshot_digest'], 'context_digest' => $b['context_digest'] ];
	}
	public function tables( OperationSession $session ): array { if ( $this->poison ) { throw new \LogicException( 'History cannot request saved snapshot tables.' ); } return [ self::table( $session->table_prefix() ) ]; }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool {
		$this->owners[] = $session;
		if ( $this->poison ) { throw new \LogicException( 'History cannot reevaluate saved snapshots.' ); }
		if ( null !== $this->on_proof ) { ( $this->on_proof )( $session, count( $this->owners ) ); }
		if ( ! $this->allow || $session->is_retired() || ! $session->in_transaction() || $session->table_prefix() !== $this->prefix || $session->site_id() !== $binding->site_id() || 2 !== $binding->revision() || 'prepared' !== $binding->state() ) { return false; }
		$expected = self::packet( $binding ); if ( $expected !== $this->captured ) { return false; }
		$table = self::table( $this->prefix );
		$rows = $session->get_results( $session->prepare( "SELECT site_id,order_id,packet_json FROM {$table} WHERE site_id=%d AND order_id=%d LIMIT 2 FOR UPDATE", $binding->site_id(), $binding->row()['order_id'] ) );
		return is_array( $rows ) && 1 === count( $rows ) && (int) $rows[0]['site_id'] === $binding->site_id() && (int) $rows[0]['order_id'] === $binding->row()['order_id'] && $rows[0]['packet_json'] === QuoteJson::encode( $expected );
	}
}
