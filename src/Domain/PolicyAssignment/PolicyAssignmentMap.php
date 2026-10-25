<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\PolicyAssignment;

use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily;
use CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyFamily;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleRecordCodec;

/** Whole logical-policy references only; a declaration hash supplies no authority. */
final readonly class PolicyAssignmentMap implements \JsonSerializable {
	public const FAMILIES = [ 'return_policy_v1', 'refund_policy_v1' ];
	public const MAX_BYTES = 4096;
	public const MAX_OFFERS = 32;

	private function __construct( private string $bytes ) {}

	public static function from_array( int $site, string $family, array $data ): self {
		self::context( $site, $family );
		OperationJson::exact_fields( (object) $data, [ 'format', 'site_id', 'family', 'definition_hash', 'default', 'offers' ] );
		if ( 1 !== $data['format'] || $site !== $data['site_id'] || $family !== $data['family']
			|| ! is_string( $data['definition_hash'] ) || ! hash_equals( self::definition( $family ), $data['definition_hash'] )
			|| ! $data['offers'] instanceof \stdClass || \stdClass::class !== get_class( $data['offers'] )
			|| count( get_object_vars( $data['offers'] ) ) > self::MAX_OFFERS ) {
			self::invalid();
		}
		$default = self::checked_slot( $data['default'] );
		$slots = [];
		foreach ( $data['offers'] as $key => $slot ) {
			$key = (string) $key;
			if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $key ) || strlen( $key ) > strlen( (string) PHP_INT_MAX )
				|| ( strlen( $key ) === strlen( (string) PHP_INT_MAX ) && strcmp( $key, (string) PHP_INT_MAX ) > 0 ) ) {
				self::invalid();
			}
			$slots[ (int) $key ] = self::checked_slot( $slot );
		}
		uksort( $slots, static fn( int $left, int $right ): int => $left <=> $right );
		$offers = new \stdClass();
		foreach ( $slots as $key => $slot ) { $offers->{(string) $key} = (object) $slot; }
		$bytes = OperationJson::encode( (object) [
			'format' => 1, 'site_id' => $site, 'family' => $family, 'definition_hash' => self::definition( $family ),
			'default' => (object) $default, 'offers' => $offers,
		] );
		if ( strlen( $bytes ) > self::MAX_BYTES ) { self::invalid(); }
		// The stored map must also fit when escaped into its enclosing state/command.
		OperationJson::encode( (object) [ 'map' => $bytes ] );
		return new self( $bytes );
	}

	public static function from_json( int $site, string $family, string $json ): self {
		if ( strlen( $json ) > self::MAX_BYTES ) { self::invalid(); }
		$map = self::from_array( $site, $family, get_object_vars( OperationJson::decode( $json ) ) );
		if ( $map->bytes !== $json ) { self::invalid(); }
		return $map;
	}

	public static function empty( int $site, string $family ): self {
		self::context( $site, $family );
		return self::from_array( $site, $family, [
			'format' => 1, 'site_id' => $site, 'family' => $family, 'definition_hash' => self::definition( $family ),
			'default' => [ 'mode' => 'inherit' ], 'offers' => new \stdClass(),
		] );
	}

	/** Detached private facts, preserving the numeric-keyed offers object. */
	public function facts(): array {
		$data = get_object_vars( OperationJson::decode( $this->bytes ) );
		$data['default'] = get_object_vars( $data['default'] );
		foreach ( $data['offers'] as $key => $slot ) { $data['offers']->{$key} = get_object_vars( $slot ); }
		return $data;
	}
	public function json(): string { return $this->bytes; }
	public function digest(): string { return hash( 'sha256', 'cetech-policy-assignment-map-v1:' . $this->bytes ); }
	public function family(): string { return $this->facts()['family']; }
	public function site_id(): int { return $this->facts()['site_id']; }

	public function with_instruction( int $offer, array $slot ): self {
		if ( $offer < 0 ) { self::invalid(); }
		$slot = self::checked_slot( $slot );
		$data = $this->facts();
		if ( 0 === $offer ) { $data['default'] = $slot; }
		else { $data['offers']->{(string) $offer} = $slot; }
		return self::from_array( $this->site_id(), $this->family(), $data );
	}

	/** Same-scope selection only. ECR owns all inheritance across scopes. */
	public function instruction( int $real_offer ): array {
		if ( $real_offer < 1 ) { self::invalid(); }
		$data = $this->facts();
		$selected = $data['offers']->{(string) $real_offer} ?? null;
		if ( is_array( $selected ) && 'inherit' !== $selected['mode'] ) {
			return [ 'instruction' => $selected, 'slot' => $real_offer ];
		}
		return [ 'instruction' => $data['default'], 'slot' => 0 ];
	}

	public function jsonSerialize(): never { throw new \LogicException( 'Policy assignments require an explicit private projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Policy assignments require an explicit private projection.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Policy assignments require a validated factory.' ); }
	public function __debugInfo(): array { return [ 'private_policy_assignment_map' => 'redacted' ]; }

	private static function checked_slot( mixed $slot ): array {
		if ( $slot instanceof \stdClass && \stdClass::class === get_class( $slot ) ) { $slot = get_object_vars( $slot ); }
		if ( ! is_array( $slot ) || ! is_string( $slot['mode'] ?? null ) || ! in_array( $slot['mode'], [ 'inherit', 'disabled', 'assigned' ], true ) ) { self::invalid(); }
		OperationJson::exact_fields( (object) $slot, 'assigned' === $slot['mode'] ? [ 'mode', 'policy_uuid' ] : [ 'mode' ] );
		return 'assigned' === $slot['mode'] ? [ 'mode' => 'assigned', 'policy_uuid' => RuleRecordCodec::uuid( $slot['policy_uuid'] ) ] : [ 'mode' => $slot['mode'] ];
	}
	private static function definition( string $family ): string {
		return match ( $family ) {
			'return_policy_v1' => ReturnPolicyFamily::definition_hash(),
			'refund_policy_v1' => RefundPolicyFamily::definition_hash(),
			default => throw new \InvalidArgumentException( 'Unsupported policy family.' ),
		};
	}
	private static function context( int $site, string $family ): void {
		if ( $site < 1 || ! in_array( $family, self::FAMILIES, true ) ) { self::invalid(); }
	}
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid policy assignment map.' ); }
}
