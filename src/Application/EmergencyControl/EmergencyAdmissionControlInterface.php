<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;

interface EmergencyAdmissionControlInterface {
	public function read( int $site_id, ?RequestContext $request = null ): EmergencyControlReadResult;
	public function confirm_enabled( int $site_id, int $expected_revision, ?callable $unchanged_facts = null, ?RequestContext $request = null ): EmergencyControlReadResult;
}
