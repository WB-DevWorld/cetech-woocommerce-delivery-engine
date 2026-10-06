<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

/**
 * Private operation namespace. An identity never grants authority or target access.
 */
final readonly class OperationIdentity {

	public function __construct(
		public int $site_id,
		public string $authority,
		public string $principal,
		public string $operation,
		public int $operation_version,
		public string $target_key,
		private string $idempotency_key
	) {
		if ( $site_id < 1 || $operation_version < 1 ) {
			throw new \InvalidArgumentException( 'Invalid operation identity version or site.' );
		}

		self::validate_opaque_key( $authority, 128 );
		self::validate_opaque_key( $principal, 256 );
		self::validate_opaque_key( $target_key, 512 );
		self::validate_opaque_key( $idempotency_key, 256 );

		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.-]{0,95}$/D', $operation ) ) {
			throw new \InvalidArgumentException( 'Invalid operation name.' );
		}
	}

	/**
	 * A storage key only: callers must authorize before lookup or replay disclosure.
	 */
	public function namespace_digest(): string {
		$encoded = json_encode(
			[
				$this->site_id,
				$this->authority,
				$this->principal,
				$this->operation,
				$this->operation_version,
				$this->target_key,
				$this->idempotency_key,
			],
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return hash( 'sha256', 'cetech-operation-namespace-v1:' . $encoded );
	}

	private static function validate_opaque_key( string $value, int $max_bytes ): void {
		if (
			'' === $value
			|| strlen( $value ) > $max_bytes
			|| trim( $value ) !== $value
			|| 1 !== preg_match( '//u', $value )
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $value )
		) {
			throw new \InvalidArgumentException( 'Invalid operation identity key.' );
		}
	}
}
