<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\StoreAwareExamples;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationDiagnostic;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationHealthChecker;
use CetechDeliveryEngine\Application\Diagnostics\DiagnosticSeverity;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * User-friendly delivery operations dashboard for store administrators.
 */
final class AdminOperationsDashboard {

	private const STATUS_READY = 'ready';

	private const STATUS_NEEDS_SETUP = 'needs_setup';

	private const STATUS_NOT_ACTIVE = 'not_active';

	private const STATUS_ATTENTION = 'attention';

	public function __construct(
		private Requirements $requirements,
		private FeatureFlags $feature_flags,
		private DestinationZoneRepositoryInterface $destination_zone_repository,
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private RateCardRepositoryInterface $rate_card_repository,
		private ConfigurationHealthChecker $configuration_health_checker,
		private ShippingRateCalculationGate $shipping_gate
	) {
	}

	public function render(): void {
		$state = $this->build_state();
		$this->render_styles();
		echo '<div class="cetech-de-dashboard">';

		$this->render_header();
		$this->render_section(
			__( 'Delivery readiness', 'cetech-woocommerce-delivery-engine' ),
			__( 'A quick look at whether your delivery setup is ready for customers.', 'cetech-woocommerce-delivery-engine' ),
			function () use ( $state ): void {
				$this->render_readiness_cards( $state );
			}
		);
		$this->render_section(
			__( 'Setup checklist', 'cetech-woocommerce-delivery-engine' ),
			__( 'Complete these steps to get delivery pricing working at checkout.', 'cetech-woocommerce-delivery-engine' ),
			function () use ( $state ): void {
				$this->render_checklist( $state );
			}
		);
		$this->render_section(
			__( "Today's delivery controls", 'cetech-woocommerce-delivery-engine' ),
			__( 'Common tasks your team can open with one click.', 'cetech-woocommerce-delivery-engine' ),
			function (): void {
				$this->render_quick_actions();
			}
		);
		$this->render_warnings( $state );
		$this->render_section(
			__( 'At a glance', 'cetech-woocommerce-delivery-engine' ),
			null,
			function () use ( $state ): void {
				$this->render_summary( $state );
			}
		);
		$this->render_help();

		echo '</div>';
	}

	/**
	 * @param callable(): void $content
	 */
	private function render_section( string $title, ?string $description, callable $content ): void {
		echo '<section class="cetech-de-section">';
		echo '<div class="cetech-de-section-head">';
		echo '<h2 class="cetech-de-section-title">' . esc_html( $title ) . '</h2>';

		if ( null !== $description && '' !== $description ) {
			echo '<p class="cetech-de-section-desc">' . esc_html( $description ) . '</p>';
		}

		echo '</div>';
		$content();
		echo '</section>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_state(): array {
		$zone_count  = $this->destination_zone_repository->count_all();
		$offer_count = $this->delivery_offer_repository->count_all();
		$rate_cards  = $this->rate_card_repository->list( [ 'limit' => 500 ] );

		$active_rate_cards   = 0;
		$inactive_rate_cards = 0;
		$zone_ids_with_rates = [];

		foreach ( $rate_cards as $rate_card ) {
			$status = (string) ( $rate_card['status'] ?? '' );

			if ( RecordStatus::Active->value === $status ) {
				++$active_rate_cards;
				$zone_id = (int) ( $rate_card['destination_zone_id'] ?? 0 );

				if ( $zone_id > 0 ) {
					$zone_ids_with_rates[ $zone_id ] = true;
				}
			} else {
				++$inactive_rate_cards;
			}
		}

		$zones_without_rates = max( 0, $zone_count - count( $zone_ids_with_rates ) );
		$checkout_runtime    = $this->shipping_gate->is_runtime_active();
		$woocommerce_active  = $this->requirements->is_woocommerce_active();

		$health = $this->configuration_health_checker->run();

		return [
			'zone_count'            => $zone_count,
			'offer_count'           => $offer_count,
			'rate_card_count'       => count( $rate_cards ),
			'active_rate_cards'     => $active_rate_cards,
			'inactive_rate_cards'   => $inactive_rate_cards,
			'zones_without_rates'   => $zones_without_rates,
			'checkout_runtime'      => $checkout_runtime,
			'woocommerce_active'    => $woocommerce_active,
			'health_diagnostics'    => $health['diagnostics'] ?? [],
			'shipping_flag_enabled' => $this->shipping_gate->is_shipping_flag_enabled(),
			'upstream_ready'        => $this->shipping_gate->is_upstream_ready(),
		];
	}

	private function render_header(): void {
		echo '<header class="cetech-de-dashboard-header">';
		echo '<div class="cetech-de-dashboard-header-text">';
		echo '<p class="cetech-de-dashboard-eyebrow">' . esc_html__( 'Delivery operations', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<h1 class="cetech-de-dashboard-title">' . esc_html__( 'CETECH Delivery Engine', 'cetech-woocommerce-delivery-engine' ) . '</h1>';
		echo '<p class="cetech-de-dashboard-subtitle">' . esc_html__(
			'Manage delivery areas, delivery options, and delivery charges for WooCommerce orders.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '</div>';
		echo '<div class="cetech-de-dashboard-header-actions">';
		echo '<div class="cetech-de-button-group cetech-de-button-group--primary">';

		$this->render_button(
			__( 'Manage Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( RateCardsPage::SLUG ),
			'primary'
		);
		$this->render_button(
			__( 'Manage Delivery Options', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DeliveryOffersPage::SLUG )
		);
		$this->render_button(
			__( 'Manage Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DestinationZonesPage::SLUG )
		);

		echo '</div><div class="cetech-de-button-group cetech-de-button-group--secondary">';
		$this->render_button(
			__( 'View Settings', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DeliverySettingsPage::SLUG ),
			'link'
		);
		echo '</div></div></header>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_readiness_cards( array $state ): void {
		echo '<div class="cetech-de-card-grid cetech-de-readiness-grid">';

		$this->render_readiness_card(
			__( 'Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery areas tell the system where you deliver.', 'cetech-woocommerce-delivery-engine' ),
			$this->zones_status( $state ),
			$this->zones_status_label( $state ),
			AdminPageRenderer::list_url( DestinationZonesPage::SLUG ),
			__( 'Manage delivery areas', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_readiness_card(
			__( 'Delivery Options', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery options define services like Same-Day, Next-Day, or Standard Delivery.', 'cetech-woocommerce-delivery-engine' ),
			$this->offers_status( $state ),
			$this->offers_status_label( $state ),
			AdminPageRenderer::list_url( DeliveryOffersPage::SLUG ),
			__( 'Manage delivery options', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_readiness_card(
			__( 'Delivery Charges', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery charges connect delivery areas and options to actual delivery fees.', 'cetech-woocommerce-delivery-engine' ),
			$this->rate_cards_status( $state ),
			$this->rate_cards_status_label( $state ),
			AdminPageRenderer::list_url( RateCardsPage::SLUG ),
			__( 'Manage delivery charges', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_readiness_card(
			__( 'Checkout Status', 'cetech-woocommerce-delivery-engine' ),
			__( 'This confirms whether customers can see delivery options during checkout.', 'cetech-woocommerce-delivery-engine' ),
			$this->checkout_status( $state ),
			$this->checkout_status_label( $state ),
			$this->woocommerce_shipping_settings_url(),
			__( 'Open shipping settings', 'cetech-woocommerce-delivery-engine' )
		);

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_checklist( array $state ): void {
		echo '<div class="cetech-de-checklist">';
		$step = 1;

		$step = $this->render_checklist_item(
			$step,
			__( 'Create at least one Delivery Area', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery areas group the places you deliver to, such as a city or region.', 'cetech-woocommerce-delivery-engine' ),
			$state['zone_count'] > 0,
			AdminPageRenderer::list_url( DestinationZonesPage::SLUG ),
			__( 'Add a delivery area', 'cetech-woocommerce-delivery-engine' )
		);

		$step = $this->render_checklist_item(
			$step,
			__( 'Create at least one Delivery Option', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery options describe the service your customers choose.', 'cetech-woocommerce-delivery-engine' ),
			$state['offer_count'] > 0,
			AdminPageRenderer::list_url( DeliveryOffersPage::SLUG ),
			__( 'Add a delivery option', 'cetech-woocommerce-delivery-engine' )
		);

		$step = $this->render_checklist_item(
			$step,
			__( 'Create at least one Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
			__( 'Delivery charges set the price for each delivery area and option combination.', 'cetech-woocommerce-delivery-engine' ),
			$state['active_rate_cards'] > 0,
			add_query_arg( [ 'page' => RateCardsPage::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
			__( 'Add a delivery charge', 'cetech-woocommerce-delivery-engine' )
		);

		$step = $this->render_checklist_item(
			$step,
			__( 'Enable delivery at checkout', 'cetech-woocommerce-delivery-engine' ),
			__( 'Confirm WooCommerce shipping is configured and Delivery Engine checkout features are turned on.', 'cetech-woocommerce-delivery-engine' ),
			$state['checkout_runtime'] && $state['woocommerce_active'],
			AdminPageRenderer::list_url( DeliverySettingsPage::SLUG ),
			__( 'Review settings', 'cetech-woocommerce-delivery-engine' )
		);

		$this->render_checklist_item(
			$step,
			__( 'Test checkout with a sample product', 'cetech-woocommerce-delivery-engine' ),
			__( 'Add a product to the cart, enter a delivery address, and confirm the delivery fee appears.', 'cetech-woocommerce-delivery-engine' ),
			false,
			$this->shop_url(),
			__( 'View store', 'cetech-woocommerce-delivery-engine' ),
			true
		);

		echo '</div>';
	}

	private function render_quick_actions(): void {
		echo '<div class="cetech-de-card-grid cetech-de-quick-actions">';

		$this->render_action_card(
			__( 'Add New Delivery Charge', 'cetech-woocommerce-delivery-engine' ),
			__( 'Set a delivery price for a delivery area and service.', 'cetech-woocommerce-delivery-engine' ),
			add_query_arg( [ 'page' => RateCardsPage::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) )
		);

		$this->render_action_card(
			__( 'Review Delivery Areas', 'cetech-woocommerce-delivery-engine' ),
			__( 'Check which areas you deliver to.', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DestinationZonesPage::SLUG )
		);

		$this->render_action_card(
			__( 'Review Delivery Options', 'cetech-woocommerce-delivery-engine' ),
			__( 'Check the delivery services available to customers.', 'cetech-woocommerce-delivery-engine' ),
			AdminPageRenderer::list_url( DeliveryOffersPage::SLUG )
		);

		$this->render_action_card(
			__( 'Open WooCommerce Shipping Settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'Review shipping zones and methods in WooCommerce.', 'cetech-woocommerce-delivery-engine' ),
			$this->woocommerce_shipping_settings_url()
		);

		$this->render_action_card(
			__( 'Testing instructions', 'cetech-woocommerce-delivery-engine' ),
			__( 'How to verify delivery pricing before going live.', 'cetech-woocommerce-delivery-engine' ),
			'#cetech-de-testing-instructions'
		);

		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_warnings( array $state ): void {
		$warnings = $this->collect_warnings( $state );

		echo '<section class="cetech-de-section cetech-de-section--warnings">';
		echo '<div class="cetech-de-section-head">';
		echo '<h2 class="cetech-de-section-title">' . esc_html__( 'Needs your attention', 'cetech-woocommerce-delivery-engine' ) . '</h2>';

		if ( [] === $warnings ) {
			echo '<p class="cetech-de-section-desc">' . esc_html__(
				'No urgent delivery setup issues were found.',
				'cetech-woocommerce-delivery-engine'
			) . '</p>';
		}

		echo '</div>';

		if ( [] === $warnings ) {
			echo '<div class="cetech-de-empty-state cetech-de-empty-state--success">';
			echo '<p class="cetech-de-empty-state-title">' . esc_html__( 'You are all set for now', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '<p class="cetech-de-empty-state-text">' . esc_html__(
				'Your core delivery setup looks healthy. Continue with the checklist or test checkout when you are ready.',
				'cetech-woocommerce-delivery-engine'
			) . '</p>';
			echo '</div></section>';
			return;
		}

		echo '<div class="cetech-de-warnings">';

		foreach ( $warnings as $warning ) {
			echo '<div class="cetech-de-warning-card">';
			echo '<div class="cetech-de-warning-icon" aria-hidden="true">!</div>';
			echo '<div class="cetech-de-warning-body">';
			echo '<p class="cetech-de-warning-title">' . esc_html( (string) $warning['title'] ) . '</p>';
			echo '<p class="cetech-de-warning-message">' . esc_html( (string) $warning['message'] ) . '</p>';

			if ( ! empty( $warning['action_label'] ) && ! empty( $warning['action_url'] ) ) {
				printf(
					'<p class="cetech-de-warning-action"><a class="button button-secondary" href="%1$s">%2$s</a></p>',
					esc_url( (string) $warning['action_url'] ),
					esc_html( (string) $warning['action_label'] )
				);
			}

			echo '</div></div>';
		}

		echo '</div></section>';
	}

	/**
	 * @param array<string, mixed> $state
	 *
	 * @return list<array{title: string, message: string, action_label?: string, action_url?: string}>
	 */
	private function collect_warnings( array $state ): array {
		$warnings = [];

		if ( 0 === $state['active_rate_cards'] ) {
			$warnings[] = [
				'title'        => __( 'No active rate cards', 'cetech-woocommerce-delivery-engine' ),
				'message'      => __( 'Customers may not see delivery fees yet. Add a rate card that links a delivery zone to a delivery offer.', 'cetech-woocommerce-delivery-engine' ),
				'action_label' => __( 'Add rate card', 'cetech-woocommerce-delivery-engine' ),
				'action_url'   => add_query_arg( [ 'page' => RateCardsPage::SLUG, 'action' => 'add' ], admin_url( 'admin.php' ) ),
			];
		}

		if ( $state['zone_count'] > 0 && $state['zones_without_rates'] > 0 ) {
			$warnings[] = [
				'title'        => __( 'Some zones have no rate cards', 'cetech-woocommerce-delivery-engine' ),
				'message'      => __( 'One or more delivery zones do not have a matching active rate card. Customers in those areas may not get a delivery price.', 'cetech-woocommerce-delivery-engine' ),
				'action_label' => __( 'Review rate cards', 'cetech-woocommerce-delivery-engine' ),
				'action_url'   => AdminPageRenderer::list_url( RateCardsPage::SLUG ),
			];
		}

		if ( ! $state['checkout_runtime'] ) {
			$message = __( 'Delivery Engine is not fully active at checkout yet. Review advanced settings to turn on checkout delivery features when you are ready.', 'cetech-woocommerce-delivery-engine' );

			if ( $state['shipping_flag_enabled'] && ! $state['upstream_ready'] ) {
				$message = __( 'Delivery pricing at checkout is not fully enabled yet. Some setup steps in advanced settings still need to be completed.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( ! $state['shipping_flag_enabled'] ) {
				$message = __( 'Delivery Engine rates will not appear at checkout until checkout delivery features are enabled in advanced settings.', 'cetech-woocommerce-delivery-engine' );
			}

			$warnings[] = [
				'title'        => __( 'Checkout delivery is not active', 'cetech-woocommerce-delivery-engine' ),
				'message'      => $message,
				'action_label' => __( 'View advanced settings', 'cetech-woocommerce-delivery-engine' ),
				'action_url'   => AdminPageRenderer::list_url( DeliverySettingsPage::SLUG ),
			];
		}

		if ( ! $state['woocommerce_active'] ) {
			$warnings[] = [
				'title'   => __( 'WooCommerce is not active', 'cetech-woocommerce-delivery-engine' ),
				'message' => __( 'Delivery Engine needs WooCommerce to calculate and show delivery fees at checkout.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		foreach ( $state['health_diagnostics'] as $diagnostic ) {
			if ( ! $diagnostic instanceof ConfigurationDiagnostic ) {
				continue;
			}

			if ( DiagnosticSeverity::Error !== $diagnostic->severity && DiagnosticSeverity::Warning !== $diagnostic->severity ) {
				continue;
			}

			if ( $this->is_dashboard_warning_duplicate( $diagnostic, $warnings ) ) {
				continue;
			}

			$warnings[] = [
				'title'   => $diagnostic->title,
				'message' => $diagnostic->message,
			];
		}

		return $warnings;
	}

	/**
	 * @param list<array{title: string, message: string, action_label?: string, action_url?: string}> $warnings
	 */
	private function is_dashboard_warning_duplicate( ConfigurationDiagnostic $diagnostic, array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( $warning['title'] === $diagnostic->title ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_summary( array $state ): void {
		echo '<div class="cetech-de-summary-grid">';
		$this->render_summary_stat( __( 'Delivery zones', 'cetech-woocommerce-delivery-engine' ), (int) $state['zone_count'] );
		$this->render_summary_stat( __( 'Delivery offers', 'cetech-woocommerce-delivery-engine' ), (int) $state['offer_count'] );
		$this->render_summary_stat( __( 'Rate cards (total)', 'cetech-woocommerce-delivery-engine' ), (int) $state['rate_card_count'] );
		$this->render_summary_stat( __( 'Active rate cards', 'cetech-woocommerce-delivery-engine' ), (int) $state['active_rate_cards'] );
		$this->render_summary_stat( __( 'Inactive rate cards', 'cetech-woocommerce-delivery-engine' ), (int) $state['inactive_rate_cards'] );
		echo '</div>';
	}

	private function render_help(): void {
		echo '<section class="cetech-de-section cetech-de-section--help">';
		echo '<div class="cetech-de-section-head">';
		echo '<h2 class="cetech-de-section-title">' . esc_html__( 'Help', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p class="cetech-de-section-desc">' . esc_html__(
			'Simple guidance for setting prices and checking checkout.',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '</div>';
		echo '<div class="cetech-de-help-grid">';

		echo '<div class="cetech-de-help-card">';
		echo '<h3 class="cetech-de-help-subtitle">' . esc_html__( 'How delivery pricing works', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<p class="cetech-de-help-lead">' . esc_html__(
			'Delivery pricing works in three steps:',
			'cetech-woocommerce-delivery-engine'
		) . '</p>';
		echo '<ol class="cetech-de-help-steps">';
		echo '<li>' . esc_html__( 'Create a Delivery Area for where you deliver.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Create a Delivery Option for the service you provide.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Create a Delivery Charge to set the price for that area and option.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '</ol>';
		echo '<p class="cetech-de-help-example"><span class="cetech-de-help-example-label">' . esc_html__( 'Example', 'cetech-woocommerce-delivery-engine' ) . '</span> ';
		echo esc_html( StoreAwareExamples::charge_list_example() ) . '</p>';
		echo '</div>';

		echo '<div id="cetech-de-testing-instructions" class="cetech-de-help-card">';
		echo '<h3 class="cetech-de-help-subtitle">' . esc_html__( 'How to check checkout', 'cetech-woocommerce-delivery-engine' ) . '</h3>';
		echo '<ol class="cetech-de-help-steps">';
		echo '<li>' . esc_html__( 'Make sure you have at least one zone, offer, and active rate card.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Open your store and add a product to the cart.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Go to checkout and enter a delivery address inside one of your zones.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '<li>' . esc_html__( 'Confirm a delivery fee appears before placing the order.', 'cetech-woocommerce-delivery-engine' ) . '</li>';
		echo '</ol>';

		if ( '' !== $this->shop_url() ) {
			printf(
				'<p class="cetech-de-help-action"><a class="button button-secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( $this->shop_url() ),
				esc_html__( 'Open store in new tab', 'cetech-woocommerce-delivery-engine' )
			);
		}

		echo '</div></div></section>';
	}

	private function render_readiness_card(
		string $title,
		string $description,
		string $status,
		string $status_label,
		string $action_url,
		string $action_label
	): void {
		echo '<article class="cetech-de-card cetech-de-card--' . esc_attr( $status ) . '">';
		echo '<div class="cetech-de-card-head">';
		echo '<h3 class="cetech-de-card-title">' . esc_html( $title ) . '</h3>';
		echo '<span class="cetech-de-badge cetech-de-badge--' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
		echo '</div>';
		echo '<p class="cetech-de-card-text">' . esc_html( $description ) . '</p>';
		echo '<div class="cetech-de-card-footer">';
		printf(
			'<a class="button button-secondary cetech-de-card-button" href="%1$s">%2$s</a>',
			esc_url( $action_url ),
			esc_html( $action_label )
		);
		echo '</div></article>';
	}

	private function render_checklist_item(
		int $step,
		string $title,
		string $description,
		bool $completed,
		string $action_url,
		string $action_label,
		bool $informational_only = false
	): int {
		$status_class = $completed ? 'completed' : ( $informational_only ? 'manual' : 'pending' );
		$badge_status = $completed
			? self::STATUS_READY
			: ( $informational_only ? self::STATUS_NOT_ACTIVE : self::STATUS_NEEDS_SETUP );
		$status_text  = $completed
			? __( 'Completed', 'cetech-woocommerce-delivery-engine' )
			: ( $informational_only
				? __( 'Manual check', 'cetech-woocommerce-delivery-engine' )
				: __( 'Needs attention', 'cetech-woocommerce-delivery-engine' ) );

		echo '<div class="cetech-de-checklist-item cetech-de-checklist-item--' . esc_attr( $status_class ) . '">';
		echo '<div class="cetech-de-checklist-step" aria-hidden="true">' . esc_html( (string) $step ) . '</div>';
		echo '<div class="cetech-de-checklist-main">';
		echo '<p class="cetech-de-checklist-title">' . esc_html( $title ) . '</p>';
		echo '<p class="cetech-de-checklist-desc">' . esc_html( $description ) . '</p>';
		echo '</div>';
		echo '<div class="cetech-de-checklist-side">';
		echo '<span class="cetech-de-badge cetech-de-badge--' . esc_attr( $badge_status ) . '">';
		echo esc_html( $status_text );
		echo '</span>';
		printf(
			'<a class="button button-secondary cetech-de-checklist-button" href="%1$s">%2$s</a>',
			esc_url( $action_url ),
			esc_html( $action_label )
		);
		echo '</div></div>';

		return $step + 1;
	}

	private function render_action_card( string $title, string $description, string $url ): void {
		echo '<a class="cetech-de-action-card" href="' . esc_url( $url ) . '">';
		echo '<span class="cetech-de-action-card-arrow" aria-hidden="true">&rarr;</span>';
		echo '<span class="cetech-de-action-card-title">' . esc_html( $title ) . '</span>';
		echo '<span class="cetech-de-action-card-desc">' . esc_html( $description ) . '</span>';
		echo '</a>';
	}

	private function render_summary_stat( string $label, int $value ): void {
		$empty_class = 0 === $value ? ' cetech-de-summary-stat--empty' : '';
		echo '<div class="cetech-de-summary-stat' . esc_attr( $empty_class ) . '">';
		echo '<span class="cetech-de-summary-value">' . esc_html( (string) $value ) . '</span>';
		echo '<span class="cetech-de-summary-label">' . esc_html( $label ) . '</span>';
		echo '</div>';
	}

	private function render_button( string $label, string $url, string $variant = 'secondary' ): void {
		$class = match ( $variant ) {
			'primary' => 'button button-primary',
			'link'    => 'button button-link',
			default   => 'button button-secondary',
		};

		printf(
			'<a class="%1$s cetech-de-header-button" href="%2$s">%3$s</a>',
			esc_attr( $class ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function zones_status( array $state ): string {
		if ( $state['zone_count'] <= 0 ) {
			return self::STATUS_NEEDS_SETUP;
		}

		if ( $state['zones_without_rates'] > 0 && $state['active_rate_cards'] > 0 ) {
			return self::STATUS_ATTENTION;
		}

		return self::STATUS_READY;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function zones_status_label( array $state ): string {
		return match ( $this->zones_status( $state ) ) {
			self::STATUS_READY => __( 'Ready', 'cetech-woocommerce-delivery-engine' ),
			self::STATUS_ATTENTION => __( 'Attention needed', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Needs setup', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function offers_status( array $state ): string {
		return $state['offer_count'] > 0 ? self::STATUS_READY : self::STATUS_NEEDS_SETUP;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function offers_status_label( array $state ): string {
		return $state['offer_count'] > 0
			? __( 'Ready', 'cetech-woocommerce-delivery-engine' )
			: __( 'Needs setup', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function rate_cards_status( array $state ): string {
		if ( $state['active_rate_cards'] <= 0 ) {
			return self::STATUS_NEEDS_SETUP;
		}

		if ( $state['inactive_rate_cards'] > 0 ) {
			return self::STATUS_ATTENTION;
		}

		return self::STATUS_READY;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function rate_cards_status_label( array $state ): string {
		return match ( $this->rate_cards_status( $state ) ) {
			self::STATUS_READY => __( 'Ready', 'cetech-woocommerce-delivery-engine' ),
			self::STATUS_ATTENTION => __( 'Attention needed', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Needs setup', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function checkout_status( array $state ): string {
		if ( ! $state['woocommerce_active'] ) {
			return self::STATUS_ATTENTION;
		}

		if ( $state['checkout_runtime'] && $state['active_rate_cards'] > 0 ) {
			return self::STATUS_READY;
		}

		if ( $state['active_rate_cards'] > 0 && ! $state['checkout_runtime'] ) {
			return self::STATUS_NOT_ACTIVE;
		}

		return self::STATUS_NEEDS_SETUP;
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function checkout_status_label( array $state ): string {
		return match ( $this->checkout_status( $state ) ) {
			self::STATUS_READY => __( 'Ready', 'cetech-woocommerce-delivery-engine' ),
			self::STATUS_NOT_ACTIVE => __( 'Not active', 'cetech-woocommerce-delivery-engine' ),
			self::STATUS_ATTENTION => __( 'Attention needed', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Needs setup', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	private function woocommerce_shipping_settings_url(): string {
		if ( ! $this->requirements->is_woocommerce_active() ) {
			return admin_url( 'plugins.php' );
		}

		return admin_url( 'admin.php?page=wc-settings&tab=shipping' );
	}

	private function shop_url(): string {
		if ( ! $this->requirements->is_woocommerce_active() || ! function_exists( 'wc_get_page_permalink' ) ) {
			return home_url( '/' );
		}

		$shop = wc_get_page_permalink( 'shop' );

		return is_string( $shop ) && '' !== $shop ? $shop : home_url( '/' );
	}

	private function render_styles(): void {
		AdminPageLayout::render_styles();
	}
}
