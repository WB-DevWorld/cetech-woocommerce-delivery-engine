<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteShape, QuoteStorageCodec};

/** Canonical private row mirrors; hashes detect corruption and never grant authority. */
final class FixedPromotionBudgetRecordCodec {
	public static function account_values( FixedPromotionBudgetState $account ): array {
		$json = $account->to_private_json(); self::bounded_json( $json, FixedPromotionBudgetSchema::ACCOUNT_JSON_BYTES );
		return [ 'site_id' => $account->site_id(), 'account_key' => $account->account_key(), 'format_version' => 1, 'revision' => $account->revision(), 'state_json' => $json, 'state_digest' => self::account_digest( $account ) ];
	}
	public static function reservation_values( FixedPromotionBudgetReservation $reservation ): array {
		$json = $reservation->to_private_json(); self::bounded_json( $json, FixedPromotionBudgetSchema::RESERVATION_JSON_BYTES ); $request = $reservation->request();
		return [ 'site_id' => $request->site_id(), 'claim_key' => $request->claim_key(), 'account_key' => $request->account_key(), 'format_version' => 1, 'state' => $reservation->state(), 'revision' => $reservation->revision(), 'reservation_json' => $json, 'reservation_digest' => self::reservation_digest( $reservation ) ];
	}
	public static function account_from_row( array $row ): FixedPromotionBudgetState {
		self::row( FixedPromotionBudgetSchema::ACCOUNTS_SUFFIX, $row ); self::bounded_json( $row['state_json'], FixedPromotionBudgetSchema::ACCOUNT_JSON_BYTES );
		$account = FixedPromotionBudgetState::from_json( $row['state_json'] ); self::mirrors( $row, self::account_values( $account ) ); return $account;
	}
	public static function reservation_from_row( array $row ): FixedPromotionBudgetReservation {
		self::row( FixedPromotionBudgetSchema::RESERVATIONS_SUFFIX, $row ); self::bounded_json( $row['reservation_json'], FixedPromotionBudgetSchema::RESERVATION_JSON_BYTES );
		$reservation = FixedPromotionBudgetReservation::from_json( $row['reservation_json'] ); self::mirrors( $row, self::reservation_values( $reservation ) ); return $reservation;
	}
	public static function account_digest( FixedPromotionBudgetState $account ): string { return hash( 'sha256', 'cetech-fixed-promotion-budget-account-record-v1:' . $account->to_private_json() ); }
	public static function reservation_digest( FixedPromotionBudgetReservation $reservation ): string { return hash( 'sha256', 'cetech-fixed-promotion-budget-reservation-record-v1:' . $reservation->to_private_json() ); }
	/** SQL applies the byte cap before transporting private payloads to PHP. */
	public static function projection( string $suffix ): string {
		$limits = FixedPromotionBudgetSchema::payload_limits( $suffix ); $parts = [];
		foreach ( FixedPromotionBudgetSchema::columns( $suffix ) as $field => $_ ) { $parts[] = isset( $limits[$field] ) ? "CASE WHEN OCTET_LENGTH(`{$field}`) <= {$limits[$field]} THEN `{$field}` ELSE NULL END AS `{$field}`" : "`{$field}`"; }
		foreach ( $limits as $field => $_ ) { $parts[] = "OCTET_LENGTH(`{$field}`) AS `__{$field}_bytes`"; }
		return implode( ', ', $parts );
	}
	public static function bounded_row( string $suffix, array $raw ): array {
		$fields = array_keys( FixedPromotionBudgetSchema::columns( $suffix ) ); $limits = FixedPromotionBudgetSchema::payload_limits( $suffix ); $markers = [];
		foreach ( $limits as $field => $_ ) { $markers[] = '__' . $field . '_bytes'; } QuoteShape::fields( $raw, [ ...$fields, ...$markers ] );
		foreach ( $limits as $field => $limit ) { $marker = '__' . $field . '_bytes'; $bytes = QuoteStorageCodec::integer( $raw[$marker], 0, $limit ); self::bounded_json( $raw[$field], $limit ); if ( strlen( $raw[$field] ) !== $bytes ) { QuoteShape::invalid(); } unset( $raw[$marker] ); }
		return $raw;
	}
	private static function row( string $suffix, array $row ): void { QuoteShape::fields( $row, array_keys( FixedPromotionBudgetSchema::columns( $suffix ) ) ); QuoteStorageCodec::integer( $row['id'] ); }
	private static function mirrors( array $row, array $expected ): void {
		foreach ( $expected as $field => $value ) {
			if ( is_int( $value ) ) { if ( QuoteStorageCodec::integer( $row[$field] ) !== $value ) { QuoteShape::invalid(); } }
			elseif ( ! is_string( $row[$field] ) || $row[$field] !== $value ) { QuoteShape::invalid(); }
		}
	}
	private static function bounded_json( mixed $json, int $limit ): void { if ( ! is_string( $json ) || '' === $json || strlen( $json ) > $limit ) { QuoteShape::invalid(); } }
}
