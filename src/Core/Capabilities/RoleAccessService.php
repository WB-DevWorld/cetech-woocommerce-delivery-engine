<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Capabilities;

/**
 * Real WordPress role → Delivery Engine capability manager.
 *
 * WordPress capabilities remain the authority. This service only grants or
 * revokes existing Delivery Engine capabilities on subordinate roles that exist.
 *
 * Administrator is never a configurable Access matrix row: Access save always
 * restores the full protected Administrator capability set and ignores revoke attempts.
 */
final class RoleAccessService {

	public const PERMISSION_VIEW = 'view';

	public const PERMISSION_SITE_WIDE = 'site_wide';

	public const PERMISSION_OPTIONS = 'options';

	public const PERMISSION_AREAS = 'areas';

	public const PERMISSION_CHARGES = 'charges';

	public const PERMISSION_PICKUP = 'pickup';

	public const PERMISSION_EXCEPTIONS = 'exceptions';

	public const PERMISSION_SETTINGS = 'settings';

	public const PERMISSION_DIAGNOSTICS = 'diagnostics';

	public const PERMISSION_SHIPMENTS = 'shipments';

	public const PERMISSION_SHIPMENT_STATUS = 'shipment_status';

	/**
	 * @return list<array{
	 *     key: string,
	 *     label: string,
	 *     capability: string,
	 *     implies_view: bool,
	 *     locked_for_administrator: bool
	 * }>
	 */
	public static function permissions(): array {
		return [
			[
				'key'                      => self::PERMISSION_VIEW,
				'label'                    => __( 'View Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => Capabilities::VIEW,
				'implies_view'             => false,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_SITE_WIDE,
				'label'                    => __( 'Manage Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => Capabilities::SITE_WIDE,
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_OPTIONS,
				'label'                    => __( 'Manage Delivery Options', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'manage_delivery_offers',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_AREAS,
				'label'                    => __( 'Manage Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'manage_delivery_zones',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_CHARGES,
				'label'                    => __( 'Manage Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'manage_delivery_rate_cards',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_PICKUP,
				'label'                    => __( 'Manage Pickup Locations', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => Capabilities::PICKUP,
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_EXCEPTIONS,
				'label'                    => __( 'Manage Product Exceptions', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'manage_product_delivery_rules',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_SETTINGS,
				'label'                    => __( 'Manage Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'manage_delivery_settings',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_DIAGNOSTICS,
				'label'                    => __( 'View Technical Diagnostics', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => Capabilities::DIAGNOSTICS,
				'implies_view'             => false,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_SHIPMENTS,
				'label'                    => __( 'Manage Shipments', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'manage_shipments',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
			[
				'key'                      => self::PERMISSION_SHIPMENT_STATUS,
				'label'                    => __( 'Update shipment status', 'cetech-woocommerce-delivery-engine' ),
				'capability'               => 'update_shipment_status',
				'implies_view'             => true,
				'locked_for_administrator' => true,
			],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function managed_capabilities(): array {
		$caps = [];
		foreach ( self::permissions() as $permission ) {
			$caps[] = $permission['capability'];
		}

		return array_values( array_unique( $caps ) );
	}

	public static function capability_for( string $permission_key ): ?string {
		foreach ( self::permissions() as $permission ) {
			if ( $permission['key'] === $permission_key ) {
				return $permission['capability'];
			}
		}

		return null;
	}

	public function can_edit(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Roles that currently exist on this WordPress installation.
	 *
	 * @return list<array{slug: string, name: string}>
	 */
	public function roles(): array {
		$roles = [];

		if ( function_exists( 'wp_roles' ) ) {
			$wp_roles = wp_roles();
			if ( is_object( $wp_roles ) && isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
				foreach ( $wp_roles->roles as $slug => $role ) {
					$slug = sanitize_key( (string) $slug );
					if ( '' === $slug || null === get_role( $slug ) ) {
						continue;
					}
					$name = is_array( $role ) ? (string) ( $role['name'] ?? $slug ) : $slug;
					if ( function_exists( 'translate_user_role' ) ) {
						$name = translate_user_role( $name );
					}
					$roles[] = [
						'slug' => $slug,
						'name' => $name,
					];
				}
			}
		}

		if ( [] === $roles ) {
			foreach ( [ 'administrator', 'shop_manager' ] as $slug ) {
				if ( null === get_role( $slug ) ) {
					continue;
				}
				$roles[] = [
					'slug' => $slug,
					'name' => 'administrator' === $slug ? 'Administrator' : 'Shop Manager',
				];
			}
		}

		return $roles;
	}

	/**
	 * Subordinate WordPress roles that may appear in the editable Access matrix.
	 * Administrator is intentionally excluded.
	 *
	 * @return list<array{slug: string, name: string}>
	 */
	public function editable_roles(): array {
		$editable = [];
		foreach ( $this->roles() as $role ) {
			if ( 'administrator' === $role['slug'] ) {
				continue;
			}
			$editable[] = $role;
		}

		return $editable;
	}

	/**
	 * @return array<string, array<string, bool>>
	 */
	public function current_matrix(): array {
		$matrix = [];

		foreach ( $this->editable_roles() as $role ) {
			$wp_role = get_role( $role['slug'] );
			$granted = [];
			foreach ( self::permissions() as $permission ) {
				$granted[ $permission['key'] ] = null !== $wp_role && $this->role_has_capability( $wp_role, $permission['capability'] );
			}
			$matrix[ $role['slug'] ] = $granted;
		}

		return $matrix;
	}

	public function role_has_permission( string $role_slug, string $permission_key ): bool {
		$capability = self::capability_for( $permission_key );
		if ( null === $capability ) {
			return false;
		}

		$role = get_role( $role_slug );

		return null !== $role && $this->role_has_capability( $role, $capability );
	}

	/**
	 * Apply an Access matrix for subordinate roles that exist. Administrator rows are
	 * ignored and the protected Administrator capability set is always restored.
	 * Unknown roles and unrelated WordPress capabilities are ignored.
	 *
	 * @param array<string, mixed> $submitted
	 */
	public function apply( array $submitted ): void {
		$permissions = self::permissions();

		$this->protect_administrator();

		foreach ( $this->editable_roles() as $role_meta ) {
			$slug = $role_meta['slug'];
			$role = get_role( $slug );
			if ( null === $role ) {
				continue;
			}

			$row = isset( $submitted[ $slug ] ) && is_array( $submitted[ $slug ] ) ? $submitted[ $slug ] : [];
			$grant_view = false;

			foreach ( $permissions as $permission ) {
				$enabled = $this->posted_enabled( $row, $permission['key'] );

				if ( $enabled ) {
					$role->add_cap( $permission['capability'] );
					if ( $permission['implies_view'] ) {
						$grant_view = true;
					}
				} else {
					$role->remove_cap( $permission['capability'] );
				}
			}

			if ( $grant_view ) {
				$role->add_cap( Capabilities::VIEW );
			}
		}
	}

	/**
	 * Server-side Administrator lock: restores the full protected capability set and
	 * rejects any submitted revoke attempt for the administrator role.
	 */
	public function protect_administrator(): void {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return;
		}

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			$role->add_cap( $capability );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function posted_enabled( array $row, string $permission_key ): bool {
		if ( ! array_key_exists( $permission_key, $row ) ) {
			return false;
		}

		$value = $row[ $permission_key ];

		return '1' === (string) $value || 1 === $value || true === $value;
	}

	private function role_has_capability( object $role, string $capability ): bool {
		if ( method_exists( $role, 'has_cap' ) ) {
			return (bool) $role->has_cap( $capability );
		}

		$caps = is_array( $role->capabilities ?? null ) ? $role->capabilities : [];

		return ! empty( $caps[ $capability ] );
	}
}
