<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Strict native SQL row values, without general coercion or raw error echo. */
final class RuleRecordCodec {
	public static function exact( array $row, array $fields ): void {
		if ( count( $row ) !== count( $fields ) || [] !== array_diff( $fields, array_keys( $row ) ) ) {
			throw new \InvalidArgumentException( 'Incomplete or unknown rule record fields.' );
		}
	}

	public static function integer( mixed $value, int $minimum = 1 ): int {
		if ( is_int( $value ) && $value >= $minimum ) { return $value; }
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) > 0 ) || (int) $value < $minimum ) {
			throw new \InvalidArgumentException( 'Invalid rule record integer.' );
		}
		return (int) $value;
	}

	public static function nullable_id( mixed $value ): ?int { return null === $value ? null : self::integer( $value ); }

	public static function priority( mixed $value ): int {
		if ( is_string( $value ) ) {
			if ( 1 !== preg_match( '/\A(?:0|-?[1-9][0-9]*)\z/D', $value ) || strlen( $value ) > 11 ) {
				throw new \InvalidArgumentException( 'Invalid rule priority.' );
			}
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < -2147483648 || $value > 2147483647 ) {
			throw new \InvalidArgumentException( 'Invalid rule priority.' );
		}
		return $value;
	}

	public static function uuid( mixed $value ): string {
		if ( ! RequestContext::is_valid_identifier( $value ) ) {
			throw new \InvalidArgumentException( 'Invalid rule UUID.' );
		}
		return $value;
	}

	public static function digest( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid rule digest.' );
		}
		return $value;
	}

	public static function code( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-z][a-z0-9_.-]{0,95}\z/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid rule code.' );
		}
		return $value;
	}

	public static function reason( mixed $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 512 || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid private rule change reason.' );
		}
		return $value;
	}

	public static function time( mixed $value ): RuleTime {
		if ( ! is_string( $value ) ) { throw new \InvalidArgumentException( 'Invalid rule record time.' ); }
		return RuleTime::parse( $value );
	}

	public static function nullable_time( mixed $value ): ?RuleTime { return null === $value ? null : self::time( $value ); }
}
