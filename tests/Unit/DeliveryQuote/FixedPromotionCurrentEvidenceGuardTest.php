<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetCurrentAuthority, FixedPromotionBudgetStore, FixedPromotionCurrentAuthority, FixedPromotionEligibilityBudgetGuard, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteCurrentEvidenceGuard, FixedPromotionQuoteProvider, LegacyQuoteSourcePlan, LegacyQuoteSourceSnapshot, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedPromotionBudgetRequest, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteMoney, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as L, QuoteFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Actual retained source reader and native capture over declared synthetic SQL/native facts. */
final class FixedPromotionCurrentEvidenceGuardTest extends TestCase {
	private function fixture( ?array $cards = null ): FixedPromotionCurrentFixture { return new FixedPromotionCurrentFixture( $cards ); }

	public function test_timed_current_proof_checks_distinct_source_and_native_contexts_without_clock_or_capture(): void {
		$f = $this->fixture(); $reads = $f->native_source->reads;
		self::assertNotSame( $f->snapshot->context()->digest(), $f->provider->context()->base_context()->digest() );
		$validity = $f->guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() );
		self::assertNotNull( $validity ); self::assertTrue( $validity->valid_at( QuoteFixtures::time()->plus_seconds( 89 ) ) );
		self::assertFalse( $validity->valid_at( QuoteFixtures::time()->plus_seconds( 90 ) ) ); self::assertSame( 0, $f->session->clocks );
		self::assertSame( $reads, $f->native_source->reads ); self::assertSame( $f->session, $f->campaign->seen[0][0] );
		self::assertSame( $f->header->to_private_json(), $f->campaign->seen[0][1]->to_private_json() );
		self::assertSame( $f->provider->context()->to_private_json(), $f->campaign->seen[0][2]->to_private_json() );
		self::assertSame( $f->provider->terms()->to_private_json(), $f->campaign->seen[0][3]->to_private_json() );
	}
	public function test_ordinary_verify_uses_exactly_one_clock_on_existing_owner(): void {
		$f = $this->fixture(); self::assertTrue( $f->guard->verify( $f->session, F::owner(), $f->provider->context() ) ); self::assertSame( 1, $f->session->clocks );
	}
	public function test_fresh_native_tax_source_is_preserved_and_tables_are_sorted_unique(): void {
		$f = $this->fixture(); self::assertSame( $f->native->native_receipt()->tax_source()->to_private_json(), $f->guard->tax_source()->to_private_json() );
		self::assertTrue( $f->guard->tax_source()->matches_context( $f->provider->context() ) );
		$tables = $f->guard->tables( $f->session ); $sorted = $tables; sort( $sorted, SORT_STRING ); self::assertSame( $sorted, $tables ); self::assertSame( array_values( array_unique( $tables ) ), $tables );
		self::assertContains( 'wp_delivery_engine_rate_cards', $tables ); self::assertContains( 'wp_promotion_budget', $tables ); self::assertContains( 'wp_woocommerce_sessions', $tables );
	}
	public static function unavailable_owner(): array { return [ 'retired' => [ 'retired' ], 'not in transaction' => [ 'transaction' ], 'foreign site' => [ 'site' ] ]; }
	#[DataProvider( 'unavailable_owner' )]
	public function test_lost_owner_refuses_before_sql_or_campaign( string $kind ): void {
		$f = $this->fixture(); if ( 'retired' === $kind ) { $f->session->retired = true; } elseif ( 'transaction' === $kind ) { $f->session->transaction = false; } else { $f->session->site = 2; }
		self::assertFalse( $f->guard->verify_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) ); self::assertSame( [], $f->session->sql ); self::assertSame( [], $f->campaign->seen );
	}
	public function test_wrong_owner_or_context_refuses_before_sql(): void {
		$f = $this->fixture(); $owner = F::owner()->facts(); $owner['principal_hash'] = QuoteFixtures::digest( 'foreign' );
		self::assertFalse( $f->guard->verify_at( $f->session, QuoteOwner::from_array( $owner ), $f->provider->context(), QuoteFixtures::time() ) );
		self::assertFalse( $f->guard->verify_at( $f->session, F::owner(), F::original_context(), QuoteFixtures::time() ) ); self::assertSame( [], $f->session->sql );
	}
	public function test_source_change_refuses_before_native_and_campaign_reads(): void {
		$f = $this->fixture(); $f->session->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01';
		self::assertFalse( $f->guard->verify_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) ); self::assertSame( [], $f->campaign->seen );
		self::assertSame( [], array_values( array_filter( $f->session->sql, static fn( string $sql ): bool => str_contains( $sql, 'woocommerce_tax_rates' ) ) ) );
	}
	public function test_fresh_native_physical_change_refuses_before_campaign(): void {
		$f = $this->fixture(); $f->session->native_rows['wp_wc_tax_rate_classes'] = [ [ 'tax_rate_class_id' => '1', 'name' => 'Changed', 'slug' => 'changed' ] ];
		self::assertFalse( $f->guard->verify_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) ); self::assertSame( [], $f->campaign->seen );
	}
	public function test_unconfirmed_campaign_has_no_usable_interval(): void {
		$f = $this->fixture(); $f->campaign->available = false; self::assertNull( $f->guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) ); self::assertCount( 1, $f->campaign->seen );
	}
	public function test_source_only_proof_precedes_budget_authority_and_never_calls_its_validity(): void {
		$f = $this->fixture(); $f->campaign->available = false; $reads = $f->native_source->reads;
		$v = $f->guard->source_validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() );
		self::assertNotNull( $v ); self::assertTrue( $v->valid_at( QuoteFixtures::time()->plus_seconds( 299 ) ) ); self::assertFalse( $v->valid_at( $f->header->expires_at() ) );
		self::assertSame( [], $f->campaign->seen ); self::assertSame( 0, $f->session->clocks ); self::assertSame( $reads, $f->native_source->reads );
		self::assertNull( $f->guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) ); self::assertCount( 1, $f->campaign->seen );
	}
	public function test_current_sources_cannot_substitute_for_a_missing_held_budget_claim(): void {
		$f = $this->fixture(); $key = QuoteFixtures::digest( 'explicit preallocation pool' );
		$r = FixedPromotionBudgetRequest::from_captured( $f->header, $f->provider->context(), $f->provider->terms(), F::key(), $key );
		$store = $this->createMock( FixedPromotionBudgetStore::class ); $store->method( 'tables' )->willReturn( [ 'wp_promotion_budget' ] );
		$store->expects( $this->once() )->method( 'lock_account' )->with( $f->session, $key )->willReturn( FixedPromotionBudgetState::create( 1, $key, $r->funding_source(), QuoteMoney::from_array( [ 'amount' => '9.00', 'currency' => 'GHS', 'precision' => 2 ] ) ) );
		$store->expects( $this->never() )->method( 'insert_reservation' ); $store->expects( $this->never() )->method( 'replace_account' ); $store->expects( $this->never() )->method( 'replace_reservation' );
		$eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class ); $eligibility->method( 'tables' )->willReturn( [ 'wp_promotion_policy' ] );
		$eligibility->expects( $this->once() )->method( 'validity_for_requests_at' )->willReturn( QuoteCurrentEvidenceValidity::capture( QuoteFixtures::time(), QuoteFixtures::time()->plus_seconds( 60 ) ) );
		$campaign = FixedPromotionBudgetCurrentAuthority::from_captured( $f->header, $f->provider->context(), $f->provider->terms(), [ [ 'component_key' => F::key(), 'account_key' => $key ] ], $store, $eligibility );
		$guard = FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $f->header, $f->provider->context(), $f->provider->terms(), $f->snapshot, $f->native, $campaign );
		self::assertNotNull( $guard->source_validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) );
		self::assertNull( $guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) );
	}
	public function test_full_proof_does_not_repeat_the_extracted_source_reads(): void {
		$source = $this->fixture(); self::assertNotNull( $source->guard->source_validity_at( $source->session, F::owner(), $source->provider->context(), QuoteFixtures::time() ) );
		$full = $this->fixture(); self::assertNotNull( $full->guard->validity_at( $full->session, F::owner(), $full->provider->context(), QuoteFixtures::time() ) );
		self::assertSame( $source->session->sql, $full->session->sql ); self::assertSame( [], $source->campaign->seen ); self::assertCount( 1, $full->campaign->seen );
	}
	public static function source_only_refusals(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'retired', 'transaction', 'site', 'prefix', 'owner', 'context', 'expired', 'backwards', 'source', 'native' ] ); }
	#[DataProvider( 'source_only_refusals' )]
	public function test_source_only_proof_retains_owner_context_time_and_physical_source_refusals( string $kind ): void {
		$f = $this->fixture(); $owner = F::owner(); $context = $f->provider->context(); $at = QuoteFixtures::time();
		if ( 'retired' === $kind ) { $f->session->retired = true; } elseif ( 'transaction' === $kind ) { $f->session->transaction = false; } elseif ( 'site' === $kind ) { $f->session->site = 2; } elseif ( 'prefix' === $kind ) { $f->session->prefix = 'wp_other_'; }
		elseif ( 'owner' === $kind ) { $facts = $owner->facts(); $facts['principal_hash'] = QuoteFixtures::digest( 'different source reader' ); $owner = QuoteOwner::from_array( $facts ); }
		elseif ( 'context' === $kind ) { $context = F::original_context(); } elseif ( 'expired' === $kind ) { $at = $f->header->expires_at(); } elseif ( 'backwards' === $kind ) { $at = $at->plus_seconds( -1 ); }
		elseif ( 'source' === $kind ) { $f->session->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } else { $f->session->native_rows['wp_wc_tax_rate_classes'] = [ [ 'tax_rate_class_id' => '1', 'name' => 'Changed', 'slug' => 'changed' ] ]; }
		self::assertNull( $f->guard->source_validity_at( $f->session, $owner, $context, $at ) ); self::assertSame( [], $f->campaign->seen ); self::assertSame( 0, $f->session->clocks );
	}
	public function test_earliest_source_boundary_and_quote_expiry_cap_campaign_validity(): void {
		$f = $this->fixture( [ L::card(), L::card( overrides: [ 'id' => 2, 'effective_from' => '2026-10-07 05:00:20' ] ) ] );
		$v = $f->guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ); self::assertNotNull( $v );
		self::assertTrue( $v->valid_at( QuoteFixtures::time()->plus_seconds( 19 ) ) ); self::assertFalse( $v->valid_at( QuoteFixtures::time()->plus_seconds( 20 ) ) );
		$f = $this->fixture(); $f->campaign->seconds = 1000;
		$v = $f->guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ); self::assertNotNull( $v );
		self::assertTrue( $v->valid_at( QuoteFixtures::time()->plus_seconds( 299 ) ) ); self::assertFalse( $v->valid_at( QuoteFixtures::time()->plus_seconds( 300 ) ) );
		self::assertFalse( $f->guard->verify_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time()->plus_seconds( 300 ) ) );
	}
	public function test_backwards_boundary_refuses_even_when_effective_rates_match(): void {
		$f = $this->fixture(); self::assertFalse( $f->guard->verify_at( $f->session, F::owner(), $f->provider->context(), QuoteTime::from_epoch_microseconds( QuoteFixtures::time()->epoch_microseconds() - 1 ) ) ); self::assertSame( [], $f->session->sql );
	}
	public static function loss_phases(): array { return [ 'table declaration' => [ 'tables' ], 'metadata validation' => [ 'metadata' ], 'source read' => [ 'source' ], 'native read' => [ 'native' ], 'campaign proof' => [ 'campaign' ], 'campaign retirement' => [ 'campaign_retire' ], 'campaign prefix switch' => [ 'campaign_prefix' ] ]; }
	#[DataProvider( 'loss_phases' )]
	public function test_owner_loss_during_dependencies_withholds_result( string $phase ): void {
		$f = $this->fixture();
		if ( 'tables' === $phase ) { $f->campaign->on_tables = static function() use ( $f ): void { $f->session->transaction = false; }; }
		elseif ( str_starts_with( $phase, 'campaign' ) ) { $f->campaign->on_proof = static function() use ( $f, $phase ): void { if ( 'campaign_retire' === $phase ) { $f->session->retired = true; } elseif ( 'campaign_prefix' === $phase ) { $f->session->prefix = 'wp_foreign_'; } else { $f->session->transaction = false; } }; }
		elseif ( 'metadata' === $phase ) { $f->session->on_validate = static function() use ( $f ): void { $f->session->transaction = false; }; }
		else { $f->session->on_read = static function( string $sql ) use ( $f, $phase ): void { if ( ( 'source' === $phase && str_contains( $sql, 'FORCE INDEX' ) ) || ( 'native' === $phase && str_contains( $sql, 'wc_tax_rate_classes' ) ) ) { $f->session->transaction = false; } }; }
		self::assertNull( $f->guard->validity_at( $f->session, F::owner(), $f->provider->context(), QuoteFixtures::time() ) );
		self::assertCount( str_starts_with( $phase, 'campaign' ) ? 1 : 0, $f->campaign->seen );
	}
	public function test_factory_refuses_different_original_header_even_for_same_money(): void {
		$f = $this->fixture(); $other = $this->fixture(); $this->expectException( \InvalidArgumentException::class );
		FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $f->header, $f->provider->context(), $f->provider->terms(), $f->snapshot, $other->native, $f->campaign );
	}
	public function test_factory_refuses_same_price_different_planning_receipt(): void {
		$f = $this->fixture(); $request = $f->native->original_requests(); $old = $request->plans()[0]['plan']->private_facts(); $old['evidence']['eligibility']['evidence_digest'] = QuoteFixtures::digest( 'replacement planning proof' );
		$plan = DeliveryPromotionPlan::from_array( $request->original_terms(), F::rule(), $old );
		$requests = FixedPromotionNativeRequests::from_captured( $request->header(), $request->original_context(), $request->original_terms(), [ [ 'component_key' => F::key(), 'plan' => $plan ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		$other = ( new FixedPromotionNativeCapture( F::source() ) )->capture( F::owner(), $requests );
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $f->header, $f->provider->context(), $f->provider->terms(), $f->snapshot, $other, $f->campaign );
	}
	public function test_factory_refuses_source_policy_packet_mismatch(): void {
		$f = $this->fixture(); $data = $f->snapshot->context()->private_facts(); $data['groups'][0]['policy_digest'] = QuoteFixtures::digest( 'other policy' );
		$source = LegacyQuoteSourceSnapshot::captured( $f->snapshot->plan(), QuoteContext::from_array( $data ), [], [], QuoteFixtures::time() );
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $f->header, $f->provider->context(), $f->provider->terms(), $source, $f->native, $f->campaign );
	}
	public function test_existing_source_verify_keeps_default_clock_and_new_verify_at_reuses_boundary(): void {
		$f = $this->fixture(); $source = $f->snapshot->guard(); self::assertTrue( $source->verify( $f->session, F::owner(), $f->snapshot->context() ) ); self::assertSame( 1, $f->session->clocks );
		$f->session->clocks = 0; self::assertTrue( $source->verify_at( $f->session, F::owner(), $f->snapshot->context(), QuoteFixtures::time() ) ); self::assertSame( 0, $f->session->clocks );
	}
	public function test_current_guard_cannot_implicitly_export_private_capture_or_funding(): void {
		$f = $this->fixture();
		foreach ( [ static fn(): string => json_encode( $f->guard, JSON_THROW_ON_ERROR ), static fn(): string => serialize( $f->guard ) ] as $export ) {
			try { $export(); self::fail( 'Private current evidence must not be exported.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( 'private_fixed_funder', $error->getMessage() ); }
		}
		ob_start(); var_dump( $f->guard ); $debug = ob_get_clean(); self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( 'private_fixed_funder', $debug );
	}
}

final class FixedPromotionCurrentFixture {
	public FixedPromotionCurrentSession $session;
	public LegacyQuoteSourceSnapshot $snapshot;
	public \CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixtureSource $native_source;
	public \CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionNativeResult $native;
	public FixedPromotionQuoteProvider $provider;
	public QuoteHeader $header;
	public FixedPromotionCurrentCampaignProbe $campaign;
	public FixedPromotionQuoteCurrentEvidenceGuard $guard;
	public function __construct( ?array $cards = null ) {
		$this->session = new FixedPromotionCurrentSession( $cards ?? [ L::card() ] ); $base = F::original_context(); $old_plan = L::plan();
		$plan = LegacyQuoteSourcePlan::create( F::owner(), $base, $old_plan->member_proofs(), $old_plan->rate_ranges(), $old_plan->fences() );
		$snapshot = ( new LegacyQuoteSourceSnapshotReader() )->capture( $this->session, F::owner(), $base, $plan ); $data = $base->private_facts();
		foreach ( $data['groups'] as &$group ) { $group['policy_digest'] = $snapshot->policy_digest(); $group['candidate_digest'] = $snapshot->candidate_digest( 20, 50, 'GHS' ); $group['candidate_count'] = $snapshot->candidate_count( 20, 50, 'GHS' ); } unset( $group );
		$context = QuoteContext::from_array( $data ); $this->snapshot = $snapshot->bind_context( $context );
		$data = F::original_terms()->private_facts(); $data['groups'][0]['policy_digest'] = $snapshot->policy_digest(); $terms = QuoteTerms::from_array( $data ); $header = F::original_header( $context, $terms );
		$requests = FixedPromotionNativeRequests::from_captured( $header, $context, $terms, [ [ 'component_key' => F::key(), 'plan' => F::plan( $terms ) ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		$this->native_source = F::source(); $this->native = ( new FixedPromotionNativeCapture( $this->native_source ) )->capture( F::owner(), $requests );
		$this->provider = FixedPromotionQuoteProvider::from_native_result( $this->native, F::rules() ); $this->header = F::header( $this->provider ); $this->campaign = new FixedPromotionCurrentCampaignProbe();
		$this->guard = FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), $this->snapshot, $this->native, $this->campaign );
		$this->session->clocks = 0; $this->session->sql = [];
	}
}

final class FixedPromotionCurrentCampaignProbe implements FixedPromotionCurrentAuthority {
	public array $seen = [];
	public bool $available = true;
	public int $seconds = 90;
	public ?\Closure $on_tables = null;
	public ?\Closure $on_proof = null;
	public function tables( OperationSession $session ): array { if ( null !== $this->on_tables ) { ( $this->on_tables )(); } return [ 'wp_promotion_budget' ]; }
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		$this->seen[] = [ $session, $header, $context, $terms, $at ]; if ( null !== $this->on_proof ) { ( $this->on_proof )(); }
		return $this->available ? QuoteCurrentEvidenceValidity::capture( $at, $at->plus_seconds( $this->seconds ) ) : null;
	}
}

final class FixedPromotionCurrentSession implements OperationSession {
	public bool $retired = false;
	public bool $transaction = true;
	public int $site = 1;
	public string $prefix = 'wp_';
	public int $clocks = 0;
	public array $sql = [];
	public array $rows = [];
	public array $native_rows = [];
	private array $columns = [];
	public ?\Closure $on_read = null;
	public ?\Closure $on_validate = null;
	public function __construct( array $cards ) {
		$rate_fields = [ 'id', 'internal_code', 'delivery_offer_id', 'destination_zone_id', 'logistics_profile_id', 'supplier_id', 'origin_id', 'charge_type', 'base_amount', 'base_currency', 'included_weight', 'increment_weight', 'increment_amount', 'per_item_amount', 'per_line_amount', 'highest_fee_mode', 'remote_surcharge', 'free_shipping_threshold', 'manual_currency_override_data', 'priority', 'effective_from', 'effective_to', 'status', 'created_at', 'updated_at' ];
		$this->columns = [ 'wp_posts' => [ 'ID', 'post_parent', 'post_type', 'post_status', 'post_modified_gmt' ], 'wp_postmeta' => [ 'meta_id', 'post_id', 'meta_key', 'meta_value' ], 'wp_term_relationships' => [ 'object_id', 'term_taxonomy_id', 'term_order' ], 'wp_options' => [ 'option_id', 'option_name', 'option_value', 'autoload' ], 'wp_delivery_engine_rate_cards' => $rate_fields ];
		foreach ( [ 'delivery_offers', 'origins', 'destination_zones', 'destination_rules', 'configuration_scopes', 'configuration_fields', 'configuration_collections' ] as $suffix ) { $this->columns['wp_delivery_engine_' . $suffix] = [ 'id', 'status' ]; }
		$this->rows['wp_posts'] = [ [ 'ID' => '10', 'post_parent' => '0', 'post_type' => 'product', 'post_status' => 'publish', 'post_modified_gmt' => '2026-10-07 05:00:00' ] ];
		$this->rows['wp_delivery_engine_delivery_offers'] = [ [ 'id' => '20', 'status' => 'active' ] ]; $this->rows['wp_delivery_engine_origins'] = [ [ 'id' => '40', 'status' => 'active' ] ]; $this->rows['wp_delivery_engine_destination_zones'] = [ [ 'id' => '50', 'status' => 'active' ] ];
		foreach ( $cards as $card ) { $row = array_replace( array_fill_keys( $rate_fields, null ), $card ); foreach ( $row as &$value ) { if ( is_int( $value ) ) { $value = (string) $value; } } unset( $value ); $this->rows['wp_delivery_engine_rate_cards'][] = $row; }
	}
	public function site_id(): int { return $this->site; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return 'utf8mb4_unicode_ci'; }
	public function begin(): bool { throw new \LogicException( 'Caller already owns the transaction.' ); }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Guard must not commit.' ); }
	public function rollback(): bool { throw new \LogicException( 'Guard must not roll back.' ); }
	public function retire(): bool { throw new \LogicException( 'Guard must not retire.' ); }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->transaction; }
	public function validate_tables( array $table_names ): bool { if ( null !== $this->on_validate ) { ( $this->on_validate )(); } return true; }
	public function query( string $sql ): int|false { throw new \LogicException( 'Guard must not write.' ); }
	public function get_row( string $sql ): array|null|false { $this->sql[] = $sql; if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clocks; return [ 'utc' => QuoteFixtures::time()->sql() ]; } throw new \LogicException( 'Unexpected single-row read.' ); }
	public function get_results( string $sql ): array|false {
		$this->sql[] = $sql; if ( null !== $this->on_read ) { ( $this->on_read )( $sql ); }
		if ( 1 === preg_match( '/\ASHOW FULL COLUMNS FROM `([^`]+)`/', $sql, $matches ) ) { return array_map( static fn( string $field ): array => [ 'Field' => $field, 'Type' => 'varchar(191)' ], $this->columns[$matches[1]] ?? [] ); }
		if ( 1 !== preg_match( '/FROM `([^`]+)`/', $sql, $matches ) ) { throw new \LogicException( 'Unexpected multi-row read.' ); }
		$table = $matches[1]; $rows = str_contains( $sql, 'source_oversized' ) ? ( $this->rows[$table] ?? [] ) : ( $this->native_rows[$table] ?? [] );
		return str_contains( $sql, 'source_oversized' ) ? array_map( static fn( array $row ): array => $row + [ 'source_oversized' => '0' ], $rows ) : $rows;
	}
	public function prepare( string $sql, mixed ...$args ): string { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } $index = 0; return preg_replace_callback( '/%[ds]/', static function( array $m ) use ( &$index, $args ): string { $value = $args[$index++]; return '%d' === $m[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'"; }, $sql ); }
	public function errno(): int { return 0; }
	public function insert_id(): int { return 0; }
}
