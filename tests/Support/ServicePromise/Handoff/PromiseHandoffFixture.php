<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff;

use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteStoredRow, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseInput, PromiseResult};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures, QuoteFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation\CalculationFixture;

require_once dirname( __DIR__, 2 ) . '/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/DeliveryQuote/LegacyQuoteProviderFixtures.php';
require_once dirname( __DIR__ ) . '/Calculation/CalculationFixture.php';

/** Pure detached contract fixtures, not native capture, source acknowledgement or host attestation. */
final class PromiseHandoffFixture {
	public static function base_context(): QuoteContext { return LegacyQuoteProviderFixtures::context(); }
	public static function base_terms(): QuoteTerms { return LegacyQuoteProviderFixtures::terms(); }
	public static function owner(): QuoteOwner { return QuoteFixtures::owner(); }
	public static function time(): QuoteTime { return QuoteTime::parse( CalculationFixture::EVALUATED ); }
	public static function input( array $policy_changes = [], ?string $accept_until = null ): PromiseInput {
		$input = CalculationFixture::input( CalculationFixture::policy( $policy_changes ), CalculationFixture::EVALUATED, null, $accept_until )->private_facts();
		$input['owner'] = self::owner()->facts(); $input['owner']['site_id'] = $input['site_id'];
		$input['material'] = [ 'group_id' => self::base_context()->private_facts()['groups'][0]['component_key'], 'material_digest' => self::base_context()->digest() ];
		return PromiseInput::from_array( $input );
	}
	public static function result( ?PromiseInput $input = null ): PromiseResult { return ( new DeterministicPromiseCalculator( CalculationFixture::runtime() ) )->calculate( $input ?? self::input(), [] ); }
	public static function packet( ?PromiseResult $result = null ): PromiseHistoricalPacket { return PromiseHistoricalPacket::capture( $result ?? self::result(), 'Standard delivery: 10:10–10:20 UTC' ); }
	public static function context( ?PromiseInput $input = null ): QuoteContext { $input ??= self::input(); return QuoteContext::from_base_promises( self::base_context(), $input->private_facts()['site_id'], [ [ 'component_key' => $input->private_facts()['material']['group_id'], 'assignment_receipt_digest' => QuoteFixtures::digest( 'assignment' ), 'policy_reference' => $input->policy()->reference()->private_facts(), 'input' => $input->to_private_json(), 'input_digest' => $input->digest() ] ] ); }
	public static function terms( ?PromiseHistoricalPacket $packet = null ): QuoteTerms { $packet ??= self::packet(); return QuoteTerms::from_base_promises( self::base_terms(), [ [ 'component_key' => $packet->input_facts()['material']['group_id'], 'packet' => $packet->private_facts() ] ] ); }
	public static function header( ?QuoteContext $context = null, ?QuoteTerms $terms = null, string $profile = 'service_promise_v1', ?QuoteTime $time = null ): QuoteHeader { $id = QuoteId::from_string( '00000000-0000-4000-8000-000000000004' ); return QuoteHeader::issue( $id, self::owner(), $context ?? self::context(), $terms ?? self::terms(), $time ?? self::time(), [ 'issue' => QuoteFixtures::digest( 'promise-issue' ), 'accept' => QuoteFixtures::digest( 'promise-accept' ), 'invalidate' => QuoteFixtures::digest( 'promise-invalidate' ) ], $profile, 1, QuoteFixtures::reference( $id ) ); }
	public static function quote( ?PromiseInput $input = null ): DeliveryQuote { $input ??= self::input(); $context = self::context( $input ); $terms = self::terms( self::packet( self::result( $input ) ) ); return DeliveryQuote::issue( self::header( $context, $terms ), $context, $terms ); }
	public static function row( string $state = 'issued' ): array { $quote = self::quote(); $header = $quote->header(); $accepted = 'accepted' === $state ? self::time()->plus_seconds( 1 )->sql() : null; return [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $header->id()->value(), 'format_version' => 2, 'profile_code' => $header->profile(), 'profile_version' => 1, 'purpose' => 'checkout', 'principal_hash' => self::owner()->facts()['principal_hash'], 'owner_digest' => self::owner()->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $quote->context()->private_facts(), 'terms' => $quote->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'], 'state' => $state, 'revision' => 'accepted' === $state ? 2 : 1, 'retention_revision' => 1, 'created_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => $accepted, 'transition_at' => $accepted ]; }
}
