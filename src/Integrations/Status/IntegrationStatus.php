<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Status;

/**
 * Operator-facing status for one optional integration.
 */
final class IntegrationStatus {

	public const STATE_NOT_INSTALLED = 'not_installed';

	public const STATE_INSTALLED_INACTIVE = 'installed_inactive';

	public const STATE_DETECTED = 'detected';

	public const STATE_SUPPORTED = 'supported';

	public const STATE_ACTIVE = 'active';

	public const STATE_COMPATIBILITY_UNVERIFIED = 'compatibility_not_yet_verified';

	public const STATE_ADAPTER_NOT_IMPLEMENTED = 'adapter_not_implemented';

	public const STATE_UNSUPPORTED_VERSION = 'unsupported_version';

	public const STATE_CONFIGURATION_REQUIRED = 'configuration_required';

	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $state,
		public readonly ?string $version,
		public readonly bool $installed,
		public readonly bool $dependency_active,
		public readonly bool $adapter_implemented,
		public readonly bool $currently_in_use,
		public readonly string $summary,
		public readonly string $detail
	) {
	}

	public function state_label(): string {
		return match ( $this->state ) {
			self::STATE_NOT_INSTALLED => __( 'Not installed', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_INSTALLED_INACTIVE => __( 'Installed but inactive', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_DETECTED => $this->detected_version_label(),
			self::STATE_SUPPORTED => __( 'Supported', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_ACTIVE => __( 'Active', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_COMPATIBILITY_UNVERIFIED => __( 'Compatibility not yet verified', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_ADAPTER_NOT_IMPLEMENTED => __( 'Adapter not implemented', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_UNSUPPORTED_VERSION => __( 'Unsupported version', 'cetech-woocommerce-delivery-engine' ),
			self::STATE_CONFIGURATION_REQUIRED => __( 'Configuration required', 'cetech-woocommerce-delivery-engine' ),
			default => $this->state,
		};
	}

	public function currently_in_use_label(): string {
		return $this->currently_in_use
			? __( 'Yes', 'cetech-woocommerce-delivery-engine' )
			: __( 'No', 'cetech-woocommerce-delivery-engine' );
	}

	public function adapter_label(): string {
		return $this->adapter_implemented
			? __( 'Implemented', 'cetech-woocommerce-delivery-engine' )
			: __( 'Not implemented', 'cetech-woocommerce-delivery-engine' );
	}

	private function detected_version_label(): string {
		if ( null !== $this->version && '' !== $this->version ) {
			return sprintf(
				/* translators: %s: detected software version */
				__( 'Detected — version %s', 'cetech-woocommerce-delivery-engine' ),
				$this->version
			);
		}

		return __( 'Detected', 'cetech-woocommerce-delivery-engine' );
	}
}
