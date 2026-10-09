<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Strict SQL scalars only: native decimal strings may normalize, arbitrary coercion may not. */
final class PromiseStorageCodec {
	public const ROW_BYTES = 98304;
	public static function exact( array $row, array $fields ): void { PromiseShape::fields( $row, $fields ); }
	public static function integer( mixed $value, int $min = 1, int $max = PHP_INT_MAX ): int {
		if ( is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\z/D', $value ) && ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ) ) { $value = (int) $value; }
		return PromiseShape::integer( $value, $min, $max );
	}
	public static function nullable_integer( mixed $value ): ?int { return null === $value ? null : self::integer( $value ); }
	public static function uuid( mixed $value ): string { if ( ! RequestContext::is_valid_identifier( $value ) ) { PromiseShape::invalid(); } return $value; }
	public static function time( mixed $value ): RuleTime { return PromiseShape::instant( $value ); }
	public static function nullable_time( mixed $value ): ?RuleTime { return null === $value ? null : self::time( $value ); }
	public static function bytes( mixed $value, int $max ): string { if ( ! is_string( $value ) || strlen( $value ) > $max ) { PromiseShape::invalid(); } return $value; }
	public static function budget( array $row ): void {
		$bytes = 0;
		foreach ( $row as $value ) { if ( ! is_null( $value ) && ! is_int( $value ) && ! is_string( $value ) ) { PromiseShape::invalid(); } $bytes += is_string( $value ) ? strlen( $value ) : strlen( (string) $value ); if ( $bytes > self::ROW_BYTES ) { PromiseShape::invalid(); } }
	}
	public static function chronological( RuleTime $from, ?RuleTime $until ): void { if ( null !== $until && $from->compare( $until ) >= 0 ) { PromiseShape::invalid(); } }
}
