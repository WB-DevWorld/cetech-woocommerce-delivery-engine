<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Configuration;

final class ConfigurationReasonCode {

	public const MISSING_REQUIRED_FIELD        = 'MISSING_REQUIRED_FIELD';
	public const UNRESOLVED_GLOBAL_VALUE      = 'UNRESOLVED_GLOBAL_VALUE';
	public const UNSUPPORTED_DISABLE          = 'UNSUPPORTED_DISABLE';
	public const INVALID_COLLECTION_OPERATION = 'INVALID_COLLECTION_OPERATION';
	public const INVALID_SCOPE_RELATIONSHIP   = 'INVALID_SCOPE_RELATIONSHIP';
	public const INVALID_REFERENCE            = 'INVALID_REFERENCE';
	public const INVALID_REQUEST              = 'INVALID_REQUEST';

	private function __construct() {
	}
}
