<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

/**
 * Loads Stage 4 admin CSS/JS only on scoped-configuration screens.
 */
final class ScopedConfigurationAdminAssets {

	public const HANDLE = 'cetech-de-scoped-config-admin';

	/** @var list<string> */
	private const PAGE_SLUGS = [
		ScopedConfigurationPage::SLUG,
		EffectiveConfigurationPreviewPage::SLUG,
		DeliverySettingsHomePage::SLUG,
		ProductExceptionsPage::SLUG,
		NeedsAttentionPage::SLUG,
		SetupWizardPage::SLUG,
		AdminMenu::PARENT_SLUG,
	];

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_scoped_config_screen() ) {
			return;
		}

		$css = CETECH_DE_URL . 'assets/admin/scoped-configuration.css';
		$js  = CETECH_DE_URL . 'assets/admin/scoped-configuration.js';

		wp_enqueue_style(
			self::HANDLE,
			$css,
			[],
			defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '1.0.0-rc.1'
		);

		wp_enqueue_script(
			self::HANDLE,
			$js,
			[],
			defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '1.0.0-rc.1',
			true
		);
	}

	private function is_scoped_config_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';

		return in_array( $page, self::PAGE_SLUGS, true );
	}
}
