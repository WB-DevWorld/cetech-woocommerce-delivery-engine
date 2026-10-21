<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteProvider;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedPromotionEligibilityFamily};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleSnapshot, RuleStartMode, RuleTime};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;

require_once dirname( __DIR__ ) . '/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Strict synthetic rule rows, distinct from accepted merchant mutation history. */
final class PromotionEligibilityFixture {
	public const CREATED = '2026-10-06 09:00:00.000000';
	public const PUBLISHED = '2026-10-06 09:59:00.000000';
	public const UPDATED = '2026-10-06 12:00:00.000000';
	public static function family(): FixedPromotionEligibilityFamily { return new FixedPromotionEligibilityFamily( static fn(): bool => true, static fn(): bool => true ); }
	public static function at(): QuoteTime { return Q::time(); }
	public static function account(): string { return Q::digest( 'explicit merchant budget pool' ); }
	public static function scope(): array { return self::family()->subject_for_component( F::original_context(), F::original_terms(), F::key(), F::rule()->private_facts()['code'] ); }
	public static function uuid( int $id ): string { return sprintf( '10000000-0000-4000-8000-%012x', $id ); }
	public static function rows(): array {
		$f = self::family(); $scope = self::scope(); $payload = [ 'rule' => F::rule()->private_facts(), 'budget_account_key' => self::account() ]; $published = RuleTime::parse( self::PUBLISHED );
		$guard = [ 'id' => 1, 'site_id' => 1, 'family_code' => $f->family(), 'family_format' => 1, 'policy_hash' => $f->policy_hash(), 'revision' => 3, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ];
		$logical = [ 'id' => 1, 'site_id' => 1, 'family_guard_id' => 1, 'logical_uuid' => self::uuid( 1 ), 'scope_format' => 1, 'scope_json' => $f->scope_schema()->encode( $scope, 4096 ), 'scope_hash' => RuleContent::scope_hash( $f, $scope ), 'revision' => 2, 'last_version_sequence' => 1, 'current_published_version_id' => 1, 'draft_version_id' => null, 'scheduled_version_id' => null, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ];
		$version = [ 'id' => 1, 'site_id' => 1, 'logical_rule_id' => 1, 'version_uuid' => self::uuid( 101 ), 'version_sequence' => 1, 'row_revision' => 2, 'state' => 'published', 'payload_format' => 1, 'payload_json' => $f->payload_schema()->encode( $payload, 16384 ), 'content_hash' => RuleContent::hash( $f, $payload, RuleStartMode::Immediate, $published, null, 10, null ), 'priority' => 10, 'start_mode' => 'immediate', 'effective_from' => self::PUBLISHED, 'effective_until' => null, 'author_user_id' => 9, 'change_reason' => 'Synthetic policy fixture', 'supersedes_version_id' => null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null, 'scheduled_predecessor_row_revision' => null, 'sealed_at' => self::PUBLISHED, 'scheduled_at' => null, 'published_at' => self::PUBLISHED, 'retired_at' => null, 'created_at' => self::CREATED, 'updated_at' => self::UPDATED ];
		RuleSnapshot::from_history_rows( $f, $guard, [ $logical ], [ $version ] ); return [ $guard, [ $logical ], [ $version ] ];
	}
	public static function retired_rows(): array {
		$rows = self::rows(); $rows[1][0]['current_published_version_id'] = null; $rows[2][0]['state'] = 'retired'; $rows[2][0]['retired_at'] = self::UPDATED;
		RuleSnapshot::from_history_rows( self::family(), ...$rows ); return $rows;
	}
	public static function successor_rows(): array {
		$rows = self::rows(); $cutover = self::at()->plus_seconds( -1 )->sql(); $prior =& $rows[2][0]; $prior['state'] = 'retired'; $prior['retired_at'] = $cutover; $prior['updated_at'] = $cutover;
		$next = $prior; $next['id'] = 2; $next['version_uuid'] = self::uuid( 102 ); $next['version_sequence'] = 2; $next['state'] = 'published'; $next['supersedes_version_id'] = 1; $next['retired_at'] = null; $next['effective_from'] = $cutover; $next['sealed_at'] = $cutover; $next['published_at'] = $cutover; $next['content_hash'] = RuleContent::hash( self::family(), json_decode( $next['payload_json'], true ), RuleStartMode::Immediate, RuleTime::parse( $cutover ), null, 10, 1 );
		$rows[2][] = $next; $rows[1][0]['current_published_version_id'] = 2; $rows[1][0]['last_version_sequence'] = 2; $rows[1][0]['updated_at'] = $cutover; $rows[0]['updated_at'] = $cutover;
		RuleSnapshot::from_history_rows( self::family(), ...$rows ); return $rows;
	}
	public static function scheduled_rows(): array {
		$rows = self::rows(); $boundary = self::at()->plus_seconds( 30 ); $next = $rows[2][0];
		$next['id'] = 2; $next['version_uuid'] = self::uuid( 102 ); $next['version_sequence'] = 2; $next['row_revision'] = 1; $next['state'] = 'scheduled'; $next['start_mode'] = 'at'; $next['effective_from'] = $boundary->sql(); $next['supersedes_version_id'] = 1; $next['sealed_at'] = self::UPDATED; $next['scheduled_at'] = self::UPDATED; $next['published_at'] = null; $next['scheduled_revision'] = 1; $next['scheduled_logical_revision'] = 3; $next['scheduled_predecessor_row_revision'] = 2; $next['content_hash'] = RuleContent::hash( self::family(), json_decode( $next['payload_json'], true ), RuleStartMode::At, RuleTime::parse( $boundary->sql() ), null, 10, 1 );
		$rows[2][] = $next; $rows[1][0]['scheduled_version_id'] = 2; $rows[1][0]['revision'] = 3; $rows[1][0]['last_version_sequence'] = 2;
		RuleSnapshot::from_history_rows( self::family(), ...$rows ); return $rows;
	}
	/** Compile the actual retained native bridge using the SQL-selected planning digest. */
	public static function compiled( array $eligibility ): array {
		$context = F::original_context(); $terms = F::original_terms(); $rule = F::rule();
		$plan = DeliveryPromotionPlan::from_evidence( $terms, F::key(), $rule, 'sql_eligibility_fixture', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'sql_eligibility_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => F::key(), 'rule_digest' => $rule->digest(), 'eligibility' => $eligibility, 'budget' => [ 'state' => 'available', 'evidence_digest' => Q::digest( 'separate synthetic budget availability' ) ] ] );
		$requests = FixedPromotionNativeRequests::from_captured( F::original_header( $context, $terms ), $context, $terms, [ [ 'component_key' => F::key(), 'plan' => $plan ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ] ] );
		$result = ( new FixedPromotionNativeCapture( F::source() ) )->capture( F::owner(), $requests ); $provider = FixedPromotionQuoteProvider::from_native_result( $result, F::rules() );
		return [ F::header( $provider ), $provider->context(), $provider->terms() ];
	}
}
