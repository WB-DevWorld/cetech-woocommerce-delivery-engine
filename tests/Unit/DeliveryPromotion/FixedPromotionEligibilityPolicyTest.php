<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedDeliveryPromotion, FixedPromotionEligibilityFamily, FixedPromotionEligibilityReference};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteTerms};
use CetechDeliveryEngine\Domain\RuleLifecycle\{LogicalRule, RuleCandidate, RuleContent, RuleFamilyGuard, RuleFamilyRegistry, RuleStartMode, RuleTime, RuleVersion};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

final class FixedPromotionEligibilityPolicyTest extends TestCase {
	public function test_family_has_no_default_grants_or_registration_and_exact_explicit_ranking(): void {
		$family = self::family();
		self::assertSame( [], ( new RuleFamilyRegistry() )->profiles() );
		self::assertSame( $family, ( new RuleFamilyRegistry( [ $family ] ) )->get( FixedPromotionEligibilityFamily::CODE ) );
		self::assertSame( FixedPromotionEligibilityFamily::CODE, $family->family() );
		self::assertSame( [ [ 'field' => 'priority', 'direction' => 'desc' ] ], $family->precedence() );
		self::assertSame( 'refuse', $family->equal_rank_policy() );
		$identity = new OperationIdentity( 1, 'merchant_admin', 'staff:7', 'configure_fixed_promotion', 1, 'campaign:fixture', 'fixture-request' );
		self::assertTrue( $family->authorize( $identity, self::scope() ) );
		self::assertTrue( $family->authorize_author( 1, 7, self::scope() ) );
		self::assertFalse( $family->authorize_author( 0, 7, self::scope() ) );
		$deny = new FixedPromotionEligibilityFamily( static fn(): int => 1, static function (): bool { throw new \RuntimeException( 'grant unavailable' ); } );
		self::assertFalse( $deny->authorize( $identity, self::scope() ) );
		self::assertFalse( $deny->authorize_author( 1, 7, self::scope() ) );
		$this->expectException( \ArgumentCountError::class );
		new FixedPromotionEligibilityFamily();
	}

	public function test_all_members_and_exact_dimensions_match_with_no_specificity_tie_break(): void {
		$family = self::family(); $scope = self::scope( [ 'product_ids' => [ 10, 11, 12 ] ] );
		$subject = self::scope( [ 'product_ids' => [ 10, 12 ] ] );
		self::assertTrue( $family->match( $scope, $subject )->matched );
		self::assertSame( 0, $family->match( $scope, $subject )->specificity );
		self::assertFalse( $family->match( $scope, self::scope( [ 'product_ids' => [ 10, 13 ] ] ) )->matched );
		foreach ( [ 'promotion_code' => 'other', 'offer_id' => 21, 'destination_zone_id' => 51, 'currency' => 'USD' ] as $field => $value ) {
			self::assertFalse( $family->match( $scope, array_replace( $subject, [ $field => $value ] ) )->matched );
		}
		self::assertTrue( $family->overlaps( $scope, self::scope( [ 'product_ids' => [ 12, 15 ] ] ) ) );
		self::assertFalse( $family->overlaps( $scope, self::scope( [ 'product_ids' => [ 15 ] ] ) ) );
		self::assertNull( $family->overlaps( $scope, self::scope( [ 'product_ids' => [] ] ) ) );
	}

	public function test_declared_product_bound_fits_the_existing_lifecycle_structural_budget(): void {
		$scope = self::scope( [ 'product_ids' => range( 1, FixedPromotionEligibilityFamily::MAX_PRODUCTS ) ] );
		self::assertSame( $scope['product_ids'], FixedPromotionEligibilityFamily::checked_scope( $scope )['product_ids'] );
		self::assertSame( $scope['product_ids'], self::family()->scope_schema()->decode( self::family()->scope_schema()->encode( $scope ) )['product_ids'] );
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionEligibilityFamily::checked_scope( self::scope( [ 'product_ids' => range( 1, FixedPromotionEligibilityFamily::MAX_PRODUCTS + 1 ) ] ) );
	}

	#[DataProvider( 'invalid_scopes' )]
	public function test_scope_refuses_empty_ambiguous_coerced_or_unproved_predicates( array $edits ): void {
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionEligibilityFamily::checked_scope( self::scope( $edits ) );
	}
	public static function invalid_scopes(): array {
		return [ [ [ 'product_ids' => [] ] ], [ [ 'product_ids' => [ 10, 10 ] ] ], [ [ 'product_ids' => [ 11, 10 ] ] ], [ [ 'product_ids' => [ '10' ] ] ], [ [ 'promotion_code' => '' ] ], [ [ 'currency' => 'ghs' ] ], [ [ 'offer_id' => '20' ] ], [ [ 'role' => 'customer' ] ] ];
	}

	#[DataProvider( 'invalid_payloads' )]
	public function test_payload_uses_the_full_strict_rule_codec_and_explicit_account( string $edit ): void {
		$payload = self::payload();
		switch ( $edit ) {
			case 'format': $payload['rule']['format_version'] = 2; break;
			case 'uncanonical_money': $payload['rule']['discount']['amount'] = '3'; break;
			case 'negative': $payload['rule']['discount']['amount'] = '-3.00'; break;
			case 'funding': $payload['rule']['funding_source']['code'] = ''; break;
			case 'version': $payload['rule']['version'] = 1000001; break;
			case 'account': $payload['budget_account_key'] = 'merchant'; break;
			case 'missing_account': unset( $payload['budget_account_key'] ); break;
			case 'extra': $payload['stacking'] = 'best'; break;
		}
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionEligibilityFamily::checked_payload( $payload );
	}
	public static function invalid_payloads(): array { return array_map( static fn( string $value ): array => [ $value ], [ 'format', 'uncanonical_money', 'negative', 'funding', 'version', 'account', 'missing_account', 'extra' ] ); }

	public function test_subject_projects_every_captured_member_without_variation_or_woo_inference(): void {
		$data = QuoteFixtures::context()->private_facts();
		$line = $data['lines'][0]; $line['line_key'] = 'second'; $line['product_id'] = 11; $line['variation_id'] = 99; $line['parent_id'] = 11;
		$data['lines'][] = $line; $data['groups'][0]['line_keys'][] = 'second';
		$subject = FixedPromotionEligibilityFamily::subject_for_component( QuoteContext::from_array( $data ), QuoteFixtures::terms(), QuoteFixtures::digest( 'component' ), 'fixture_fixed' );
		self::assertSame( [ 10, 11 ], $subject['product_ids'] );
		self::assertTrue( self::family()->match( self::scope( [ 'product_ids' => [ 10, 11 ] ] ), $subject )->matched );
		self::assertFalse( self::family()->match( self::scope(), $subject )->matched );
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionEligibilityFamily::subject_for_component( QuoteContext::from_array( $data ), QuoteFixtures::terms(), QuoteFixtures::digest( 'absent component' ), 'fixture_fixed' );
	}

	public function test_subject_refuses_unrelated_terms_coverage(): void {
		$data = QuoteFixtures::terms()->private_facts(); $data['groups'][0]['component_key'] = QuoteFixtures::digest( 'other component' );
		$this->expectException( \InvalidArgumentException::class );
		FixedPromotionEligibilityFamily::subject_for_component( QuoteFixtures::context(), QuoteTerms::from_array( $data ), QuoteFixtures::digest( 'component' ), 'fixture_fixed' );
	}

	public function test_reference_binds_merchant_selection_but_not_unrelated_draft_or_observation_revisions(): void {
		$reference = FixedPromotionEligibilityReference::from_candidate( self::candidate() );
		$after_draft = self::candidate( logical_edits: [ 'revision' => 8, 'last_version_sequence' => 2, 'draft_version_id' => 21 ], version_edits: [ 'row_revision' => 7 ] );
		self::assertSame( $reference->digest(), FixedPromotionEligibilityReference::from_candidate( $after_draft )->digest() );
		self::assertSame( self::payload()['budget_account_key'], $reference->account_key() );
		self::assertSame( FixedDeliveryPromotion::from_array( self::payload()['rule'] )->digest(), $reference->rule()->digest() );
		self::assertSame( $reference->digest(), hash( 'sha256', 'cetech-fixed-promotion-eligibility-reference-v1:' . $reference->to_private_json() ) );
		self::assertArrayNotHasKey( 'revision', $reference->private_facts() ); self::assertArrayNotHasKey( 'observed_at', $reference->private_facts() );
		$export = $reference->private_facts(); $export['budget_account_key'] = str_repeat( 'f', 64 );
		self::assertSame( self::payload()['budget_account_key'], $reference->account_key() );
		$changed = [ self::candidate( scope_edits: [ 'product_ids' => [ 10, 11 ] ] ), self::candidate( payload_edits: [ 'budget_account_key' => str_repeat( 'b', 64 ) ] ), self::candidate( version_edits: [ 'priority' => 1 ] ), self::candidate( version_edits: [ 'version_uuid' => '33333333-3333-4333-8333-333333333333' ] ), self::candidate( logical_edits: [ 'logical_uuid' => '44444444-4444-4444-8444-444444444444' ] ) ];
		$payload = self::payload(); ++$payload['rule']['funding_source']['version']; $changed[] = self::candidate( payload_edits: $payload );
		foreach ( $changed as $candidate ) { self::assertNotSame( $reference->digest(), FixedPromotionEligibilityReference::from_candidate( $candidate )->digest() ); }
	}

	#[DataProvider( 'invalid_references' )]
	public function test_reference_refuses_noncurrent_preview_and_scope_rule_substitution( string $kind ): void {
		$candidate = match ( $kind ) {
			'noncurrent' => self::candidate( logical_edits: [ 'current_published_version_id' => 99 ] ),
			'preview' => self::candidate( hypothetical: true ),
			'code' => self::candidate( scope_edits: [ 'promotion_code' => 'different_campaign' ] ),
			'currency' => self::candidate( scope_edits: [ 'currency' => 'USD' ] ),
			default => self::candidate( version_edits: [ 'state' => 'retired', 'retired_at' => '2026-10-21 10:30:00.000000', 'updated_at' => '2026-10-21 10:30:00.000000' ] ),
		};
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionEligibilityReference::from_candidate( $candidate );
	}
	public static function invalid_references(): array { return [ [ 'noncurrent' ], [ 'preview' ], [ 'code' ], [ 'currency' ], [ 'retired' ] ]; }

	public function test_reference_implicit_export_refuses_and_debug_is_redacted(): void {
		$reference = FixedPromotionEligibilityReference::from_candidate( self::candidate() );
		self::assertSame( [ 'private_eligibility_reference' => 'redacted' ], $reference->__debugInfo() );
		$this->expectException( \LogicException::class ); json_encode( $reference, JSON_THROW_ON_ERROR );
	}

	private static function family(): FixedPromotionEligibilityFamily { return new FixedPromotionEligibilityFamily( static fn(): bool => true, static fn(): bool => true ); }
	private static function scope( array $edits = [] ): array { return array_replace( [ 'promotion_code' => 'fixture_fixed', 'offer_id' => 20, 'destination_zone_id' => 50, 'currency' => 'GHS', 'product_ids' => [ 10 ] ], $edits ); }
	private static function payload(): array { return [ 'budget_account_key' => str_repeat( 'a', 64 ), 'rule' => FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'fixture_fixed', 'version' => 1, 'discount' => [ 'amount' => '3.00', 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'merchant_fixture', 'version' => 1 ] ] )->private_facts() ]; }
	private static function candidate( array $scope_edits = [], array $payload_edits = [], array $logical_edits = [], array $version_edits = [], bool $hypothetical = false ): RuleCandidate {
		$family = self::family(); $scope = self::scope( $scope_edits ); $payload = array_replace( self::payload(), $payload_edits );
		$guard = RuleFamilyGuard::from_row( [ 'id' => 1, 'site_id' => 1, 'family_code' => $family->family(), 'family_format' => 1, 'policy_hash' => $family->policy_hash(), 'revision' => 10, 'created_at' => '2026-10-21 08:00:00.000000', 'updated_at' => '2026-10-21 12:00:00.000000' ], $family );
		$logical = LogicalRule::from_row( array_replace( [ 'id' => 10, 'site_id' => 1, 'family_guard_id' => 1, 'logical_uuid' => '11111111-1111-4111-8111-111111111111', 'scope_format' => 1, 'scope_json' => $family->scope_schema()->encode( $scope, 4096 ), 'scope_hash' => RuleContent::scope_hash( $family, $scope ), 'revision' => 3, 'last_version_sequence' => 1, 'current_published_version_id' => 20, 'draft_version_id' => null, 'scheduled_version_id' => null, 'created_at' => '2026-10-21 09:00:00.000000', 'updated_at' => '2026-10-21 12:00:00.000000' ], $logical_edits ), $guard, $family );
		$row = array_replace( [ 'id' => 20, 'site_id' => 1, 'logical_rule_id' => 10, 'version_uuid' => '22222222-2222-4222-8222-222222222222', 'version_sequence' => 1, 'row_revision' => 3, 'state' => 'published', 'payload_format' => 1, 'payload_json' => $family->payload_schema()->encode( $payload ), 'content_hash' => '', 'priority' => 0, 'start_mode' => 'immediate', 'effective_from' => '2026-10-21 10:00:00.000000', 'effective_until' => '2026-10-21 11:00:00.000000', 'author_user_id' => 7, 'change_reason' => 'Owned fixture policy', 'supersedes_version_id' => null, 'scheduled_revision' => null, 'scheduled_logical_revision' => null, 'scheduled_predecessor_row_revision' => null, 'sealed_at' => '2026-10-21 09:30:00.000000', 'scheduled_at' => null, 'published_at' => '2026-10-21 10:00:00.000000', 'retired_at' => null, 'created_at' => '2026-10-21 09:00:00.000000', 'updated_at' => '2026-10-21 10:00:00.000000' ], $version_edits );
		$row['content_hash'] = RuleContent::hash( $family, $payload, RuleStartMode::from( $row['start_mode'] ), RuleTime::parse( $row['effective_from'] ), RuleTime::parse( $row['effective_until'] ), $row['priority'], $row['supersedes_version_id'] );
		$version = $hypothetical ? RuleVersion::hypothetical( $row, $logical, $family ) : RuleVersion::from_row( $row, $logical, $family );
		return new RuleCandidate( $logical, $version );
	}
}
