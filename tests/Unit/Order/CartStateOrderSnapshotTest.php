<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionReconciler;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use PHPUnit\Framework\TestCase;

final class CartStateOrderSnapshotTest extends TestCase {

	public function test_refreshed_cart_line_is_the_only_state_available_for_snapshot(): void {
		$stored_intent = [
			'contract_version'          => '1',
			'product_id'                => 44,
			'variation_id'              => null,
			'target_type'               => 'product',
			'target_id'                 => 44,
			'display_key'               => 'in_warehouse:delivery:10',
			'fulfilment_availability'   => 'in_warehouse',
			'fulfilment_choice'         => 'delivery',
			'delivery_offer_id'         => 10,
			'rule_id'                   => null,
			'issued_at'                 => '2026-08-01T00:00:00+00:00',
			'configuration_fingerprint' => 'old',
		];
		$cart_item = [
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $stored_intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
				'delivery_offer_public_label' => 'Old label',
				'estimate_text'               => 'Old ETA',
			],
			CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $stored_intent ),
		];

		$fresh_intent = $stored_intent;
		$fresh_intent['configuration_fingerprint'] = 'new';
		$fresh_intent['issued_at'] = '2026-09-01T00:00:00+00:00';

		$refreshed = CartDeliverySelectionReconciler::apply_refresh(
			$cart_item,
			\CetechDeliveryEngine\Application\Cart\CartLineCustomerIdentity::overlayCustomerOwned(
				CartDeliverySelectionSessionData::normalizeIntent( $fresh_intent ) ?? $fresh_intent,
				$stored_intent
			),
			[
				'delivery_offer_public_label' => 'New label',
				'estimate_text'               => 'New ETA',
				'fulfilment_choice_label'     => 'Delivery',
			]
		);

		self::assertSame( 'New label', $refreshed[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['delivery_offer_public_label'] );
		self::assertSame( 'New ETA', $refreshed[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]['estimate_text'] );
		self::assertSame( 'new', $refreshed[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['configuration_fingerprint'] );
		self::assertSame( '2026-08-01T00:00:00+00:00', $refreshed[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]['issued_at'] );
		self::assertSame(
			CartDeliverySelectionFingerprint::fromIntent( $refreshed[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ),
			$refreshed[ CartDeliverySelectionCapture::CART_HASH_KEY ]
		);
	}

	public function test_reconciler_does_not_write_historical_order_snapshot_meta(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$reconciler  = (string) file_get_contents( $plugin_root . '/src/Application/Cart/CartDeliverySelectionReconciler.php' );
		$identity    = (string) file_get_contents( $plugin_root . '/src/Application/Cart/CartLineCustomerIdentity.php' );
		$reselection = (string) file_get_contents( $plugin_root . '/src/Application/Cart/CartDeliveryReselectionService.php' );

		foreach ( [ $reconciler, $identity, $reselection ] as $source ) {
			self::assertStringNotContainsString( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $source );
			self::assertStringNotContainsString( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, $source );
			self::assertStringNotContainsString( 'WC_Order', $source );
			self::assertStringNotContainsString( 'update_meta_data', $source );
		}

		$persister = (string) file_get_contents( $plugin_root . '/src/Application/Order/OrderDeliverySnapshotPersister.php' );
		self::assertStringContainsString( 'OrderDeliverySnapshot', $persister );
	}
}
