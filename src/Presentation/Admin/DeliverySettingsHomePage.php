<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility;
use CetechDeliveryEngine\Application\Configuration\InStoreMethodSelection;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

/**
 * Task-based Delivery Settings landing, guided setup, and site-wide default editor.
 */
final class DeliverySettingsHomePage {

	public const SLUG = 'cetech-delivery-engine-delivery-settings';

	private const ACTION_APPLY = 'cetech_de_apply_sitewide_defaults';

	private const ACTION_SAVE_PROFILE = 'cetech_de_save_profile_defaults';

	public function __construct(
		private readonly SiteWideDefaultsService $defaults,
		private readonly SiteWideDefaultsSettings $settings,
		private readonly SiteWideDefaultSummary $summaries,
		private readonly NeedsAttentionQuery $needs_attention,
		private readonly ScopedConfigurationRepositoryInterface $scopes,
		private readonly EntityLabelResolver $labels,
		private readonly AdminActionHandler $action_handler,
		private readonly ?DeliveryOfferRepositoryInterface $offers = null,
		private readonly ?PickupLocationRepositoryInterface $pickups = null
	) {
	}

	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['cetech_de_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( wp_unslash( (string) $_POST['cetech_de_action'] ) );

		if ( self::ACTION_APPLY === $action && $this->action_handler->verify_post( self::ACTION_APPLY, self::ACTION_APPLY, Capabilities::SITE_WIDE, self::SLUG ) ) {
			$this->handle_apply();
		}

		if ( self::ACTION_SAVE_PROFILE === $action && $this->action_handler->verify_post( self::ACTION_SAVE_PROFILE, self::ACTION_SAVE_PROFILE, Capabilities::SITE_WIDE, self::SLUG ) ) {
			$this->handle_save_profile();
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( Capabilities::SITE_WIDE );
		$this->action_handler->notices()->render_notices();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$profile_key = isset( $_GET['profile'] ) ? sanitize_key( wp_unslash( (string) $_GET['profile'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? absint( wp_unslash( $_GET['step'] ) ) : 0;

		$state = $this->settings->read();

		if ( '' === $profile_key ) {
			$active = $state['active_profiles'] ?: FulfilmentProfileRegistry::keys();
			$profile_key = $active[0] ?? '';
		}

		if ( '' !== $profile_key && FulfilmentProfileRegistry::has( $profile_key ) ) {
			$this->render_profile_editor( $profile_key, $state );
			return;
		}

		$this->render_landing( $state );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_landing( array $state ): void {
		$preview = $this->defaults->preview();
		$primary = FulfilmentProfileRegistry::get( (string) $state['primary_profile'] );
		$attention_count = $this->needs_attention->count();

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ),
			__( 'Products use these settings automatically unless you create an exception.', 'cetech-woocommerce-delivery-engine' )
		);

		AdminPageLayout::render_summary_stats(
			[
				[
					'label' => __( 'Store-wide delivery status', 'cetech-woocommerce-delivery-engine' ),
					'value' => $state['setup_completed']
						? __( 'Site-wide defaults are active', 'cetech-woocommerce-delivery-engine' )
						: __( 'Setup not finished', 'cetech-woocommerce-delivery-engine' ),
				],
				[
					'label' => __( 'Primary default', 'cetech-woocommerce-delivery-engine' ),
					'value' => $primary?->label ?? __( 'Not chosen', 'cetech-woocommerce-delivery-engine' ),
				],
				[
					'label' => __( 'Products using defaults', 'cetech-woocommerce-delivery-engine' ),
					'value' => (string) $preview->can_safely_inherit,
				],
				[
					'label' => __( 'Product exceptions', 'cetech-woocommerce-delivery-engine' ),
					'value' => (string) $preview->product_exceptions,
				],
				[
					'label' => __( 'Variation exceptions', 'cetech-woocommerce-delivery-engine' ),
					'value' => (string) $preview->variation_exceptions,
				],
				[
					'label' => __( 'Items needing attention', 'cetech-woocommerce-delivery-engine' ),
					'value' => (string) $attention_count,
					'empty' => 0 === $attention_count,
				],
			]
		);

		AdminPageLayout::open_section(
			__( 'Active fulfilment defaults', 'cetech-woocommerce-delivery-engine' ),
			__( 'These are the store-wide rules each fulfilment type uses. Products inherit them unless you customize a product or variation.', 'cetech-woocommerce-delivery-engine' )
		);

		$active = $state['active_profiles'];
		if ( [] === $active ) {
			$active = FulfilmentProfileRegistry::keys();
		}

		echo '<div class="cetech-de-profile-card-grid">';
		foreach ( $active as $key ) {
			$summary = $this->summaries->for_profile( $key );
			echo '<article class="cetech-de-profile-card">';
			echo '<h3>' . esc_html( (string) $summary['label'] ) . '</h3>';
			echo '<p class="cetech-de-profile-status">' . esc_html(
				$summary['configured']
					? __( 'Configured', 'cetech-woocommerce-delivery-engine' )
					: __( 'Not configured yet', 'cetech-woocommerce-delivery-engine' )
			) . '</p>';
			if ( '' !== (string) $summary['delivery_method'] ) {
				$method_label = ! empty( $summary['air_sea'] )
					? __( 'Customer fulfilment', 'cetech-woocommerce-delivery-engine' )
					: __( 'Delivery method', 'cetech-woocommerce-delivery-engine' );
				echo '<p><strong>' . esc_html( $method_label ) . ':</strong> ' . esc_html( (string) $summary['delivery_method'] ) . '</p>';
			}
			if ( '' !== (string) $summary['delivery_options'] ) {
				$options_label = ! empty( $summary['air_sea'] )
					? __( 'International shipping options', 'cetech-woocommerce-delivery-engine' )
					: __( 'Delivery option', 'cetech-woocommerce-delivery-engine' );
				echo '<p><strong>' . esc_html( $options_label ) . ':</strong> ' . esc_html( (string) $summary['delivery_options'] ) . '</p>';
			}
			if ( '' !== (string) $summary['estimated_delivery'] ) {
				echo '<p><strong>' . esc_html__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ' . esc_html( (string) $summary['estimated_delivery'] ) . '</p>';
			}
			if ( '' !== (string) $summary['rate_summary'] ) {
				echo '<p><strong>' . esc_html__( 'Delivery charge', 'cetech-woocommerce-delivery-engine' ) . ':</strong> ' . esc_html( (string) $summary['rate_summary'] ) . '</p>';
			}
			echo '<p><a class="button" href="' . esc_url( $this->url( [ 'profile' => $key ] ) ) . '">' . esc_html__( 'Edit defaults', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
			echo '</article>';
		}
		echo '</div>';
		AdminPageLayout::close_section();

		echo '<p class="cetech-de-button-group">';
		echo '<a class="button button-primary" href="' . esc_url( AdminPageRenderer::list_url( ProductExceptionsPage::SLUG ) ) . '">' . esc_html__( 'Review Product Exceptions', 'cetech-woocommerce-delivery-engine' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( AdminPageRenderer::list_url( NeedsAttentionPage::SLUG ) ) . '">' . esc_html__( 'Review Items Needing Attention', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</p>';

		AdminPageLayout::close_page();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_setup( int $step, array $state ): void {
		$step = min( 5, max( 1, $step ) );

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Settings', 'cetech-woocommerce-delivery-engine' ),
			__( 'Set up site-wide delivery defaults', 'cetech-woocommerce-delivery-engine' ),
			__( 'Configure normal delivery rules once. Existing and future products can inherit them without opening every product.', 'cetech-woocommerce-delivery-engine' )
		);

		echo '<ol class="cetech-de-setup-steps" aria-label="' . esc_attr__( 'Setup steps', 'cetech-woocommerce-delivery-engine' ) . '">';
		$labels = [
			1 => __( 'Choose fulfilment types', 'cetech-woocommerce-delivery-engine' ),
			2 => __( 'Configure site-wide defaults', 'cetech-woocommerce-delivery-engine' ),
			3 => __( 'Choose the primary default', 'cetech-woocommerce-delivery-engine' ),
			4 => __( 'Review existing products', 'cetech-woocommerce-delivery-engine' ),
			5 => __( 'Save & Apply Site-wide', 'cetech-woocommerce-delivery-engine' ),
		];
		foreach ( $labels as $number => $label ) {
			$class = $number === $step ? ' is-current' : ( $number < $step ? ' is-complete' : '' );
			echo '<li class="' . esc_attr( $class ) . '"><span>' . esc_html( (string) $number ) . '</span> ' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';

		match ( $step ) {
			1 => $this->render_setup_step_types( $state ),
			2 => $this->render_setup_step_defaults( $state ),
			3 => $this->render_setup_step_primary( $state ),
			4 => $this->render_setup_step_review(),
			default => $this->render_setup_step_apply( $state ),
		};

		AdminPageLayout::close_page();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_setup_step_types( array $state ): void {
		$selected = $state['active_profiles'];
		if ( [] === $selected ) {
			$selected = [ \CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability::InWarehouse->value ];
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_APPLY ) . '" />';
		echo '<input type="hidden" name="setup_step" value="1" />';
		AdminFormHelper::nonce_field( self::ACTION_APPLY );

		echo '<fieldset class="cetech-de-form-panel"><legend>' . esc_html__( 'Which fulfilment types does this store use?', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		foreach ( FulfilmentProfileRegistry::all() as $profile ) {
			$checked = in_array( $profile->key, $selected, true ) ? ' checked' : '';
			echo '<p><label><input type="checkbox" name="active_profiles[]" value="' . esc_attr( $profile->key ) . '"' . $checked . ' /> ';
			echo '<strong>' . esc_html( $profile->label ) . '</strong> — ' . esc_html( $profile->description ) . '</label></p>';
		}
		echo '</fieldset>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_setup_step_defaults( array $state ): void {
		$active = $state['active_profiles'];
		if ( [] === $active ) {
			$active = FulfilmentProfileRegistry::keys();
		}

		echo '<p>' . esc_html__( 'Set the normal rules for each fulfilment type. You are choosing existing delivery options and charges, not creating a separate copy on every product.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p>';
		echo '<a href="' . esc_url( AdminPageRenderer::list_url( DeliveryOffersPage::SLUG ) ) . '">' . esc_html__( 'Manage Delivery Options', 'cetech-woocommerce-delivery-engine' ) . '</a> · ';
		echo '<a href="' . esc_url( AdminPageRenderer::list_url( DestinationZonesPage::SLUG ) ) . '">' . esc_html__( 'Manage Delivery Areas', 'cetech-woocommerce-delivery-engine' ) . '</a> · ';
		echo '<a href="' . esc_url( AdminPageRenderer::list_url( RateCardsPage::SLUG ) ) . '">' . esc_html__( 'Manage Delivery Charges', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</p>';

		foreach ( $active as $key ) {
			$summary = $this->summaries->for_profile( $key );
			echo '<p><a class="button" href="' . esc_url( $this->url( [ 'profile' => $key, 'return' => 'setup-2' ] ) ) . '">';
			echo esc_html( sprintf( /* translators: %s fulfilment type */ __( 'Edit %s defaults', 'cetech-woocommerce-delivery-engine' ), (string) $summary['label'] ) );
			echo '</a> ';
			echo $summary['configured']
				? esc_html__( 'Configured', 'cetech-woocommerce-delivery-engine' )
				: esc_html__( 'Not configured yet', 'cetech-woocommerce-delivery-engine' );
			echo '</p>';
		}

		echo '<p><a class="button button-primary" href="' . esc_url( $this->url( [ 'view' => 'setup', 'step' => 3 ] ) ) . '">' . esc_html__( 'Continue', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_setup_step_primary( array $state ): void {
		$active = $state['active_profiles'];
		if ( [] === $active ) {
			$active = FulfilmentProfileRegistry::keys();
		}
		$primary = (string) $state['primary_profile'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_APPLY ) . '" />';
		echo '<input type="hidden" name="setup_step" value="3" />';
		foreach ( $active as $key ) {
			echo '<input type="hidden" name="active_profiles[]" value="' . esc_attr( $key ) . '" />';
		}
		AdminFormHelper::nonce_field( self::ACTION_APPLY );

		echo '<fieldset class="cetech-de-form-panel"><legend>' . esc_html__( 'Which fulfilment type should ordinary products use?', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p>' . esc_html__( 'Products with no special delivery settings inherit this primary default automatically.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		foreach ( $active as $key ) {
			$profile = FulfilmentProfileRegistry::get( $key );
			if ( null === $profile ) {
				continue;
			}
			$checked = $primary === $key || ( '' === $primary && $key === $active[0] ) ? ' checked' : '';
			echo '<p><label><input type="radio" name="primary_profile" value="' . esc_attr( $key ) . '"' . $checked . ' /> ';
			echo esc_html( $profile->label ) . '</label></p>';
		}
		echo '</fieldset>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	private function render_setup_step_review(): void {
		$preview = $this->defaults->preview();

		echo '<div class="cetech-de-form-panel">';
		echo '<h2>' . esc_html__( 'How these defaults will apply to the existing catalog', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<ul class="cetech-de-review-counts">';
		echo '<li>' . esc_html( sprintf( /* translators: %d count */ __( '%d products can use Site-wide Defaults.', 'cetech-woocommerce-delivery-engine' ), $preview->can_safely_inherit ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %d count */ __( '%d products already have Product-Specific Settings and will retain them.', 'cetech-woocommerce-delivery-engine' ), $preview->product_exceptions ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %d count */ __( '%d variations already have Variation-Specific Settings and will retain them.', 'cetech-woocommerce-delivery-engine' ), $preview->variation_exceptions ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( /* translators: %d count */ __( '%d products need review before defaults are applied.', 'cetech-woocommerce-delivery-engine' ), $preview->needs_review ) ) . '</li>';
		echo '</ul>';
		$this->render_example_list( __( 'Can safely inherit', 'cetech-woocommerce-delivery-engine' ), $preview->can_safely_inherit_examples );
		$this->render_example_list( __( 'Has product-specific differences', 'cetech-woocommerce-delivery-engine' ), $preview->product_exception_examples );
		$this->render_example_list( __( 'Has variation-specific differences', 'cetech-woocommerce-delivery-engine' ), $preview->variation_exception_examples );
		$this->render_example_list( __( 'Needs review', 'cetech-woocommerce-delivery-engine' ), $preview->needs_review_examples );
		echo '</div>';

		echo '<p><a class="button button-primary" href="' . esc_url( $this->url( [ 'view' => 'setup', 'step' => 5 ] ) ) . '">' . esc_html__( 'Continue', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_setup_step_apply( array $state ): void {
		$active  = $state['active_profiles'];
		$primary = (string) $state['primary_profile'];

		echo '<div class="cetech-de-form-panel">';
		echo '<h2>' . esc_html__( 'Save & Apply Site-wide', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'This turns on the site-wide delivery policy for existing and future products that do not have their own delivery exceptions.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'This does not copy default values onto every product.', 'cetech-woocommerce-delivery-engine' ) . '</strong> ';
		echo esc_html__( 'Products without exceptions inherit the defaults automatically. Later changes to a site-wide default update inherited fields only.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p>' . esc_html__( 'Recommended action: apply to products without delivery exceptions. Explicit product and variation settings are kept.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_APPLY ) . '" />';
		echo '<input type="hidden" name="setup_step" value="5" />';
		echo '<input type="hidden" name="finish_setup" value="1" />';
		echo '<input type="hidden" name="primary_profile" value="' . esc_attr( $primary ) . '" />';
		foreach ( $active as $key ) {
			echo '<input type="hidden" name="active_profiles[]" value="' . esc_attr( $key ) . '" />';
		}
		AdminFormHelper::nonce_field( self::ACTION_APPLY );
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Apply to products without delivery exceptions', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function render_profile_editor( string $profile_key, array $state = [] ): void {
		$profile = FulfilmentProfileRegistry::get( $profile_key );
		if ( null === $profile ) {
			return;
		}

		$this->defaults->ensure_profile_scope( $profile_key );
		$scope = $this->scopes->findByScopeAndSlice(
			ConfigurationScopeType::Global,
			ConfigurationScope::GLOBAL_SCOPE_ID,
			$profile_key
		);

		AdminPageLayout::open_page();
		AdminPageLayout::render_page_header(
			__( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ),
			__( 'Site-wide Defaults', 'cetech-woocommerce-delivery-engine' ),
			__( 'Products use these settings automatically unless you create an exception.', 'cetech-woocommerce-delivery-engine' )
		);

		AdminPageLayout::render_info_notice(
			__( 'These settings apply store-wide unless a product or variation has its own exception.', 'cetech-woocommerce-delivery-engine' )
		);

		$active = $state['active_profiles'] ?? FulfilmentProfileRegistry::keys();
		if ( [] === $active ) {
			$active = FulfilmentProfileRegistry::keys();
		}
		echo '<nav class="cetech-de-profile-tabs" aria-label="' . esc_attr__( 'Fulfilment types', 'cetech-woocommerce-delivery-engine' ) . '">';
		foreach ( $active as $key ) {
			$tab = FulfilmentProfileRegistry::get( $key );
			if ( null === $tab ) {
				continue;
			}
			$class = $key === $profile_key ? ' is-current' : '';
			echo '<a class="' . esc_attr( trim( $class ) ) . '" href="' . esc_url( $this->url( [ 'profile' => $key ] ) ) . '">' . esc_html( $tab->label ) . '</a>';
		}
		echo '</nav>';

		echo '<p>' . esc_html( $profile->description ) . '</p>';

		echo '<p>';
		echo '<a href="' . esc_url( AdminPageRenderer::list_url( DeliveryOffersPage::SLUG ) ) . '">' . esc_html__( 'Manage Delivery Options', 'cetech-woocommerce-delivery-engine' ) . '</a> · ';
		echo '<a href="' . esc_url( AdminPageRenderer::list_url( DestinationZonesPage::SLUG ) ) . '">' . esc_html__( 'Manage Delivery Areas', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Delivery charges are determined by the customer\'s Delivery Area and selected Delivery Option.', 'cetech-woocommerce-delivery-engine' ) . ' ';
		echo '<a href="' . esc_url( AdminPageRenderer::list_url( RateCardsPage::SLUG ) ) . '">' . esc_html__( 'Manage Delivery Charges', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';

		echo '<form method="post" class="cetech-de-profile-editor">';
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION_SAVE_PROFILE ) . '" />';
		echo '<input type="hidden" name="profile_key" value="' . esc_attr( $profile_key ) . '" />';
		AdminFormHelper::nonce_field( self::ACTION_SAVE_PROFILE );

		$choice       = $scope?->scalars[ ConfigurationFieldKey::FULFILMENT_CHOICE ]->value ?? '';
		$eta          = $scope?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value ?? '';
		$offers       = $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->members ?? [];
		$pickup_id    = (int) ( $scope?->scalars[ ConfigurationFieldKey::PICKUP_LOCATION_ID ]->value ?? 0 );
		$local_ids    = InStoreMethodSelection::local_delivery_offer_ids( is_array( $offers ) ? $offers : [], $this->offers );
		$has_pickup   = $pickup_id > 0;
		$has_delivery = [] !== $local_ids;

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Fulfilment', 'cetech-woocommerce-delivery-engine' ) . '</th><td>' . esc_html( $profile->label ) . '</td></tr>';

		if ( $profile->pickup_allowed ) {
			if ( ! $has_delivery && ! $has_pickup ) {
				$has_delivery = true;
			}
			echo '<tr><th>' . esc_html__( 'Available fulfilment methods', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Available fulfilment methods', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
			echo '<label style="display:block;margin:0 0 0.35rem;"><input type="checkbox" name="available_methods[]" value="delivery"' . checked( $has_delivery, true, false ) . ' /> ';
			echo esc_html__( 'Delivery', 'cetech-woocommerce-delivery-engine' ) . '</label>';
			echo '<label style="display:block;margin:0 0 0.35rem;"><input type="checkbox" name="available_methods[]" value="store_pickup"' . checked( $has_pickup, true, false ) . ' /> ';
			echo esc_html__( 'Store Pickup', 'cetech-woocommerce-delivery-engine' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'One or both methods may be enabled. These are availability settings, not an exclusive choice.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '</fieldset></td></tr>';

			echo '<tr><th>' . esc_html__( 'Default customer choice', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
			echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Default customer choice', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
			$default = FulfilmentChoice::StorePickup->value === (string) $choice && $has_pickup
				? FulfilmentChoice::StorePickup->value
				: FulfilmentChoice::Delivery->value;
			echo '<label style="display:block;margin:0 0 0.35rem;"><input type="radio" name="default_customer_choice" value="' . esc_attr( FulfilmentChoice::Delivery->value ) . '"' . checked( $default, FulfilmentChoice::Delivery->value, false ) . ' /> ';
			echo esc_html__( 'Delivery', 'cetech-woocommerce-delivery-engine' ) . '</label>';
			echo '<label style="display:block;margin:0 0 0.35rem;"><input type="radio" name="default_customer_choice" value="' . esc_attr( FulfilmentChoice::StorePickup->value ) . '"' . checked( $default, FulfilmentChoice::StorePickup->value, false ) . ' /> ';
			echo esc_html__( 'Store Pickup', 'cetech-woocommerce-delivery-engine' ) . '</label>';
			echo '<p class="description">' . esc_html__( 'Must be one of the enabled methods. Delivery may be the default when both are enabled.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '</fieldset></td></tr>';
		} else {
			echo '<tr><th>' . esc_html__( 'Available fulfilment methods', 'cetech-woocommerce-delivery-engine' ) . '</th><td>' . esc_html__( 'Delivery only', 'cetech-woocommerce-delivery-engine' ) . '</td></tr>';
		}

		$options_label = $profile->air_sea_allowed
			? __( 'International shipping options', 'cetech-woocommerce-delivery-engine' )
			: __( 'Delivery Options', 'cetech-woocommerce-delivery-engine' );
		echo '<tr><th>' . esc_html( $options_label ) . '</th><td>';
		if ( $profile->pickup_allowed && ! $profile->air_sea_allowed ) {
			echo '<p class="description">' . esc_html__( 'Local doorstep delivery services, such as Standard Delivery. Store Pickup is configured separately.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		$compatible = $this->compatible_option_labels( $profile );
		if ( [] === $compatible ) {
			if ( $profile->air_sea_allowed ) {
				echo '<p>' . esc_html__( 'No Air Shipping or Sea Shipping options yet. Create an international Delivery Option, then return here to select it.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( AdminPageRenderer::list_url( DeliveryOffersPage::SLUG ) ) . '">' . esc_html__( 'Create Air or Sea Shipping option', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
			} else {
				echo '<p>' . esc_html__( 'No compatible delivery options for this fulfilment type.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			}
		} else {
			foreach ( $compatible as $id => $label ) {
				$checked = in_array( $id, $local_ids, true ) ? ' checked' : '';
				echo '<label style="display:block;margin:0 0 0.35rem;"><input type="checkbox" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][members][]" value="' . esc_attr( (string) $id ) . '"' . $checked . ' /> ' . esc_html( $label ) . '</label>';
			}
		}
		echo '<input type="hidden" name="fields[' . esc_attr( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) . '][mode]" value="replace" />';
		echo '</td></tr>';

		if ( $profile->pickup_allowed ) {
			echo '<tr><th><label for="pickup_location_id">' . esc_html__( 'Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
			$locations = $this->active_pickup_locations();
			if ( [] === $locations ) {
				echo '<p>' . esc_html__( 'No active Pickup Locations yet. Create one before enabling Store Pickup.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
				echo '<p><a class="button" href="' . esc_url( AdminPageRenderer::list_url( PickupLocationsPage::SLUG ) ) . '">' . esc_html__( 'Add Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
			} else {
				echo '<select id="pickup_location_id" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][value]">';
				echo '<option value="">' . esc_html__( 'Select a Pickup Location', 'cetech-woocommerce-delivery-engine' ) . '</option>';
				foreach ( $locations as $location ) {
					$id    = (int) ( $location['id'] ?? 0 );
					$label = (string) ( $location['location_name'] ?? '' );
					echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $pickup_id, $id, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				echo '<input type="hidden" name="fields[' . esc_attr( ConfigurationFieldKey::PICKUP_LOCATION_ID ) . '][mode]" value="override" />';
			}
			echo '<p class="description">' . esc_html__( 'Required when Store Pickup is enabled. Customers see this location and its pickup readiness.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			echo '</td></tr>';
		}

		echo '<tr><th><label for="estimated_delivery">' . esc_html__( 'Estimated delivery', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="estimated_delivery" name="fields[' . esc_attr( ConfigurationFieldKey::ESTIMATED_DELIVERY ) . '][value]" value="' . esc_attr( is_string( $eta ) ? $eta : '' ) . '" placeholder="3–5 days" />';
		echo '<input type="hidden" name="fields[' . esc_attr( ConfigurationFieldKey::ESTIMATED_DELIVERY ) . '][mode]" value="override" />';
		echo '<p class="description">' . esc_html__( 'Shown to customers for Delivery. Store Pickup uses the Pickup Location’s readiness instead.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		echo '<p class="cetech-de-button-group">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save Changes', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		if ( empty( $state['setup_completed'] ) ) {
			echo ' <a class="button" href="' . esc_url( AdminPageRenderer::list_url( SetupWizardPage::SLUG ) ) . '">' . esc_html__( 'Save & Apply Site-wide', 'cetech-woocommerce-delivery-engine' ) . '</a>';
		}
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Changes affect products still using these defaults. Product and variation exceptions remain protected.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</form>';
		AdminPageLayout::close_page();
	}

	/**
	 * @param list<array{id: int, label: string, reason: string}> $examples
	 */
	private function render_example_list( string $title, array $examples ): void {
		if ( [] === $examples ) {
			return;
		}

		echo '<h3>' . esc_html( $title ) . '</h3><ul>';
		foreach ( $examples as $example ) {
			echo '<li>' . esc_html( $example['label'] ) . ' — ' . esc_html( $example['reason'] ) . '</li>';
		}
		echo '</ul>';
	}

	private function handle_apply(): void {
		$step = isset( $_POST['setup_step'] ) ? absint( wp_unslash( $_POST['setup_step'] ) ) : 1;
		$active = [];
		if ( isset( $_POST['active_profiles'] ) && is_array( $_POST['active_profiles'] ) ) {
			foreach ( wp_unslash( $_POST['active_profiles'] ) as $key ) {
				$key = sanitize_key( (string) $key );
				if ( FulfilmentProfileRegistry::has( $key ) ) {
					$active[] = $key;
				}
			}
		}

		$primary = isset( $_POST['primary_profile'] ) ? sanitize_key( wp_unslash( (string) $_POST['primary_profile'] ) ) : '';
		$finish  = isset( $_POST['finish_setup'] ) && '1' === (string) wp_unslash( $_POST['finish_setup'] );

		if ( 1 === $step ) {
			if ( [] === $active ) {
				$this->action_handler->notices()->flash_error( __( 'Choose at least one fulfilment type.', 'cetech-woocommerce-delivery-engine' ) );
				$this->action_handler->redirect( self::SLUG, [ 'view' => 'setup', 'step' => 1 ] );
			}
			$this->settings->save(
				[
					'active_profiles' => $active,
					'primary_profile' => $primary,
					'setup_completed' => false,
				]
			);
			$this->action_handler->redirect( self::SLUG, [ 'view' => 'setup', 'step' => 2 ] );
		}

		if ( 3 === $step ) {
			$current = $this->settings->read();
			$active  = [] !== $active ? $active : $current['active_profiles'];
			$this->settings->save(
				[
					'active_profiles' => $active,
					'primary_profile' => $primary,
					'setup_completed' => false,
				]
			);
			$this->action_handler->redirect( self::SLUG, [ 'view' => 'setup', 'step' => 4 ] );
		}

		if ( $finish || 5 === $step ) {
			$current = $this->settings->read();
			$active  = [] !== $active ? $active : $current['active_profiles'];
			$primary = '' !== $primary ? $primary : (string) $current['primary_profile'];
			try {
				$result = $this->defaults->apply_site_wide( $active, $primary, true );
			} catch ( \InvalidArgumentException $exception ) {
				$this->action_handler->notices()->flash_error( $exception->getMessage() );
				$this->action_handler->redirect( self::SLUG, [ 'view' => 'setup', 'step' => 5 ] );
				return;
			}

			$this->action_handler->notices()->flash_success(
				sprintf(
					/* translators: 1: products converted to inherit, 2: exceptions kept */
					__( 'Site-wide defaults are active. %1$d matching saved product values now inherit the defaults. %2$d product exceptions were left unchanged. Legacy rules were not overwritten.', 'cetech-woocommerce-delivery-engine' ),
					$result['converted_products'],
					$result['skipped_exceptions']
				)
			);
			$this->action_handler->redirect( self::SLUG );
		}

		$this->action_handler->redirect( self::SLUG, [ 'view' => 'setup', 'step' => $step ] );
	}

	private function handle_save_profile(): void {
		$profile_key = isset( $_POST['profile_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['profile_key'] ) ) : '';
		$raw         = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : [];

		$fields = [];
		foreach ( $raw as $field_key => $payload ) {
			$field_key = sanitize_key( (string) $field_key );
			if ( ! is_array( $payload ) ) {
				continue;
			}
			$entry = [ 'mode' => sanitize_key( (string) ( $payload['mode'] ?? 'override' ) ) ];
			if ( isset( $payload['value'] ) ) {
				$entry['value'] = sanitize_text_field( (string) $payload['value'] );
			}
			if ( isset( $payload['members'] ) && is_array( $payload['members'] ) ) {
				$entry['members'] = array_map( 'absint', $payload['members'] );
			}
			$fields[ $field_key ] = $entry;
		}

		$profile = FulfilmentProfileRegistry::get( $profile_key );
		if ( $profile instanceof FulfilmentProfile && isset( $fields[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] ) && is_array( $fields[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] ) ) {
			$fields[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] = DeliveryOptionCompatibility::filter_member_ids(
				$fields[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'],
				$this->offers?->list( [ 'limit' => 200 ] ) ?? [],
				$profile
			);
		}

		if ( $profile instanceof FulfilmentProfile && $profile->pickup_allowed ) {
			$methods = [];
			if ( isset( $_POST['available_methods'] ) && is_array( $_POST['available_methods'] ) ) {
				foreach ( wp_unslash( $_POST['available_methods'] ) as $method ) {
					$methods[] = sanitize_key( (string) $method );
				}
			}
			$default = isset( $_POST['default_customer_choice'] )
				? sanitize_key( wp_unslash( (string) $_POST['default_customer_choice'] ) )
				: (string) ( $fields[ ConfigurationFieldKey::FULFILMENT_CHOICE ]['value'] ?? FulfilmentChoice::Delivery->value );
			$pickup_id = isset( $fields[ ConfigurationFieldKey::PICKUP_LOCATION_ID ]['value'] )
				? absint( $fields[ ConfigurationFieldKey::PICKUP_LOCATION_ID ]['value'] )
				: 0;
			$selection = InStoreMethodSelection::from_posted(
				$methods,
				$default,
				$fields[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] ?? [],
				$pickup_id,
				$this->offers
			);
			$errors = $selection->validate( $this->pickups );
			if ( [] !== $errors ) {
				$this->action_handler->notices()->flash_error( implode( ' ', $errors ) );
				$this->action_handler->redirect( self::SLUG, [ 'profile' => $profile_key ] );
				return;
			}
			$fields = array_merge( $fields, $selection->field_payloads() );
		}

		try {
			$this->defaults->save_profile_defaults( $profile_key, $fields );
		} catch ( \Throwable $exception ) {
			$this->action_handler->notices()->flash_error( $exception->getMessage() );
			$this->action_handler->redirect( self::SLUG, [ 'profile' => $profile_key ] );
			return;
		}

		$this->action_handler->notices()->flash_success( __( 'Site-wide defaults saved. Products that inherit these settings will use the new values immediately.', 'cetech-woocommerce-delivery-engine' ) );
		$this->action_handler->redirect( self::SLUG, [ 'profile' => $profile_key ] );
	}

	/**
	 * @return array<int, string>
	 */
	private function compatible_option_labels( FulfilmentProfile $profile ): array {
		$offers = $this->offers?->list( [ 'limit' => 200 ] ) ?? [];

		return DeliveryOptionCompatibility::filter_option_labels(
			$this->labels->options_for( 'delivery_offer' ),
			$offers,
			$profile
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function active_pickup_locations(): array {
		$rows = $this->pickups?->list( [ 'limit' => 200 ] ) ?? [];
		$out  = [];
		foreach ( $rows as $row ) {
			if ( RecordStatus::Active->value !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param array<string, scalar> $args
	 */
	private function url( array $args = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::SLUG ], $args ),
			admin_url( 'admin.php' )
		);
	}
}
