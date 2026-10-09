<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness,OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;
use CetechDeliveryEngine\Integrations\ServicePromise\PromiseNativeServiceRegistry;

/** Protected headless configuration; required reader readiness precedes explicit profile enable. */
final class PromiseQuotePlacementActivation {
	public const OPTION = 'cetech_de_service_promise_adoption';
	public const PROFILE = 'service_promise_v1';
	public const MAX_BYTES = 65536;
	private \Closure $prerequisites;
	private \Closure $authorize;
	private OperationReadiness $readiness;
	public function __construct( private OperationConnectionFactory $factory, callable $prerequisites, ?OperationReadiness $readiness = null, ?callable $authorize = null ) {
		$this->prerequisites = \Closure::fromCallable( $prerequisites ); $this->readiness = $readiness ?? new DatabaseOperationReadiness(); $this->authorize = null === $authorize ? static fn(): bool => false : \Closure::fromCallable( $authorize );
	}
	public static function decode( ?string $bytes ): array {
		if ( null === $bytes ) { return [ 'format' => 1, 'profile' => self::PROFILE, 'enabled' => false, 'revision' => 0, 'binding' => null, 'registry' => [ 'format' => 1, 'entries' => [] ] ]; }
		$state = QuoteJson::decode( $bytes, self::MAX_BYTES ); $keys = array_keys( $state ); sort( $keys, SORT_STRING );
		if ( $keys !== [ 'binding', 'enabled', 'format', 'profile', 'registry', 'revision' ] || 1 !== $state['format'] || self::PROFILE !== $state['profile'] || ! is_bool( $state['enabled'] ) || ! is_int( $state['revision'] ) || $state['revision'] < 1 || $state['revision'] >= PHP_INT_MAX ) { throw new \InvalidArgumentException( 'Unknown promise adoption state.' ); }
		$registry = PromiseNativeServiceRegistry::from_array( $state['registry'] ); $binding = null === $state['binding'] ? null : PromiseSiteBinding::from_array( $state['binding'] );
		if ( null === $binding && ! $registry->is_empty() || $state['enabled'] && ( null === $binding || $registry->is_empty() ) || QuoteJson::encode( $state, self::MAX_BYTES ) !== $bytes ) { throw new \InvalidArgumentException( 'Incomplete promise adoption configuration.' ); }
		return $state;
	}
	public function status(): array {
		$ready = false; try { $ready = true === ( $this->prerequisites )(); } catch ( \Throwable ) {}
		try { $captured = $this->capture(); $state = self::decode( $captured['rows'][self::OPTION]['option_value'] ?? null ); $configured = null !== $state['binding'] && ! PromiseNativeServiceRegistry::from_array( $state['registry'] )->is_empty(); if ( $configured ) { if ( PromiseSiteBinding::from_array( $state['binding'] )->site_id() !== $captured['site'] ) { throw new \RuntimeException( 'Foreign promise configuration.' ); } } $ready = $ready && $configured && PromiseQuotePlacementPolicyFence::flags_ready( $captured['rows'] ); return [ ...$state, 'ready' => $ready, 'active' => $state['enabled'] && $ready, 'available' => true ]; }
		catch ( \Throwable ) { return [ 'enabled' => false, 'revision' => 0, 'ready' => false, 'active' => false, 'available' => false ]; }
	}
	public function active(): bool { return $this->status()['active']; }
	public function requested(): bool { try { return self::decode( $this->capture()['rows'][self::OPTION]['option_value'] ?? null )['enabled']; } catch ( \Throwable ) { return true; } }
	public function configuration(): ?array {
		$state = $this->status(); if ( ! $state['active'] ) { return null; }
		return [ 'binding' => PromiseSiteBinding::from_array( $state['binding'] ), 'registry' => PromiseNativeServiceRegistry::from_array( $state['registry'] ), 'revision' => $state['revision'] ];
	}
	public function fence(): PromiseQuotePlacementPolicyFence {
		if ( true !== ( $this->prerequisites )() ) { throw new \RuntimeException( 'Promise adoption is unavailable.' ); }
		$c = $this->capture(); $state = self::decode( $c['rows'][self::OPTION]['option_value'] ?? null );
		if ( ! $state['enabled'] || ! PromiseQuotePlacementPolicyFence::flags_ready( $c['rows'] ) || PromiseSiteBinding::from_array( $state['binding'] )->site_id() !== $c['site'] ) { throw new \RuntimeException( 'Promise adoption is unavailable.' ); }
		return new PromiseQuotePlacementPolicyFence( $c['site'], $c['prefix'], $c['rows'] );
	}
	/** Configuration acknowledges OFF. A separate revisioned decision is needed to enable writes. */
	public function configure( PromiseSiteBinding $binding, PromiseNativeServiceRegistry $registry, int $expected_revision, OperationIdentity $actor ): bool {
		if ( $registry->is_empty() ) { return false; }
		return $this->write( false, $expected_revision, $actor, $binding, $registry );
	}
	public function change( bool $enabled, int $expected_revision, OperationIdentity $actor ): bool { return $this->write( $enabled, $expected_revision, $actor ); }
	private function write( bool $enabled, int $expected_revision, OperationIdentity $actor, ?PromiseSiteBinding $binding = null, ?PromiseNativeServiceRegistry $registry = null ): bool {
		try {
			if ( $expected_revision < 0 || $expected_revision >= PHP_INT_MAX - 1 || $enabled && true !== ( $this->prerequisites )() ) { return false; }
			if ( null === $binding ) { $captured = self::decode( $this->capture()['rows'][self::OPTION]['option_value'] ?? null ); if ( $captured['revision'] !== $expected_revision || null === $captured['binding'] ) { return false; } $binding = PromiseSiteBinding::from_array( $captured['binding'] ); $registry = PromiseNativeServiceRegistry::from_array( $captured['registry'] ); }
			if ( null === $registry || $registry->is_empty() || $actor->site_id !== $binding->site_id() || 'service_promise.adoption' !== $actor->operation || 1 !== $actor->operation_version || true !== ( $this->authorize )( $actor, $binding, $expected_revision ) ) { return false; }
			// These immutable values form the permission grant consumed below. All
			// host capability/identity reads have completed before opening this owner.
			$grant = [ $actor->namespace_digest(), $binding->digest(), $expected_revision ];
		} catch ( \Throwable ) { return false; }
		$session = null; $begun = false; $accepted = false;
		try {
			$session = $this->factory->open(); if ( $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { return false; } $begun = true; $this->readiness->assert_ready( $session ); $table = $session->table_prefix() . 'options'; if ( ! $session->validate_tables( [ $table ] ) ) { return false; }
			$rows = PromiseQuotePlacementPolicyFence::read( $session ); $state = self::decode( $rows[self::OPTION]['option_value'] ?? null );

			if ( null === $binding || $registry->is_empty() || $state['revision'] !== $expected_revision || $actor->site_id !== $binding->site_id() || 'service_promise.adoption' !== $actor->operation || 1 !== $actor->operation_version ) { return false; } $binding->assert_session( $session );
			if ( $grant !== [ $actor->namespace_digest(), $binding->digest(), $state['revision'] ] || $enabled && ! PromiseQuotePlacementPolicyFence::flags_ready( $rows ) ) { return false; }
			$next = [ 'format' => 1, 'profile' => self::PROFILE, 'enabled' => $enabled, 'revision' => $expected_revision + 1, 'binding' => $binding->private_facts(), 'registry' => $registry->private_facts() ]; $bytes = QuoteJson::encode( $next, self::MAX_BYTES ); self::decode( $bytes );
			if ( isset( $rows[self::OPTION] ) ) { $changed = $session->query( $session->prepare( "UPDATE `{$table}` SET option_value=%s WHERE option_id=%d AND BINARY option_value=BINARY %s", $bytes, (int) $rows[self::OPTION]['option_id'], $rows[self::OPTION]['option_value'] ) ); }
			else { $changed = $session->query( $session->prepare( "INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::OPTION, $bytes ) ); }
			if ( 1 !== $changed || ( PromiseQuotePlacementPolicyFence::read( $session )[self::OPTION]['option_value'] ?? null ) !== $bytes ) { return false; }
			$commit = $session->commit(); $begun = OperationCommitResult::NotSent === $commit && $session->in_transaction(); $accepted = OperationCommitResult::Acknowledged === $commit && $session->retire();
		} catch ( \Throwable ) { $accepted = false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
		if ( $accepted && function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		return $accepted;
	}
	private function capture(): array {
		$session = $this->factory->open(); $begun = false;
		try { if ( $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { throw new \RuntimeException( 'Promise adoption is unavailable.' ); } $begun = true; $this->readiness->assert_ready( $session ); if ( ! $session->validate_tables( [ $session->table_prefix() . 'options' ] ) ) { throw new \RuntimeException( 'Promise adoption is unavailable.' ); } $result = [ 'site' => $session->site_id(), 'prefix' => $session->table_prefix(), 'rows' => PromiseQuotePlacementPolicyFence::read( $session ) ]; if ( ! $session->rollback() ) { throw new \RuntimeException( 'Promise adoption is unavailable.' ); } $begun = false; if ( ! $session->retire() ) { throw new \RuntimeException( 'Promise adoption is unavailable.' ); } return $result; }
		finally { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} }
	}
}
