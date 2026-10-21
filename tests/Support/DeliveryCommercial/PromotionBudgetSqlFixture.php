<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryCommercial;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetService;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetSchema, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;

require_once __DIR__ . '/FixedPromotionQuoteFixture.php';

/** Synthetic commercial packets over genuinely separate SQL owners; no Woo runtime. */
final class PromotionBudgetSqlFixture {
	public static function money( string $amount ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => 'GHS', 'precision' => 2 ] ); }
	public static function request( string $account = 'owned sql promotion account' ): FixedPromotionBudgetRequest {
		$p = FixedPromotionQuoteFixture::provider();
		return FixedPromotionBudgetRequest::from_captured( FixedPromotionQuoteFixture::header( $p ), $p->context(), $p->terms(), FixedPromotionQuoteFixture::key(), QuoteFixtures::digest( $account ) );
	}
	public static function account( FixedPromotionBudgetRequest $r, string $limit = '3.00' ): FixedPromotionBudgetState { return FixedPromotionBudgetState::create( $r->site_id(), $r->account_key(), $r->funding_source(), self::money( $limit ) ); }
	public static function service( ?SqlFixedPromotionBudgetStore $store = null ): FixedPromotionBudgetService { return new FixedPromotionBudgetService( $store ?? new SqlFixedPromotionBudgetStore(), static fn(): bool => true ); }
	public static function install( \mysqli $db, string $prefix ): void { DB::validate_prefix( $prefix ); foreach ( FixedPromotionBudgetSchema::create_table_statements( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $prefix . 'delivery_engine_' ) as $sql ) { DB::execute( $db, $sql ); } }
	public static function cleanup( \mysqli $db, string $prefix ): void { DB::validate_prefix( $prefix ); foreach ( FixedPromotionBudgetSchema::tables( $prefix ) as $table ) { DB::execute( $db, "DROP TABLE IF EXISTS `{$table}`" ); } }
}
