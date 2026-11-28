<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * A promised delivery window as calendar dates in one named time zone.
 *
 * Dates, not instants: `from` and `to` are `Y-m-d` days in `timezone` (the WordPress site time
 * zone when the promise was made). The same value is shown on every surface and is what an order
 * freezes, so a later time-zone or rule change can never move a placed order's dates.
 */
final readonly class PromiseDisplay {

	public const FORMAT = 1;

	private function __construct(
		public string $from_date,
		public string $to_date,
		public string $timezone,
		public string $rule_reference
	) {
		self::assert_date( $from_date );
		self::assert_date( $to_date );
		if ( $to_date < $from_date ) {
			throw new \InvalidArgumentException( 'A promise window cannot end before it starts.' );
		}
		self::zone( $timezone );
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9:._\-]{0,190}\z/', $rule_reference ) ) {
			throw new \InvalidArgumentException( 'A promise needs a printable rule reference.' );
		}
	}

	/**
	 * @param string $rule_reference The rule and version that produced it, for staff and audit (never shown to customers).
	 */
	public static function window( string $from_date, string $to_date, string $timezone, string $rule_reference ): self {
		return new self( $from_date, $to_date, $timezone, $rule_reference );
	}

	public function is_single_day(): bool {
		return $this->from_date === $this->to_date;
	}

	public function from(): \DateTimeImmutable {
		return self::day( $this->from_date, $this->timezone );
	}

	public function to(): \DateTimeImmutable {
		return self::day( $this->to_date, $this->timezone );
	}

	/**
	 * Stable shape for snapshots and public payloads. Keys never change within FORMAT 1.
	 *
	 * @return array{format: int, from: string, to: string, timezone: string, rule: string}
	 */
	public function to_array(): array {
		return [
			'format'   => self::FORMAT,
			'from'     => $this->from_date,
			'to'       => $this->to_date,
			'timezone' => $this->timezone,
			'rule'     => $this->rule_reference,
		];
	}

	/**
	 * @param array<mixed> $data
	 */
	public static function from_array( array $data ): self {
		$keys = [ 'format', 'from', 'to', 'timezone', 'rule' ];
		if ( array_keys( $data ) !== $keys || self::FORMAT !== $data['format'] ) {
			throw new \InvalidArgumentException( 'Unknown promise display format.' );
		}
		foreach ( [ 'from', 'to', 'timezone', 'rule' ] as $key ) {
			if ( ! is_string( $data[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Malformed promise display.' );
			}
		}

		return new self( $data['from'], $data['to'], $data['timezone'], $data['rule'] );
	}

	private static function assert_date( string $date ): void {
		if ( 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date ) ) {
			throw new \InvalidArgumentException( 'Promise dates must be Y-m-d.' );
		}
		[ $year, $month, $day ] = array_map( 'intval', explode( '-', $date ) );
		if ( ! checkdate( $month, $day, $year ) ) {
			throw new \InvalidArgumentException( 'Promise dates must be real calendar days.' );
		}
	}

	private static function zone( string $timezone ): \DateTimeZone {
		try {
			return new \DateTimeZone( $timezone );
		} catch ( \Exception ) {
			throw new \InvalidArgumentException( 'Unknown promise time zone.' );
		}
	}

	private static function day( string $date, string $timezone ): \DateTimeImmutable {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, self::zone( $timezone ) );
		if ( false === $value ) {
			throw new \InvalidArgumentException( 'Promise dates must be Y-m-d.' );
		}

		return $value;
	}
}
