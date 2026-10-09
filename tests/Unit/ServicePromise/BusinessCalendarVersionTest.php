<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;
use PHPUnit\Framework\TestCase;

final class BusinessCalendarVersionTest extends TestCase {
	public function test_explicit_week_is_not_assumed_monday_to_friday_and_closure_wins(): void {
		$data = self::facts(); $data['closed_dates'] = [ '2026-10-09' ]; $data['exception_openings'] = [ [ 'date' => '2026-10-09', 'intervals' => [ [ 'open' => '06:00', 'close' => '24:00' ] ] ] ];
		$calendar = BusinessCalendarVersion::from_array( $data );
		self::assertSame( [], $calendar->openings_for_date( '2026-10-09' ) );
		self::assertSame( [], $calendar->openings_for_date( '2026-10-12' ) );
		self::assertSame( [ [ 'close' => '20:00', 'open' => '12:00' ] ], $calendar->openings_for_date( '2026-10-11' ) );
		self::assertCount( 1, $calendar->private_facts()['exception_openings'] );
	}
	public function test_calendar_sets_canonicalize_and_detach_while_versions_change_identity(): void {
		$data = self::facts(); $data['closed_dates'] = [ '2026-10-10', '2026-10-09' ]; $data['weekly_openings']['fri'] = [ [ 'open' => '12:00', 'close' => '16:00' ], [ 'open' => '08:00', 'close' => '11:00' ] ];
		$data['sources'] = [ self::source(), self::source() ]; $calendar = BusinessCalendarVersion::from_array( $data );
		$data['closed_dates'] = array_reverse( $data['closed_dates'] ); $data['weekly_openings']['fri'] = array_reverse( $data['weekly_openings']['fri'] );
		self::assertSame( $calendar->digest(), BusinessCalendarVersion::from_array( $data )->digest() ); self::assertCount( 1, $calendar->private_facts()['sources'] );
		$data['sources'][0]['source']['version'] = 2; array_pop( $data['sources'] );
		self::assertNotSame( $calendar->digest(), BusinessCalendarVersion::from_array( $data )->digest() );
		$data['timezone'] = 'Europe/London'; $copy = $calendar->private_facts(); $copy['weekly_openings']['sun'][0]['open'] = '00:00';
		self::assertSame( 'Africa/Accra', $calendar->timezone() ); self::assertSame( '12:00', $calendar->private_facts()['weekly_openings']['sun'][0]['open'] );
		self::assertSame( $calendar->digest(), $calendar->reference()->content_digest() ); self::assertSame( '2026b', $calendar->tzdata_version() );
		self::assertSame( $calendar->to_private_json(), BusinessCalendarVersion::from_json( $calendar->to_private_json() )->to_private_json() );
	}
	public function test_half_open_adjacent_intervals_and_split_overnight_are_explicit(): void {
		$data = self::facts(); $data['weekly_openings']['fri'] = [ [ 'open' => '20:00', 'close' => '24:00' ], [ 'open' => '16:00', 'close' => '20:00' ] ]; $data['weekly_openings']['sat'] = [ [ 'open' => '00:00', 'close' => '04:00' ] ];
		self::assertCount( 2, BusinessCalendarVersion::from_array( $data )->openings_for_date( '2026-10-09' ) );
		self::assertSame( [ [ 'close' => '04:00', 'open' => '00:00' ] ], BusinessCalendarVersion::from_array( $data )->openings_for_date( '2026-10-10' ) );
	}
	public function test_366_distinct_sparse_dates_fit_but_dense_record_refuses_as_whole(): void {
		$data = self::facts(); $date = new \DateTimeImmutable( '2028-01-01', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < 366; ++$i ) { $local = $date->modify( '+' . $i . ' days' )->format( 'Y-m-d' ); $data['closed_dates'][] = $local; $data['exception_openings'][] = [ 'date' => $local, 'intervals' => [ [ 'open' => '08:00', 'close' => '09:00' ] ] ]; }
		$calendar = BusinessCalendarVersion::from_array( $data ); self::assertCount( 366, $calendar->private_facts()['exception_openings'] ); self::assertLessThanOrEqual( PromiseLimits::RECORD_BYTES, strlen( $calendar->to_private_json() ) );
		foreach ( $data['exception_openings'] as &$exception ) { $exception['intervals'] = self::eight_intervals(); } unset( $exception );
		$this->expectException( \InvalidArgumentException::class ); BusinessCalendarVersion::from_array( $data );
	}
	public function test_date_union_instead_of_individual_list_bound_refuses_367(): void {
		$data = self::facts(); $date = new \DateTimeImmutable( '2028-01-01', new \DateTimeZone( 'UTC' ) );
		for ( $i = 0; $i < 366; ++$i ) { $data['closed_dates'][] = $date->modify( '+' . $i . ' days' )->format( 'Y-m-d' ); }
		$data['exception_openings'] = [ [ 'date' => '2029-01-01', 'intervals' => [] ] ];
		$this->expectException( \InvalidArgumentException::class ); BusinessCalendarVersion::from_array( $data );
	}
	public function test_eight_windows_fit_and_ninth_is_not_truncated(): void {
		$data = self::facts(); $data['weekly_openings']['fri'] = self::eight_intervals(); self::assertCount( 8, BusinessCalendarVersion::from_array( $data )->openings_for_date( '2026-10-09' ) );
		$data['weekly_openings']['fri'][] = [ 'open' => '16:00', 'close' => '17:00' ]; $this->expectException( \InvalidArgumentException::class ); BusinessCalendarVersion::from_array( $data );
	}
	public function test_exact_whole_wire_byte_limit_is_enforced_before_decoding(): void {
		$calendar = BusinessCalendarVersion::from_array( self::facts() ); $wire = str_pad( $calendar->to_private_json(), PromiseLimits::RECORD_BYTES, ' ' ); self::assertSame( $calendar->digest(), BusinessCalendarVersion::from_json( $wire )->digest() );
		$this->expectException( \InvalidArgumentException::class ); BusinessCalendarVersion::from_json( $wire . ' ' );
	}
	/** @dataProvider invalid_facts */
	public function test_malformed_or_unidentified_calendar_refuses( string $case ): void {
		$data = self::facts();
		switch ( $case ) {
			case 'version': $data['format_version'] = 2; break;
			case 'extra': $data['country'] = 'GH'; break;
			case 'missing_day': unset( $data['weekly_openings']['mon'] ); break;
			case 'invalid_date': $data['closed_dates'] = [ '2026-02-29' ]; break;
			case 'duplicate_date': $data['closed_dates'] = [ '2026-10-09', '2026-10-09' ]; break;
			case 'duplicate_exception': $data['exception_openings'] = [ [ 'date' => '2026-10-09', 'intervals' => [] ], [ 'date' => '2026-10-09', 'intervals' => [] ] ]; break;
			case 'offset_zone': $data['timezone'] = '+00:00'; break;
			case 'missing_provenance': $data['tzdata_version'] = ''; break;
			case 'foreign_source': $data['sources'] = [ self::source() ]; $data['sources'][0]['source']['site_id'] = 'other-site'; break;
			case 'conflicting_source': $source = self::source(); $data['sources'] = [ $source, $source ]; $data['sources'][1]['source']['version'] = 2; break;
			case 'source_string_version': $data['sources'] = [ self::source() ]; $data['sources'][0]['source']['version'] = '1'; break;
			case 'source_extra': $data['sources'] = [ self::source() ]; $data['sources'][0]['source']['country'] = 'GH'; break;
			case 'overnight': $data['weekly_openings']['fri'] = [ [ 'open' => '20:00', 'close' => '04:00' ] ]; break;
			case 'start_24': $data['weekly_openings']['fri'] = [ [ 'open' => '24:00', 'close' => '24:00' ] ]; break;
			case 'zero_interval': $data['weekly_openings']['fri'] = [ [ 'open' => '08:00', 'close' => '08:00' ] ]; break;
			case 'overlap': $data['weekly_openings']['fri'] = [ [ 'open' => '08:00', 'close' => '12:00' ], [ 'open' => '11:00', 'close' => '16:00' ] ]; break;
			case 'noncanonical_time': $data['weekly_openings']['fri'] = [ [ 'open' => '8:00', 'close' => '12:00' ] ]; break;
		}
		$this->expectException( \InvalidArgumentException::class ); BusinessCalendarVersion::from_array( $data );
	}
	public static function invalid_facts(): array { $cases = [ 'version', 'extra', 'missing_day', 'invalid_date', 'duplicate_date', 'duplicate_exception', 'offset_zone', 'missing_provenance', 'foreign_source', 'conflicting_source', 'source_string_version', 'source_extra', 'overnight', 'start_24', 'zero_interval', 'overlap', 'noncanonical_time' ]; return array_combine( $cases, array_map( static fn( string $case ): array => [ $case ], $cases ) ); }
	public function test_calendar_private_value_cannot_serialize_as_public_payload(): void { $this->expectException( \LogicException::class ); json_encode( BusinessCalendarVersion::from_array( self::facts() ), JSON_THROW_ON_ERROR ); }
	public function test_calendar_cannot_serialize_private_payload(): void { $this->expectException( \LogicException::class ); serialize( BusinessCalendarVersion::from_array( self::facts() ) ); }
	public function test_unserialize_cannot_bypass_calendar_factory(): void { $class = BusinessCalendarVersion::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	private static function facts(): array { return [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'picking', 'version' => 1, 'timezone' => 'Africa/Accra', 'tzdata_version' => '2026b', 'weekly_openings' => [ 'mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [ [ 'open' => '08:00', 'close' => '16:00' ] ], 'sat' => [], 'sun' => [ [ 'open' => '12:00', 'close' => '20:00' ] ] ], 'closed_dates' => [], 'exception_openings' => [], 'sources' => [] ]; }
	private static function source(): array { return [ 'kind' => 'holiday', 'source' => [ 'format_version' => 1, 'site_id' => 'site-1', 'source_id' => 'configured-holidays', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ] ]; }
	private static function eight_intervals(): array { $out = []; for ( $i = 0; $i < 8; ++$i ) { $out[] = [ 'open' => sprintf( '%02d:00', $i * 2 ), 'close' => sprintf( '%02d:00', $i * 2 + 1 ) ]; } return $out; }
}
