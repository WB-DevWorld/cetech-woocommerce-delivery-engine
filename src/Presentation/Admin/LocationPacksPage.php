<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;

final class LocationPacksPage {

	public const SLUG = 'cetech-delivery-engine-location-packs';

	private const ACTION_INSTALL = 'cetech_de_install_location_pack';

	private const ACTION_TICK = 'cetech_de_tick_location_pack';

	public function __construct(
		private GeographyPackService $packs,
		private AdminActionHandler $action_handler
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_INSTALL, self::ACTION_INSTALL, 'manage_delivery_zones', self::SLUG ) ) {
			$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_POST['country_code'] ?? 'GH' ) ) ) );
			$path    = sanitize_text_field( wp_unslash( (string) ( $_POST['source_path'] ?? '' ) ) );
			$pack    = $this->packs->install( $country, $path );
			$this->action_handler->notices()->add_success(
				sprintf(
					/* translators: %s country code */
					__( 'Location pack for %s queued. Import continues in the background.', 'cetech-woocommerce-delivery-engine' ),
					$country
				)
			);
			if ( '' !== $path ) {
				$this->packs->tick( $pack->id, $path, 100 );
			}
		}

		if ( $this->action_handler->verify_post( self::ACTION_TICK, self::ACTION_TICK, 'manage_delivery_zones', self::SLUG ) ) {
			$pack_id = (int) ( $_POST['pack_id'] ?? 0 );
			$path    = sanitize_text_field( wp_unslash( (string) ( $_POST['source_path'] ?? '' ) ) );
			$this->packs->tick( $pack_id, $path, 150 );
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_zones' );
		$this->action_handler->notices()->render_notices();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Location Packs', 'cetech-woocommerce-delivery-engine' ) . '</h1>';
		echo '<p>' . esc_html__( 'Install country locality packs used by Delivery Areas and the product-page city selector. Shopper requests never call GeoNames.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p class="description">' . esc_html( \CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter::ATTRIBUTION ) . '</p>';

		echo '<h2>' . esc_html__( 'Install a pack', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( self::ACTION_INSTALL, self::ACTION_INSTALL );
		echo '<table class="form-table"><tr><th><label for="cetech-de-pack-country">' . esc_html__( 'Country', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<input type="text" id="cetech-de-pack-country" name="country_code" value="GH" maxlength="2" class="regular-text" />';
		echo '</td></tr><tr><th><label for="cetech-de-pack-path">' . esc_html__( 'Local gazetteer file', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<input type="text" id="cetech-de-pack-path" name="source_path" class="large-text" placeholder="C:\\path\\to\\GH.txt" />';
		echo '<p class="description">' . esc_html__( 'Provide the extracted GeoNames country text file. Do not paste licensed ZIP binaries into the plugin.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</td></tr></table>';
		submit_button( __( 'Install / update pack', 'cetech-woocommerce-delivery-engine' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Installed packs', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Country', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Provider', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Dataset', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Installed', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'License', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '</tr></thead><tbody>';
		$rows = $this->packs->list_public();
		if ( [] === $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No location packs installed yet.', 'cetech-woocommerce-delivery-engine' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['country_code'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['provider'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['dataset_name'] . ' ' . $row['dataset_version'] ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['installed_at'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['license_name'] ) . '</td>';
			$progress = is_array( $row['updated_progress'] ?? null ) ? $row['updated_progress'] : [];
			echo '<td>' . esc_html( (string) ( $progress['imported'] ?? 0 ) ) . ' / ' . esc_html( (string) ( $progress['processed'] ?? 0 ) ) . '</td>';
			echo '<td>';
			if ( GeographyPackStatus::Failed->value === $status || GeographyPackStatus::Importing->value === $status ) {
				echo '<form method="post" style="display:inline">';
				wp_nonce_field( self::ACTION_TICK, self::ACTION_TICK );
				echo '<input type="hidden" name="pack_id" value="' . esc_attr( (string) $row['id'] ) . '" />';
				submit_button( __( 'Continue / retry', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
				echo '</form>';
			}
			if ( '' !== (string) ( $row['last_error'] ?? '' ) ) {
				echo '<p class="description">' . esc_html( (string) $row['last_error'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
