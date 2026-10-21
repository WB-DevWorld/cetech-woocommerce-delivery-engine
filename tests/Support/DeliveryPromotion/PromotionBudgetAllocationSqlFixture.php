<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAllocationService, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteProvider;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedPromotionBudgetAllocationSet};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteTerms};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetInstallationReadiness, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, FixedPromotionQuoteFixtureSource, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

require_once dirname( __DIR__ ) . '/DeliveryCommercial/PromotionBudgetSqlFixture.php';

/** Real budget SQL with complete compiled two-promotion packets; native capture facts are synthetic. */
final class PromotionBudgetAllocationSqlFixture {
	public static function set( bool $shared = true, bool $reverse = false ): FixedPromotionBudgetAllocationSet {
		$context = F::original_context()->private_facts(); $terms = F::original_terms()->private_facts();
		$line = $context['lines'][0]; $line['line_key'] = 'line_two'; $line['product_id'] = 11; $line['component_key'] = F::second_key(); $context['lines'][] = $line;
		$group = $context['groups'][0]; $group['component_key'] = F::second_key(); $group['line_keys'] = [ 'line_two' ]; $context['groups'][] = $group;
		$group = $terms['groups'][0]; $group['component_key'] = F::second_key(); $terms['groups'][] = $group;
		$context = QuoteContext::from_array( $context ); $terms = QuoteTerms::from_array( $terms ); $rule = F::rule(); $plans = []; $mapping = [];
		foreach ( [ F::key() => 'in_store|delivery|20', F::second_key() => 'second|delivery|20' ] as $key => $group_id ) {
			$plan = DeliveryPromotionPlan::from_evidence( $terms, $key, $rule, 'allocation_sql_fixture', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'allocation_sql_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $key, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => Q::digest( 'synthetic allocation policy' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => Q::digest( 'synthetic planning only' ) ] ] );
			$plans[] = [ 'component_key' => $key, 'plan' => $plan ]; $mapping[] = [ 'component_key' => $key, 'legacy_group_id' => $group_id ];
		}
		$requests = FixedPromotionNativeRequests::from_captured( F::original_header( $context, $terms ), $context, $terms, $plans, $mapping );
		$facts = F::facts(); $package = $facts['packages'][0]; $package['group_id'] = 'second|delivery|20'; $package['members'][0]['line_key'] = 'line_two'; $package['members'][0]['product_id'] = 11; $facts['packages'][] = $package; $facts['cart_shipping_total'] = '19.00'; $facts['cart_shipping_tax'] = '3.80';
		$result = ( new FixedPromotionNativeCapture( new FixedPromotionQuoteFixtureSource( $facts ) ) )->capture( F::owner(), $requests );
		$provider = FixedPromotionQuoteProvider::from_native_result( $result, [ [ 'component_key' => F::key(), 'rule' => $rule ], [ 'component_key' => F::second_key(), 'rule' => $rule ] ] );
		$slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'allocation pool A' ) ], [ 'component_key' => F::second_key(), 'account_key' => Q::digest( $shared ? 'allocation pool A' : 'allocation pool B' ) ] ];
		return FixedPromotionBudgetAllocationSet::from_captured( F::header( $provider ), $provider->context(), $provider->terms(), $reverse ? array_reverse( $slots ) : $slots );
	}
	public static function packet( FixedPromotionBudgetAllocationSet $set ): array { return [ 'header' => $set->header()->to_private_json(), 'context' => $set->context()->to_private_json(), 'terms' => $set->terms()->to_private_json(), 'slots' => $set->account_slots() ]; }
	public static function from_packet( array $packet ): FixedPromotionBudgetAllocationSet { return FixedPromotionBudgetAllocationSet::from_captured( QuoteHeader::from_json( $packet['header'] ), QuoteContext::from_json( $packet['context'] ), QuoteTerms::from_json( $packet['terms'] ), $packet['slots'] ); }
	public static function service(): FixedPromotionBudgetAllocationService { return new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true ); }
	public static function install( \mysqli $db, string $prefix ): void {
		B::install( $db, $prefix );
		DB::execute( $db, "CREATE TABLE `{$prefix}options` (option_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,option_name varchar(191) NOT NULL UNIQUE,option_value longtext NOT NULL,autoload varchar(20) NOT NULL DEFAULT 'off') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		self::publication( $db, $prefix, 'success' );
	}
	public static function publication( \mysqli $db, string $prefix, string $status, string $version = '12' ): void {
		DB::validate_prefix( $prefix );
		foreach ( [ SchemaVersion::OPTION_NAME => $version, MigrationStatus::OPTION_NAME => serialize( [ 'status' => $status, 'to_version' => $version, 'migration_id' => FixedPromotionBudgetInstallationReadiness::MIGRATION_ID ] ) ] as $name => $value ) {
			$s = $db->prepare( "INSERT INTO `{$prefix}options` (option_name,option_value) VALUES (?,?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
			if ( false === $s || ! $s->bind_param( 'ss', $name, $value ) || ! $s->execute() ) { throw new \RuntimeException( 'Owned allocation publication fixture failed.' ); } $s->close();
		}
	}
}
