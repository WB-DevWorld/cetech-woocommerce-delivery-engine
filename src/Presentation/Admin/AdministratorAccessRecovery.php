<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;

/**
 * Independent Administrator Delivery Engine access recovery.
 *
 * Authorised solely by native WordPress manage_options — never by custom
 * Delivery Engine capabilities such as view_delivery_diagnostics.
 */
final class AdministratorAccessRecovery {

	public const ACTION = 'cetech_de_restore_administrator_access';

	public const NOTICE_QUERY = 'cetech_de_admin_access_restored';

	public function __construct(
		private Capabilities $capabilities
	) {
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
	}

	public function can_recover(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	public function needs_repair(): bool {
		return $this->capabilities->administrator_missing_required_capabilities();
	}

	/**
	 * Restores the full protected Administrator Delivery Engine capability set.
	 * Idempotent. Does not modify subordinate roles.
	 */
	public function restore(): void {
		$this->capabilities->ensure_administrator_recovery();
	}

	/**
	 * Attempt recovery when the caller already proved manage_options authority.
	 * Returns false when the current user lacks manage_options.
	 */
	public function restore_if_authorized(): bool {
		if ( ! $this->can_recover() ) {
			return false;
		}

		$this->restore();

		return true;
	}

	/**
	 * Validate authority + nonce, then restore. Returns true on success.
	 * Unauthorized callers trigger wp_die. Invalid nonce fails via check_admin_referer.
	 */
	public function process_restore_request(): bool {
		if ( ! $this->can_recover() ) {
			wp_die(
				esc_html__(
					'You do not have permission to restore Delivery Engine administrator access.',
					'cetech-woocommerce-delivery-engine'
				)
			);
		}

		check_admin_referer( self::ACTION, 'cetech_de_nonce' );
		$this->restore();

		return true;
	}

	public function handle_actions(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );
		if ( self::ACTION !== $action ) {
			return;
		}

		$this->process_restore_request();

		$redirect = wp_get_referer();
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url();
		}

		$redirect = add_query_arg( self::NOTICE_QUERY, '1', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_notice(): void {
		if ( ! is_admin() || ! $this->can_recover() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::NOTICE_QUERY ] ) && '1' === sanitize_key( wp_unslash( (string) $_GET[ self::NOTICE_QUERY ] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html__( 'Delivery Engine administrator access was restored.', 'cetech-woocommerce-delivery-engine' );
			echo '</p></div>';
			return;
		}

		if ( ! $this->needs_repair() ) {
			return;
		}

		echo '<div class="notice notice-warning cetech-de-admin-access-recovery"><p>';
		echo '<strong>' . esc_html__( 'Delivery Engine administrator access needs repair.', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
		echo esc_html__(
			'Required Delivery Engine capabilities are missing from the WordPress Administrator role.',
			'cetech-woocommerce-delivery-engine'
		);
		echo '</p><p>';
		echo '<form method="post" action="" style="display:inline;">';
		wp_nonce_field( self::ACTION, 'cetech_de_nonce' );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" />';
		submit_button(
			__( 'Restore Administrator Access', 'cetech-woocommerce-delivery-engine' ),
			'primary',
			'submit',
			false
		);
		echo '</form>';
		echo '</p></div>';
	}
}
