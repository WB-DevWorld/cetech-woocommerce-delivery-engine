<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Capability / CSRF gate for scoped configuration admin actions (unit-testable).
 */
final class ScopedConfigurationAuthorization {

	public const CAPABILITY_GLOBAL = \CetechDeliveryEngine\Core\Capabilities\Capabilities::SITE_WIDE;

	public const CAPABILITY_PRODUCT = 'manage_product_delivery_rules';

	public const CAPABILITY_PREVIEW = 'manage_product_delivery_rules';

	/**
	 * @param callable(string): bool $capability_checker
	 * @param callable(string): bool $nonce_verifier
	 */
	public function __construct(
		private readonly mixed $capability_checker = null,
		private readonly mixed $nonce_verifier = null
	) {
	}

	public function can_manage_global(): bool {
		return $this->can( self::CAPABILITY_GLOBAL );
	}

	public function can_manage_product(): bool {
		return $this->can( self::CAPABILITY_PRODUCT );
	}

	public function can_preview(): bool {
		return $this->can( self::CAPABILITY_PREVIEW );
	}

	public function verify_write( string $capability, string $nonce_action, bool $is_post ): array {
		$errors = [];

		if ( ! $is_post ) {
			$errors[] = 'Configuration writes are not allowed via GET.';
			return $errors;
		}

		if ( ! $this->can( $capability ) ) {
			$errors[] = 'Insufficient capability to modify scoped configuration.';
		}

		if ( ! $this->verify_nonce( $nonce_action ) ) {
			$errors[] = 'Security check failed (invalid nonce).';
		}

		return $errors;
	}

	public function verify_preview_read( string $capability ): array {
		if ( $this->can( $capability ) ) {
			return [];
		}

		return [ 'Insufficient capability to preview effective configuration.' ];
	}

	private function can( string $capability ): bool {
		if ( is_callable( $this->capability_checker ) ) {
			return (bool) ( $this->capability_checker )( $capability );
		}

		return function_exists( 'current_user_can' ) && current_user_can( $capability );
	}

	private function verify_nonce( string $nonce_action ): bool {
		if ( is_callable( $this->nonce_verifier ) ) {
			return (bool) ( $this->nonce_verifier )( $nonce_action );
		}

		return \CetechDeliveryEngine\Presentation\Admin\AdminFormHelper::verify_nonce( $nonce_action );
	}
}
