<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Canonical UTC microseconds in the non-zero SQL DATETIME range. */
final readonly class RuleTime {
	private function __construct( private string $value, private int $microseconds ) {}

	public static function parse( string $value ): self {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid rule UTC instant.' );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s.u' ) !== $value ) {
			throw new \InvalidArgumentException( 'Invalid rule UTC instant.' );
		}
		return new self( $value, (int) $date->format( 'U' ) * 1000000 + (int) $date->format( 'u' ) );
	}

	public static function from_epoch_microseconds( int $value ): self {
		$seconds = intdiv( $value, 1000000 );
		$fraction = $value % 1000000;
		if ( $fraction < 0 ) {
			--$seconds;
			$fraction += 1000000;
		}
		try {
			$date = ( new \DateTimeImmutable( '@' . $seconds ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
			return self::parse( $date->format( 'Y-m-d H:i:s' ) . '.' . str_pad( (string) $fraction, 6, '0', STR_PAD_LEFT ) );
		} catch ( \Throwable ) {
			throw new \InvalidArgumentException( 'Invalid rule UTC instant.' );
		}
	}

	/** Explicit offset conversion; the domain never guesses a local time zone. */
	public static function from_offset( string $value ): self {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid rule offset instant.' );
		}
		$offset = substr( $value, -6 );
		if ( 'Z' !== substr( $value, -1 ) && ( (int) substr( $offset, 1, 2 ) > 14 || (int) substr( $offset, 4, 2 ) > 59 || ( 14 === (int) substr( $offset, 1, 2 ) && '00' !== substr( $offset, 4, 2 ) ) ) ) {
			throw new \InvalidArgumentException( 'Invalid rule offset instant.' );
		}
		$normalized = 'Z' === substr( $value, -1 ) ? substr( $value, 0, -1 ) . '+00:00' : $value;
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s.uP', $normalized );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) ) || $date->format( 'Y-m-d\TH:i:s.uP' ) !== $normalized ) {
			throw new \InvalidArgumentException( 'Invalid rule offset instant.' );
		}
		return self::parse( $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ) );
	}

	public function sql(): string { return $this->value; }
	public function epoch_microseconds(): int { return $this->microseconds; }
	public function compare( self $other ): int { return $this->microseconds <=> $other->microseconds; }
	public function equals( self $other ): bool { return $this->microseconds === $other->microseconds; }
}
