<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DataLifecycle;

use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheTicket;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory as NativeFactory;

/** Fenced replaceable cache. Any refusal leaves the caller's fresh response intact. */
final class ManagedGeographyCache {
	private OperationConnectionFactory $factory;
	private DataLifecycleOptionsStore $store;
	private bool $standard_route;
	private ?\Closure $clock;
	private \Closure $salt;
	private \Closure $cache_get;
	private \Closure $cache_set;
	private \Closure $cache_delete;
	private string $last_status = 'not_recorded';
	public function __construct( ?OperationConnectionFactory $factory = null, ?DataLifecycleOptionsStore $store = null, ?callable $clock = null, ?callable $salt = null, ?callable $cache_get = null, ?callable $cache_set = null, ?callable $cache_delete = null ) {
		$this->standard_route = null === $factory;
		$this->factory = $factory ?? new NativeFactory(); $this->store = $store ?? new DataLifecycleOptionsStore();
		$this->clock = null === $clock ? null : \Closure::fromCallable( $clock );
		$this->salt = null === $salt ? static function (): string { if ( ! function_exists( 'wp_salt' ) ) { throw new \RuntimeException( 'Managed cache is unavailable.' ); } return wp_salt( 'auth' ); } : \Closure::fromCallable( $salt );
		$this->cache_get = null === $cache_get ? static function ( string $key, string $group ): mixed { if ( ! function_exists( 'wp_cache_get' ) ) { throw new \RuntimeException( 'Managed cache is unavailable.' ); } return wp_cache_get( $key, $group ); } : \Closure::fromCallable( $cache_get );
		$this->cache_set = null === $cache_set ? static function ( string $key, array $value, string $group, int $ttl ): bool { return function_exists( 'wp_cache_set' ) && wp_cache_set( $key, $value, $group, $ttl ); } : \Closure::fromCallable( $cache_set );
		$this->cache_delete = null === $cache_delete ? static function ( string $key, string $group ): bool { return function_exists( 'wp_cache_delete' ) && wp_cache_delete( $key, $group ); } : \Closure::fromCallable( $cache_delete );
	}
	public function identity( int $site_id, string $kind, string $country, string $parent, string $query, string $extra, int $page, string $revision, string $locale ): ManagedGeographyCacheIdentity {
		try { $salt = ( $this->salt )(); if ( ! is_string( $salt ) ) { throw new \RuntimeException(); } return ManagedGeographyCacheIdentity::create( $site_id, $kind, $country, $parent, $query, $extra, $page, $revision, $locale, $salt ); }
		catch ( \Throwable ) { throw new \InvalidArgumentException( 'Managed cache identity is unavailable.' ); }
	}
	public function lookup( ManagedGeographyCacheIdentity $identity ): ManagedGeographyCacheTicket {
		$session = null; $now = 0; $this->last_status = 'miss';
		try {
			$session = $this->open( $identity ); $now = $this->now( $session );
			if ( ! $this->current_revision( $session, $identity ) ) { $this->last_status = 'publication_conflict'; return $this->finish_lookup( $session, new ManagedGeographyCacheTicket( $identity, $now ) ); }
			$row = $this->store->current_by_name( $session, $identity->option_name() );
			if ( null === $row ) { return $this->finish_lookup( $session, new ManagedGeographyCacheTicket( $identity, $now, usable: true ) ); }
			$envelope = $this->read_envelope( $row, $identity );
			if ( null === $envelope ) { $this->last_status = 'invalid_envelope'; return $this->finish_lookup( $session, new ManagedGeographyCacheTicket( $identity, $now ) ); }
			$current_time = $this->now( $session );
			$payload = null;
			if ( $envelope->valid_at( $current_time ) ) {
				try {
					$advisory = ( $this->cache_get )( $identity->digest(), ManagedGeographyCacheIdentity::CACHE_GROUP );
					$cached = $this->advisory_envelope( $advisory, $row['option_id'], $envelope, $identity, $current_time );
					$payload = ( $cached ?? $envelope )->payload(); $this->last_status = 'hit';
				} catch ( \Throwable ) { $this->last_status = 'cache_unavailable'; }
			} else { $this->last_status = 'expired'; }
			return $this->finish_lookup( $session, new ManagedGeographyCacheTicket( $identity, $now, $row['option_id'], $envelope->generation(), $payload, true ) );
		} catch ( \Throwable ) { $this->last_status = 'storage_unavailable'; $this->close_refused( $session ); return new ManagedGeographyCacheTicket( $identity, $now ); }
	}
	public function publish( ManagedGeographyCacheTicket $ticket, array $derived_payload ): bool {
		if ( ! $ticket->is_usable() ) { $this->last_status = 'storage_unavailable'; return false; }
		$session = null; $accepted = false;
		try {
			$identity = $ticket->identity();
			$envelope = ManagedGeographyCacheEnvelope::create( $identity, $derived_payload, $ticket->observed_at() );
			$session = $this->open( $identity ); $now = $this->now( $session );
			if ( ! $envelope->valid_at( $now ) || ! $this->current_revision( $session, $identity ) ) { $this->last_status = 'publication_conflict'; $this->close_refused( $session ); return false; }
			$current = $this->store->current_by_name( $session, $identity->option_name() );
			$now = $this->now( $session );
			if ( ! $envelope->valid_at( $now ) ) { $this->last_status = 'publication_conflict'; $this->close_refused( $session ); return false; }
			if ( null === $ticket->row_id() ) {
				if ( null !== $current ) { $this->last_status = 'publication_conflict'; $this->close_refused( $session ); return false; }
				$id = $this->store->insert( $session, $identity->option_name(), $envelope->to_json() );
			} else {
				$current_envelope = null === $current ? null : $this->read_envelope( $current, $identity );
				if ( null === $current_envelope || $ticket->row_id() !== $current['option_id'] || $ticket->generation() !== $current_envelope->generation() || ! $this->store->replace( $session, $current['option_id'], $identity->option_name(), $current['option_value'], $envelope->to_json() ) ) { $this->last_status = 'publication_conflict'; $this->close_refused( $session ); return false; }
				$id = $current['option_id'];
			}
			$commit = $session->commit();
			if ( OperationCommitResult::Acknowledged !== $commit ) { $this->last_status = 'storage_unavailable'; $this->close_refused( $session ); return false; }
			$accepted = true; $session->retire(); $this->last_status = 'published';
			$remaining = $ticket->expires_at() - $now;
			try {
				$invalidated = $this->store->invalidate( [ $identity->option_name() ] );
				( $this->cache_delete )( $identity->digest(), ManagedGeographyCacheIdentity::CACHE_GROUP );
				$published = ( $this->cache_set )( $identity->digest(), [ 'option_id' => $id, 'envelope' => $envelope->internal_fields() ], ManagedGeographyCacheIdentity::CACHE_GROUP, $remaining );
				if ( ! $invalidated || true !== $published ) { $this->last_status = 'publication_pending'; }
			} catch ( \Throwable ) { $this->last_status = 'publication_pending'; }
			return true;
		} catch ( \Throwable ) { $this->last_status = $accepted ? 'publication_pending' : 'storage_unavailable'; $this->close_refused( $session ); return $accepted; }
	}
	public function diagnostics(): array { return [ 'status' => $this->last_status ]; }
	private function open( ManagedGeographyCacheIdentity $identity ): OperationSession {
		$session = $this->factory->open();
		try { if ( ! $session->begin() ) { throw new \RuntimeException(); } if ( $this->standard_route ) { $this->store->assert_standard_wordpress_route( $session ); } $this->store->assert_ready( $session, $identity->site_id() ); return $session; }
		catch ( \Throwable ) { $this->close_refused( $session ); throw new \RuntimeException( 'Managed cache is unavailable.' ); }
	}
	private function now( OperationSession $session ): int { $now = null === $this->clock ? $this->store->now( $session ) : ( $this->clock )(); if ( ! is_int( $now ) || $now < 0 || $now > PHP_INT_MAX - 120 ) { throw new \RuntimeException( 'Managed cache is unavailable.' ); } return $now; }
	private function current_revision( OperationSession $session, ManagedGeographyCacheIdentity $identity ): bool { $row = $this->store->current_by_name( $session, DataLifecycleOptionsStore::REVISION_OPTION, 256 ); return ( null === $row ? '0' : $row['option_value'] ) === $identity->revision(); }
	private function read_envelope( array $row, ManagedGeographyCacheIdentity $identity ): ?ManagedGeographyCacheEnvelope {
		try { if ( ! is_string( $row['option_value'] ?? null ) || ! in_array( $row['autoload'] ?? '', [ 'off', 'no' ], true ) || $row['option_name'] !== $identity->option_name() ) { return null; } $envelope = ManagedGeographyCacheEnvelope::from_json( $row['option_value'] ); return $envelope->matches( $identity ) ? $envelope : null; }
		catch ( \Throwable ) { return null; }
	}
	private function advisory_envelope( mixed $value, int $id, ManagedGeographyCacheEnvelope $current, ManagedGeographyCacheIdentity $identity, int $now ): ?ManagedGeographyCacheEnvelope {
		try {
			if ( ! is_array( $value ) || 2 !== count( $value ) || ! array_key_exists( 'envelope', $value ) || ( $value['option_id'] ?? null ) !== $id || ! is_array( $value['envelope'] ) ) { return null; }
			$envelope = ManagedGeographyCacheEnvelope::from_array( $value['envelope'] );
			return $envelope->matches( $identity ) && $envelope->generation() === $current->generation() && $envelope->valid_at( $now ) && $envelope->to_json() === $current->to_json() ? $envelope : null;
		} catch ( \Throwable ) { return null; }
	}
	private function finish_lookup( OperationSession $session, ManagedGeographyCacheTicket $ticket ): ManagedGeographyCacheTicket { if ( ! $session->rollback() || ! $session->retire() ) { throw new \RuntimeException( 'Managed cache is unavailable.' ); } return $ticket; }
	private function close_refused( ?OperationSession $session ): void { if ( null !== $session ) { try { if ( $session->in_transaction() ) { $session->rollback(); } } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} } }
}
