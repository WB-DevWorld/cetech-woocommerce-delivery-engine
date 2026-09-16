<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Capabilities;

/**
 * Registers granular delivery-engine capabilities on WordPress roles.
 *
 * Capabilities must self-heal on version change when an active plugin folder is
 * replaced without reactivation. Never remove unrelated WordPress capabilities.
 *
 * Administrator is a protected full-access role: required Delivery Engine
 * capabilities are always retained and repaired without resetting subordinate roles.
 */
final class Capabilities {

	public const VIEW = 'view_delivery_engine';

	public const SITE_WIDE = 'manage_site_wide_defaults';

	public const PICKUP = 'manage_pickup_locations';

	public const DIAGNOSTICS = 'view_delivery_diagnostics';

	/**
	 * Bump when the capability matrix changes so existing installs receive new caps
	 * without requiring a fresh activation.
	 */
	public const VERSION = 4;

	public const VERSION_OPTION = 'cetech_de_capabilities_version';

	/** @var list<string> */
	public const ALL = [
		self::VIEW,
		'manage_delivery_settings',
		self::SITE_WIDE,
		'manage_delivery_offers',
		'manage_delivery_rate_cards',
		'manage_delivery_zones',
		self::PICKUP,
		'manage_logistics_profiles',
		'manage_private_sources',
		'manage_product_delivery_rules',
		'manage_shipments',
		'update_shipment_status',
		'view_private_delivery_costs',
		'view_private_origins',
		'manage_delivery_integrations',
		'view_delivery_logs',
		'import_delivery_data',
		self::DIAGNOSTICS,
	];

	/** @var list<string> */
	public const NORMAL_MANAGEMENT = [
		'manage_delivery_settings',
		self::SITE_WIDE,
		'manage_delivery_offers',
		'manage_delivery_rate_cards',
		'manage_delivery_zones',
		self::PICKUP,
		'manage_logistics_profiles',
		'manage_private_sources',
		'manage_product_delivery_rules',
	];

	/**
	 * Full protected Administrator Delivery Engine capability set.
	 * Recovery and Access save must never leave Administrator without these.
	 *
	 * @var list<string>
	 */
	public const ADMINISTRATOR_RECOVERY = self::ALL;

	/** @var list<string> */
	private const ROLES = [
		'administrator',
		'shop_manager',
	];

	/** @var list<string> */
	private const ADMINISTRATOR_ONLY = [
		self::DIAGNOSTICS,
	];

	public function register(): void {
		foreach ( self::ROLES as $role_slug ) {
			$role = get_role( $role_slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::ALL as $capability ) {
				if ( in_array( $capability, self::ADMINISTRATOR_ONLY, true ) && 'administrator' !== $role_slug ) {
					continue;
				}
				$role->add_cap( $capability );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Idempotent upgrade path for folder-replace installs where activation does not run.
	 *
	 * Version 1 and earlier still receive the historical default matrix. Version 2+
	 * upgrades are additive so Access-editor customizations are not reset.
	 *
	 * When the stored version already matches, Administrator required capabilities are
	 * still self-healed. Subordinate roles are never rewritten by that path.
	 */
	public function ensure_current(): void {
		$stored = (int) get_option( self::VERSION_OPTION, 0 );

		if ( self::VERSION !== $stored ) {
			if ( $stored < 2 ) {
				$this->register();
				return;
			}

			if ( $stored < 3 ) {
				$this->grant_v3_caps_additively();
			}

			// Version 4 records WCFM vendor capability stripping. The strip itself
			// is performed by WcfmVendorIsolation on every boot so later WCFM
			// installs are covered without a schema change.
			update_option( self::VERSION_OPTION, self::VERSION, false );
		}

		$this->ensure_administrator_recovery();
	}

	/**
	 * Repairs administrator full Delivery Engine access only. Does not reset Shop Manager
	 * or custom-role assignments made in Settings → Access.
	 */
	public function sync(): void {
		$this->ensure_administrator_recovery();
	}

	public function unregister(): void {
		foreach ( $this->known_role_slugs() as $role_slug ) {
			$role = get_role( $role_slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::ALL as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * @return list<string>
	 */
	public function capabilities_for_role( string $role_slug ): array {
		$caps = [];
		foreach ( self::ALL as $capability ) {
			if ( in_array( $capability, self::ADMINISTRATOR_ONLY, true ) && 'administrator' !== $role_slug ) {
				continue;
			}
			$caps[] = $capability;
		}

		return $caps;
	}

	/**
	 * Grants every Delivery Engine capability to the WordPress Administrator role.
	 * Idempotent. Never modifies subordinate roles.
	 */
	public function ensure_administrator_recovery(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}

		foreach ( self::ADMINISTRATOR_RECOVERY as $capability ) {
			$role->add_cap( $capability );
		}
	}

	public function administrator_missing_required_capabilities(): bool {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return false;
		}

		foreach ( self::ADMINISTRATOR_RECOVERY as $capability ) {
			if ( ! $this->role_has_capability( $role, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	private function grant_v3_caps_additively(): void {
		foreach ( $this->known_role_slugs() as $role_slug ) {
			$role = get_role( $role_slug );
			if ( null === $role ) {
				continue;
			}

			if ( $this->role_has_any_delivery_capability( $role ) ) {
				$role->add_cap( self::VIEW );
			}

			if ( $this->role_has_capability( $role, 'manage_delivery_zones' ) ) {
				$role->add_cap( self::PICKUP );
			}

			if ( $this->role_has_capability( $role, 'manage_delivery_settings' ) ) {
				$role->add_cap( self::SITE_WIDE );
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function known_role_slugs(): array {
		$slugs = self::ROLES;

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();
			if ( is_object( $wp_roles ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
				$slugs = array_values( array_unique( array_merge( $slugs, array_map( 'strval', array_keys( $wp_roles->roles ) ) ) ) );
			}
		}

		return $slugs;
	}

	private function role_has_any_delivery_capability( object $role ): bool {
		foreach ( self::ALL as $capability ) {
			if ( self::VIEW === $capability ) {
				continue;
			}
			if ( $this->role_has_capability( $role, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	private function role_has_capability( object $role, string $capability ): bool {
		if ( method_exists( $role, 'has_cap' ) ) {
			return (bool) $role->has_cap( $capability );
		}

		$caps = is_array( $role->capabilities ?? null ) ? $role->capabilities : [];

		return ! empty( $caps[ $capability ] );
	}
}
