<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Explicit local-calendar facts only; no holiday inference, clock capture or date arithmetic. */
final readonly class BusinessCalendarVersion implements \JsonSerializable {
	public const FORMAT = 1;
	public const WEEKDAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES );
		PromiseShape::fields( $data, [ 'format_version', 'site_id', 'calendar_id', 'version', 'timezone', 'tzdata_version', 'weekly_openings', 'closed_dates', 'exception_openings', 'sources' ] );
		if ( self::FORMAT !== $data['format_version'] ) { PromiseShape::invalid(); }
		PromiseShape::id( $data['site_id'] ); PromiseShape::id( $data['calendar_id'] ); PromiseShape::integer( $data['version'], 1, PromiseLimits::VERSION_MAX );
		PromiseShape::timezone( $data['timezone'] ); PromiseShape::text( $data['tzdata_version'], 64 );
		$weekly = PromiseShape::object( $data['weekly_openings'] ); PromiseShape::fields( $weekly, self::WEEKDAYS );
		foreach ( self::WEEKDAYS as $day ) { $weekly[$day] = self::intervals( $weekly[$day] ); } $data['weekly_openings'] = $weekly;
		$closed = PromiseShape::list( $data['closed_dates'], 0, PromiseLimits::DATED_EXCEPTIONS ); $dates = [];
		foreach ( $closed as $date ) { self::local_date( $date ); if ( isset( $dates[$date] ) ) { PromiseShape::invalid(); } $dates[$date] = true; }
		sort( $closed, SORT_STRING ); $data['closed_dates'] = $closed;
		$exceptions = PromiseShape::list( $data['exception_openings'], 0, PromiseLimits::DATED_EXCEPTIONS ); $seen_exceptions = [];
		foreach ( $exceptions as &$exception ) {
			$exception = PromiseShape::object( $exception ); PromiseShape::fields( $exception, [ 'date', 'intervals' ] ); self::local_date( $exception['date'] );
			if ( isset( $seen_exceptions[$exception['date']] ) ) { PromiseShape::invalid(); } $seen_exceptions[$exception['date']] = true; $dates[$exception['date']] = true;
			$exception['intervals'] = self::intervals( $exception['intervals'] );
		} unset( $exception );
		if ( count( $dates ) > PromiseLimits::DATED_EXCEPTIONS ) { PromiseShape::invalid(); }
		usort( $exceptions, static fn( array $a, array $b ): int => strcmp( $a['date'], $b['date'] ) ); $data['exception_openings'] = $exceptions;
		$sources = PromiseShape::list( $data['sources'], 0, PromiseLimits::CALENDARS ); $canonical = [];
		foreach ( $sources as $source ) {
			$source = PromiseShape::object( $source ); PromiseShape::fields( $source, [ 'kind', 'source' ] ); PromiseShape::choice( $source['kind'], [ 'holiday', 'carrier' ] );
			$receipt = PromiseShape::object( $source['source'] ); PromiseShape::fields( $receipt, [ 'format_version', 'site_id', 'source_id', 'version', 'digest' ] );
			if ( self::FORMAT !== $receipt['format_version'] || $data['site_id'] !== $receipt['site_id'] ) { PromiseShape::invalid(); }
			PromiseShape::id( $receipt['source_id'] ); PromiseShape::integer( $receipt['version'], 1, PromiseLimits::VERSION_MAX ); PromiseShape::digest( $receipt['digest'] );
			$source['source'] = $receipt; $key = $receipt['source_id'];
			if ( isset( $canonical[$key] ) && PromiseJson::encode( $canonical[$key] ) !== PromiseJson::encode( $source ) ) { PromiseShape::invalid(); }
			$canonical[$key] = $source;
		} ksort( $canonical, SORT_STRING ); $data['sources'] = array_values( $canonical );
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-business-calendar-v1:' . $this->json ); }
	public function site_id(): string { return $this->private_facts()['site_id']; }
	public function timezone(): string { return $this->private_facts()['timezone']; }
	public function tzdata_version(): string { return $this->private_facts()['tzdata_version']; }
	public function reference(): PromiseCalendarReference { $data = $this->private_facts(); return PromiseCalendarReference::from_array( [ 'format_version' => 1, 'site_id' => $data['site_id'], 'calendar_id' => $data['calendar_id'], 'version' => $data['version'], 'digest' => $this->digest() ] ); }
	/** Resolve explicitly declared openings, without advancing or converting any wall time. */
	public function openings_for_date( string $date ): array {
		self::local_date( $date ); $data = $this->private_facts();
		if ( in_array( $date, $data['closed_dates'], true ) ) { return []; }
		foreach ( $data['exception_openings'] as $exception ) { if ( $date === $exception['date'] ) { return $exception['intervals']; } }
		$day = strtolower( ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->format( 'D' ) );
		return $data['weekly_openings'][$day];
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicitly authorized service promise projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise values cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise values require an explicit validated factory.' ); }
	private static function local_date( mixed $value ): void {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $value ) ) { PromiseShape::invalid(); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) ); $errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) { PromiseShape::invalid(); }
	}
	private static function intervals( mixed $value ): array {
		$intervals = PromiseShape::list( $value, 0, PromiseLimits::INTERVALS_PER_DATE );
		foreach ( $intervals as &$interval ) {
			$interval = PromiseShape::object( $interval ); PromiseShape::fields( $interval, [ 'open', 'close' ] );
			$open = self::minute( $interval['open'], false ); $close = self::minute( $interval['close'], true ); if ( $open >= $close ) { PromiseShape::invalid(); }
		} unset( $interval );
		usort( $intervals, static fn( array $a, array $b ): int => strcmp( $a['open'], $b['open'] ) ); $last_close = -1;
		foreach ( $intervals as $interval ) { $open = self::minute( $interval['open'], false ); if ( $open < $last_close ) { PromiseShape::invalid(); } $last_close = self::minute( $interval['close'], true ); }
		return $intervals;
	}
	private static function minute( mixed $value, bool $end ): int {
		if ( ! is_string( $value ) || ( 1 !== preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $value ) && ! ( $end && '24:00' === $value ) ) ) { PromiseShape::invalid(); }
		return (int) substr( $value, 0, 2 ) * 60 + (int) substr( $value, 3, 2 );
	}
}
