<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Order;

/** Explicit historical display only. The caller authorizes the exact order before loading. */
final class DeliveryQuoteSnapshotProjection {
	public static function for_customer( DeliveryQuoteSnapshotReadResult $read ): array {
		if ( null === $read->envelope ) { return [ 'status' => $read->status, 'groups' => [] ]; }
		$groups = [];
		foreach ( $read->envelope->private_facts()['money_receipt']['groups'] as $group ) {
			$groups[] = [ 'customer_label' => $group['customer_label'], 'list' => $group['list'], 'final' => $group['final'], 'tax' => $group['tax'], 'total' => $group['total'], 'promotion' => $group['promotion'], 'rounded_tax' => $group['rounded_tax'], 'display_total' => $group['display_total'] ];
		}
		return [ 'status' => 'recorded', 'groups' => $groups ];
	}
	public static function diagnostic( DeliveryQuoteSnapshotReadResult $read ): array { return [ 'status' => $read->status, 'format_supported' => $read->supported() ]; }
}
