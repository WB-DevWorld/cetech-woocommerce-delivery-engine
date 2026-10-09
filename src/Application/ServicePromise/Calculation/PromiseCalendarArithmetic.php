<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseDuration;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;

/** Pure civil-calendar transfer. UTC microseconds, not a process clock, carry the result. */
final class PromiseCalendarArithmetic {
	private const MINUTE_MICROSECONDS = 60000000;

	/** A fold needs the exact native offset; a gap can never be repaired by an offset. */
	public function resolve_local( string $date, string $time, string $zone, ?int $offsetSeconds = null, ?PromiseCalculationBudget $budget = null ): RuleTime {
		$budget ??= new PromiseCalculationBudget();
		$budget->consume();
		$timezone = $this->timezone( $zone );
		$civil = $this->civil( $date, $time );
		$wall = (int) $civil->format( 'U' ) * 1000000 + (int) $civil->format( 'u' );
		$seconds = (int) $civil->format( 'U' );
		$transitions = $timezone->getTransitions( $seconds - 172800, $seconds + 172801 );
		if ( false === $transitions || count( $transitions ) > 16 ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
		$offsets = [];
		foreach ( $transitions as $transition ) { $budget->consume(); $offsets[(int) $transition['offset']] = true; }
		$candidates = [];
		foreach ( array_keys( $offsets ) as $offset ) {
			$budget->consume();
			try { $candidate = RuleTime::from_epoch_microseconds( $wall - $offset * 1000000 ); }
			catch ( \InvalidArgumentException ) { continue; }
			$local = $this->local( $candidate, $timezone, $budget );
			if ( $local->format( 'Y-m-d H:i:s.u' ) === $civil->format( 'Y-m-d H:i:s.u' ) && $local->getOffset() === $offset ) { $candidates[$offset] = $candidate; }
		}
		if ( null !== $offsetSeconds ) {
			if ( isset( $candidates[$offsetSeconds] ) ) { return $candidates[$offsetSeconds]; }
			throw new PromiseCalculationException( 'estimate_unavailable' );
		}
		if ( 1 !== count( $candidates ) ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
		return array_values( $candidates )[0];
	}

	public function next_start( RuleTime $from, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): RuleTime {
		return $this->seek_start( $from, $calendar, $budget, $from );
	}

	private function seek_start( RuleTime $from, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget, RuleTime $origin ): RuleTime {
		$date = $this->local( $from, $this->timezone( $calendar->timezone() ), $budget )->format( 'Y-m-d' );
		for ( $day = 0; $day <= PromiseLimits::LOOKAHEAD_DAYS; ++$day ) {
			$probe = $this->shift_date( $date, $day, $budget );
			$this->date_horizon( $origin, $probe, $calendar->timezone(), $budget );
			foreach ( $this->intervals( $probe, $calendar, $budget ) as $interval ) {
				if ( $from->compare( $interval['end'] ) >= 0 ) { continue; }
				$result = $from->compare( $interval['start'] ) >= 0 ? $from : $interval['start'];
				$this->horizon( $origin, $result, $calendar->timezone(), $budget );
				return $result;
			}
		}
		throw new PromiseCalculationException( 'budget_exceeded' );
	}

	/** Completion may equal close; a new start uses the normal half-open interval. */
	public function containing_interval( RuleTime $at, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget, bool $completion = false ): ?array {
		$date = $this->local( $at, $this->timezone( $calendar->timezone() ), $budget )->format( 'Y-m-d' );
		foreach ( $this->intervals( $date, $calendar, $budget ) as $interval ) {
			if ( $at->compare( $interval['start'] ) >= 0 && ( $at->compare( $interval['end'] ) < 0 || ( $completion && $at->equals( $interval['end'] ) ) ) ) { return $interval; }
		}
		// A recorded 24:00 close belongs to the preceding civil date.
		if ( $completion && '00:00:00.000000' === $this->local( $at, $this->timezone( $calendar->timezone() ), $budget )->format( 'H:i:s.u' ) ) {
			foreach ( $this->intervals( $this->shift_date( $date, -1, $budget ), $calendar, $budget ) as $interval ) { if ( $at->equals( $interval['end'] ) ) { return $interval; } }
		}
		return null;
	}

	public function add( RuleTime $from, int $amount, string $unit, ?BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): RuleTime {
		$budget->consume();
		if ( $amount < 0 || ! in_array( $unit, PromiseDuration::UNITS, true ) ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		if ( 'elapsed_minutes' !== $unit && null === $calendar ) { throw new PromiseCalculationException( 'missing_source' ); }
		if ( 0 === $amount ) { return 'business_days' === $unit ? $this->next_start( $from, $calendar, $budget ) : $from; }
		if ( 'elapsed_minutes' === $unit ) {
			if ( $amount > PromiseLimits::LOOKAHEAD_DAYS * 1440 ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
			$result = $this->elapsed( $from, $amount * self::MINUTE_MICROSECONDS );
			$this->horizon( $from, $result, $calendar?->timezone() ?? 'UTC', $budget );
			return $result;
		}
		if ( 'calendar_days' === $unit ) {
			if ( $amount > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
			$local = $this->local( $from, $this->timezone( $calendar->timezone() ), $budget );
			$result = $this->resolve_local( $this->shift_date( $local->format( 'Y-m-d' ), $amount, $budget ), $local->format( 'H:i:s.u' ), $calendar->timezone(), null, $budget );
			$this->horizon( $from, $result, $calendar->timezone(), $budget );
			return $result;
		}
		if ( 'business_days' === $unit ) { return $this->business_days( $from, $amount, $calendar, $budget ); }
		return $this->business_minutes( $from, $amount, $calendar, $budget );
	}

	/** Conservative proof for an acceptance envelope; unavailable proof never becomes a winner. */
	public function prove_monotonic( RuleTime $from, RuleTime $until, int $amount, string $unit, ?BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): void {
		$budget->consume();
		if ( $from->compare( $until ) > 0 || $amount < 0 || ! in_array( $unit, PromiseDuration::UNITS, true ) ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		if ( 'elapsed_minutes' !== $unit && null === $calendar ) { throw new PromiseCalculationException( 'missing_source' ); }
		if ( $from->equals( $until ) || 'elapsed_minutes' === $unit || ( 0 === $amount && 'business_days' !== $unit ) ) { return; }
		if ( 'business_minutes' === $unit ) {
			$this->validate_calendar_envelope( $from, $until, $calendar, $budget );
			$a = $this->add( $from, $amount, $unit, $calendar, $budget ); $b = $this->add( $until, $amount, $unit, $calendar, $budget );
			if ( $a->compare( $b ) > 0 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			return;
		}
		if ( ! in_array( $unit, [ 'calendar_days', 'business_days' ], true ) || null === $calendar ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		$timezone = $this->timezone( $calendar->timezone() );
		$from_local = $this->local( $from, $timezone, $budget ); $until_local = $this->local( $until, $timezone, $budget );
		if ( $from_local->format( 'Y-m-d' ) !== $until_local->format( 'Y-m-d' ) ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		$this->transition_free( $from, $until, $timezone, $budget );
		if ( 'business_days' === $unit ) {
			$a = $this->next_start( $from, $calendar, $budget ); $b = $this->next_start( $until, $calendar, $budget );
			$ia = $this->containing_interval( $a, $calendar, $budget ); $ib = $this->containing_interval( $b, $calendar, $budget );
			if ( null === $ia || null === $ib || ! $ia['start']->equals( $ib['start'] ) || ! $ia['end']->equals( $ib['end'] ) ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		}
		$a = $this->add( $from, $amount, $unit, $calendar, $budget ); $b = $this->add( $until, $amount, $unit, $calendar, $budget );
		if ( $a->compare( $b ) > 0 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		$this->transition_free( $a, $b, $timezone, $budget );
	}

	/** Endpoint-only success cannot conceal an undefined interior transfer. */
	private function validate_calendar_envelope( RuleTime $from, RuleTime $until, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): void {
		$this->horizon( $from, $until, $calendar->timezone(), $budget ); $timezone = $this->timezone( $calendar->timezone() );
		$date = $this->local( $from, $timezone, $budget )->format( 'Y-m-d' ); $end = $this->local( $until, $timezone, $budget )->format( 'Y-m-d' );
		for ( $day = 0; $day <= PromiseLimits::LOOKAHEAD_DAYS; ++$day ) {
			$probe = $this->shift_date( $date, $day, $budget ); $this->intervals( $probe, $calendar, $budget );
			if ( $probe === $end ) { return; }
		}
		throw new PromiseCalculationException( 'budget_exceeded' );
	}

	private function business_minutes( RuleTime $from, int $amount, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): RuleTime {
		if ( $amount > intdiv( PHP_INT_MAX, self::MINUTE_MICROSECONDS ) ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
		$remaining = $amount * self::MINUTE_MICROSECONDS; $cursor = $this->seek_start( $from, $calendar, $budget, $from );
		while ( $remaining > 0 ) {
			$budget->consume(); $this->horizon( $from, $cursor, $calendar->timezone(), $budget );
			$interval = $this->containing_interval( $cursor, $calendar, $budget );
			if ( null === $interval ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
			$available = $interval['end']->epoch_microseconds() - $cursor->epoch_microseconds();
			if ( $remaining <= $available ) { $result = $this->elapsed( $cursor, $remaining ); $this->horizon( $from, $result, $calendar->timezone(), $budget ); return $result; }
			$remaining -= $available; $cursor = $this->seek_start( $interval['end'], $calendar, $budget, $from );
		}
		throw new PromiseCalculationException( 'estimate_unavailable' );
	}

	private function business_days( RuleTime $from, int $amount, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): RuleTime {
		if ( $amount > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
		$eligible = $this->seek_start( $from, $calendar, $budget, $from ); $local = $this->local( $eligible, $this->timezone( $calendar->timezone() ), $budget );
		$date = $local->format( 'Y-m-d' ); $count = 0;
		for ( $day = 1; $day <= PromiseLimits::LOOKAHEAD_DAYS; ++$day ) {
			$probe = $this->shift_date( $date, $day, $budget );
			$this->date_horizon( $from, $probe, $calendar->timezone(), $budget );
			if ( [] === $this->intervals( $probe, $calendar, $budget ) ) { continue; }
			if ( ++$count !== $amount ) { continue; }
			$target = $this->resolve_local( $probe, $local->format( 'H:i:s.u' ), $calendar->timezone(), null, $budget );
			$result = $this->seek_start( $target, $calendar, $budget, $from ); $this->horizon( $from, $result, $calendar->timezone(), $budget ); return $result;
		}
		throw new PromiseCalculationException( 'budget_exceeded' );
	}

	/** Resolving every endpoint prevents hidden gap/fold selection in a visited date. */
	private function intervals( string $date, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): array {
		$budget->consume(); $raw = $calendar->openings_for_date( $date ); $result = [];
		foreach ( $raw as $interval ) {
			$budget->consume(); $end_date = '24:00' === $interval['close'] ? $this->shift_date( $date, 1, $budget ) : $date;
			$start = $this->resolve_local( $date, $interval['open'], $calendar->timezone(), null, $budget );
			$end = $this->resolve_local( $end_date, '24:00' === $interval['close'] ? '00:00' : $interval['close'], $calendar->timezone(), null, $budget );
			if ( $start->compare( $end ) >= 0 ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
			$result[] = [ 'start' => $start, 'end' => $end ];
		}
		return $result;
	}

	private function transition_free( RuleTime $from, RuleTime $until, \DateTimeZone $timezone, PromiseCalculationBudget $budget ): void {
		$budget->consume(); $start = intdiv( $from->epoch_microseconds(), 1000000 ); $end = intdiv( $until->epoch_microseconds(), 1000000 );
		$transitions = $timezone->getTransitions( $start, $end + 1 );
		if ( false === $transitions || count( $transitions ) > 1 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		if ( $this->local( $from, $timezone, $budget )->getOffset() !== $this->local( $until, $timezone, $budget )->getOffset() ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
	}

	private function horizon( RuleTime $from, RuleTime $until, string $zone, PromiseCalculationBudget $budget ): void {
		$budget->consume(); $timezone = $this->timezone( $zone );
		$a = $this->civil( $this->local( $from, $timezone, $budget )->format( 'Y-m-d' ), '00:00' );
		$b = $this->civil( $this->local( $until, $timezone, $budget )->format( 'Y-m-d' ), '00:00' );
		$days = (int) $a->diff( $b )->format( '%r%a' );
		if ( $until->compare( $from ) < 0 || $days > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
	}

	private function elapsed( RuleTime $from, int $microseconds ): RuleTime {
		if ( $from->epoch_microseconds() > PHP_INT_MAX - $microseconds ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
		try { return RuleTime::from_epoch_microseconds( $from->epoch_microseconds() + $microseconds ); }
		catch ( \InvalidArgumentException ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
	}

	private function date_horizon( RuleTime $origin, string $date, string $zone, PromiseCalculationBudget $budget ): void {
		$budget->consume();
		$start = $this->civil( $this->local( $origin, $this->timezone( $zone ), $budget )->format( 'Y-m-d' ), '00:00' );
		$days = (int) $start->diff( $this->civil( $date, '00:00' ) )->format( '%r%a' );
		if ( $days > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
	}

	private function local( RuleTime $time, \DateTimeZone $zone, PromiseCalculationBudget $budget ): \DateTimeImmutable {
		$budget->consume();
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $time->sql(), new \DateTimeZone( 'UTC' ) );
		if ( false === $date ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
		return $date->setTimezone( $zone );
	}

	private function shift_date( string $date, int $days, PromiseCalculationBudget $budget ): string {
		$budget->consume(); $result = $this->civil( $date, '00:00' )->modify( ( $days < 0 ? '' : '+' ) . $days . ' days' )->format( 'Y-m-d' );
		$this->civil( $result, '00:00' ); return $result;
	}

	private function civil( string $date, string $time ): \DateTimeImmutable {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $date ) || 1 !== preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9](?:\.[0-9]{6})?)?\z/D', $time ) ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
		$canonical = strlen( $time ) === 5 ? $time . ':00.000000' : ( strlen( $time ) === 8 ? $time . '.000000' : $time );
		$value = $date . ' ' . $canonical; $result = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) ); $errors = \DateTimeImmutable::getLastErrors();
		if ( false === $result || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $result->format( 'Y-m-d H:i:s.u' ) !== $value ) { throw new PromiseCalculationException( 'estimate_unavailable' ); }
		return $result;
	}

	private function timezone( string $zone ): \DateTimeZone {
		if ( ! in_array( $zone, \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ), true ) ) { throw new PromiseCalculationException( 'unknown_timezone' ); }
		return new \DateTimeZone( $zone );
	}
}
