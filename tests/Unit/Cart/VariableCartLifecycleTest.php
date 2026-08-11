<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Cart;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use PHPUnit\Framework\TestCase;

/**
 * Stage 6A: Cart intent lifecycle tests for variable products.
 *
 * Verifies that variation_id and configuration_fingerprint survive the
 * normalize → fingerprint → session store → restore round-trip, and that
 * different variations produce isolated, non-contaminating hashes.
 */
final class VariableCartLifecycleTest extends TestCase {

	/** Minimum valid base intent for PHP Contract Version 1. */
	private const BASE_INTENT = [
		'contract_version'        => '1',
		'product_id'              => 202,
		'variation_id'            => null,
		'target_type'             => 'variation',
		'target_id'               => 303,
		'display_key'             => 'in_store:delivery:7',
		'fulfilment_availability' => 'in_store',
		'fulfilment_choice'       => 'delivery',
		'delivery_offer_id'       => 7,
		'rule_id'                 => null,
		'issued_at'               => '2026-08-10T00:00:00+00:00',
	];

	private const VALID_SUMMARY = [
		'fulfilment_availability_label' => 'In Store',
		'fulfilment_choice_label'       => 'Delivery',
		'delivery_offer_public_label'   => 'Local Delivery',
		'estimate_text'                 => '1-2 business days',
	];

	// -------------------------------------------------------------------------
	// CANONICAL_INTENT_KEYS includes variation_id and configuration_fingerprint
	// -------------------------------------------------------------------------

	public function test_canonical_intent_keys_contains_variation_id(): void {
		self::assertContains( 'variation_id', CartDeliverySelectionSessionData::CANONICAL_INTENT_KEYS );
	}

	public function test_canonical_intent_keys_contains_configuration_fingerprint(): void {
		self::assertContains( 'configuration_fingerprint', CartDeliverySelectionSessionData::CANONICAL_INTENT_KEYS );
	}

	// -------------------------------------------------------------------------
	// Intent survives normalize/restore round-trip
	// -------------------------------------------------------------------------

	public function test_intent_with_variation_id_survives_normalize(): void {
		$intent = array_merge( self::BASE_INTENT, [
			'variation_id' => 303,
		] );

		$normalized = CartDeliverySelectionSessionData::normalizeIntent( $intent );

		self::assertNotNull( $normalized );
		self::assertSame( 303, $normalized['variation_id'] );
	}

	public function test_intent_with_configuration_fingerprint_survives_normalize(): void {
		$fp     = hash( 'sha256', 'test_config_v1' );
		$intent = array_merge( self::BASE_INTENT, [
			'variation_id'              => 303,
			'configuration_fingerprint' => $fp,
		] );

		$normalized = CartDeliverySelectionSessionData::normalizeIntent( $intent );

		self::assertNotNull( $normalized );
		self::assertArrayHasKey( 'configuration_fingerprint', $normalized );
		self::assertSame( $fp, $normalized['configuration_fingerprint'] );
	}

	public function test_intent_without_configuration_fingerprint_does_not_add_key(): void {
		$intent = array_merge( self::BASE_INTENT, [ 'variation_id' => 303 ] );
		// No configuration_fingerprint key present.

		$normalized = CartDeliverySelectionSessionData::normalizeIntent( $intent );

		self::assertNotNull( $normalized );
		self::assertArrayNotHasKey( 'configuration_fingerprint', $normalized );
	}

	// -------------------------------------------------------------------------
	// Fingerprint includes variation_id binding
	// -------------------------------------------------------------------------

	public function test_fingerprint_includes_variation_id_binding(): void {
		$fp = hash( 'sha256', 'shared_ecr_fingerprint' );

		$simple_intent = array_merge( self::BASE_INTENT, [
			'variation_id'              => null,
			'configuration_fingerprint' => $fp,
		] );

		$variation_intent = array_merge( self::BASE_INTENT, [
			'variation_id'              => 303,
			'configuration_fingerprint' => $fp,
		] );

		self::assertNotSame(
			CartDeliverySelectionFingerprint::fromIntent( $simple_intent ),
			CartDeliverySelectionFingerprint::fromIntent( $variation_intent )
		);
	}

	public function test_fingerprint_differs_for_different_variations_same_display_key(): void {
		$fp = hash( 'sha256', 'shared_ecr_fingerprint' );

		$intent_v1 = array_merge( self::BASE_INTENT, [
			'variation_id'              => 201,
			'configuration_fingerprint' => $fp,
		] );

		$intent_v2 = array_merge( self::BASE_INTENT, [
			'variation_id'              => 202,
			'configuration_fingerprint' => $fp,
		] );

		self::assertNotSame(
			CartDeliverySelectionFingerprint::fromIntent( $intent_v1 ),
			CartDeliverySelectionFingerprint::fromIntent( $intent_v2 )
		);
	}

	public function test_fingerprint_stable_for_same_variation_and_config_fingerprint(): void {
		$fp     = hash( 'sha256', 'stable_fp' );
		$intent = array_merge( self::BASE_INTENT, [
			'variation_id'              => 303,
			'configuration_fingerprint' => $fp,
		] );

		$hash1 = CartDeliverySelectionFingerprint::fromIntent( $intent );
		$hash2 = CartDeliverySelectionFingerprint::fromIntent( $intent );

		self::assertSame( $hash1, $hash2 );
	}

	// -------------------------------------------------------------------------
	// Session restore fails closed on fingerprint mismatch
	// -------------------------------------------------------------------------

	public function test_session_restore_fails_closed_on_fingerprint_mismatch(): void {
		$fp = hash( 'sha256', 'real_ecr_fingerprint' );

		$original_intent = array_merge( self::BASE_INTENT, [
			'variation_id'              => 303,
			'configuration_fingerprint' => $fp,
		] );

		// Compute correct hash for original intent.
		$correct_hash = CartDeliverySelectionFingerprint::fromIntent( $original_intent );

		// Tamper: change fingerprint in stored intent (simulates stale session after config change).
		$tampered_intent                          = $original_intent;
		$tampered_intent['configuration_fingerprint'] = hash( 'sha256', 'different_ecr_fingerprint' );

		$values = [
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $tampered_intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => self::VALID_SUMMARY,
			CartDeliverySelectionCapture::CART_HASH_KEY      => $correct_hash,
		];

		$restored = CartDeliverySelectionSessionData::restoreFromSession( $values );

		// Hash of tampered intent ≠ stored hash → must return null (fail-closed).
		self::assertNull( $restored );
	}

	public function test_session_restore_succeeds_with_matching_hash(): void {
		$fp = hash( 'sha256', 'ecr_fingerprint_v1' );

		$intent = array_merge( self::BASE_INTENT, [
			'variation_id'              => 303,
			'configuration_fingerprint' => $fp,
		] );

		$hash = CartDeliverySelectionFingerprint::fromIntent( $intent );

		$values = [
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => self::VALID_SUMMARY,
			CartDeliverySelectionCapture::CART_HASH_KEY      => $hash,
		];

		$restored = CartDeliverySelectionSessionData::restoreFromSession( $values );

		self::assertNotNull( $restored );
		self::assertSame( 303, $restored['intent']['variation_id'] );
		self::assertSame( $fp, $restored['intent']['configuration_fingerprint'] );
		self::assertSame( $hash, $restored['hash'] );
	}

	// -------------------------------------------------------------------------
	// Two different variations of same parent have isolated hashes
	// -------------------------------------------------------------------------

	public function test_two_variations_same_parent_have_isolated_hashes(): void {
		$fp = hash( 'sha256', 'shared_parent_ecr_fp' );

		$intent_var1 = array_merge( self::BASE_INTENT, [
			'variation_id'              => 301,
			'target_id'                 => 301,
			'configuration_fingerprint' => $fp,
		] );

		$intent_var2 = array_merge( self::BASE_INTENT, [
			'variation_id'              => 302,
			'target_id'                 => 302,
			'configuration_fingerprint' => $fp,
		] );

		$hash1 = CartDeliverySelectionFingerprint::fromIntent( $intent_var1 );
		$hash2 = CartDeliverySelectionFingerprint::fromIntent( $intent_var2 );

		self::assertNotSame( $hash1, $hash2 );

		// Each hash is also stable on its own.
		self::assertSame( $hash1, CartDeliverySelectionFingerprint::fromIntent( $intent_var1 ) );
		self::assertSame( $hash2, CartDeliverySelectionFingerprint::fromIntent( $intent_var2 ) );
	}

	// -------------------------------------------------------------------------
	// Simple and variable intents do not cross-contaminate
	// -------------------------------------------------------------------------

	public function test_simple_and_variable_intents_do_not_cross_contaminate(): void {
		$fp = hash( 'sha256', 'ecr_fp' );

		$simple_intent = [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => 'in_store:delivery:7',
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'delivery',
			'delivery_offer_id'       => 7,
			'rule_id'                 => null,
			'issued_at'               => '2026-08-10T00:00:00+00:00',
			'configuration_fingerprint' => $fp,
		];

		$variable_intent = array_merge( $simple_intent, [
			'product_id'    => 202,
			'variation_id'  => 303,
			'target_type'   => 'variation',
			'target_id'     => 303,
		] );

		// Hashes must be different.
		$simple_hash   = CartDeliverySelectionFingerprint::fromIntent( $simple_intent );
		$variable_hash = CartDeliverySelectionFingerprint::fromIntent( $variable_intent );

		self::assertNotSame( $simple_hash, $variable_hash );

		// normalizeIntent preserves variation_id=null for simple.
		$normalized_simple = CartDeliverySelectionSessionData::normalizeIntent( $simple_intent );
		self::assertNotNull( $normalized_simple );
		self::assertNull( $normalized_simple['variation_id'] );

		// normalizeIntent preserves variation_id=303 for variable.
		$normalized_variable = CartDeliverySelectionSessionData::normalizeIntent( $variable_intent );
		self::assertNotNull( $normalized_variable );
		self::assertSame( 303, $normalized_variable['variation_id'] );
	}

	public function test_legacy_simple_intent_without_fingerprint_has_stable_seven_part_hash(): void {
		$legacy_intent = [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => 'in_store:delivery:7',
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'delivery',
			'delivery_offer_id'       => 7,
			'rule_id'                 => null,
			'issued_at'               => '2026-08-10T00:00:00+00:00',
		];

		$parts = CartDeliverySelectionFingerprint::fingerprintParts( $legacy_intent );

		// Legacy: exactly 7 parts (no configuration_fingerprint part).
		self::assertCount( 7, $parts );
	}
}
