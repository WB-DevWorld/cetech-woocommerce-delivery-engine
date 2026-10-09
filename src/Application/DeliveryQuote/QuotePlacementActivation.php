<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness,OperationReadiness};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory,OperationSession};
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;

/** One purpose-specific adoption switch. Existing runtime flags remain independent. */
final class QuotePlacementActivation {
	public const OPTION = 'cetech_de_quote_placement_adoption';
	public const PROFILE = 'legacy_fixed_base_v1';
	private \Closure $prerequisites;
	private OperationReadiness $readiness;
	public function __construct( private OperationConnectionFactory $factory, callable $prerequisites, ?OperationReadiness $readiness = null ) { $this->prerequisites = \Closure::fromCallable( $prerequisites ); $this->readiness = $readiness ?? new DatabaseOperationReadiness(); }
	public static function decode( ?string $bytes ): array {
		if ( null === $bytes ) { return [ 'format' => 1, 'profile' => self::PROFILE, 'enabled' => false, 'revision' => 0 ]; }
		$state = QuoteJson::decode( $bytes, 1024 );
		$keys = array_keys( $state ); sort( $keys, SORT_STRING );
		if ( $keys !== [ 'enabled', 'format', 'profile', 'revision' ] || 1 !== $state['format'] || self::PROFILE !== $state['profile'] || ! is_bool( $state['enabled'] ) || ! is_int( $state['revision'] ) || $state['revision'] < 1 || $state['revision'] >= PHP_INT_MAX ) { throw new \InvalidArgumentException( 'Unknown quote adoption state.' ); }
		return $state;
	}
	public function status(): array {
		$ready = false; try { $ready = true === ( $this->prerequisites )(); } catch ( \Throwable ) {}
		try { $captured = $this->capture(); $state = self::decode( $captured['rows'][self::OPTION]['option_value'] ?? null ); return [ ...$state, 'ready' => $ready && QuotePlacementPolicyFence::flags_ready( $captured['rows'] ), 'active' => $state['enabled'] && $ready && QuotePlacementPolicyFence::flags_ready( $captured['rows'] ), 'available' => true ]; }
		catch ( \Throwable ) { return [ 'enabled' => false, 'revision' => 0, 'ready' => false, 'active' => false, 'available' => false ]; }
	}
	public function active(): bool { return $this->status()['active']; }
	/** A requested adoption never silently falls back when a prerequisite disappears. */
	public function requested(): bool {
		try { $captured = $this->capture(); return self::decode( $captured['rows'][self::OPTION]['option_value'] ?? null )['enabled']; }
		catch ( \Throwable ) { return true; }
	}
	public function fence(): QuotePlacementPolicyFence {
		if ( true !== ( $this->prerequisites )() ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
		$captured = $this->capture();
		if ( ! QuotePlacementPolicyFence::flags_ready( $captured['rows'] ) || ! self::decode( $captured['rows'][self::OPTION]['option_value'] ?? null )['enabled'] ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
		return new QuotePlacementPolicyFence( $captured['site'], $captured['prefix'], $captured['rows'] );
	}
	/** Expected revision prevents a stale admin form from replacing a newer decision. */
	public function change( bool $enabled, int $expected_revision ): bool {
		try { if ( $expected_revision < 0 || $expected_revision >= PHP_INT_MAX - 1 || ( $enabled && true !== ( $this->prerequisites )() ) ) { return false; } } catch ( \Throwable ) { return false; }
		$session = null; $begun = false; $accepted = false;
		try {
			$session = $this->factory->open(); if ( $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { return false; } $begun = true; $this->readiness->assert_ready( $session );
			$table = $session->table_prefix() . 'options'; if ( ! $session->validate_tables( [ $table ] ) ) { return false; }
			$rows = QuotePlacementPolicyFence::read( $session ); $state = self::decode( $rows[self::OPTION]['option_value'] ?? null );
			if ( $state['revision'] !== $expected_revision || ( $enabled && ! QuotePlacementPolicyFence::flags_ready( $rows ) ) ) { return false; }
			$next = [ 'format' => 1, 'profile' => self::PROFILE, 'enabled' => $enabled, 'revision' => $expected_revision + 1 ]; $bytes = QuoteJson::encode( $next );
			if ( isset( $rows[self::OPTION] ) ) { $changed = $session->query( $session->prepare( "UPDATE `{$table}` SET option_value = %s WHERE option_id = %d AND BINARY option_value = BINARY %s", $bytes, (int) $rows[self::OPTION]['option_id'], $rows[self::OPTION]['option_value'] ) ); }
			else { $changed = $session->query( $session->prepare( "INSERT INTO `{$table}` (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::OPTION, $bytes ) ); }
			if ( 1 !== $changed || ( QuotePlacementPolicyFence::read( $session )[self::OPTION]['option_value'] ?? null ) !== $bytes ) { return false; }
			$commit = $session->commit(); $begun = OperationCommitResult::NotSent === $commit && $session->in_transaction(); $accepted = OperationCommitResult::Acknowledged === $commit && $session->retire();
		} catch ( \Throwable ) { $accepted = false; }
		finally { if ( null !== $session ) { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
		if ( $accepted && function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); }
		return $accepted;
	}
	private function capture(): array {
		$session = $this->factory->open(); $begun = false;
		try {
			if ( $session->is_retired() || $session->in_transaction() || ! $session->begin() ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); } $begun = true; $this->readiness->assert_ready( $session );
			if ( ! $session->validate_tables( [ $session->table_prefix() . 'options' ] ) ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); }
			$result = [ 'site' => $session->site_id(), 'prefix' => $session->table_prefix(), 'rows' => QuotePlacementPolicyFence::read( $session ) ];
			if ( ! $session->rollback() ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); } $begun = false;
			if ( ! $session->retire() ) { throw new \RuntimeException( 'Quote adoption is unavailable.' ); } return $result;
		} finally { if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} }
	}
}
