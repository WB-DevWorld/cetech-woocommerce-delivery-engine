<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Verifies admin POST actions and redirects with flash notices.
 */
final class AdminActionHandler {

	public function __construct(
		private AdminNoticeService $notices
	) {
	}

	public function notices(): AdminNoticeService {
		return $this->notices;
	}

	public function verify_post(
		string $expected_action,
		string $nonce_action,
		string $capability,
		string $redirect_page_slug
	): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return false;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );

		if ( $expected_action !== $action ) {
			return false;
		}

		if ( AdminPageAccess::current_user_is_restricted() ) {
			AdminPageAccess::require_capability( $capability );
		}

		if ( ! is_admin() || ! current_user_can( $capability ) ) {
			$this->fail_post( $redirect_page_slug, __( 'You do not have permission to perform this action.', 'cetech-woocommerce-delivery-engine' ) );

			return false;
		}

		if ( ! AdminFormHelper::verify_nonce( $nonce_action ) ) {
			$this->fail_post( $redirect_page_slug, __( 'Security check failed. Please try again.', 'cetech-woocommerce-delivery-engine' ) );

			return false;
		}

		return true;
	}

	public function redirect( string $page_slug, array $query_args = [] ): never {
		$args = array_merge( [ 'page' => $page_slug ], $query_args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function fail_post( string $page_slug, string $message ): void {
		$this->notices->flash_error( $message );
		$this->redirect( $page_slug );
	}
}
