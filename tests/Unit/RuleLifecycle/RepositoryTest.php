<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRuleLifecycleRepository;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CoordinatorTest.php';

/** SQL-shape/unit protocol proofs; native ownership and locks have separate SQL proofs. */
final class RepositoryTest extends TestCase {
	private RuleUnitFactory $factory;
	private RuleProofFamily $family;
	private RuleLifecycleService $service;
	protected function setUp(): void {
		$this->family = new RuleProofFamily(); $this->factory = new RuleUnitFactory( tempnam( sys_get_temp_dir(), 'rule-repo-' ), $this->family ); $this->factory->install();
		$this->service = new RuleLifecycleService( new RuleFamilyRegistry( [ $this->family ] ), $this->factory, new RuleUnitReadiness() );
	}
	protected function tearDown(): void { foreach ( $this->factory->sessions as $session ) { $session->retire(); } unlink( $this->factory->path ); }
	public function test_unowned_read_refuses_before_query_and_does_not_create_guard(): void {
		$repo = new WpdbRuleLifecycleRepository( $this->factory->open() );
		try { $repo->snapshot_family( $this->family->family() ); self::fail( 'Unowned read must refuse.' ); } catch ( OperationStorageException ) {}
		self::assertSame( [], $this->factory->sql ); self::assertSame( 0, (int) $this->factory->inspection()->query( 'SELECT COUNT(*) FROM unit_delivery_engine_rule_family_guards' )->fetchColumn() );
	}
	public function test_coherent_current_snapshot_includes_one_retired_direct_predecessor_and_not_history_walk(): void {
		$p = $this->create( 1, 2 ); $this->attempt( 'rule.publish', $this->opened( $p ), 'publish-first' );
		$successor = RuleProofEnvelope::create( $p['logical_uuid'], RuleProofEnvelope::uuid( 3 ) ); $opened = $this->opened( $p );
		$successor['predecessor_uuid'] = $p['version_uuid']; $successor['preconditions'] = $opened['preconditions']; $successor['preconditions']['version_id'] = 0; $successor['preconditions']['version_revision'] = 0; $successor['preconditions']['predecessor_id'] = $opened['preconditions']['version_id']; $successor['preconditions']['predecessor_revision'] = $opened['preconditions']['version_revision'];
		$this->attempt( 'rule.draft.create', $successor, 'successor' ); $this->attempt( 'rule.publish', $this->opened( $successor ), 'publish-successor' );
		$session = $this->factory->open(); $session->begin(); $repo = new WpdbRuleLifecycleRepository( $session ); $before = count( $this->factory->sql );
		$rows = $repo->snapshot_family( $this->family->family() ); self::assertSame( 1, count( $this->factory->sql ) - $before ); self::assertTrue( $rows['complete'] ); self::assertCount( 2, $rows['versions'] );
		$snapshot = RuleSnapshot::from_rows( $this->family, $rows['family'], $rows['logicals'], $rows['versions'], true ); self::assertCount( 1, $snapshot->candidates() ); self::assertSame( RuleProofEnvelope::uuid( 3 ), $snapshot->candidates()[0]->version->version_uuid );
		$original = $repo->snapshot_version( $p['version_uuid'] ); self::assertSame( 'retired', $original['version']['state'] ); $session->rollback();
		$this->attempt( 'rule.retire', $this->opened( $successor ), 'retire-successor' ); $session->begin(); $empty = $repo->snapshot_family( $this->family->family() ); self::assertTrue( $empty['complete'] ); self::assertSame( [], $empty['versions'] ); self::assertSame( [], $empty['logicals'] ); $session->rollback();
	}
	public function test_orphan_current_version_is_captured_and_refused_instead_of_hidden(): void {
		$p = $this->create( 1, 2 ); $this->attempt( 'rule.publish', $this->opened( $p ), 'publish' );
		$this->factory->inspection()->exec( 'UPDATE unit_delivery_engine_logical_rules SET current_published_version_id=NULL' );
		$session = $this->factory->open(); $session->begin(); $rows = ( new WpdbRuleLifecycleRepository( $session ) )->snapshot_family( $this->family->family() ); $session->rollback(); self::assertCount( 1, $rows['versions'] );
		$this->expectException( \InvalidArgumentException::class ); RuleSnapshot::from_rows( $this->family, $rows['family'], $rows['logicals'], $rows['versions'], true );
	}
	public function test_current_candidate_bound_observes_only_one_extra_and_never_claims_complete(): void {
		for ( $i = 1; $i <= 4; ++$i ) { $this->create( $i * 2, $i * 2 + 1, RuleProofEnvelope::scope( 'product', $i ) ); }
		$session = $this->factory->open(); $session->begin(); $repo = new WpdbRuleLifecycleRepository( $session ); $rows = $repo->snapshot_family( $this->family->family(), 3 ); $session->rollback();
		self::assertFalse( $rows['complete'] ); self::assertCount( 2, $rows['versions'] ); self::assertCount( 2, $rows['logicals'] ); self::assertStringContainsString( 'LIMIT 3', end( $this->factory->sql ) );
	}
	public function test_due_page_keyset_retains_ceiling_and_requires_paired_cursor(): void {
		for ( $i = 1; $i <= 3; ++$i ) { $p = RuleProofEnvelope::create( RuleProofEnvelope::uuid( $i * 2 ), RuleProofEnvelope::uuid( $i * 2 + 1 ), RuleProofEnvelope::scope( 'product', $i ) ); $p['start_mode'] = 'at'; $p['effective_from'] = '2026-10-06 21:00:00.000000'; $p = $this->create( $i * 2, $i * 2 + 1, $p['scope'], $p ); $this->attempt( 'rule.schedule', $this->opened( $p ), 'schedule-' . $i ); }
		$session = $this->factory->open(); $session->begin(); $repo = new WpdbRuleLifecycleRepository( $session ); $first = $repo->due_page( 1, 2, null, null, RuleTime::parse( '2026-10-06 21:00:00.000000' ), 1 );
		self::assertSame( 1, (int) $first[0]['id'] ); $second = $repo->due_page( 1, 2, $first[0]['effective_from'], (int) $first[0]['id'], RuleTime::parse( '2026-10-06 21:00:00.000000' ) ); self::assertSame( [ 2 ], array_map( static fn( array $r ): int => (int) $r['id'], $second ) ); self::assertSame( 3, $repo->max_version_id( 1 ) );
		try { $repo->due_page( 1, 2, null, 1, RuleTime::parse( '2026-10-06 21:00:00.000000' ) ); self::fail( 'Unpaired cursor must refuse.' ); } catch ( OperationStorageException ) {} $session->rollback();
	}
	public function test_revision_guard_and_unknown_column_refuse_without_partial_update(): void {
		$p = $this->create( 1, 2 ); $before = $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_logical_rules' )->fetch( \PDO::FETCH_ASSOC );
		$session = $this->factory->open(); $session->begin(); $repo = new WpdbRuleLifecycleRepository( $session ); $session->validate_tables( $repo->table_names() );
		foreach ( [ [ 'revision' => 3, 'unknown_column' => 'value' ], [ 'revision' => 4 ] ] as $values ) { try { $repo->update_logical( $before, $values ); self::fail( 'Invalid update must refuse.' ); } catch ( OperationStorageException ) {} }
		$session->rollback(); self::assertSame( $before, $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_logical_rules' )->fetch( \PDO::FETCH_ASSOC ) );
	}
	private function create( int $logical, int $version, ?array $scope = null, ?array $payload = null ): array {
		$p = $payload ?? RuleProofEnvelope::create( RuleProofEnvelope::uuid( $logical ), RuleProofEnvelope::uuid( $version ), $scope ); $g = $this->factory->inspection()->query( 'SELECT revision FROM unit_delivery_engine_rule_family_guards' )->fetchColumn(); $p['preconditions']['family_revision'] = false === $g ? 0 : (int) $g; $this->attempt( 'rule.draft.create', $p, 'create-' . $version ); return $p;
	}
	private function attempt( string $action, array $p, string $token ): void { $r = $this->service->attempt( RuleProofEnvelope::identity( $p, $action, $token ), $p, RequestContext::create() ); self::assertSame( 'accepted', $r->outcome->state, $r->outcome->error?->code ?? '' ); }
	private function opened( array $p ): array {
		$db = $this->factory->inspection(); $q = $db->prepare( 'SELECT * FROM unit_delivery_engine_logical_rules WHERE logical_uuid=?' ); $q->execute( [ $p['logical_uuid'] ] ); $l = $q->fetch( \PDO::FETCH_ASSOC ); $q = $db->prepare( 'SELECT * FROM unit_delivery_engine_rule_versions WHERE version_uuid=?' ); $q->execute( [ $p['version_uuid'] ] ); $v = $q->fetch( \PDO::FETCH_ASSOC ); $g = $db->query( 'SELECT * FROM unit_delivery_engine_rule_family_guards' )->fetch( \PDO::FETCH_ASSOC );
		$prior = null === $v['supersedes_version_id'] ? null : $db->query( 'SELECT * FROM unit_delivery_engine_rule_versions WHERE id=' . (int) $v['supersedes_version_id'] )->fetch( \PDO::FETCH_ASSOC );
		return array_replace( $p, [ 'effective_from' => $v['effective_from'], 'effective_until' => $v['effective_until'], 'preconditions' => [ 'family_revision' => (int) $g['revision'], 'logical_id' => (int) $l['id'], 'logical_revision' => (int) $l['revision'], 'version_id' => (int) $v['id'], 'version_revision' => (int) $v['row_revision'], 'predecessor_id' => (int) ( $prior['id'] ?? 0 ), 'predecessor_revision' => (int) ( $prior['row_revision'] ?? 0 ) ] ] );
	}
}
