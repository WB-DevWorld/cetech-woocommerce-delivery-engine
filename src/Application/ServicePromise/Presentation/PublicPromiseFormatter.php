<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Presentation;

use CetechDeliveryEngine\Domain\ServicePromise\{PublicPromiseView, PromiseShape};

/** Current locale copy from the closed customer view; never reads private policy/source facts. */
final class PublicPromiseFormatter {
	private ?\Closure $translate;
	private ?\Closure $plural;
	private ?\Closure $date;
	public function __construct( ?callable $translate = null, ?callable $plural = null, ?callable $date = null ) {
		$this->translate = null === $translate ? null : \Closure::fromCallable( $translate );
		$this->plural = null === $plural ? null : \Closure::fromCallable( $plural );
		$this->date = null === $date ? null : \Closure::fromCallable( $date );
	}
	public function text( PublicPromiseView $view ): string {
		$facts = $view->fields();
		$body = match ( $facts['state'] ) {
			'absolute_window' => $this->absolute( $facts ),
			'relative_window' => $this->relative( $facts ),
			'ineligible' => $this->tr( 'This service is not available for these delivery details.' ),
			default => $this->tr( 'Delivery estimate unavailable. Review delivery again at checkout.' ),
		};
		return PromiseShape::text( sprintf( $this->tr( '%1$s: %2$s' ), $facts['service_label'], $body ), 2048 );
	}
	public function format( PublicPromiseView $view ): array {
		$facts = $view->fields();
		return [ 'view' => $facts, 'text' => $this->text( $view ), 'state_label' => $this->tr( match ( $facts['state'] ) { 'absolute_window' => 'Delivery window', 'relative_window' => 'After payment confirmation', 'ineligible' => 'Service unavailable', default => 'Estimate unavailable' } ), 'reason_texts' => array_map( fn( string $code ): string => $this->reason( $code ), $facts['reason_codes'] ) ];
	}
	public function reason( string $code ): string {
		return $this->tr( match ( $code ) {
			'missing_destination' => 'Enter complete delivery details to see an estimate.',
			'outside_service_window' => 'This service cannot meet its delivery window. Choose another service.',
			'acceptance_expired' => 'This delivery window has expired. Review delivery again.',
			'capacity_unknown', 'capacity_unavailable', 'capacity_stale' => 'This service is currently unavailable. Choose another service or try again.',
			'source_changed' => 'Delivery availability changed. Review delivery again.',
			default => 'Delivery estimate unavailable. Review delivery again at checkout.',
		} );
	}
	private function absolute( array $facts ): string {
		$from = $this->date( $facts['from'], $facts['display_timezone'] ); $until = $this->date( $facts['until'], $facts['display_timezone'] );
		return $facts['from'] === $facts['until'] ? sprintf( $this->tr( '%1$s (%2$s)' ), $from, $facts['display_timezone'] ) : sprintf( $this->tr( '%1$s–%2$s (%3$s)' ), $from, $until, $facts['display_timezone'] );
	}
	private function relative( array $facts ): string {
		[ $one, $many ] = match ( $facts['unit'] ) { 'elapsed_minutes' => [ 'minute', 'minutes' ], 'calendar_days' => [ 'calendar day', 'calendar days' ], 'business_minutes' => [ 'business minute', 'business minutes' ], 'business_days' => [ 'business day', 'business days' ] };
		$count = $facts['max']; $unit = null !== $this->plural ? ( $this->plural )( $one, $many, $count ) : ( function_exists( '_n' ) ? _n( $one, $many, $count, 'cetech-woocommerce-delivery-engine' ) : ( 1 === $count ? $one : $many ) );
		$duration = $facts['min'] === $facts['max'] ? (string) $facts['min'] : $facts['min'] . '–' . $facts['max'];
		return sprintf( $this->tr( '%1$s %2$s after payment confirmation (%3$s)' ), $duration, $unit, $facts['display_timezone'] );
	}
	private function date( string $instant, string $timezone ): string {
		$utc = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $instant, new \DateTimeZone( 'UTC' ) ); $zone = new \DateTimeZone( $timezone );
		if ( null !== $this->date ) { return ( $this->date )( $utc->getTimestamp(), $zone ); }
		$format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ) : 'Y-m-d H:i';
		return function_exists( 'wp_date' ) ? wp_date( $format, $utc->getTimestamp(), $zone ) : $utc->setTimezone( $zone )->format( $format );
	}
	private function tr( string $text ): string { return null !== $this->translate ? ( $this->translate )( $text ) : ( function_exists( '__' ) ? __( $text, 'cetech-woocommerce-delivery-engine' ) : $text ); }
}
