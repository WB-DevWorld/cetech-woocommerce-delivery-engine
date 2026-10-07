<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

/** Lookup-time absence/current identity, not an insertion/deletion history fence. */
final readonly class ManagedGeographyCacheTicket implements \JsonSerializable {
	private ?array $payload_value;
	public function __construct( private ManagedGeographyCacheIdentity $identity_value, private int $observed, private ?int $id = null, private ?string $generation_value = null, ?array $payload_value = null, private bool $usable = false ) {
		if ( $observed < 0 || $observed > PHP_INT_MAX - 120 || ( null !== $id && $id < 1 ) || ( null === $id ) !== ( null === $generation_value ) || ( null !== $generation_value && 1 !== preg_match( '/\A[0-9a-f]{32}\z/D', $generation_value ) ) || ( null !== $payload_value && ( ! $usable || null === $id ) ) ) { throw new \InvalidArgumentException( 'Invalid managed geography cache ticket.' ); }
		$this->payload_value = null === $payload_value ? null : ManagedGeographyCacheEnvelope::create( $identity_value, $payload_value, $observed, $generation_value )->payload();
	}
	public function identity(): ManagedGeographyCacheIdentity { return $this->identity_value; }
	public function observed_at(): int { return $this->observed; }
	public function row_id(): ?int { return $this->id; }
	public function generation(): ?string { return $this->generation_value; }
	public function payload(): ?array { return $this->payload_value; }
	public function is_usable(): bool { return $this->usable; }
	public function expires_at(): int { return $this->observed + 120; }
	public function jsonSerialize(): never { throw new \LogicException( 'Managed cache ticket requires an explicit projection.' ); }
}
