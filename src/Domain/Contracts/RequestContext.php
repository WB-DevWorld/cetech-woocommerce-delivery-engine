<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Contracts;

/**
 * One attempt and its work correlation. These identifiers grant no authority
 * and are neither an idempotency key nor a stored-completion lookup contract.
 */
final readonly class RequestContext {

	private function __construct(
		public string $request_id,
		public string $correlation_id
	) {
	}

	/** Invalid caller input is replaced without being retained or echoed. */
	public static function create( mixed $correlation_id = null ): self {
		return new self(
			self::generate_identifier(),
			self::is_valid_identifier( $correlation_id ) ? $correlation_id : self::generate_identifier()
		);
	}

	/** Child work retains correlation and receives its own attempt identity. */
	public function child(): self {
		return self::create( $this->correlation_id );
	}

	public static function is_valid_identifier( mixed $identifier ): bool {
		return is_string( $identifier ) && 36 === strlen( $identifier )
			&& 1 === preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $identifier );
	}

	private static function generate_identifier(): string {
		try {
			$bytes = random_bytes( 16 );
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'Request identifiers could not be generated.' );
		}
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 )
			. '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
