<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Isolated, explicitly owned same-site rows. No global wpdb or implicit transaction. */
final class WpdbRuleLifecycleRepository {
	private array $tables;
	public function __construct( private readonly OperationSession $session ) {
		$this->tables = [];
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { $this->tables[$suffix] = WpdbOperationRecordRepository::table_name( $session, $suffix ); }
	}
	public function table_names(): array { return array_values( $this->tables ); }
	public function accepted_time(): RuleTime {
		$row = $this->one( 'SELECT UTC_TIMESTAMP(6) AS accepted_at' );
		if ( null === $row || ! is_string( $row['accepted_at'] ?? null ) ) { throw new OperationStorageException(); }
		return RuleTime::parse( $row['accepted_at'] );
	}
	public function find_family( string $code ): ?array { return $this->family( $code, false ); }
	public function lock_family( string $code, string $policy_hash, bool $create = false ): ?array {
		$row = $this->family( $code, true );
		if ( null === $row && $create ) {
			$table = $this->tables['rule_family_guards'];
			$affected = $this->session->query( $this->session->prepare( "INSERT INTO `{$table}` (site_id,family_code,family_format,policy_hash,revision,created_at,updated_at) VALUES (%d,%s,1,%s,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", $this->session->site_id(), $code, $policy_hash ) );
			if ( 1 !== $affected && ! ( false === $affected && 1062 === $this->session->errno() ) ) { throw new OperationStorageException(); }
			$row = $this->family( $code, true );
		}
		return $row;
	}
	public function find_logical( string $uuid ): ?array { return $this->uuid_row( 'logical_rules', 'logical_uuid', $uuid, false ); }
	public function lock_logical( string $uuid ): ?array { return $this->uuid_row( 'logical_rules', 'logical_uuid', $uuid, true ); }
	public function find_version( string $uuid ): ?array { return $this->uuid_row( 'rule_versions', 'version_uuid', $uuid, false ); }
	public function lock_version( string $uuid ): ?array { return $this->uuid_row( 'rule_versions', 'version_uuid', $uuid, true ); }
	public function lock_logicals_for_family( int $guard_id, int $limit = 1001 ): array {
		$table = $this->tables['logical_rules'];
		return $this->many( $this->session->prepare( "SELECT * FROM `{$table}` WHERE site_id=%d AND family_guard_id=%d ORDER BY id ASC LIMIT %d FOR UPDATE", $this->session->site_id(), $guard_id, $this->limit( $limit, 1001 ) ) );
	}
	public function lock_versions_for_logical( int $logical_id, int $limit = 1001 ): array {
		$table = $this->tables['rule_versions'];
		return $this->many( $this->session->prepare( "SELECT * FROM `{$table}` WHERE site_id=%d AND logical_rule_id=%d ORDER BY id ASC LIMIT %d FOR UPDATE", $this->session->site_id(), $logical_id, $this->limit( $limit, 1001 ) ) );
	}
	public function lock_versions_for_family( int $guard_id, int $limit = 1003, ?string $target_uuid = null, ?string $predecessor_uuid = null ): array {
		$v = $this->tables['rule_versions']; $l = $this->tables['logical_rules'];
		$args = [ $this->session->site_id(), $guard_id ]; $extra = '';
		foreach ( [ $target_uuid, $predecessor_uuid ] as $uuid ) { if ( null !== $uuid ) { $extra .= ' OR v.version_uuid=%s'; $args[] = $uuid; } }
		$args[] = $this->limit( $limit, 1003 );
		return $this->many( $this->session->prepare( "SELECT v.* FROM `{$v}` v INNER JOIN `{$l}` l ON l.id=v.logical_rule_id AND l.site_id=v.site_id WHERE v.site_id=%d AND l.family_guard_id=%d AND (v.state IN ('published','scheduled'){$extra}) ORDER BY v.id ASC LIMIT %d FOR UPDATE", ...$args ) );
	}
	/** One joined read, so guard/heads/versions never mix different read generations. */
	public function snapshot_family( string $code, int $limit = 1001 ): array {
		$limit = $this->limit( $limit, 1001 );
		$f = $this->tables['rule_family_guards']; $l = $this->tables['logical_rules']; $v = $this->tables['rule_versions'];
		$select = $this->aliases( 'rule_family_guards', 'f', 'f' ) . ',' . $this->aliases( 'logical_rules', 'l', 'l' ) . ',' . $this->aliases( 'rule_versions', 'v', 'v' ) . ',' . $this->aliases( 'rule_versions', 'p', 'p' );
		$rows = $this->many( $this->session->prepare( "SELECT {$select} FROM `{$f}` f LEFT JOIN `{$l}` l ON l.site_id=f.site_id AND l.family_guard_id=f.id AND (l.current_published_version_id IS NOT NULL OR l.draft_version_id IS NOT NULL OR l.scheduled_version_id IS NOT NULL OR l.id IN (SELECT a.logical_rule_id FROM `{$v}` a WHERE a.site_id=f.site_id AND a.logical_rule_id=l.id AND a.state IN ('draft','published','scheduled'))) LEFT JOIN `{$v}` v ON v.site_id=l.site_id AND v.logical_rule_id=l.id AND (v.state IN ('draft','published','scheduled') OR v.id=l.current_published_version_id OR v.id=l.draft_version_id OR v.id=l.scheduled_version_id) LEFT JOIN `{$v}` p ON p.site_id=v.site_id AND p.id=v.supersedes_version_id AND p.logical_rule_id=v.logical_rule_id WHERE f.site_id=%d AND f.family_code=%s ORDER BY l.id ASC,v.id ASC LIMIT %d", $this->session->site_id(), $code, $limit ) );
		$complete = count( $rows ) < $limit;
		$family = [] === $rows ? null : $this->unalias( $rows[0], 'rule_family_guards', 'f' );
		$logicals = []; $versions = [];
		foreach ( array_slice( $rows, 0, max( 0, $limit - 1 ) ) as $row ) {
			$logical = $this->unalias( $row, 'logical_rules', 'l' ); $version = $this->unalias( $row, 'rule_versions', 'v' ); $prior = $this->unalias( $row, 'rule_versions', 'p' );
			if ( null !== $logical ) { $logicals[(string) $logical['id']] = $logical; }
			if ( null !== $version ) { $versions[(string) $version['id']] = $version; }
			if ( null !== $prior ) { $versions[(string) $prior['id']] = $prior; }
		}
		return [ 'family' => $family, 'logicals' => array_values( $logicals ), 'versions' => array_values( $versions ), 'complete' => $complete ];
	}
	public function snapshot_version( string $uuid ): ?array {
		$f = $this->tables['rule_family_guards']; $l = $this->tables['logical_rules']; $v = $this->tables['rule_versions'];
		$select = $this->aliases( 'rule_family_guards', 'f', 'f' ) . ',' . $this->aliases( 'logical_rules', 'l', 'l' ) . ',' . $this->aliases( 'rule_versions', 'v', 'v' ) . ',' . $this->aliases( 'rule_versions', 'p', 'p' );
		$row = $this->one( $this->session->prepare( "SELECT {$select} FROM `{$v}` v INNER JOIN `{$l}` l ON l.site_id=v.site_id AND l.id=v.logical_rule_id INNER JOIN `{$f}` f ON f.site_id=l.site_id AND f.id=l.family_guard_id LEFT JOIN `{$v}` p ON p.site_id=v.site_id AND p.id=v.supersedes_version_id AND p.logical_rule_id=v.logical_rule_id WHERE v.site_id=%d AND v.version_uuid=%s LIMIT 1", $this->session->site_id(), $uuid ) );
		if ( null === $row ) { return null; }
		return [ 'family' => $this->unalias( $row, 'rule_family_guards', 'f' ), 'logical' => $this->unalias( $row, 'logical_rules', 'l' ), 'version' => $this->unalias( $row, 'rule_versions', 'v' ), 'predecessor' => $this->unalias( $row, 'rule_versions', 'p' ) ];
	}
	public function max_version_id( int $guard_id ): int {
		$v = $this->tables['rule_versions']; $l = $this->tables['logical_rules'];
		$row = $this->one( $this->session->prepare( "SELECT MAX(v.id) AS ceiling FROM `{$v}` v INNER JOIN `{$l}` l ON l.id=v.logical_rule_id AND l.site_id=v.site_id WHERE v.site_id=%d AND l.family_guard_id=%d", $this->session->site_id(), $guard_id ) );
		return null === ( $row['ceiling'] ?? null ) ? 0 : WpdbOperationRecordRepository::positive_integer( $row['ceiling'] );
	}
	public function due_page( int $guard_id, int $ceiling, ?string $cursor_at, ?int $cursor_id, RuleTime $at, int $limit = 25 ): array {
		if ( $guard_id < 1 || $ceiling < 0 || ( null === $cursor_at ) !== ( null === $cursor_id ) || ( null !== $cursor_id && $cursor_id < 1 ) ) { throw new OperationStorageException(); }
		if ( null !== $cursor_at ) { RuleTime::parse( $cursor_at ); }
		$v = $this->tables['rule_versions']; $l = $this->tables['logical_rules'];
		$args = [ $this->session->site_id(), $guard_id, $ceiling, $at->sql() ];
		$cursor = '';
		if ( null !== $cursor_at ) { $cursor = ' AND (v.effective_from>%s OR (v.effective_from=%s AND v.id>%d))'; array_push( $args, $cursor_at, $cursor_at, $cursor_id ); }
		$args[] = $this->limit( $limit, 25 );
		return $this->many( $this->session->prepare( "SELECT v.* FROM `{$v}` v INNER JOIN `{$l}` l ON l.site_id=v.site_id AND l.id=v.logical_rule_id WHERE v.site_id=%d AND l.family_guard_id=%d AND v.id<=%d AND v.state='scheduled' AND v.effective_from<=%s{$cursor} ORDER BY v.effective_from ASC,v.id ASC LIMIT %d", ...$args ) );
	}
	public function insert_logical( array $values ): int { return $this->insert( 'logical_rules', $values ); }
	public function insert_version( array $values ): int { return $this->insert( 'rule_versions', $values ); }
	public function update_logical( array $locked, array $values ): void { $this->update( 'logical_rules', 'revision', $locked, $values ); }
	public function update_version( array $locked, array $values ): void { $this->update( 'rule_versions', 'row_revision', $locked, $values ); }
	public function advance_family( array $locked, RuleTime $at ): void { $this->update( 'rule_family_guards', 'revision', $locked, [ 'revision' => WpdbOperationRecordRepository::positive_integer( $locked['revision'] ?? null ) + 1, 'updated_at' => $at->sql() ] ); }
	private function family( string $code, bool $lock ): ?array { $t = $this->tables['rule_family_guards']; return $this->one( $this->session->prepare( "SELECT * FROM `{$t}` WHERE site_id=%d AND family_code=%s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' ), $this->session->site_id(), $code ) ); }
	private function uuid_row( string $suffix, string $column, string $uuid, bool $lock ): ?array { $t = $this->tables[$suffix]; return $this->one( $this->session->prepare( "SELECT * FROM `{$t}` WHERE site_id=%d AND {$column}=%s LIMIT 1" . ( $lock ? ' FOR UPDATE' : '' ), $this->session->site_id(), $uuid ) ); }
	private function insert( string $suffix, array $values ): int {
		$values = [ 'site_id' => $this->session->site_id() ] + $values;
		[ $columns, $formats, $args ] = $this->parameters( $suffix, $values );
		$t = $this->tables[$suffix];
		if ( 1 !== $this->session->query( $this->session->prepare( "INSERT INTO `{$t}` (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $formats ) . ')', ...$args ) ) || $this->session->insert_id() < 1 ) { throw new OperationStorageException(); }
		return $this->session->insert_id();
	}
	private function update( string $suffix, string $revision_column, array $locked, array $values ): void {
		$id = WpdbOperationRecordRepository::positive_integer( $locked['id'] ?? null ); $revision = WpdbOperationRecordRepository::positive_integer( $locked[$revision_column] ?? null );
		if ( PHP_INT_MAX === $revision || (string) ( $locked['site_id'] ?? '' ) !== (string) $this->session->site_id() || isset( $values['id'] ) || isset( $values['site_id'] ) || ( $values[$revision_column] ?? null ) !== $revision + 1 ) { throw new OperationStorageException(); }
		[ $columns, $formats, $args ] = $this->parameters( $suffix, $values );
		$set = []; foreach ( $columns as $i => $column ) { $set[] = $column . '=' . $formats[$i]; }
		array_push( $args, $id, $this->session->site_id(), $revision ); $t = $this->tables[$suffix];
		if ( 1 !== $this->session->query( $this->session->prepare( "UPDATE `{$t}` SET " . implode( ',', $set ) . " WHERE id=%d AND site_id=%d AND {$revision_column}=%d", ...$args ) ) ) { throw new OperationStorageException(); }
	}
	private function parameters( string $suffix, array $values ): array {
		$allowed = RuleLifecycleSchema::columns( $suffix ); $columns = []; $formats = []; $args = [];
		if ( [] === $values ) { throw new OperationStorageException(); }
		foreach ( $values as $column => $value ) {
			if ( ! is_string( $column ) || ! isset( $allowed[$column] ) || 'id' === $column || ( null !== $value && ! is_int( $value ) && ! is_string( $value ) ) ) { throw new OperationStorageException(); }
			$columns[] = $column;
			if ( null === $value ) { $formats[] = 'NULL'; } else { $formats[] = is_int( $value ) ? '%d' : '%s'; $args[] = $value; }
		}
		return [ $columns, $formats, $args ];
	}
	private function aliases( string $suffix, string $alias, string $key ): string { $fields = []; foreach ( array_keys( RuleLifecycleSchema::columns( $suffix ) ) as $column ) { $fields[] = "{$alias}.{$column} AS {$key}__{$column}"; } return implode( ',', $fields ); }
	private function unalias( array $row, string $suffix, string $key ): ?array { if ( null === ( $row[$key . '__id'] ?? null ) ) { return null; } $result = []; foreach ( array_keys( RuleLifecycleSchema::columns( $suffix ) ) as $column ) { if ( ! array_key_exists( $key . '__' . $column, $row ) ) { throw new OperationStorageException(); } $result[$column] = $row[$key . '__' . $column]; } return $result; }
	private function one( string $sql ): ?array { $this->assert_owned(); $row = $this->session->get_row( $sql ); if ( false === $row ) { throw new OperationStorageException(); } return $row; }
	private function many( string $sql ): array { $this->assert_owned(); $rows = $this->session->get_results( $sql ); if ( false === $rows ) { throw new OperationStorageException(); } return $rows; }
	private function assert_owned(): void { if ( ! $this->session->in_transaction() || $this->session->is_retired() ) { throw new OperationStorageException(); } }
	private function limit( int $limit, int $max ): int { if ( $limit < 1 || $limit > $max ) { throw new OperationStorageException(); } return $limit; }
}
