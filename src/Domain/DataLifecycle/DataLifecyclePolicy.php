<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

/** Finite dispositions. Owner expiry is not a grant to the maintenance worker. */
enum DataLifecyclePolicy: string {

	case Preserve = 'preserve';
	case OwnerExpiryOnly = 'owner_expiry_only';
	case ManagedCacheExpiry = 'managed_cache_expiry';
	case ManagedCacheRemoval = 'managed_cache_removal';
	case ActivationNoticeRemoval = 'activation_notice_removal';
	case CapabilityMarkerRemoval = 'capability_marker_removal';
	case UninstallIntentCompletion = 'uninstall_intent_completion';
	case RolePermissionRemoval = 'role_permission_removal';

	public function cleanup_eligible(): bool {
		return self::ManagedCacheExpiry === $this;
	}

	public function sql_row_removal_eligible(): bool {
		return in_array( $this, [ self::ManagedCacheExpiry, self::ManagedCacheRemoval ], true );
	}
}
