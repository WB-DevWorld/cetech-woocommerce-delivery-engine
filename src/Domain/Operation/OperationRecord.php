<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;

/** Hydrated current row. Acceptance requires the exact linked same-site event. */
final readonly class OperationRecord {
	private const FIELDS = [ 'id', 'site_id', 'namespace_hash', 'intent_hash', 'namespace_format', 'intent_format', 'record_format', 'operation', 'operation_version', 'target_hash', 'state', 'publication_state', 'completion_json', 'audit_id', 'row_version', 'created_at', 'updated_at', 'completed_at' ];
	private const EVENT_FIELDS = [ 'id', 'site_id', 'operation_id', 'event_format', 'event_json', 'created_at' ];

	private function __construct(
		public int $id, public int $site_id, public string $namespace_hash, public string $intent_hash,
		public string $operation, public int $operation_version, public string $target_hash,
		public string $state, public string $publication_state, public ?int $audit_id, public int $row_version,
		public string $created_at, public string $updated_at, public ?string $completed_at,
		public ?OperationCompletion $completion, public ?OperationMaterialEvent $event
	) {}

	public static function from_row( array $row, OperationProfile $profile, ?array $event_row = null ): self {
		self::exact_fields( $row, self::FIELDS );
		$id = self::positive_integer( $row['id'] );
		$site = self::positive_integer( $row['site_id'] );
		$version = self::positive_integer( $row['operation_version'] );
		$row_version = self::positive_integer( $row['row_version'] );
		foreach ( [ 'namespace_format', 'intent_format', 'record_format' ] as $field ) {
			if ( 1 !== self::positive_integer( $row[ $field ] ) ) {
				throw new \InvalidArgumentException( 'Unsupported operation record format.' );
			}
		}
		foreach ( [ 'namespace_hash', 'intent_hash', 'target_hash' ] as $field ) {
			if ( ! is_string( $row[ $field ] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $row[ $field ] ) ) {
				throw new \InvalidArgumentException( 'Invalid operation record digest.' );
			}
		}
		if ( $row['operation'] !== $profile->operation() || $version !== $profile->version() || ! in_array( $row['state'], [ 'pending', 'accepted', 'rejected', 'not_applicable' ], true ) || ! in_array( $row['publication_state'], [ 'none', 'pending', 'published' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid operation record profile or state.' );
		}
		$created = self::timestamp( $row['created_at'] );
		$updated = self::timestamp( $row['updated_at'] );
		$completed = null === $row['completed_at'] ? null : self::timestamp( $row['completed_at'] );
		$audit = null === $row['audit_id'] ? null : self::positive_integer( $row['audit_id'] );
		$completion = null;
		$event = null;
		if ( 'pending' === $row['state'] ) {
			if ( null !== $row['completion_json'] || null !== $audit || null !== $completed || 'none' !== $row['publication_state'] || null !== $event_row ) {
				throw new \InvalidArgumentException( 'Invalid pending operation record.' );
			}
		} else {
			if ( ! is_string( $row['completion_json'] ) || null === $completed ) {
				throw new \InvalidArgumentException( 'Missing operation completion facts.' );
			}
			$completion = OperationCompletion::from_json( $row['completion_json'], $profile );
			if ( $row['state'] !== $completion->state ) {
				throw new \InvalidArgumentException( 'Operation completion state mismatch.' );
			}
			if ( 'accepted' === $row['state'] ) {
				if ( null === $audit || null === $event_row || ( null === $completion->publication ? 'none' !== $row['publication_state'] : ! in_array( $row['publication_state'], [ 'pending', 'published' ], true ) ) ) {
					throw new \InvalidArgumentException( 'Invalid accepted operation evidence.' );
				}
				self::exact_fields( $event_row, self::EVENT_FIELDS );
				if ( $audit !== self::positive_integer( $event_row['id'] ) || $site !== self::positive_integer( $event_row['site_id'] ) || $id !== self::positive_integer( $event_row['operation_id'] ) || OperationMaterialEvent::FORMAT !== self::positive_integer( $event_row['event_format'] ) || ! is_string( $event_row['event_json'] ) ) {
					throw new \InvalidArgumentException( 'Operation material event link mismatch.' );
				}
				self::timestamp( $event_row['created_at'] );
				$event = OperationMaterialEvent::from_json( $event_row['event_json'], $profile );
				if ( ! $profile->validate_accepted_facts( $completion, $event ) ) {
					throw new \InvalidArgumentException( 'Operation accepted facts are inconsistent.' );
				}
			} elseif ( null !== $audit || null !== $event_row || 'none' !== $row['publication_state'] ) {
				throw new \InvalidArgumentException( 'No-effect completion has material or publication evidence.' );
			}
		}
		return new self( $id, $site, $row['namespace_hash'], $row['intent_hash'], $row['operation'], $version, $row['target_hash'], $row['state'], $row['publication_state'], $audit, $row_version, $created, $updated, $completed, $completion, $event );
	}

	public function matches( OperationIdentity $identity, CanonicalIntent $intent ): bool {
		return $this->matches_identity( $identity ) && hash_equals( $this->intent_hash, $intent->fingerprint() );
	}

	/** An immutable identity mismatch is corruption, not a changed-command conflict. */
	public function matches_identity( OperationIdentity $identity ): bool {
		return $this->site_id === $identity->site_id && $this->operation === $identity->operation && $this->operation_version === $identity->operation_version
			&& hash_equals( $this->namespace_hash, $identity->namespace_digest() )
			&& hash_equals( $this->target_hash, hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ) );
	}

	public static function positive_integer( mixed $value ): int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		$max = (string) PHP_INT_MAX;
		if ( ! is_string( $value ) || strlen( $value ) > strlen( $max ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid operation integer.' );
		}
		if ( strlen( $value ) === strlen( $max ) && strcmp( $value, $max ) > 0 ) {
			throw new \InvalidArgumentException( 'Operation integer exceeds its supported range.' );
		}
		return (int) $value;
	}

	private static function timestamp( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid operation UTC timestamp.' );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s.u' ) !== $value || (int) $date->format( 'Y' ) < 1000 ) {
			throw new \InvalidArgumentException( 'Invalid operation UTC timestamp.' );
		}
		return $value;
	}

	private static function exact_fields( array $row, array $fields ): void {
		if ( count( $row ) !== count( $fields ) || [] !== array_diff( $fields, array_keys( $row ) ) ) {
			throw new \InvalidArgumentException( 'Operation row fields are incomplete or unknown.' );
		}
	}
}
