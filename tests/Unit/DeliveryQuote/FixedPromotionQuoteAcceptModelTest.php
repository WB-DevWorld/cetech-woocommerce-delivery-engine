<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteReference, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** Pure compiled structure only; these fixtures grant no source, budget, checkout or payment authority. */
final class FixedPromotionQuoteAcceptModelTest extends TestCase {

	private function captured( bool $mixed = false ): array {
		$provider = $mixed ? F::mixed_provider() : F::provider(); $reference = Q::reference( QuoteId::generate() ); $header = F::header( $provider, $reference );
		return [ DeliveryQuote::issue( $header, $provider->context(), $provider->terms() ), $reference ];
	}
	private function accept( DeliveryQuote $quote, QuoteReference $reference, ?QuoteTime $at = null ): DeliveryQuote {
		$h = $quote->header(); return $quote->accept_fixed_promotion( F::owner(), $reference, $quote->context(), $at ?? Q::time()->plus_seconds( 1 ), 1, $h->body_digest(), $h->expires_at() );
	}
	private function accepted_row(): array {
		[ $quote, $reference ] = $this->captured(); $accepted = $this->accept( $quote, $reference ); $h = $quote->header(); $owner = $h->owner(); $at = $accepted->accepted_at()->sql();
		return [ 'id' => 1, 'site_id' => $owner->site_id(), 'quote_uuid' => $h->id()->value(), 'format_version' => 3, 'profile_code' => $h->profile(), 'profile_version' => 1, 'purpose' => 'checkout',
			'principal_hash' => $owner->facts()['principal_hash'], 'owner_digest' => $owner->digest(), 'material_digest' => $h->material_digest(), 'body_digest' => $h->body_digest(), 'header_json' => $h->to_private_json(),
			'private_body_json' => QuoteJson::encode( [ 'context' => $quote->context()->private_facts(), 'terms' => $quote->terms()->private_facts() ] ),
			'issue_namespace_hash' => $h->namespace_hashes()['issue'], 'accept_namespace_hash' => $h->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $h->namespace_hashes()['invalidate'],
			'state' => 'accepted', 'revision' => 2, 'retention_revision' => 1, 'created_at' => $h->created_at()->sql(), 'expires_at' => $h->expires_at()->sql(), 'accepted_at' => $at, 'transition_at' => $at ];
	}

	public function test_named_transition_preserves_the_exact_compiled_capture_and_only_adds_acceptance(): void {
		[ $quote, $reference ] = $this->captured(); $before = [ $quote->header()->to_private_json(), $quote->context()->to_private_json(), $quote->terms()->to_private_json() ];
		self::assertTrue( $quote->fixed_promotion_acceptable_at( Q::time() ) ); self::assertFalse( $quote->usable_at( Q::time() ) );
		$at = Q::time()->plus_seconds( 1 ); $accepted = $this->accept( $quote, $reference, $at );
		self::assertSame( 'issued', $quote->state() ); self::assertNull( $quote->accepted_at() ); self::assertSame( 'accepted', $accepted->state() ); self::assertSame( 2, $accepted->revision() ); self::assertSame( $at, $accepted->accepted_at() );
		self::assertSame( $before, [ $accepted->header()->to_private_json(), $accepted->context()->to_private_json(), $accepted->terms()->to_private_json() ] );
		self::assertFalse( $accepted->usable_at( $at ) ); self::assertSame( 'quote_unavailable', $accepted->reason_at( $at ) );
		$this->expectException( \DomainException::class ); $accepted->accept( F::owner(), $reference, $accepted->context(), $at, 1, $accepted->header()->body_digest(), $accepted->header()->expires_at() );
	}
	public function test_mixed_promoted_and_unadjusted_components_keep_the_original_prices(): void {
		[ $quote, $reference ] = $this->captured( true ); $accepted = $this->accept( $quote, $reference );
		self::assertCount( 2, $accepted->terms()->display_money() ); self::assertSame( $quote->terms()->to_private_json(), $accepted->terms()->to_private_json() );
		foreach ( $accepted->terms()->private_facts()['groups'] as $group ) { if ( F::second_key() === $group['component_key'] ) { self::assertSame( 'none', $group['promotion']['state'] ); self::assertSame( '12.5000', $group['final']['amount'] ); } }
	}
	public static function time_boundaries(): array { return [ 'created' => [ 'created', true ], 'before created' => [ 'before', false ], 'last microsecond' => [ 'last', true ], 'exclusive expiry' => [ 'expiry', false ] ]; }
	#[DataProvider( 'time_boundaries' )]
	public function test_original_structural_window_has_an_exclusive_deadline( string $position, bool $allowed ): void {
		[ $quote, $reference ] = $this->captured(); $h = $quote->header();
		$at = match ( $position ) { 'created' => $h->created_at(), 'before' => QuoteTime::from_epoch_microseconds( $h->created_at()->epoch_microseconds() - 1 ), 'last' => QuoteTime::from_epoch_microseconds( $h->expires_at()->epoch_microseconds() - 1 ), default => $h->expires_at() };
		self::assertSame( $allowed, $quote->fixed_promotion_acceptable_at( $at ) );
		if ( ! $allowed ) { $this->expectException( \DomainException::class ); } else { self::assertSame( 'accepted', $this->accept( $quote, $reference, $at )->state() ); return; }
		$this->accept( $quote, $reference, $at );
	}
	public static function original_mismatches(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'owner', 'reference handle', 'reference id', 'context tax', 'base context', 'revision', 'body', 'expiry' ] ); }
	#[DataProvider( 'original_mismatches' )]
	public function test_named_transition_refuses_changed_original_facts( string $case ): void {
		[ $quote, $reference ] = $this->captured(); $h = $quote->header(); $owner = F::owner(); $context = $quote->context(); $revision = 1; $body = $h->body_digest(); $expiry = $h->expires_at();
		if ( 'owner' === $case ) { $facts = $owner->facts(); $facts['session_hash'] = Q::digest( 'another session' ); $owner = QuoteOwner::from_array( $facts ); }
		elseif ( 'reference handle' === $case ) { $reference = QuoteReference::generate( $h->id() ); }
		elseif ( 'reference id' === $case ) { $reference = QuoteReference::generate( QuoteId::generate() ); }
		elseif ( 'context tax' === $case ) { $facts = $context->private_facts(); $facts['tax']['context_digest'] = Q::digest( 'new native capture' ); $context = QuoteContext::from_array( $facts ); }
		elseif ( 'base context' === $case ) { $context = $context->base_context(); }
		elseif ( 'revision' === $case ) { $revision = 2; }
		elseif ( 'body' === $case ) { $body = Q::digest( 'foreign body' ); }
		else { $expiry = $expiry->plus_seconds( 1 ); }
		$this->expectException( \DomainException::class ); $quote->accept_fixed_promotion( $owner, $reference, $context, Q::time()->plus_seconds( 1 ), $revision, $body, $expiry );
	}
	public function test_original_pure_acceptance_replay_after_expiry_retains_its_time_and_revision(): void {
		[ $quote, $reference ] = $this->captured(); $accepted = $this->accept( $quote, $reference ); $after = $quote->header()->expires_at()->plus_seconds( 600 );
		self::assertFalse( $accepted->fixed_promotion_acceptable_at( $after ) ); self::assertSame( $accepted, $this->accept( $accepted, $reference, $after ) ); self::assertSame( 2, $accepted->revision() ); self::assertSame( Q::time()->plus_seconds( 1 )->sql(), $accepted->accepted_at()->sql() ); self::assertFalse( $accepted->usable_at( $after ) );
	}
	public function test_pure_replay_cannot_precede_original_acceptance(): void {
		[ $quote, $reference ] = $this->captured(); $accepted = $this->accept( $quote, $reference ); $this->expectException( \DomainException::class ); $this->accept( $accepted, $reference, Q::time() );
	}
	public function test_legacy_and_promise_models_are_not_mistaken_for_fixed_promotion(): void {
		foreach ( [ Q::issue(), PromiseHandoffFixture::quote() ] as $quote ) { self::assertFalse( $quote->fixed_promotion_acceptable_at( $quote->header()->created_at() ) ); }
		$quote = Q::issue(); $this->expectException( \DomainException::class ); $quote->accept_fixed_promotion( Q::owner(), Q::reference( $quote->header()->id() ), $quote->context(), Q::time(), 1, $quote->header()->body_digest(), $quote->header()->expires_at() );
	}
	public static function ended_states(): array { return [ [ 'invalidated' ], [ 'stripped' ] ]; }
	#[DataProvider( 'ended_states' )]
	public function test_named_transition_cannot_revive_an_ended_model( string $state ): void {
		[ $quote, $reference ] = $this->captured(); $h = $quote->header();
		$ended = 'stripped' === $state ? $quote->strip_for_model( F::owner(), $reference, $h->expires_at()->plus_seconds( 1800 ), 1, $h->body_digest(), $h->expires_at() ) : $quote->invalidate( F::owner(), $reference, Q::context(), Q::time()->plus_seconds( 1 ), 1, $h->body_digest(), $h->expires_at() );
		self::assertFalse( $ended->fixed_promotion_acceptable_at( Q::time()->plus_seconds( 2 ) ) ); $this->expectException( \DomainException::class );
		$ended->accept_fixed_promotion( F::owner(), $reference, $quote->context(), Q::time()->plus_seconds( 2 ), 1, $h->body_digest(), $h->expires_at() );
	}
	public function test_accepted_storage_is_canonical_history_without_generic_authority(): void {
		$row = $this->accepted_row(); $stored = QuoteStoredRow::from_row( $row ); self::assertSame( $row, $stored->row() ); self::assertSame( $row, QuoteStoredRow::from_row( $stored->row() )->row() );
		self::assertSame( 3, $stored->context()->format_version() ); self::assertSame( 2, $stored->revision() ); self::assertSame( $row['accepted_at'], $stored->accepted_at()->sql() );
		self::assertFalse( DeliveryQuote::issue( $stored->header(), $stored->context(), $stored->terms() )->usable_at( $stored->accepted_at() ) );
	}
	public static function corrupt_rows(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'revision', 'retention', 'missing accepted', 'missing transition', 'different transition', 'before creation', 'at expiry', 'issued with accepted', 'later invalidated', 'stripped accepted', 'future format', 'wrong profile', 'noncanonical header', 'noncanonical body', 'extra body', 'cross context', 'changed money', 'cross owner' ] ); }
	#[DataProvider( 'corrupt_rows' )]
	public function test_structural_history_still_refuses_corrupt_or_unsupported_rows( string $case ): void {
		$row = $this->accepted_row(); $header = json_decode( $row['header_json'], true, 16, JSON_THROW_ON_ERROR ); $body = json_decode( $row['private_body_json'], true, 20, JSON_THROW_ON_ERROR );
		if ( 'revision' === $case ) { $row['revision'] = 1; }
		elseif ( 'retention' === $case ) { $row['retention_revision'] = 2; }
		elseif ( 'missing accepted' === $case ) { $row['accepted_at'] = null; }
		elseif ( 'missing transition' === $case ) { $row['transition_at'] = null; }
		elseif ( 'different transition' === $case ) { $row['transition_at'] = Q::time()->plus_seconds( 2 )->sql(); }
		elseif ( 'before creation' === $case ) { $row['accepted_at'] = Q::time()->plus_seconds( -1 )->sql(); $row['transition_at'] = $row['accepted_at']; }
		elseif ( 'at expiry' === $case ) { $row['accepted_at'] = $row['expires_at']; $row['transition_at'] = $row['accepted_at']; }
		elseif ( 'issued with accepted' === $case ) { $row['state'] = 'issued'; $row['revision'] = 1; }
		elseif ( 'later invalidated' === $case ) { $row['state'] = 'invalidated'; $row['revision'] = 3; $row['transition_at'] = Q::time()->plus_seconds( 2 )->sql(); }
		elseif ( 'stripped accepted' === $case ) { $row['state'] = 'stripped'; $row['private_body_json'] = null; $row['retention_revision'] = 2; $row['transition_at'] = QuoteTime::parse( $row['expires_at'] )->plus_seconds( 1800 )->sql(); }
		elseif ( 'future format' === $case ) { $header['format_version'] = 4; $row['format_version'] = 4; $row['header_json'] = QuoteJson::encode( $header ); }
		elseif ( 'wrong profile' === $case ) { $header['profile'] = 'legacy_fixed_base_v1'; $row['profile_code'] = $header['profile']; $row['header_json'] = QuoteJson::encode( $header ); }
		elseif ( 'noncanonical header' === $case ) { $row['header_json'] = json_encode( $header, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ); }
		elseif ( 'noncanonical body' === $case ) { $row['private_body_json'] = json_encode( $body, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR ); }
		elseif ( 'extra body' === $case ) { $body['extra'] = true; $row['private_body_json'] = QuoteJson::encode( $body ); }
		elseif ( 'cross owner' === $case ) {
			$header['owner']['session_hash'] = Q::digest( 'other original owner' ); $row['owner_digest'] = QuoteOwner::from_array( $header['owner'] )->digest(); $row['header_json'] = QuoteJson::encode( $header );
		} else {
			if ( 'cross context' === $case ) { $body['context']['tax']['context_digest'] = Q::digest( 'other frozen tax context' ); $header['material_digest'] = QuoteContext::from_array( $body['context'] )->digest(); $row['material_digest'] = $header['material_digest']; }
			else { $group = &$body['terms']['groups'][0]; $group['list']['amount'] = '13.5000'; $group['promotion']['amount']['amount'] = '4.0000'; unset( $group ); }
			$row['private_body_json'] = QuoteJson::encode( $body ); $header['body_digest'] = hash( 'sha256', 'cetech-quote-body-v3:' . $row['private_body_json'] ); $row['body_digest'] = $header['body_digest']; $row['header_json'] = QuoteJson::encode( $header );
		}
		$this->expectException( \InvalidArgumentException::class ); QuoteStoredRow::from_row( $row );
	}
	public function test_retained_accepted_storage_keeps_the_original_canonical_bytes(): void {
		foreach ( [ QuoteStorageFixtures::quote( state: 'accepted' )->row(), PromiseHandoffFixture::row( 'accepted' ) ] as $row ) { $json = QuoteJson::encode( $row ); self::assertSame( $row, QuoteStoredRow::from_row( $row )->row() ); self::assertSame( $json, QuoteJson::encode( QuoteStoredRow::from_row( $row )->row() ) ); }
	}
}
