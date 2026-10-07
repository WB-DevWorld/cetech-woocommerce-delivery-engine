<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\EmergencyControl;

use CetechDeliveryEngine\Domain\Operation\OperationJson;

/** Exact physical bytes stay private; current absence is a virtual legacy view. */
final readonly class EmergencyControlState implements \JsonSerializable {
	public const FORMAT = 1;
	public const MAX_BYTES = 2048;
	public const STATES = [ 'enabled', 'checkout_suspended' ];
	public const REASONS = [ 'operator_pause', 'incident_pause', 'maintenance_pause', 'resume_verified' ];
	private function __construct( public int $site_id, public string $state, public int $revision, public int $row_id, public bool $initialized, public string $reason_code, public ?int $actor_user_id, public ?int $changed_at_epoch, private string $bytes ) {}

	public static function absent( int $site ): self {
		if ( $site < 1 ) { self::invalid(); }
		return new self( $site, 'enabled', 1, 0, false, 'legacy_default', null, null, '' );
	}
	public static function from_physical( int $site, int $row_id, string $json ): self {
		try {
			if ( $site < 1 || $row_id < 1 || '' === $json || strlen( $json ) > self::MAX_BYTES ) { self::invalid(); }
			// One flat object: depth 2, eight nodes including the root, no text payload.
			$shape = json_decode( $json, false, 2, JSON_THROW_ON_ERROR );
			if ( ! $shape instanceof \stdClass || count( get_object_vars( $shape ) ) + 1 > 16 ) { self::invalid(); }
			foreach ( $shape as $value ) { if ( ! is_int( $value ) && ! is_string( $value ) ) { self::invalid(); } }
			$data = OperationJson::exact_fields( OperationJson::decode( $json ), [ 'format_version', 'site_id', 'state', 'revision', 'reason_code', 'actor_user_id', 'changed_at_epoch' ] );
			if ( self::FORMAT !== $data['format_version'] || $site !== $data['site_id'] || ! is_int( $data['revision'] ) || $data['revision'] < 2 || ! is_int( $data['actor_user_id'] ) || $data['actor_user_id'] < 1 || ! is_int( $data['changed_at_epoch'] ) || $data['changed_at_epoch'] < 1 || ! is_string( $data['state'] ) || ! is_string( $data['reason_code'] ) || ! self::valid_reason( $data['state'], $data['reason_code'] ) ) { self::invalid(); }
			return new self( $site, $data['state'], $data['revision'], $row_id, true, $data['reason_code'], $data['actor_user_id'], $data['changed_at_epoch'], $json );
		} catch ( \Throwable ) { self::invalid(); }
	}
	public static function record_json( int $site, string $state, int $revision, string $reason, int $actor, int $at ): string {
		$json = OperationJson::encode( (object) [ 'format_version' => self::FORMAT, 'site_id' => $site, 'state' => $state, 'revision' => $revision, 'reason_code' => $reason, 'actor_user_id' => $actor, 'changed_at_epoch' => $at ] );
		return self::from_physical( $site, 1, $json )->original_bytes();
	}
	public static function valid_reason( string $state, string $reason ): bool { return 'enabled' === $state ? 'resume_verified' === $reason : 'checkout_suspended' === $state && in_array( $reason, [ 'operator_pause', 'incident_pause', 'maintenance_pause' ], true ); }
	public function original_bytes(): string { return $this->bytes; }
	public function enabled(): bool { return 'enabled' === $this->state; }
	public function epoch(): array { return [ 'state' => $this->state, 'revision' => $this->revision ]; }
	public function opened_preconditions(): array { return [ 'opened_row_id' => $this->row_id, 'opened_revision' => $this->revision, 'opened_bytes' => $this->bytes ]; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized emergency-control projection is required.' ); }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Emergency checkout control is unavailable.' ); }
}
