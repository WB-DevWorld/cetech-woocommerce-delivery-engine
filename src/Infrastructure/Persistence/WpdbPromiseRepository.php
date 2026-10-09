<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseLifecycleOperationProfile;
use CetechDeliveryEngine\Domain\Operation\{OperationRecord, OperationSession};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseCalendarReference, PromiseLimits, PromisePolicyReference, PromiseShape, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseLifecycleRecordedProfile, PromiseSiteBinding, PromiseSourceReceipt, PromiseStoredAssignment, PromiseStoredObject, PromiseStoredVersion};

/** Private same-owner primitives. No transaction, connection, clock fallback or implicit retry is created here. */
final class WpdbPromiseRepository {
	private array $tables = [];
	private array $created_baselines = [];
	private array $created_objects = [];
	private bool $ready = false;
	public function __construct( private readonly OperationSession $session, private readonly PromiseSiteBinding $binding ) {
		$binding->assert_session( $session );
		foreach ( PromiseStorageSchema::SUFFIXES as $suffix ) { $this->tables[$suffix] = WpdbOperationRecordRepository::table_name( $session, $suffix ); }
	}
	public function table_names(): array { return array_values( $this->tables ); }
	public function binding(): PromiseSiteBinding { return $this->binding; }
	public function accepted_time(): RuleTime {
		$this->assert_owned(); $rows = $this->session->get_results( 'SELECT UTC_TIMESTAMP(6) AS accepted_at' );
		if ( false === $rows || 1 !== count( $rows ) || ! is_string( $rows[0]['accepted_at'] ?? null ) ) { self::fail(); }
		try { return RuleTime::parse( $rows[0]['accepted_at'] ); } catch ( \Throwable ) { self::fail(); }
	}
	public function find_object( string $kind, string $logical_id ): ?array { return $this->object( $kind, $logical_id, false ); }
	public function lock_object( string $kind, string $logical_id ): ?array { return $this->object( $kind, $logical_id, true ); }
	public function insert_object( array $values ): int {
		$row = $this->insert_row( 'promise_objects', $values, true );
		if ( 1 !== $row['revision'] || 0 !== $row['last_sequence'] || null !== $row['draft_version_id'] || null !== $row['scheduled_version_id'] || null !== $row['published_version_id'] ) { self::fail(); }
		$id = $this->insert( 'promise_objects', $row ); $this->created_objects[$id] = true; return $id;
	}
	public function update_object( array $locked, array $values ): void { $baseline = 0 === ( $locked['last_sequence'] ?? null ); if ( $baseline && ! isset( $this->created_objects[$locked['id'] ?? 0] ) ) { self::fail(); } $this->update( 'promise_objects', $locked, $values, [ 'revision', 'last_sequence', 'draft_version_id', 'scheduled_version_id', 'published_version_id', 'latest_version_id', 'latest_source_receipt_digest', 'updated_at' ], 'revision', $baseline ); }
	/** Exact last accepted head mutation, or this owner's uncommitted new baseline only. */
	public function assert_object_ack( array $object ): void {
		$this->assert_owned(); try { $head = PromiseStoredObject::from_row( $object, $this->binding, isset( $this->created_objects[$object['id'] ?? 0] ) )->row(); } catch ( \Throwable ) { self::fail(); }
		if ( 0 === $head['last_sequence'] ) { if ( ! isset( $this->created_objects[$head['id']] ) || 1 !== $head['revision'] || null !== $head['latest_version_id'] || null !== $head['latest_source_receipt_digest'] ) { self::fail(); } return; }
		$row = $this->one( $this->rows( 'promise_versions', ' WHERE site_id=%d AND site_key=%s AND id=%d LIMIT 2', [ $this->binding->native_site_id(), $this->binding->site_key(), $head['latest_version_id'] ] ) ); if ( null === $row ) { self::fail(); }
		try { $version = PromiseStoredVersion::from_row( $row, $this->binding ); $version->assert_parent( PromiseStoredObject::from_row( $head, $this->binding ) ); $receipt = $version->source_receipt(); $facts = $receipt->private_facts(); } catch ( \Throwable ) { self::fail(); }
		if ( 'primary' !== $facts['role'] || $receipt->digest() !== $head['latest_source_receipt_digest'] || $facts['after_revision'] !== $head['revision'] || $receipt->accepted_at()->sql() !== $head['updated_at'] ) { self::fail(); }
		$record = $this->accepted_receipt( $receipt, $row ); $expected = [];
		foreach ( [ 'revision', 'last_sequence', 'draft_version_id', 'scheduled_version_id', 'published_version_id', 'latest_version_id' ] as $field ) { $expected[$field] = $head[$field] ?? 0; }
		if ( ( $record->completion->result['object_head'] ?? null ) !== $expected ) { self::fail(); }
	}
	public function find_version( string $kind, string $logical_id, int $version ): ?array { return $this->version( $kind, $logical_id, $version, false ); }
	public function lock_version( string $kind, string $logical_id, int $version ): ?array { return $this->version( $kind, $logical_id, $version, true ); }
	public function insert_version( array $locked_object, array $values ): int {
		try { $parent = PromiseStoredObject::from_row( $locked_object, $this->binding, isset( $this->created_objects[$locked_object['id'] ?? 0] ) ); } catch ( \Throwable ) { self::fail(); }
		$row = $this->insert_row( 'promise_versions', $values );
		if ( 'draft' !== $row['state'] || 1 !== $row['row_revision'] || $row['domain_version'] !== $locked_object['last_sequence'] + 1 ) { self::fail(); }
		try { $version = PromiseStoredVersion::from_row( $row, $this->binding ); $version->assert_parent( PromiseStoredObject::from_row( array_replace( $parent->row(), [ 'last_sequence' => $row['domain_version'], 'revision' => $parent->revision() + 1, 'updated_at' => $row['created_at'], 'latest_version_id' => $row['id'], 'latest_source_receipt_digest' => $row['source_receipt_digest'] ] ), $this->binding ) ); } catch ( \Throwable ) { self::fail(); }
		return $this->insert( 'promise_versions', $row );
	}
	/** Bodies, identities, declaration, author, reason and original creation/publication links never change. */
	public function update_version( array $locked, array $values ): void {
		$mutable = [ 'row_revision', 'state', 'sealed_at', 'scheduled_at', 'published_at', 'retired_at', 'schedule_expected_object_revision', 'schedule_expected_published_version_id', 'publication_receipt_json', 'publication_receipt_digest', 'source_receipt_json', 'source_receipt_digest' ];
		foreach ( [ 'publication_receipt_json', 'publication_receipt_digest', 'sealed_at', 'scheduled_at', 'published_at', 'retired_at' ] as $field ) { if ( null !== ( $locked[$field] ?? null ) && array_key_exists( $field, $values ) && $values[$field] !== $locked[$field] ) { self::fail(); } }
		$this->update( 'promise_versions', $locked, $values, $mutable, 'row_revision' );
	}
	public function lock_versions_for_object( int $object_id, int $limit = 1001 ): array {
		if ( $object_id < 1 || $limit < 1 || $limit > 1001 ) { self::fail(); }
		return $this->rows( 'promise_versions', ' WHERE site_id=%d AND site_key=%s AND object_id=%d ORDER BY domain_version ASC LIMIT %d FOR UPDATE', [ $this->binding->native_site_id(), $this->binding->site_key(), $object_id, $limit ] );
	}
	/** One bounded, sorted complete request. Conflicting or foreign references refuse before SQL. */
	public function lock_exact_versions( string $kind, array $references ): array { return $this->exact_versions( $kind, $references, true ); }
	public function load_policy_versions( array $references ): array {
		$rows = $this->exact_versions( 'policy', $references, false ); $out = [];
		foreach ( $rows as $row ) {
			$version = PromiseStoredVersion::from_row( $row, $this->binding ); $this->historical_publication( $version );
			$publication = $version->publication_receipt()->private_facts(); $body = $version->body();
			if ( ! $body instanceof ServicePromisePolicy ) { self::fail(); }
			$calendar_rows = $this->exact_versions( 'calendar', $body->calendars(), false ); $captured = [];
			foreach ( $calendar_rows as $calendar_row ) {
				$calendar = PromiseStoredVersion::from_row( $calendar_row, $this->binding ); $this->historical_publication( $calendar );
				$captured[$calendar_row['logical_id']] = [ 'reference' => $calendar->reference()->private_facts(), 'publication_digest' => $calendar->publication_receipt()->digest(), 'published_at' => $calendar_row['published_at'] ];
			}
			$expected = [];
			foreach ( $publication['calendar_publications'] as $item ) { $id = $item['reference']['calendar_id']; if ( isset( $expected[$id] ) ) { self::fail(); } $expected[$id] = $item; }
			ksort( $captured, SORT_STRING ); ksort( $expected, SORT_STRING );
			if ( \CetechDeliveryEngine\Domain\ServicePromise\PromiseJson::encode( [ 'calendars' => array_values( $captured ) ] ) !== \CetechDeliveryEngine\Domain\ServicePromise\PromiseJson::encode( [ 'calendars' => array_values( $expected ) ] ) ) { self::fail(); }
			$out[] = $body;
		}
		return $out;
	}
	public function load_calendar_versions( array $references ): array {
		$out = []; foreach ( $this->exact_versions( 'calendar', $references, false ) as $row ) { $version = PromiseStoredVersion::from_row( $row, $this->binding ); $this->historical_publication( $version ); $body = $version->body(); if ( ! $body instanceof BusinessCalendarVersion ) { self::fail(); } $out[] = $body; } return $out;
	}
	public function find_assignment( array $key ): ?array { return $this->assignment( $key, false, false ); }
	/** Baseline insertion is private to this attempt and must be replaced before C03 can commit. */
	public function lock_assignment( array $key, bool $create_baseline = false ): ?array { return $this->assignment( $key, true, $create_baseline ); }
	public function insert_assignment( array $values ): int {
		$row = $this->insert_row( 'promise_assignments', $values, true );
		if ( 1 !== $row['revision'] || 0 !== $row['generation'] || 'inherit' !== $row['state'] || null !== $row['source_receipt_json'] ) { self::fail(); }
		$id = $this->insert( 'promise_assignments', $row ); $this->created_baselines[$id] = true; return $id;
	}
	public function update_assignment( array $locked, array $values ): void {
		$allow_baseline = 0 === ( $locked['generation'] ?? null ) && isset( $this->created_baselines[$locked['id'] ?? 0] );
		if ( 0 === ( $locked['generation'] ?? null ) && ! $allow_baseline ) { self::fail(); }
		$this->update( 'promise_assignments', $locked, $values, [ 'revision', 'generation', 'state', 'policy_object_id', 'policy_version_id', 'policy_reference_json', 'updated_at', 'source_receipt_json', 'source_receipt_digest' ], 'revision', $allow_baseline );
	}
	/** Original accepted C03 record and material event, with no current source dereference. */
	public function accepted_receipt( PromiseSourceReceipt $receipt, ?array $target_row = null ): OperationRecord {
		$this->assert_owned(); $facts = $receipt->private_facts();
		if ( $facts['site_id'] !== $this->binding->native_site_id() || $facts['site_key'] !== $this->binding->site_key() ) { self::fail(); }
		$records = WpdbOperationRecordRepository::table_name( $this->session, 'operation_records' ); $changes = WpdbOperationRecordRepository::table_name( $this->session, 'operation_changes' );
		$projection = $this->operation_projection( 'operation_records' );
		$rows = $this->raw_rows( $this->session->prepare( "SELECT {$projection} FROM `{$records}` WHERE site_id=%d AND namespace_hash=%s LIMIT 2", $this->binding->native_site_id(), $facts['namespace_hash'] ), [ 'completion_json' => 16384 ] );
		if ( 1 !== count( $rows ) ) { self::fail(); } $row = $rows[0];
		$events = $this->raw_rows( $this->session->prepare( 'SELECT ' . $this->operation_projection( 'operation_changes' ) . " FROM `{$changes}` WHERE site_id=%d AND operation_id=%d LIMIT 2", $this->binding->native_site_id(), WpdbOperationRecordRepository::positive_integer( $row['id'] ?? null ) ), [ 'event_json' => 16384 ] );
		if ( 1 !== count( $events ) ) { self::fail(); }
		try {
			$profile = PromiseLifecycleOperationProfile::receipt_profile( $facts['operation'] ); $record = OperationRecord::from_row( $row, $profile, $events[0] );
			$companion = 'version' === $receipt->kind() && 'superseded' === $facts['role']; $prefix = $companion ? 'predecessor_' : '';
			if ( 'accepted' !== $record->state || 'none' !== $record->publication_state || ! hash_equals( $record->intent_hash, $facts['intent_hash'] ) || ! hash_equals( $record->target_hash, $facts['target_digest'] ) || ! hash_equals( $record->completion->result[$prefix . 'source_receipt_hash'] ?? '', $receipt->digest() ) || $record->event->before_revision !== $facts['before_revision'] || $record->event->after_revision !== $facts['after_revision'] ) { self::fail(); }
			if ( null === $target_row ) {
				$result = $record->completion->result; $suffix = 'version' === $receipt->kind() ? 'promise_versions' : 'promise_assignments'; $id = WpdbOperationRecordRepository::positive_integer( $result['version' === $receipt->kind() ? $prefix . 'version_id' : 'assignment_id'] ?? null );
				$target_row = $this->one( $this->rows( $suffix, ' WHERE site_id=%d AND site_key=%s AND id=%d LIMIT 2', [ $this->binding->native_site_id(), $this->binding->site_key(), $id ] ) ); if ( null === $target_row ) { self::fail(); }
			}
			PromiseLifecycleRecordedProfile::assert_receipt( $row, $events[0], $receipt, $target_row );
			return $record;
		} catch ( \Throwable ) { self::fail(); }
	}
	private function object( string $kind, string $logical_id, bool $lock ): ?array {
		$this->identity( $kind, $logical_id ); $rows = $this->rows( 'promise_objects', ' WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), [ $this->binding->native_site_id(), $this->binding->site_key(), $kind, $logical_id ] ); return $this->one( $rows );
	}
	private function version( string $kind, string $logical_id, int $version, bool $lock ): ?array {
		$this->identity( $kind, $logical_id ); if ( $version < 1 || $version > PromiseLimits::VERSION_MAX ) { self::fail(); }
		$row = $this->one( $this->rows( 'promise_versions', ' WHERE site_id=%d AND site_key=%s AND kind=%s AND logical_id=%s AND domain_version=%d LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), [ $this->binding->native_site_id(), $this->binding->site_key(), $kind, $logical_id, $version ] ) );
		if ( null !== $row ) { $parent = $this->object( $kind, $logical_id, false ); if ( null === $parent ) { self::fail(); } try { PromiseStoredVersion::from_row( $row, $this->binding )->assert_parent( PromiseStoredObject::from_row( $parent, $this->binding ) ); } catch ( \Throwable ) { self::fail(); } } return $row;
	}
	private function exact_versions( string $kind, array $references, bool $lock ): array {
		try { PromiseShape::choice( $kind, [ 'policy', 'calendar' ] ); PromiseShape::list( $references, 0, 200 ); } catch ( \Throwable ) { self::fail(); }
		$requests = [];
		foreach ( $references as $ref ) {
			if ( ( 'policy' === $kind && ! $ref instanceof PromisePolicyReference ) || ( 'calendar' === $kind && ! $ref instanceof PromiseCalendarReference ) || $ref->site_id() !== $this->binding->site_key() ) { self::fail(); }
			$facts = $ref->private_facts(); $id = $facts[ 'policy' === $kind ? 'policy_id' : 'calendar_id' ]; $key = ':' . $id . ':' . $facts['version'];
			if ( isset( $requests[$key] ) && ! hash_equals( $requests[$key]['digest'], $facts['digest'] ) ) { self::fail(); } $requests[$key] = [ 'id' => $id, 'version' => $facts['version'], 'digest' => $facts['digest'] ];
		}
		if ( [] === $requests ) { return []; } $requests = array_values( $requests ); usort( $requests, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) ?: $a['version'] <=> $b['version'] );
		$where = []; $args = [ $this->binding->native_site_id(), $this->binding->site_key(), $kind ];
		foreach ( $requests as $request ) { $where[] = '(logical_id=%s AND domain_version=%d AND body_digest=%s)'; array_push( $args, $request['id'], $request['version'], $request['digest'] ); }
		$args[] = count( $requests ) + 1;
		$rows = $this->rows( 'promise_versions', ' WHERE site_id=%d AND site_key=%s AND kind=%s AND (' . implode( ' OR ', $where ) . ') ORDER BY logical_id ASC,domain_version ASC LIMIT %d' . ( $lock ? ' FOR UPDATE' : '' ), $args );
		if ( count( $rows ) !== count( $requests ) ) { self::fail(); }
		foreach ( $rows as $i => $row ) {
			if ( $row['logical_id'] !== $requests[$i]['id'] || $row['domain_version'] !== $requests[$i]['version'] || ! hash_equals( $row['body_digest'], $requests[$i]['digest'] ) ) { self::fail(); }
			$parent = $this->find_object( $kind, $row['logical_id'] ); if ( null === $parent ) { self::fail(); }
			foreach ( [ 'site_id', 'site_key', 'kind', 'logical_id' ] as $field ) { if ( $row[$field] !== $parent[$field] ) { self::fail(); } } if ( $row['object_id'] !== $parent['id'] ) { self::fail(); }
		}
		return $rows;
	}
	private function historical_publication( PromiseStoredVersion $version ): void {
		$row = $version->row(); $receipt = $version->publication_receipt();
		if ( ! in_array( $row['state'], [ 'published', 'retired' ], true ) || null === $row['published_at'] || null === $receipt ) { self::fail(); }
		$created = $this->accepted_receipt( $version->create_receipt(), $row ); $published = $this->accepted_receipt( $receipt, $row ); $this->accepted_receipt( $version->source_receipt(), $row );
		foreach ( [ $created, $published ] as $record ) {
			$result = $record->completion->result;
			if ( ( $result['object_id'] ?? null ) !== $row['object_id'] || ( $result['version_id'] ?? null ) !== $row['id'] || ( $result['domain_version'] ?? null ) !== $row['domain_version'] || ! hash_equals( $result['body_digest'] ?? '', $row['body_digest'] ) || ( $result['version_uuid'] ?? null ) !== $row['version_uuid'] ) { self::fail(); }
		}
	}
	private function assignment( array $key, bool $lock, bool $create ): ?array {
		try { PromiseStoredAssignment::validate_key( $key ); $this->binding->assert_row( $key ); } catch ( \Throwable ) { self::fail(); }
		$where = ' WHERE site_id=%d AND site_key=%s AND scope_kind=%s AND scope_id=%d AND service_kind=%s AND service_code=%s AND endpoint=%s AND endpoint_kind=%s LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' );
		$args = [ $this->binding->native_site_id(), $this->binding->site_key(), $key['scope_kind'], $key['scope_id'], $key['service_kind'], $key['service_code'], $key['endpoint'], $key['endpoint_kind'] ];
		$row = $this->one( $this->rows( 'promise_assignments', $where, $args ) );
		if ( null !== $row || ! $create ) { return $row; }
		$at = $this->accepted_time()->sql();
		$values = $key + [ 'revision' => 1, 'generation' => 0, 'state' => 'inherit', 'policy_object_id' => null, 'policy_version_id' => null, 'policy_reference_json' => null, 'created_at' => $at, 'updated_at' => $at, 'source_receipt_json' => null, 'source_receipt_digest' => null ];
		$id = $this->insert_assignment( $values ); return [ 'id' => $id, 'site_id' => $this->binding->native_site_id(), 'site_key' => $this->binding->site_key() ] + $values;
	}
	private function rows( string $suffix, string $tail, array $args ): array {
		$this->assert_owned(); $table = $this->tables[$suffix]; $rows = $this->raw_rows( $this->session->prepare( 'SELECT ' . $this->projection( $suffix ) . " FROM `{$table}`" . $tail, ...$args ), $this->limits( $suffix ) );
		$out = []; foreach ( $rows as $row ) { $baseline = 'promise_objects' === $suffix && isset( $this->created_objects[$row['id'] ?? 0] ); $out[] = $this->validate_row( $suffix, $row, $baseline ); } return $out;
	}
	private function raw_rows( string $sql, array $limits ): array {
		$this->assert_owned(); try { $rows = $this->session->get_results( $sql ); } catch ( \Throwable ) { self::fail(); }
		if ( false === $rows || ! array_is_list( $rows ) ) { self::fail(); }
		foreach ( $rows as &$row ) {
			if ( ! is_array( $row ) ) { self::fail(); }
			foreach ( $limits as $field => $limit ) {
				$marker = '__' . $field . '_bytes'; if ( ! array_key_exists( $marker, $row ) ) { self::fail(); } $bytes = $row[$marker];
				if ( null !== $bytes ) { try { $count = 0 === $bytes || '0' === $bytes ? 0 : WpdbOperationRecordRepository::positive_integer( $bytes ); } catch ( \Throwable ) { self::fail(); } if ( $count > $limit || ! is_string( $row[$field] ?? null ) || strlen( $row[$field] ) !== $count ) { self::fail(); } }
				elseif ( null !== ( $row[$field] ?? null ) ) { self::fail(); } unset( $row[$marker] );
			}
		} unset( $row ); return $rows;
	}
	private function validate_row( string $suffix, array $row, bool $allow_baseline = false ): array {
		try { return match ( $suffix ) { 'promise_objects' => PromiseStoredObject::from_row( $row, $this->binding, $allow_baseline )->row(), 'promise_versions' => PromiseStoredVersion::from_row( $row, $this->binding )->row(), 'promise_assignments' => PromiseStoredAssignment::from_row( $row, $this->binding, $allow_baseline )->row() }; } catch ( \Throwable ) { self::fail(); }
	}
	private function insert_row( string $suffix, array $values, bool $baseline = false ): array {
		$this->assert_owned(); if ( isset( $values['id'] ) || ( isset( $values['site_id'] ) && $values['site_id'] !== $this->binding->native_site_id() ) || ( isset( $values['site_key'] ) && $values['site_key'] !== $this->binding->site_key() ) ) { self::fail(); }
		return $this->validate_row( $suffix, [ 'id' => PHP_INT_MAX, 'site_id' => $this->binding->native_site_id(), 'site_key' => $this->binding->site_key() ] + $values, $baseline );
	}
	private function insert( string $suffix, array $row ): int {
		$this->assert_owned(); unset( $row['id'] ); [ $columns, $formats, $args ] = $this->parameters( $suffix, $row ); $table = $this->tables[$suffix];
		try { $affected = $this->session->query( $this->session->prepare( "INSERT INTO `{$table}` (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $formats ) . ')', ...$args ) ); $id = $this->session->insert_id(); } catch ( \Throwable ) { self::fail(); }
		if ( 1 !== $affected || $id < 1 ) { self::fail(); } $this->validate_row( $suffix, [ 'id' => $id ] + $row, in_array( $suffix, [ 'promise_assignments', 'promise_objects' ], true ) ); return $id;
	}
	private function update( string $suffix, array $locked, array $values, array $mutable, string $revision, bool $baseline = false ): void {
		$this->assert_owned(); $expected = $this->validate_row( $suffix, $locked, $baseline );
		if ( [] === $values || [] !== array_diff( array_keys( $values ), $mutable ) || PHP_INT_MAX === $expected[$revision] || ( $values[$revision] ?? null ) !== $expected[$revision] + 1 ) { self::fail(); }
		$next = $this->validate_row( $suffix, array_replace( $expected, $values ) );
		if ( 'promise_assignments' === $suffix && $next['generation'] !== $expected['generation'] + 1 ) { self::fail(); }
		[ $columns, $formats, $args ] = $this->parameters( $suffix, $values ); $set = [];
		foreach ( $columns as $i => $column ) { $set[] = $column . '=' . $formats[$i]; }
		$where = [];
		foreach ( $expected as $field => $value ) { if ( null === $value ) { $where[] = "`{$field}` IS NULL"; } else { $where[] = ( is_string( $value ) ? 'BINARY ' : '' ) . "`{$field}`=" . ( is_int( $value ) ? '%d' : '%s' ); $args[] = $value; } }
		$table = $this->tables[$suffix]; try { $affected = $this->session->query( $this->session->prepare( "UPDATE `{$table}` SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $where ), ...$args ) ); } catch ( \Throwable ) { self::fail(); }
		if ( 1 !== $affected ) { self::fail(); }
	}
	private function parameters( string $suffix, array $values ): array {
		$allowed = PromiseStorageSchema::columns( $suffix ); $columns = []; $formats = []; $args = [];
		foreach ( $values as $field => $value ) { if ( ! is_string( $field ) || ! isset( $allowed[$field] ) || 'id' === $field || ( null !== $value && ! is_int( $value ) && ! is_string( $value ) ) ) { self::fail(); } $columns[] = "`{$field}`"; if ( null === $value ) { $formats[] = 'NULL'; } else { $formats[] = is_int( $value ) ? '%d' : '%s'; $args[] = $value; } } return [ $columns, $formats, $args ];
	}
	private function limits( string $suffix ): array { return match ( $suffix ) { 'promise_versions' => [ 'body_json' => 32768, 'reason' => 256, 'create_receipt_json' => 16384, 'publication_receipt_json' => 16384, 'source_receipt_json' => 16384 ], 'promise_assignments' => [ 'policy_reference_json' => 4096, 'source_receipt_json' => 16384 ], default => [] }; }
	private function projection( string $suffix ): string { return $this->bounded_projection( array_keys( PromiseStorageSchema::columns( $suffix ) ), $this->limits( $suffix ) ); }
	private function operation_projection( string $suffix ): string { return $this->bounded_projection( array_keys( OperationStoreSchema::columns( $suffix ) ), 'operation_records' === $suffix ? [ 'completion_json' => 16384 ] : [ 'event_json' => 16384 ] ); }
	private function bounded_projection( array $fields, array $limits ): string {
		$out = []; foreach ( $fields as $field ) { $out[] = isset( $limits[$field] ) ? "CASE WHEN OCTET_LENGTH(`{$field}`)<={$limits[$field]} THEN `{$field}` ELSE NULL END AS `{$field}`" : "`{$field}`"; }
		foreach ( $limits as $field => $limit ) { $out[] = "OCTET_LENGTH(`{$field}`) AS `__{$field}_bytes`"; } return implode( ',', $out );
	}
	private function assert_owned(): void {
		try { $this->binding->assert_session( $this->session ); if ( $this->session->is_retired() || ! $this->session->in_transaction() ) { self::fail(); } if ( ! $this->ready ) { ( new PromiseStorageReadiness( $this->session ) )->assert_ready(); if ( ! $this->session->validate_tables( [ ...$this->table_names(), WpdbOperationRecordRepository::table_name( $this->session, 'operation_records' ), WpdbOperationRecordRepository::table_name( $this->session, 'operation_changes' ) ] ) ) { self::fail(); } $this->ready = true; } } catch ( \Throwable ) { self::fail(); }
	}
	private function identity( string $kind, string $logical_id ): void { try { PromiseShape::choice( $kind, [ 'policy', 'calendar' ] ); PromiseShape::id( $logical_id ); } catch ( \Throwable ) { self::fail(); } }
	private function one( array $rows ): ?array { if ( count( $rows ) > 1 ) { self::fail(); } return $rows[0] ?? null; }
	private static function fail(): never { throw new OperationStorageException(); }
}
