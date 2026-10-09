<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSourceReceipt;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredAssignment;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredObject;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStoredVersion;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseStorageCodec;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PersistenceFixtures.php';

final class PersistenceContractsTest extends TestCase {
	public function test_opaque_and_native_site_ids_are_explicitly_bound_without_casting(): void {
		$binding = PromiseSiteBinding::bind( 1, 'opaque-site-001' ); self::assertSame( 1, $binding->native_site_id() ); self::assertSame( 'opaque-site-001', $binding->site_key() ); self::assertSame( $binding->digest(), PromiseSiteBinding::from_json( $binding->to_private_json() )->digest() );
		$binding->assert_row( [ 'site_id' => '1', 'site_key' => 'opaque-site-001' ] ); self::assertSame( 64, strlen( PromiseSiteBinding::bind( 1, str_repeat( 's', 64 ) )->site_key() ) );
		$this->expectException( \InvalidArgumentException::class ); $binding->assert_row( [ 'site_id' => 2, 'site_key' => 'opaque-site-001' ] );
	}
	#[DataProvider( 'invalid_sites' )]
	public function test_site_binding_rejects_coercion_or_ambiguous_opaque_keys( mixed $site, mixed $key ): void {
		$this->expectException( \InvalidArgumentException::class ); PromiseSiteBinding::from_array( [ 'format_version' => 1, 'site_id' => $site, 'site_key' => $key ] );
	}
	public static function invalid_sites(): array { return [ [ '1', 'site-1' ], [ 0, 'site-1' ], [ 1, 1 ], [ 1, '' ], [ 1, str_repeat( 's', 65 ) ], [ 1, 'a b' ], [ 1, '日本' ] ]; }
	#[DataProvider( 'versions' )]
	public function test_valid_versions_preserve_exact_body_and_original_publication_after_retirement( string $kind, string $state ): void {
		$row = PersistenceFixtures::version( $kind, $state ); foreach ( [ 'id', 'site_id', 'object_id', 'format_version', 'domain_version', 'row_revision', 'author_user_id' ] as $field ) { $row[$field] = (string) $row[$field]; }
		$version = PromiseStoredVersion::from_row( $row, PromiseSiteBinding::bind( 1, 'site-1' ) ); $version->assert_parent( PromiseStoredObject::from_row( PersistenceFixtures::object( $kind, $state ) ) );
		self::assertSame( PersistenceFixtures::version( $kind, $state ), $version->row() ); self::assertSame( PersistenceFixtures::body( $kind )->to_private_json(), $version->body()->to_private_json() ); self::assertSame( $version->body()->digest(), $version->reference()->content_digest() );
		self::assertSame( in_array( $state, [ 'published', 'retired' ], true ), $version->history_available() );
		if ( 'retired' === $state ) { self::assertSame( PersistenceFixtures::receipt( $kind, 'published' )->digest(), $version->publication_receipt()->digest() ); self::assertSame( 'promise.version.retire', $version->source_receipt()->operation() ); }
		$copy = $version->row(); $copy['body_json'] = '{}'; self::assertSame( PersistenceFixtures::body( $kind )->to_private_json(), $version->row()['body_json'] );
	}
	public static function versions(): array { $cases = []; foreach ( [ 'policy', 'calendar' ] as $kind ) { foreach ( [ 'draft', 'sealed', 'published', 'retired' ] as $state ) { $cases[] = [ $kind, $state ]; } } return $cases; }
	public function test_publication_time_is_separate_from_declared_window_and_the_end_is_exclusive(): void {
		$version = PromiseStoredVersion::from_row( PersistenceFixtures::version( state: 'published' ) );
		self::assertFalse( $version->eligible_at( RuleTime::parse( PersistenceFixtures::CREATED ) ) ); self::assertTrue( $version->eligible_at( RuleTime::parse( PersistenceFixtures::PUBLISHED ) ) ); self::assertFalse( $version->eligible_at( RuleTime::parse( '2026-10-10 08:00:00.000000' ) ) );
		self::assertFalse( PromiseStoredVersion::from_row( PersistenceFixtures::version( state: 'retired' ) )->eligible_at( RuleTime::parse( PersistenceFixtures::PUBLISHED ) ) );
		self::assertSame( PersistenceFixtures::CREATED, $version->body()->private_facts()['effective_from'] );
	}
	#[DataProvider( 'corrupt_versions' )]
	public function test_corrupt_version_never_acquires_body_or_publication_authority( string $field, mixed $value ): void {
		$row = PersistenceFixtures::version( state: 'published' ); $row[$field] = $value; $this->expectException( \InvalidArgumentException::class ); PromiseStoredVersion::from_row( $row );
	}
	public static function corrupt_versions(): array {
		return [ [ 'id', true ], [ 'site_id', '01' ], [ 'object_id', '-1' ], [ 'domain_version', '1000001' ], [ 'row_revision', 1.0 ], [ 'format_version', 2 ], [ 'site_key', 'foreign' ], [ 'kind', 'unknown' ], [ 'logical_id', 'foreign' ], [ 'version_uuid', 'arbitrary' ], [ 'body_json', '{}' ], [ 'body_json', str_repeat( 'x', 32769 ) ], [ 'body_digest', str_repeat( '0', 64 ) ], [ 'declared_from', '2026-10-09 07:59:00.000000' ], [ 'declared_until', null ], [ 'sealed_at', null ], [ 'published_at', null ], [ 'retired_at', PersistenceFixtures::RETIRED ], [ 'author_user_id', 8 ], [ 'reason', 'Different reason' ], [ 'predecessor_version_id', 1 ], [ 'schedule_expected_object_revision', 1 ], [ 'source_receipt_json', str_repeat( 'x', 16385 ) ], [ 'source_receipt_digest', str_repeat( '0', 64 ) ], [ 'publication_receipt_json', null ], [ 'publication_receipt_digest', null ], [ 'create_receipt_json', null ], [ 'create_receipt_digest', str_repeat( '0', 64 ) ] ];
	}
	public function test_noncanonical_body_and_receipt_bytes_refuse_even_with_equivalent_facts(): void {
		$row = PersistenceFixtures::version(); $row['body_json'] .= ' '; $this->expectException( \InvalidArgumentException::class ); PromiseStoredVersion::from_row( $row );
	}
	public function test_wrong_parent_generation_refuses(): void {
		$head = PersistenceFixtures::object(); $head['last_sequence'] = 0; $head['draft_version_id'] = null; $head['revision'] = 1; $head['latest_version_id'] = null; $head['latest_source_receipt_digest'] = null;
		$this->expectException( \InvalidArgumentException::class ); PromiseStoredVersion::from_row( PersistenceFixtures::version() )->assert_parent( PromiseStoredObject::from_row( $head, null, true ) );
	}
	public function test_assignment_generation_and_exact_original_published_policy_link_are_validated(): void {
		$assignment = PromiseStoredAssignment::from_row( PersistenceFixtures::assignment() ); $assignment->assert_policy( PromiseStoredVersion::from_row( PersistenceFixtures::version( state: 'retired' ) ) );
		self::assertSame( 2, $assignment->revision() ); self::assertSame( 1, $assignment->generation() ); self::assertSame( PersistenceFixtures::body()->reference()->to_private_json(), $assignment->reference()->to_private_json() );
		foreach ( [ 'inherit', 'disabled' ] as $state ) { $other = PromiseStoredAssignment::from_row( PersistenceFixtures::assignment( $state ) ); self::assertNull( $other->reference() ); self::assertSame( $state, $other->state() ); }
	}
	public function test_unaccepted_assignment_baseline_requires_explicit_transaction_local_opt_in(): void {
		$row = PersistenceFixtures::assignment( baseline: true ); self::assertSame( 0, PromiseStoredAssignment::from_row( $row, null, true )->generation() );
		$this->expectException( \InvalidArgumentException::class ); PromiseStoredAssignment::from_row( $row );
	}
	#[DataProvider( 'corrupt_assignments' )]
	public function test_corrupt_scope_or_foreign_source_assignment_refuses( string $field, mixed $value ): void {
		$row = PersistenceFixtures::assignment(); $row[$field] = $value; $this->expectException( \InvalidArgumentException::class ); PromiseStoredAssignment::from_row( $row );
	}
	public static function corrupt_assignments(): array { return [ [ 'scope_id', 1 ], [ 'scope_kind', 'variation' ], [ 'service_kind', 'merchant' ], [ 'service_code', 'unknown' ], [ 'endpoint_kind', 'origin' ], [ 'revision', 1 ], [ 'generation', 2 ], [ 'policy_object_id', null ], [ 'policy_version_id', null ], [ 'policy_reference_json', null ], [ 'state', 'inherit' ], [ 'source_receipt_json', null ], [ 'source_receipt_digest', str_repeat( '0', 64 ) ], [ 'updated_at', PersistenceFixtures::PUBLISHED ] ]; }
	public function test_source_receipt_detaches_orders_set_references_and_stays_inside_its_independent_budget(): void {
		$facts = PersistenceFixtures::receipt()->private_facts(); $receipt = PromiseSourceReceipt::from_array( $facts ); $facts['reason'] = 'Changed'; self::assertSame( 'Explicit policy change', $receipt->private_facts()['reason'] );
		$wire = str_pad( $receipt->to_private_json(), 16384, ' ' ); self::assertSame( $receipt->digest(), PromiseSourceReceipt::from_json( $wire )->digest() );
		$this->expectException( \InvalidArgumentException::class ); PromiseSourceReceipt::from_json( $wire . ' ' );
	}
	public function test_unknown_receipt_fields_or_escaped_duplicates_are_not_authority(): void {
		$json = PersistenceFixtures::receipt()->to_private_json(); $json = substr( $json, 0, -1 ) . ',"\\u006bind":"version"}'; $this->expectException( \InvalidArgumentException::class ); PromiseSourceReceipt::from_json( $json );
	}
	public function test_virtual_zero_object_guard_is_refused_while_new_version_zero_is_explicit(): void {
		$facts = PersistenceFixtures::receipt()->private_facts(); self::assertSame( 0, $facts['version_before_revision'] ); self::assertSame( 1, $facts['before_revision'] ); $facts['before_revision'] = 0; $facts['after_revision'] = 1;
		$this->expectException( \InvalidArgumentException::class ); PromiseSourceReceipt::from_array( $facts );
	}
	public function test_parent_head_cannot_be_older_than_original_accepted_version_receipt(): void {
		$head = PersistenceFixtures::object( state: 'published' ); $head['revision'] = 3;
		$this->expectException( \InvalidArgumentException::class ); PromiseStoredVersion::from_row( PersistenceFixtures::version( state: 'published' ) )->assert_parent( PromiseStoredObject::from_row( $head ) );
	}
	public function test_exact_latest_object_head_guard_refuses_invented_revision(): void {
		$row = PersistenceFixtures::object( state: 'published' ); $head = PromiseStoredObject::from_row( $row ); $head->assert_latest( PromiseStoredVersion::from_row( PersistenceFixtures::version( state: 'published' ) ) ); self::assertSame( 4, $head->revision() );
		$row['revision'] = 5; $this->expectException( \InvalidArgumentException::class ); PromiseStoredObject::from_row( $row )->assert_latest( PromiseStoredVersion::from_row( PersistenceFixtures::version( state: 'published' ) ) );
	}
	public function test_object_baseline_never_becomes_readable_before_original_acknowledged_advance(): void {
		$row = PersistenceFixtures::object(); $row['revision'] = 1; $row['last_sequence'] = 0; $row['draft_version_id'] = null; $row['latest_version_id'] = null; $row['latest_source_receipt_digest'] = null;
		self::assertSame( 1, PromiseStoredObject::from_row( $row, null, true )->revision() ); $this->expectException( \InvalidArgumentException::class ); PromiseStoredObject::from_row( $row );
	}
	public function test_successor_cutover_retains_old_body_and_original_publication_receipt(): void {
		$row = PersistenceFixtures::version( state: 'retired' ); $facts = PersistenceFixtures::receipt( state: 'retired' )->private_facts(); $facts['role'] = 'superseded'; $facts['operation'] = 'promise.version.publish'; $facts['author_user_id'] = 8; $facts['reason'] = 'Publish the exact successor';
		$receipt = PromiseSourceReceipt::from_array( $facts ); $row['source_receipt_json'] = $receipt->to_private_json(); $row['source_receipt_digest'] = $receipt->digest(); $old = PromiseStoredVersion::from_row( $row );
		self::assertTrue( $old->history_available() ); self::assertSame( 7, $old->row()['author_user_id'] ); self::assertSame( PersistenceFixtures::body()->to_private_json(), $old->body()->to_private_json() ); self::assertSame( PersistenceFixtures::receipt( state: 'published' )->digest(), $old->publication_receipt()->digest() ); self::assertSame( 'superseded', $old->source_receipt()->private_facts()['role'] );
	}
	public function test_calendar_publication_set_has_canonical_order_and_measured_sixteen_reference_capacity(): void {
		$facts = PersistenceFixtures::receipt( state: 'published' )->private_facts(); $facts['site_key'] = str_repeat( 's', 64 ); $facts['reason'] = str_repeat( 'r', 256 );
		for ( $i = 0; $i < 16; ++$i ) { $facts['calendar_publications'][] = [ 'reference' => [ 'format_version' => 1, 'site_id' => $facts['site_key'], 'calendar_id' => str_pad( 'calendar-' . $i, 64, 'c' ), 'version' => 1000000, 'digest' => str_repeat( 'a', 64 ) ], 'publication_digest' => str_repeat( 'b', 64 ), 'published_at' => PersistenceFixtures::CREATED ]; }
		$receipt = PromiseSourceReceipt::from_array( $facts ); self::assertCount( 16, $receipt->private_facts()['calendar_publications'] ); self::assertLessThanOrEqual( 16384, strlen( $receipt->to_private_json() ) );
		$facts['calendar_publications'] = array_reverse( $facts['calendar_publications'] ); self::assertSame( $receipt->digest(), PromiseSourceReceipt::from_array( $facts )->digest() ); $facts['calendar_publications'][] = $facts['calendar_publications'][0];
		$this->expectException( \InvalidArgumentException::class ); PromiseSourceReceipt::from_array( $facts );
	}
	#[DataProvider( 'invalid_receipt_sources' )]
	public function test_calendar_receipt_is_exact_private_observation_not_untrusted_or_future_source( string $case ): void {
		$facts = PersistenceFixtures::receipt( state: 'published' )->private_facts(); $publication = [ 'reference' => [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'warehouse', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ], 'publication_digest' => str_repeat( 'b', 64 ), 'published_at' => PersistenceFixtures::CREATED ];
		switch ( $case ) { case 'foreign_site': $publication['reference']['site_id'] = 'foreign'; break; case 'future': $publication['published_at'] = PersistenceFixtures::RETIRED; break; case 'duplicate': $facts['calendar_publications'][] = $publication; break; case 'raw_provider': $publication['provider_token'] = 'PRIVATE_SENTINEL'; break; case 'numeric_version': $publication['reference']['version'] = '1'; break; }
		$facts['calendar_publications'][] = $publication; $this->expectException( \InvalidArgumentException::class ); PromiseSourceReceipt::from_array( $facts );
	}
	public static function invalid_receipt_sources(): array { return [ [ 'foreign_site' ], [ 'future' ], [ 'duplicate' ], [ 'raw_provider' ], [ 'numeric_version' ] ]; }
	public function test_retiring_unsealed_proposal_does_not_require_live_calendar_publication(): void {
		$row = PersistenceFixtures::version(); $body_facts = PersistenceFixtures::body()->private_facts(); $body_facts['calendars'] = [ [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'not-published', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ] ]; $body = \CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy::from_array( $body_facts );
		$row['body_json'] = $body->to_private_json(); $row['body_digest'] = $body->digest(); $create = PersistenceFixtures::receipt()->private_facts(); $create['content_digest'] = $body->digest(); $create = PromiseSourceReceipt::from_array( $create ); $row['create_receipt_json'] = $create->to_private_json(); $row['create_receipt_digest'] = $create->digest();
		$source = $create->private_facts(); $source['operation'] = 'promise.version.retire'; $source['state'] = 'retired'; $source['accepted_at'] = PersistenceFixtures::RETIRED; $source['before_revision'] = 2; $source['after_revision'] = 3; $source['version_before_revision'] = 1; $source['version_after_revision'] = 2; $source = PromiseSourceReceipt::from_array( $source );
		$row['state'] = 'retired'; $row['row_revision'] = 2; $row['retired_at'] = PersistenceFixtures::RETIRED; $row['source_receipt_json'] = $source->to_private_json(); $row['source_receipt_digest'] = $source->digest(); $retired = PromiseStoredVersion::from_row( $row );
		self::assertFalse( $retired->history_available() ); self::assertNull( $retired->publication_receipt() ); self::assertSame( [], $retired->source_receipt()->private_facts()['calendar_publications'] ); self::assertSame( $body->digest(), $retired->body()->digest() );
	}
	public function test_combined_record_budget_does_not_pass_through_the_smaller_p01_packet_codec(): void {
		PromiseStorageCodec::budget( [ 'body' => str_repeat( 'x', 98304 ) ] ); self::assertSame( 98304, PromiseStorageCodec::ROW_BYTES );
		$this->expectException( \InvalidArgumentException::class ); PromiseStorageCodec::budget( [ 'body' => str_repeat( 'x', 98305 ) ] );
	}
	#[DataProvider( 'private_values' )]
	public function test_implicit_private_carrier_serialization_refuses( string $class, string $projection ): void {
		$value = match ( $class ) { PromiseSiteBinding::class => PromiseSiteBinding::bind( 1, 'site-1' ), PromiseSourceReceipt::class => PersistenceFixtures::receipt(), PromiseStoredObject::class => PromiseStoredObject::from_row( PersistenceFixtures::object() ), PromiseStoredVersion::class => PromiseStoredVersion::from_row( PersistenceFixtures::version() ), PromiseStoredAssignment::class => PromiseStoredAssignment::from_row( PersistenceFixtures::assignment() ) };
		$this->expectException( \LogicException::class ); if ( 'json' === $projection ) { json_encode( $value, JSON_THROW_ON_ERROR ); } elseif ( 'serialize' === $projection ) { serialize( $value ); } else { unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	}
	public static function private_values(): array { $cases = []; foreach ( [ PromiseSiteBinding::class, PromiseSourceReceipt::class, PromiseStoredObject::class, PromiseStoredVersion::class, PromiseStoredAssignment::class ] as $class ) { foreach ( [ 'json', 'serialize', 'unserialize' ] as $projection ) { $cases[] = [ $class, $projection ]; } } return $cases; }
}
