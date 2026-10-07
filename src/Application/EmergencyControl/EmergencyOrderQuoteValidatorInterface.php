<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

interface EmergencyOrderQuoteValidatorInterface {
	public function validate_order( \WC_Order $order, string $route ): bool;
	public function fingerprint( \WC_Order $order ): ?string;
}
