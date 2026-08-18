<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;

/**
 * Authoritative normal shipment status transition matrix.
 *
 * Corrective changes are a separate path and are not listed here.
 */
final class ShipmentStatusTransitionPolicy {

	/**
	 * @return list<ShipmentStatus>
	 */
	public static function normal_targets( ShipmentStatus $from ): array {
		return match ( $from ) {
			ShipmentStatus::AwaitingFulfilment => [
				ShipmentStatus::Processing,
				ShipmentStatus::Cancelled,
			],
			ShipmentStatus::Processing => [
				ShipmentStatus::Dispatched,
				ShipmentStatus::Delayed,
				ShipmentStatus::Cancelled,
			],
			ShipmentStatus::Dispatched => [
				ShipmentStatus::InTransit,
				ShipmentStatus::Delayed,
			],
			ShipmentStatus::InTransit => [
				ShipmentStatus::Delivered,
				ShipmentStatus::Delayed,
			],
			ShipmentStatus::Delayed => [
				ShipmentStatus::Processing,
				ShipmentStatus::Dispatched,
				ShipmentStatus::InTransit,
				ShipmentStatus::Delivered,
				ShipmentStatus::Cancelled,
			],
			ShipmentStatus::Delivered, ShipmentStatus::Cancelled => [],
		};
	}

	public static function allows_normal( ShipmentStatus $from, ShipmentStatus $to ): bool {
		foreach ( self::normal_targets( $from ) as $allowed ) {
			if ( $allowed === $to ) {
				return true;
			}
		}

		return false;
	}

	public static function is_terminal( ShipmentStatus $status ): bool {
		return ShipmentStatus::Delivered === $status || ShipmentStatus::Cancelled === $status;
	}

	public static function allows_automatic_cancel( ShipmentStatus $from ): bool {
		return ShipmentStatus::AwaitingFulfilment === $from
			|| ShipmentStatus::Processing === $from;
	}

	public static function has_physically_progressed( ShipmentStatus $status ): bool {
		return in_array(
			$status,
			[
				ShipmentStatus::Dispatched,
				ShipmentStatus::InTransit,
				ShipmentStatus::Delayed,
				ShipmentStatus::Delivered,
			],
			true
		);
	}

	public static function requires_reason( ShipmentStatus $from, ShipmentStatus $to, bool $correction ): bool {
		if ( $correction ) {
			return true;
		}

		if ( ShipmentStatus::Delayed === $to || ShipmentStatus::Cancelled === $to ) {
			return true;
		}

		return ShipmentStatus::Delayed === $from;
	}
}
