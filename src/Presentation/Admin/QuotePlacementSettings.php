<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementActivation;

/** Explicit store adoption, separate from ordinary display settings and C07 pause. */
final class QuotePlacementSettings {
	public const ACTION = 'cetech_de_change_quote_placement';
	public function __construct( private QuotePlacementActivation $activation, private AdminActionHandler $actions ) {}
	public function handle_actions(): void {
		if ( ! $this->actions->verify_post( self::ACTION, self::ACTION, 'manage_delivery_settings', DeliverySettingsPage::SLUG ) ) { return; }
		$revision = $_POST['quote_adoption_revision'] ?? null; $enabled = $_POST['quote_adoption_enabled'] ?? null;
		$valid = is_string( $revision ) && preg_match( '/\A(?:0|[1-9][0-9]{0,18})\z/D', $revision ) && (string) (int) $revision === $revision && in_array( $enabled, [ '0', '1' ], true );
		$saved = $valid && $this->activation->change( '1' === $enabled, (int) $revision );
		AdminPageAccess::require_capability( 'manage_delivery_settings' );
		if ( $saved ) { $this->actions->notices()->flash_success( __( 'Delivery price review was saved.', 'cetech-woocommerce-delivery-engine' ) ); }
		else { $this->actions->notices()->flash_error( __( 'The change could not be confirmed. Reload the current setting before trying again.', 'cetech-woocommerce-delivery-engine' ) ); }
		$this->actions->redirect( DeliverySettingsPage::SLUG );
	}
	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_settings' ); $state = $this->activation->status();
		AdminPageLayout::open_section( __( 'Delivery price review', 'cetech-woocommerce-delivery-engine' ), __( 'Require customers to review and confirm their delivery price before placing an order.', 'cetech-woocommerce-delivery-engine' ) );
		echo '<p role="status">' . esc_html( $state['active'] ? __( 'Enabled', 'cetech-woocommerce-delivery-engine' ) : __( 'Disabled', 'cetech-woocommerce-delivery-engine' ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'This setting applies to Classic checkout and Blocks together. Turning it off preserves saved order details and their payment checks.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		if ( ! $state['available'] || ( ! $state['enabled'] && ! $state['ready'] ) ) { echo '<p>' . esc_html__( 'Complete checkout setup for Classic and Blocks, including the Delivery shipping method, before enabling price review.', 'cetech-woocommerce-delivery-engine' ) . '</p>'; }
		else {
			echo '<form method="post" action="">'; AdminFormHelper::nonce_field( self::ACTION );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="quote_adoption_revision" value="' . esc_attr( (string) $state['revision'] ) . '" /><input type="hidden" name="quote_adoption_enabled" value="' . ( $state['enabled'] ? '0' : '1' ) . '" />';
			echo '<p><button type="submit" class="button">' . esc_html( $state['enabled'] ? __( 'Disable price review', 'cetech-woocommerce-delivery-engine' ) : __( 'Enable price review', 'cetech-woocommerce-delivery-engine' ) ) . '</button></p></form>';
		}
		AdminPageLayout::close_section();
	}
}
