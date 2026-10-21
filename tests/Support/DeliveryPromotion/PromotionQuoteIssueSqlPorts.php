<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionEligibilityBudgetGuard, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, LegacyQuoteSourcePlan, QuoteCurrentEvidenceValidity, QuoteProviderInterface};
use CetechDeliveryEngine\Domain\DeliveryPromotion\DeliveryPromotionPlan;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetSchema, LegacyQuoteSourceSnapshotReader};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, FixedPromotionQuoteFixtureSource};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures as L, QuoteFixtures as Q, QuoteLifecycleProofFactory};
use CetechDeliveryEngine\Tests\Unit\DeliveryQuote\FixedPromotionCurrentSession;

require_once dirname( __DIR__, 2 ) . '/Unit/DeliveryQuote/FixedPromotionCurrentEvidenceGuardTest.php';

/** Declared synthetic original/native/eligibility sources. All durable owners and effect SQL are real. */
final class PromotionQuoteIssueSqlPorts {

	public static function capture( OperationSession $session ): array {
		$context = F::original_context()->private_facts(); $terms = F::original_terms()->private_facts();
		$line = $context['lines'][0]; $line['line_key'] = 'line_two'; $line['product_id'] = 11; $line['component_key'] = F::second_key(); $context['lines'][] = $line;
		$group = $context['groups'][0]; $group['component_key'] = F::second_key(); $group['line_keys'] = [ 'line_two' ]; $context['groups'][] = $group;
		$group = $terms['groups'][0]; $group['component_key'] = F::second_key(); $terms['groups'][] = $group;
		$base = QuoteContext::from_array( $context ); $old = L::plan(); $members = $old->member_proofs(); $member = $members[0]; $member['line_key'] = 'line_two'; $members[] = $member; $fences = $old->fences();
		foreach ( $fences as &$fence ) {
			if ( in_array( $fence['source'], [ 'product', 'product_meta', 'term_relationships' ], true ) ) { $fence['ids'][] = 11; }
			if ( 'scopes' === $fence['source'] ) { $fence['targets'][] = [ 'type' => 'product', 'id' => 11 ]; }
		} unset( $fence );
		$plan = LegacyQuoteSourcePlan::create( F::owner(), $base, $members, $old->rate_ranges(), $fences );
		$original = ( new LegacyQuoteSourceSnapshotReader() )->capture( $session, F::owner(), $base, $plan, Q::time() );
		foreach ( $context['groups'] as &$group ) { $group['policy_digest'] = $original->policy_digest(); $group['candidate_digest'] = $original->candidate_digest( 20, 50, 'GHS' ); $group['candidate_count'] = $original->candidate_count( 20, 50, 'GHS' ); } unset( $group );
		foreach ( $terms['groups'] as &$group ) { $group['policy_digest'] = $original->policy_digest(); } unset( $group );
		$context = QuoteContext::from_array( $context ); $terms = QuoteTerms::from_array( $terms ); $original = $original->bind_context( $context ); $rule = F::rule(); $plans = []; $mapping = []; $rules = [];
		foreach ( [ F::key() => 'in_store|delivery|20', F::second_key() => 'second|delivery|20' ] as $key => $legacy ) {
			$plans[] = [ 'component_key' => $key, 'plan' => DeliveryPromotionPlan::from_evidence( $terms, $key, $rule, 'e10_synthetic_policy', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'e10_synthetic_policy', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $key, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => Q::digest( 'E10 synthetic eligibility, not merchant authority' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => Q::digest( 'E10 planning only, actual ledger checked in SQL' ) ] ] ) ];
			$mapping[] = [ 'component_key' => $key, 'legacy_group_id' => $legacy ]; $rules[] = [ 'component_key' => $key, 'rule' => $rule ];
		}
		$requests = FixedPromotionNativeRequests::from_captured( F::original_header( $context, $terms ), $context, $terms, $plans, $mapping );
		$facts = F::facts(); $package = $facts['packages'][0]; $package['group_id'] = 'second|delivery|20'; $package['members'][0]['line_key'] = 'line_two'; $package['members'][0]['product_id'] = 11; $facts['packages'][] = $package; $facts['cart_shipping_total'] = '19.00'; $facts['cart_shipping_tax'] = '3.80'; $facts['source_facts']['selectors']['table_prefix'] = $session->table_prefix();
		$source = new FixedPromotionQuoteFixtureSource( $facts ); $fresh = ( new FixedPromotionNativeCapture( $source ) )->capture( F::owner(), $requests );
		$provider = new PromotionQuoteIssueCountingProvider( FixedPromotionQuoteProvider::from_native_result( $fresh, $rules ) );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'E10 shared owned pool' ) ], [ 'component_key' => F::second_key(), 'account_key' => Q::digest( 'E10 shared owned pool' ) ] ];
		return compact( 'original', 'fresh', 'provider', 'source', 'slots' );
	}
}

/** A new real SQL owner on every open; wrappers never swap the owner inside a transaction. */
final class PromotionQuoteIssueSqlFactory implements OperationConnectionFactory {
	public array $sessions = [];
	public function __construct( public readonly QuoteLifecycleProofFactory $physical ) {}
	public function open(): OperationSession { $session = new PromotionQuoteIssueMixedSession( $this->physical->open() ); $this->sessions[] = $session; return $session; }
}

/** Only finite source/native reads are synthetic; operations, quotes, admission, options and budget remain physical. */
final class PromotionQuoteIssueMixedSession implements OperationSession {
	public FixedPromotionCurrentSession $source;
	public function __construct( private OperationSession $physical ) { $this->source = new FixedPromotionCurrentSession( [ L::card() ] ); $second = $this->source->rows['wp_posts'][0]; $second['ID'] = '11'; $this->source->rows['wp_posts'][] = $second; }
	public function site_id(): int { return $this->physical->site_id(); }
	public function table_prefix(): string { return $this->physical->table_prefix(); }
	public function charset_collate(): string { return $this->physical->charset_collate(); }
	public function begin(): bool { return $this->physical->begin(); }
	public function commit(): OperationCommitResult { return $this->physical->commit(); }
	public function rollback(): bool { return $this->physical->rollback(); }
	public function retire(): bool { return $this->physical->retire(); }
	public function is_retired(): bool { return $this->physical->is_retired(); }
	public function in_transaction(): bool { return $this->physical->in_transaction(); }
	public function validate_tables( array $tables ): bool {
		$native = array_values( array_filter( $tables, fn( string $table ): bool => ! $this->synthetic_table( $table ) ) );
		return [] === $native || $this->physical->validate_tables( $native );
	}
	public function query( string $sql ): int|false { return $this->physical->query( $sql ); }
	public function get_row( string $sql ): array|null|false { return $this->physical->get_row( $sql ); }
	public function get_results( string $sql ): array|false {
		if ( str_starts_with( $sql, 'SHOW ' ) && str_contains( $sql, '`' . $this->table_prefix() . 'delivery_engine_rate_cards`' ) ) { return $this->physical->get_results( $sql ); }
		$synthetic = str_contains( $sql, 'source_oversized' );
		if ( 1 === preg_match( '/(?:FROM|COLUMNS FROM) `([^`]+)`/', $sql, $match ) ) { $synthetic = $synthetic || $this->synthetic_table( $match[1] ); }
		// Native tax evidence deliberately has empty synthetic Woo option rows, distinct from current publication/control.
		if ( str_starts_with( $sql, 'SELECT option_id,option_name,LEFT(option_value,16385) AS option_value,autoload FROM `' . $this->table_prefix() . 'options`' ) ) { $synthetic = true; }
		return $synthetic ? $this->source->get_results( str_replace( $this->table_prefix(), 'wp_', $sql ) ) : $this->physical->get_results( $sql );
	}
	private function synthetic_table( string $table ): bool { $suffix = substr( $table, strlen( $this->table_prefix() ) ); return in_array( $suffix, [ 'posts', 'postmeta', 'term_relationships', 'woocommerce_tax_rates', 'woocommerce_tax_rate_locations', 'wc_tax_rate_classes', 'woocommerce_shipping_zone_methods', 'woocommerce_sessions', 'usermeta', 'delivery_engine_rate_cards', 'delivery_engine_delivery_offers', 'delivery_engine_origins', 'delivery_engine_destination_zones', 'delivery_engine_destination_rules', 'delivery_engine_configuration_scopes', 'delivery_engine_configuration_fields', 'delivery_engine_configuration_collections' ], true ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->physical->prepare( $sql, ...$args ); }
	public function errno(): int { return $this->physical->errno(); }
	public function insert_id(): int { return $this->physical->insert_id(); }
}

/** Trusted synthetic eligibility only. Optional callback models a slow dependency on the same real owner. */
final class PromotionQuoteIssueEligibilityPort implements FixedPromotionEligibilityBudgetGuard {
	public array $owners = [];
	public ?\Closure $on_proof = null;
	public function tables( OperationSession $session ): array { return FixedPromotionBudgetSchema::tables( $session->table_prefix() ); }
	public function validity_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { throw new \LogicException( 'E10 must use account-aware eligibility.' ); }
	public function validity_for_requests_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { $this->owners[] = $s; if ( null !== $this->on_proof ) { ( $this->on_proof )( $s, count( $this->owners ) ); } return QuoteCurrentEvidenceValidity::capture( $at, $h->expires_at() ); }
}

final class PromotionQuoteIssueCountingProvider implements QuoteProviderInterface {
	public int $captures = 0;
	public function __construct( public readonly FixedPromotionQuoteProvider $inner ) {}
	public function code(): string { return $this->inner->code(); }
	public function version(): int { return $this->inner->version(); }
	public function profile(): string { return $this->inner->profile(); }
	public function profile_version(): int { return $this->inner->profile_version(); }
	public function evidence_providers(): array { return $this->inner->evidence_providers(); }
	public function capture( QuoteContext $context ): QuoteTerms { ++$this->captures; return $this->inner->capture( $context ); }
}
