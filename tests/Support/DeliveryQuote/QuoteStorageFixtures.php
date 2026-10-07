<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;

require_once __DIR__ . '/QuoteFixtures.php';

/** Synthetic canonical storage facts only; usable without tests/bootstrap.php. */
final class QuoteStorageFixtures {
	public static function quote( int $id = 1, int $site = 1, ?QuoteId $quote_id = null, string $state = 'issued' ): QuoteStoredRow {
		$quote_id ??= QuoteId::generate();
		$owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'site_id' => $site ] ) );
		$context = QuoteFixtures::context(); $terms = QuoteFixtures::terms(); $time = QuoteFixtures::time();
		$header = QuoteHeader::issue( $quote_id, $owner, $context, $terms, $time,
			[ 'issue' => QuoteFixtures::digest( 'issue:' . $quote_id->value() ), 'accept' => QuoteFixtures::digest( 'accept:' . $quote_id->value() ), 'invalidate' => QuoteFixtures::digest( 'invalidate:' . $quote_id->value() ) ],
			'fixture_v1', 1, QuoteFixtures::reference( $quote_id ) );
		$accepted = 'accepted' === $state ? $time->plus_seconds( 1 )->sql() : null;
		$stripped = 'stripped' === $state;
		return QuoteStoredRow::from_row( [
			'id' => $id, 'site_id' => $site, 'quote_uuid' => $quote_id->value(), 'format_version' => 1, 'profile_code' => $header->profile(), 'profile_version' => $header->profile_version(), 'purpose' => $header->purpose(),
			'principal_hash' => $owner->facts()['principal_hash'], 'owner_digest' => $owner->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(),
			'private_body_json' => $stripped ? null : QuoteJson::encode( [ 'context' => $context->private_facts(), 'terms' => $terms->private_facts() ] ),
			'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'],
			'state' => $state, 'revision' => 'issued' === $state ? 1 : 2, 'retention_revision' => $stripped ? 2 : 1,
			'created_at' => $time->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => $accepted,
			'transition_at' => $stripped ? $header->expires_at()->plus_seconds( 1800 )->sql() : ( 'issued' === $state ? null : $time->plus_seconds( 1 )->sql() ),
		] );
	}
	public static function binding( QuoteStoredRow $quote, int $id = 1, int $order = 100, ?string $placement_uuid = null ): QuoteBinding {
		$placement_uuid ??= QuoteId::generate()->value(); $facts = $quote->context()?->private_facts();
		if ( null === $facts || null === $quote->accepted_at() ) { throw new \InvalidArgumentException( 'Synthetic binding requires an accepted quote.' ); }
		$members = []; foreach ( $facts['lines'] as $line ) { $members[$line['line_key']] = $line; }
		$groups = []; $item = 900;
		foreach ( $facts['groups'] as $group ) {
			$lines = []; foreach ( $group['line_keys'] as $key ) { $line = $members[$key]; $lines[] = [ 'line_key' => $key, 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'parent_id' => $line['parent_id'], 'quantity' => $line['quantity'], 'item_id' => ++$item ]; }
			$groups[] = [ 'component_key' => $group['component_key'], 'lines' => $lines ];
		}
		$mapping = QuoteBinding::canonical_mapping( [ 'format_version' => 1, 'groups' => $groups ] );
		return QuoteBinding::from_row( [
			'id' => $id, 'site_id' => $quote->site_id(), 'format_version' => 1, 'quote_uuid' => $quote->header()->id()->value(), 'order_id' => $order, 'placement_uuid' => $placement_uuid,
			'managed_group_manifest_digest' => QuoteBinding::manifest_digest( $mapping ), 'mapping_json' => QuoteJson::encode( $mapping ), 'native_money_digest' => $facts['tax']['native_money_digest'], 'accepted_body_digest' => $quote->header()->body_digest(),
			'snapshot_digest' => null, 'context_digest' => null, 'bind_namespace_hash' => QuoteFixtures::digest( 'bind:' . $placement_uuid ), 'seal_namespace_hash' => QuoteFixtures::digest( 'seal:' . $placement_uuid ),
			'state' => 'prepared', 'revision' => 1, 'created_at' => $quote->accepted_at()->sql(), 'verified_at' => null, 'sealed_at' => null,
		], $quote );
	}
	public static function budget( int $id = 1, int $site = 1, string $namespace = 'admission_one', string $window = '2026-10-07 05:00:00.000000', bool $admission = true ): QuoteBudgetSlot {
		$time = QuoteFixtures::time( $window ); $namespace_hash = QuoteFixtures::digest( 'admission:' . $namespace );
		return QuoteBudgetSlot::from_row( [
			'id' => $id, 'site_id' => $site, 'format_version' => 1, 'purpose' => 'delivery_quote.issue', 'slot_kind' => $admission ? 'admission' : 'site_minute', 'slot_key' => $admission ? QuoteBudgetSlot::admission_slot_key( $namespace_hash ) : QuoteBudgetSlot::site_slot_key( $site ),
			'window_start' => $time->sql(), 'principal_hash' => $admission ? QuoteFixtures::owner()->facts()['principal_hash'] : null, 'attempt_count' => 1, 'revision' => 1, 'created_at' => $time->sql(), 'last_seen_at' => $time->sql(),
			'admission_namespace_hash' => $admission ? $namespace_hash : null, 'admission_intent_digest' => $admission ? QuoteFixtures::digest( 'intent:' . $namespace ) : null,
			'server_attempt_digest' => $admission ? QuoteFixtures::digest( 'attempt:' . $namespace ) : null, 'lease_expires_at' => $admission ? $time->plus_seconds( 60 )->sql() : null, 'lease_state' => $admission ? 'granted' : null,
			'consumed_quote_uuid' => null, 'consumed_at' => null,
		] );
	}
}
