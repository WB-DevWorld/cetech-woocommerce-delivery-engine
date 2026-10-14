<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Configuration;

use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\ServiceIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;

/**
 * Assembles one supported delivery-time draft from staff fields.
 * It does not publish, assign or enable customer admission.
 */
final class PromiseStaffDraft {

	public const SITE_KEY = 'store';

	/**
	 * @param array<string, string> $input
	 * @param array<string, mixed>  $offer
	 * @return array{missing: list<string>, calendar: ?BusinessCalendarVersion, policy: ?ServicePromisePolicy, summary: string}
	 */
	public static function assemble( array $input, array $offer, int $version = 1 ): array {
		$missing = [];
		$label   = trim( (string) ( $offer['public_label'] ?? '' ) );
		$code    = trim( (string) ( $offer['internal_code'] ?? '' ) );
		$route   = (string) ( $offer['route'] ?? '' );
		if ( '' === $label || '' === $code || strlen( $label ) > 120 || 1 === preg_match( '/[<>\x00-\x1F\x7F]/', $label ) ) {
			$missing[] = __( 'Select an existing delivery option by its name.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( ! in_array( $route, [ 'local_delivery', 'store_pickup' ], true ) ) {
			$missing[] = __( 'This delivery option is not a local delivery or store pickup. The guided editor cannot represent it and leaves any saved document unchanged.', 'cetech-woocommerce-delivery-engine' );
		}
		$unit = (string) ( $input['unit'] ?? '' );
		if ( ! in_array( $unit, [ 'elapsed_minutes', 'business_days' ], true ) ) {
			$missing[] = __( 'Choose whether the ranges are elapsed minutes or business days.', 'cetech-woocommerce-delivery-engine' );
		}
		$processing = self::range( $input['processing_min'] ?? '', $input['processing_max'] ?? '', __( 'preparation', 'cetech-woocommerce-delivery-engine' ) );
		$delivery   = self::range( $input['delivery_min'] ?? '', $input['delivery_max'] ?? '', __( 'delivery', 'cetech-woocommerce-delivery-engine' ) );
		$missing    = array_merge( $missing, $processing['missing'], $delivery['missing'] );
		$required   = (string) ( $input['estimate_required'] ?? '' );
		if ( ! in_array( $required, [ 'yes', 'no' ], true ) ) {
			$missing[] = __( 'Choose whether customers must have an estimate before checkout continues.', 'cetech-woocommerce-delivery-engine' );
		}
		$scope = (string) ( $input['scope'] ?? '' );
		if ( ! in_array( $scope, [ 'global', 'product', 'variation' ], true ) ) {
			$missing[] = __( 'Choose whether the rule applies to the whole store, one product, or one variation.', 'cetech-woocommerce-delivery-engine' );
		}
		$target = null;
		if ( 'product' === $scope || 'variation' === $scope ) {
			$target = (int) ( $input['target_id'] ?? 0 );
			if ( $target < 1 ) {
				$missing[] = 'variation' === $scope
					? __( 'Select the variation by its name.', 'cetech-woocommerce-delivery-engine' )
					: __( 'Select the product by its name.', 'cetech-woocommerce-delivery-engine' );
			}
		}
		$timezone = trim( (string) ( $input['timezone'] ?? '' ) );
		$hours    = self::weekly( $input );
		$weekly   = $hours['weekly'];
		$missing  = array_merge( $missing, $hours['missing'] );
		$closed   = self::dates( (string) ( $input['closures'] ?? '' ) );
		$cutoff   = trim( (string) ( $input['cutoff'] ?? '' ) );
		if ( '' !== $cutoff && 1 !== preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $cutoff ) ) {
			$missing[] = __( 'Enter the cutoff as hours and minutes, such as 15:00, or leave it blank.', 'cetech-woocommerce-delivery-engine' );
		}
		$needs_calendar = 'business_days' === $unit || '' !== $cutoff || self::has_hours( $weekly ) || [] !== $closed['dates'];
		if ( $needs_calendar && ! in_array( $timezone, \DateTimeZone::listIdentifiers(), true ) ) {
			$missing[] = __( 'Select the timezone used for working hours and the cutoff.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( 'business_days' === $unit && ! self::has_hours( $weekly ) ) {
			$missing[] = __( 'Enter at least one working period. Business days are not assumed.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( $closed['invalid'] ) {
			$missing[] = __( 'Enter each closure as a calendar date, one per line.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( $version < 1 ) {
			$missing[] = __( 'The saved rule could not be confirmed. Review it before saving another draft.', 'cetech-woocommerce-delivery-engine' );
		}
		if ( [] !== $missing ) {
			return [ 'missing' => $missing, 'calendar' => null, 'policy' => null, 'summary' => '' ];
		}

		try {
			return self::documents( $input, $label, $code, $route, $unit, $processing, $delivery, $required, $scope, $target, $timezone, $weekly, $closed['dates'], $cutoff, $needs_calendar, $version );
		} catch ( \InvalidArgumentException ) {
			return [
				'missing'  => [ __( 'These entries cannot be saved by the guided editor. Any existing advanced rule is left unchanged.', 'cetech-woocommerce-delivery-engine' ) ],
				'calendar' => null,
				'policy'   => null,
				'summary'  => '',
			];
		}
	}

	/**
	 * @param array{min: int, max: int, missing: list<string>} $processing
	 * @param array{min: int, max: int, missing: list<string>} $delivery
	 * @param array<string, list<array{open: string, close: string}>> $weekly
	 * @param list<string> $closed
	 * @return array{missing: list<string>, calendar: ?BusinessCalendarVersion, policy: ?ServicePromisePolicy, summary: string}
	 */
	private static function documents( array $input, string $label, string $code, string $route, string $unit, array $processing, array $delivery, string $required, string $scope, ?int $target, string $timezone, array $weekly, array $closed, string $cutoff, bool $needs_calendar, int $version ): array {
		$now      = gmdate( 'Y-m-d H:i:s' ) . '.000000';
		$identity = self::identity( $code );
		$suffix   = $identity . ( 'global' === $scope ? '-store' : '-' . $scope . '-' . (string) $target );
		$calendar = null;
		$refs     = [];
		if ( $needs_calendar ) {
			$calendar = BusinessCalendarVersion::from_array(
				[
					'format_version'     => 1,
					'site_id'            => self::SITE_KEY,
					'calendar_id'        => 'hours-' . $suffix,
					'version'            => $version,
					'timezone'           => $timezone,
					'tzdata_version'     => timezone_version_get(),
					'weekly_openings'    => $weekly,
					'closed_dates'       => $closed,
					'exception_openings' => [],
					'sources'            => [],
				]
			);
			$refs[] = $calendar->reference()->private_facts();
		}
		$calendar_ref = [] === $refs ? null : $refs[0];
		$duration     = static function ( int $min, int $max ) use ( $unit, $calendar_ref ): array {
			return [
				'format_version' => 1,
				'min'            => $min,
				'max'            => $max,
				'unit'           => $unit,
				'calendar'       => 'elapsed_minutes' === $unit ? null : $calendar_ref,
			];
		};
		$source       = [
			'format_version' => 1,
			'site_id'        => self::SITE_KEY,
			'source_id'      => 'staff-estimate',
			'version'        => 1,
			'digest'         => hash( 'sha256', 'staff-estimate' ),
		];
		$pickup       = 'store_pickup' === $route;
		$endpoint     = $pickup ? 'store-counter' : 'customer-door';
		$endpoint_kind = $pickup ? 'pickup' : 'doorstep';
		$policy       = ServicePromisePolicy::from_array(
			[
				'format_version' => 1,
				'site_id'        => self::SITE_KEY,
				'policy_id'      => 'rule-' . $suffix,
				'version'        => $version,
				'effective_from' => $now,
				'effective_until' => null,
				'service'        => [
					'format_version' => 1,
					'kind'           => in_array( $identity, ServiceIdentity::BUILT_IN_CODES, true ) ? 'built_in' : 'merchant',
					'code'           => $identity,
					'customer_label' => $label,
				],
				'anchor'         => 'checkout_capture',
				'endpoint'       => $endpoint,
				'endpoint_kind'  => $endpoint_kind,
				'endpoint_terminal_component_ids' => [ 'delivery' ],
				'scope'          => [ 'kind' => $scope, 'target_id' => 'global' === $scope ? null : $target ],
				'promise_timezone' => $needs_calendar ? $timezone : 'UTC',
				'graph'          => [
					'format_version' => 1,
					'components'     => [
						self::component( 'preparation', 'preparation', $duration( $processing['min'], $processing['max'] ), [], 'merchant-origin', 'origin', $source ),
						self::component( 'delivery', 'final_mile', $duration( $delivery['min'], $delivery['max'] ), [ 'preparation' ], $endpoint, $endpoint_kind, $source ),
					],
					'terminal_component_ids' => [ 'delivery' ],
				],
				'calendars'      => $refs,
				'cutoff'         => '' === $cutoff ? null : [
					'kind'                => 'local_time',
					'calendar'            => $calendar_ref,
					'time'                => $cutoff,
					'missed_window_rule'  => 'reject' === ( $input['cutoff_miss'] ?? '' ) ? 'reject' : 'next_opening',
				],
				'day_constraint' => 'none',
				'promise_required' => 'yes' === $required,
				'late_payment_rule' => 'refuse_if_infeasible',
				'capacity_mode'  => 'none',
				'capacity_source' => null,
			]
		);
		$summary = sprintf(
			/* translators: 1: delivery option name, 2: preparation range, 3: delivery range */
			__( '%1$s: preparation %2$s, delivery %3$s. This is a draft preview, not a promise shown to customers.', 'cetech-woocommerce-delivery-engine' ),
			$label,
			self::range_text( $processing['min'], $processing['max'], $unit ),
			self::range_text( $delivery['min'], $delivery['max'], $unit )
		);
		return [ 'missing' => [], 'calendar' => $calendar, 'policy' => $policy, 'summary' => $summary ];
	}

	public static function encode_policy( ServicePromisePolicy $policy ): string {
		return $policy->to_private_json();
	}

	public static function encode_calendar( BusinessCalendarVersion $calendar ): string {
		return PromiseJson::encode( [ 'calendars' => [ $calendar->private_facts() ] ] );
	}

	/**
	 * @param array<string, string> $input
	 * @return array{weekly: array<string, list<array{open: string, close: string}>>, missing: list<string>}
	 */
	private static function weekly( array $input ): array {
		$days    = [];
		$missing = [];
		foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) {
			$open  = trim( (string) ( $input[ $day . '_open' ] ?? '' ) );
			$close = trim( (string) ( $input[ $day . '_close' ] ?? '' ) );
			if ( '' === $open && '' === $close ) {
				$days[ $day ] = [];
				continue;
			}
			if ( 1 !== preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $open ) || 1 !== preg_match( '/\A(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)\z/', $close ) || strcmp( $open, $close ) >= 0 ) {
				$missing[] = __( 'Each working period needs an opening time before its closing time, or leave that day blank.', 'cetech-woocommerce-delivery-engine' );
				$days[ $day ] = [];
				continue;
			}
			$days[ $day ] = [ [ 'open' => $open, 'close' => $close ] ];
		}
		return [ 'weekly' => $days, 'missing' => $missing ];
	}

	/**
	 * @param array<string, list<array{open: string, close: string}>> $weekly
	 */
	private static function has_hours( array $weekly ): bool {
		foreach ( $weekly as $intervals ) {
			if ( [] !== $intervals ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{min: int, max: int, missing: list<string>}
	 */
	private static function range( string $min, string $max, string $name ): array {
		if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,5})\z/', $min ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,5})\z/', $max ) || (int) $min > (int) $max ) {
			return [ 'min' => 0, 'max' => 0, 'missing' => [ sprintf( __( 'Enter a %s range. The shorter number comes first.', 'cetech-woocommerce-delivery-engine' ), $name ) ] ];
		}
		return [ 'min' => (int) $min, 'max' => (int) $max, 'missing' => [] ];
	}

	/**
	 * @return array{dates: list<string>, invalid: bool}
	 */
	private static function dates( string $raw ): array {
		$dates   = [];
		$invalid = false;
		foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $line ) ) {
				$invalid = true;
				continue;
			}
			$dates[] = $line;
		}
		return [ 'dates' => $dates, 'invalid' => $invalid ];
	}

	private static function identity( string $code ): string {
		$safe = strtolower( (string) preg_replace( '/[^a-zA-Z0-9_.:-]+/', '-', $code ) );
		$safe = trim( $safe, '-_.:' );
		if ( '' === $safe || 1 !== preg_match( '/\A[a-zA-Z0-9]/', $safe ) ) {
			$safe = 'option';
		}
		return substr( $safe, 0, 24 );
	}

	private static function range_text( int $min, int $max, string $unit ): string {
		$label = 'business_days' === $unit ? __( 'business days', 'cetech-woocommerce-delivery-engine' ) : __( 'elapsed minutes', 'cetech-woocommerce-delivery-engine' );
		return $min . '–' . $max . ' ' . $label;
	}

	/**
	 * @param array<string, mixed>              $duration
	 * @param list<string>                      $predecessors
	 * @param array<string, int|string>         $source
	 * @return array<string, mixed>
	 */
	private static function component( string $id, string $role, array $duration, array $predecessors, string $endpoint, string $kind, array $source ): array {
		return [
			'format_version'          => 1,
			'component_id'            => $id,
			'role'                    => $role,
			'duration'                => $duration,
			'operating_calendar'      => null,
			'completion_window_rule'  => 'none',
			'predecessors'            => $predecessors,
			'endpoint'                => $endpoint,
			'endpoint_kind'           => $kind,
			'source'                  => $source,
		];
	}
}
