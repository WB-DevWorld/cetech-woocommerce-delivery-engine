<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Exact private schemas. Request strings never acquire scalar authority by coercion. */
final class PromiseShape {
	public static function invalid(): never { throw new \InvalidArgumentException( 'Invalid service promise facts.' ); }
	public static function fields( array $data, array $fields ): void {
		if ( array_is_list( $data ) || count( $data ) !== count( $fields ) || [] !== array_diff( $fields, array_keys( $data ) ) ) { self::invalid(); }
	}
	public static function object( mixed $value ): array { if ( ! is_array( $value ) || array_is_list( $value ) ) { self::invalid(); } return $value; }
	public static function list( mixed $value, int $min = 0, int $max = 200 ): array {
		if ( $min < 0 || $max < $min || ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) < $min || count( $value ) > $max ) { self::invalid(); } return $value;
	}
	public static function integer( mixed $value, int $min = 0, int $max = PHP_INT_MAX ): int { if ( $max < $min || ! is_int( $value ) || $value < $min || $value > $max ) { self::invalid(); } return $value; }
	public static function choice( mixed $value, array $allowed ): string { if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) { self::invalid(); } return $value; }
	public static function id( mixed $value ): string { if ( ! is_string( $value ) || strlen( $value ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]*\z/D', $value ) ) { self::invalid(); } return $value; }
	public static function digest( mixed $value ): string { if ( ! is_string( $value ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $value ) ) { self::invalid(); } return $value; }
	public static function text( mixed $value, int $max ): string {
		if ( $max < 1 || ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $max || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x1F\x7F<>]/u', $value ) ) { self::invalid(); } return $value;
	}
	public static function boolean( mixed $value ): bool { if ( ! is_bool( $value ) ) { self::invalid(); } return $value; }
	public static function instant( mixed $value ): RuleTime { if ( ! is_string( $value ) ) { self::invalid(); } try { return RuleTime::parse( $value ); } catch ( \Throwable ) { self::invalid(); } }
	public static function timezone( mixed $value ): string {
		if ( ! is_string( $value ) || ! in_array( $value, \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ), true ) ) { self::invalid(); } return $value;
	}
}
