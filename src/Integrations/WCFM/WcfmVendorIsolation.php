<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WCFM;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;

/**
 * Narrow WCFM administrative isolation.
 *
 * Ordinary marketplace vendors must have zero Delivery Engine admin visibility
 * until a deliberate vendor-specific Delivery Engine interface exists.
 *
 * This is not a vendor fulfilment adapter. Isolation is automatic when WCFM
 * is present and is not controlled by enable_wcfm_adapter.
 */
final class WcfmVendorIsolation {

	public const VENDOR_ROLE = 'wcfm_vendor';

	public const DISABLED_VENDOR_ROLE = 'disable_vendor';

	/**
	 * @param (callable(int|null):bool)|null $vendor_check Test seam. Production uses wcfm_is_vendor().
	 * @param (callable():bool)|null         $runtime_present Test seam. Production uses WCFM constants/classes/function_exists.
	 */
	public function __construct(
		private mixed $vendor_check = null,
		private mixed $runtime_present = null
	) {
	}

	/**
	 * Restricted WCFM vendors must not receive Delivery Engine administration.
	 *
	 * WordPress administrators with manage_options are never locked out.
	 */
	public function is_restricted_vendor_user(): bool {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( ! $this->vendor_identity_available() ) {
			return false;
		}

		return $this->wcfm_reports_vendor( $this->current_user_id() );
	}

	public function denies_administrative_access(): bool {
		return $this->is_restricted_vendor_user();
	}

	/**
	 * Explicit WCFM vendor role slugs excluded from the Access matrix.
	 * Does not guess from display names containing "vendor".
	 *
	 * @return list<string>
	 */
	public function excluded_admin_role_slugs(): array {
		$slugs = [ self::VENDOR_ROLE ];

		if ( $this->should_exclude_disabled_vendor_role() ) {
			$slugs[] = self::DISABLED_VENDOR_ROLE;
		}

		return $slugs;
	}

	public function is_excluded_admin_role( string $role_slug ): bool {
		return in_array( sanitize_key( $role_slug ), $this->excluded_admin_role_slugs(), true );
	}

	/**
	 * Idempotent. Removes every Capabilities::ALL entry from known WCFM vendor
	 * roles without touching Administrator, Shop Manager, or unrelated roles.
	 */
	public function harden_vendor_role_capabilities(): void {
		foreach ( $this->roles_to_strip() as $slug ) {
			$role = function_exists( 'get_role' ) ? get_role( $slug ) : null;
			if ( null === $role ) {
				continue;
			}

			foreach ( Capabilities::ALL as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	public function is_wcfm_present(): bool {
		if ( is_callable( $this->runtime_present ) ) {
			return (bool) ( $this->runtime_present )();
		}

		return defined( 'WCFM_VERSION' )
			|| class_exists( 'WCFM' )
			|| function_exists( 'wcfm_is_vendor' );
	}

	private function vendor_identity_available(): bool {
		if ( is_callable( $this->vendor_check ) ) {
			return true;
		}

		return function_exists( 'wcfm_is_vendor' );
	}

	private function wcfm_reports_vendor( ?int $user_id ): bool {
		if ( is_callable( $this->vendor_check ) ) {
			return (bool) ( $this->vendor_check )( $user_id );
		}

		if ( ! function_exists( 'wcfm_is_vendor' ) ) {
			return false;
		}

		if ( null !== $user_id && $user_id > 0 ) {
			return (bool) wcfm_is_vendor( $user_id );
		}

		return (bool) wcfm_is_vendor();
	}

	private function current_user_id(): ?int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return null;
		}

		$id = (int) get_current_user_id();

		return $id > 0 ? $id : null;
	}

	private function should_exclude_disabled_vendor_role(): bool {
		if ( $this->is_wcfm_present() ) {
			return true;
		}

		return function_exists( 'get_role' ) && null !== get_role( self::VENDOR_ROLE );
	}

	/**
	 * @return list<string>
	 */
	private function roles_to_strip(): array {
		$slugs = [ self::VENDOR_ROLE ];

		if ( $this->should_exclude_disabled_vendor_role() ) {
			$slugs[] = self::DISABLED_VENDOR_ROLE;
		}

		return $slugs;
	}
}
