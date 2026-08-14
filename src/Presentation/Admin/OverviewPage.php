<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Everyday Delivery Engine overview inside normal wp-admin.
 */
final class OverviewPage {

	public const SLUG = AdminMenu::PARENT_SLUG;

	public function __construct(
		private readonly SetupWizardPage $wizard,
		private readonly SetupWizardProgress $progress,
		private readonly SiteWideDefaultsService $defaults,
		private readonly SiteWideDefaultsSettings $settings,
		private readonly SiteWideDefaultSummary $summaries,
		private readonly NeedsAttentionQuery $needs_attention,
		private readonly AdminActionHandler $action_handler,
		private readonly OperationalStateService $operational_state
	) {
	}

	public function handle_actions(): void {
		$this->wizard->handle_actions();
	}

	public function render(): void {
		AdminPageAccess::require_capability( $this->entry_capability() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$continue = isset( $_GET['continue_setup'] ) && '1' === (string) wp_unslash( $_GET['continue_setup'] );

		if ( current_user_can( 'manage_delivery_settings' ) && $continue ) {
			$wizard = $this->progress->read();
			wp_safe_redirect(
				$this->wizard->url(
					[
						'step'          => (int) $wizard['step'],
						'profile_index' => (int) $wizard['profile_index'],
					]
				)
			);
			exit;
		}

		$this->action_handler->notices()->render_notices();

		$state   = $this->settings->read();
		$preview = $this->defaults->preview();
		$op      = $this->operational_state->current();
		$primary = FulfilmentProfileRegistry::get( (string) $state['primary_profile'] );
		$attention_count = $this->needs_attention->count();
		$wizard  = $this->progress->read();

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Manage delivery rules for your store in one place.', 'cetech-woocommerce-delivery-engine' )
		);

		$banner = [
			'tone'  => $op->overview_tone,
			'title' => $op->overview_title,
			'text'  => $op->overview_text,
		];
		if ( $op->customers_use_sitewide_runtime() && $op->sitewide_setup_complete ) {
			$banner['meta'] = sprintf(
				/* translators: %s primary fulfilment type */
				__( 'Primary default: %s', 'cetech-woocommerce-delivery-engine' ),
				'<span class="cetech-de-badge cetech-de-badge--ready">' . esc_html( $primary?->label ?? __( 'Not chosen', 'cetech-woocommerce-delivery-engine' ) ) . '</span>'
			);
			$banner['action_label'] = __( 'Preview delivery', 'cetech-woocommerce-delivery-engine' );
			$banner['action_url']   = AdminPageRenderer::list_url( EffectiveConfigurationPreviewPage::SLUG );
		} elseif ( ! $op->sitewide_setup_complete ) {
			$banner['action_label'] = __( 'Continue Setup', 'cetech-woocommerce-delivery-engine' );
			$banner['action_url']   = $this->wizard->url(
				[
					'step'          => (int) $wizard['step'],
					'profile_index' => (int) $wizard['profile_index'],
				]
			);
		} else {
			$banner['action_label'] = __( 'Preview delivery', 'cetech-woocommerce-delivery-engine' );
			$banner['action_url']   = AdminPageRenderer::list_url( EffectiveConfigurationPreviewPage::SLUG );
		}
		AdminPageLayout::render_status_banner( $banner );

		echo '<div class="cetech-de-overview-cards">';
		$this->render_stat_card(
			__( 'Site-wide defaults', 'cetech-woocommerce-delivery-engine' ),
			sprintf(
				/* translators: %d configured fulfilment types */
				_n( '%d configured', '%d configured', count( $this->configured_profiles( $state ) ), 'cetech-woocommerce-delivery-engine' ),
				count( $this->configured_profiles( $state ) )
			),
			__( 'Edit store defaults', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DeliverySettingsHomePage::SLUG ),
			'dashicons-admin-site-alt3'
		);
		$this->render_stat_card(
			__( 'Product-level exceptions', 'cetech-woocommerce-delivery-engine' ),
			sprintf(
				/* translators: %d customized products */
				_n( '%d exception', '%d exceptions', $preview->product_exceptions, 'cetech-woocommerce-delivery-engine' ),
				$preview->product_exceptions
			),
			__( 'Manage exceptions', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( ProductExceptionsPage::SLUG ),
			'dashicons-tag'
		);
		$this->render_stat_card(
			__( 'Needs attention', 'cetech-woocommerce-delivery-engine' ),
			sprintf(
				/* translators: %d items */
				_n( '%d item', '%d items', $attention_count, 'cetech-woocommerce-delivery-engine' ),
				$attention_count
			),
			__( 'View items', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( NeedsAttentionPage::SLUG ),
			'dashicons-warning',
			$attention_count > 0
		);
		echo '</div>';

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => $op->products_defaults_label,
					'value' => (string) $preview->can_safely_inherit,
				],
				[
					'label' => __( 'Variation exceptions', 'cetech-woocommerce-delivery-engine' ),
					'value' => (string) $preview->variation_exceptions,
				],
			]
		);

		AdminPageLayout::open_section(
			__( 'Store-wide fulfilment defaults', 'cetech-woocommerce-delivery-engine' ),
			__( 'These defaults apply unless a product exception exists.', 'cetech-woocommerce-delivery-engine' )
		);

		$active = $state['active_profiles'];
		if ( [] === $active ) {
			$active = FulfilmentProfileRegistry::keys();
		}

		echo '<div class="cetech-de-profile-card-grid">';
		foreach ( $active as $key ) {
			$summary = $this->summaries->for_profile( $key );
			$status  = $summary['configured']
				? __( 'Configured', 'cetech-woocommerce-delivery-engine' )
				: __( 'Needs Setup', 'cetech-woocommerce-delivery-engine' );
			$badge   = $summary['configured'] ? 'cetech-de-badge--ready' : 'cetech-de-badge--needs_setup';
			echo '<article class="cetech-de-profile-card">';
			echo '<span class="cetech-de-profile-card-icon dashicons ' . esc_attr( $this->profile_icon( $key ) ) . '" aria-hidden="true"></span>';
			echo '<h3>' . esc_html( (string) $summary['label'] ) . '</h3>';
			echo '<p><span class="cetech-de-badge ' . esc_attr( $badge ) . '">' . esc_html( $status ) . '</span></p>';
			$parts = array_filter(
				[
					(string) $summary['delivery_method'],
					(string) $summary['delivery_options'],
				]
			);
			if ( [] !== $parts ) {
				echo '<p>' . esc_html( implode( ' • ', $parts ) ) . '</p>';
			}
			if ( '' !== (string) $summary['estimated_delivery'] ) {
				echo '<p>' . esc_html( (string) $summary['estimated_delivery'] ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( add_query_arg( [ 'page' => DeliverySettingsHomePage::SLUG, 'profile' => $key ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Edit defaults', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
			echo '</article>';
		}
		echo '</div>';
		AdminPageLayout::close_section();

		echo '<div class="cetech-de-next-steps">';
		$this->render_next_step(
			__( 'Review delivery options', 'cetech-woocommerce-delivery-engine' ),
			__( 'Manage delivery methods, availability, and display settings.', 'cetech-woocommerce-delivery-engine' ),
			__( 'Go to delivery options', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DeliveryOffersPage::SLUG )
		);
		$this->render_next_step(
			__( 'Review delivery areas', 'cetech-woocommerce-delivery-engine' ),
			__( 'Choose where you deliver and how destinations are matched.', 'cetech-woocommerce-delivery-engine' ),
			__( 'Go to delivery areas', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DestinationZonesPage::SLUG )
		);
		$this->render_next_step(
			__( 'Check delivery charges', 'cetech-woocommerce-delivery-engine' ),
			__( 'Review shipping rates and surcharges across your store.', 'cetech-woocommerce-delivery-engine' ),
			__( 'Go to delivery charges', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( RateCardsPage::SLUG )
		);
		$this->render_next_step(
			__( 'Preview product delivery settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'See how delivery options appear for a product.', 'cetech-woocommerce-delivery-engine' ),
			__( 'Preview delivery', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( EffectiveConfigurationPreviewPage::SLUG )
		);
		echo '</div>';

		AdminPageLayout::close_page();
	}

	/**
	 * @param array<string, mixed> $state
	 *
	 * @return list<string>
	 */
	private function configured_profiles( array $state ): array {
		$active = $state['active_profiles'] ?: FulfilmentProfileRegistry::keys();
		$out    = [];
		foreach ( $active as $key ) {
			if ( $this->summaries->for_profile( $key )['configured'] ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	private function render_stat_card( string $title, string $value, string $link_label, string $url, string $icon, bool $attention = false ): void {
		echo '<article class="cetech-de-stat-card' . ( $attention ? ' is-attention' : '' ) . '">';
		echo '<span class="cetech-de-stat-card-icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<p class="cetech-de-stat-card-value">' . esc_html( $value ) . '</p>';
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( $link_label ) . ' →</a></p>';
		echo '</article>';
	}

	private function render_next_step( string $title, string $text, string $label, string $url ): void {
		echo '<article class="cetech-de-next-step">';
		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<p>' . esc_html( $text ) . '</p>';
		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' →</a></p>';
		echo '</article>';
	}

	private function profile_icon( string $key ): string {
		return match ( $key ) {
			'in_warehouse' => 'dashicons-building',
			'in_store' => 'dashicons-store',
			'international_fulfilment' => 'dashicons-airplane',
			default => 'dashicons-admin-site-alt3',
		};
	}

	private function entry_capability(): string {
		return Capabilities::VIEW;
	}
}
