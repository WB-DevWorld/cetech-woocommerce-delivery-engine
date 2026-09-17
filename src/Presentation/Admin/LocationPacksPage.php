<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;

final class LocationPacksPage {

	public const SLUG = 'cetech-delivery-engine-location-packs';

	private const ACTION_INSTALL = 'cetech_de_install_location_pack';

	private const ACTION_TICK = 'cetech_de_tick_location_pack';

	private const ACTION_RECONCILE = 'cetech_de_reconcile_legacy_coverage';

	public function __construct(
		private GeographyPackService $packs,
		private AdminActionHandler $action_handler,
		private ?Schema6CoverageUpgradeService $upgrade = null
	) {
	}

	public function handle_actions(): void {
		if ( $this->action_handler->verify_post( self::ACTION_INSTALL, self::ACTION_INSTALL, 'manage_delivery_zones', self::SLUG ) ) {
			$country = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_POST['country_code'] ?? 'GH' ) ) ) );
			if ( 2 !== strlen( $country ) || ! ctype_alpha( $country ) ) {
				$this->action_handler->notices()->add_error( __( 'Enter a two-letter country code.', 'cetech-woocommerce-delivery-engine' ) );
				return;
			}
			$download = ! empty( $_POST['download_official'] );
			$path     = '';
			if ( $download ) {
				$this->packs->queue_official_download( $country );
				$this->action_handler->notices()->add_success(
					sprintf(
						/* translators: %s country code */
						__( 'Official GeoNames pack for %s queued for background download.', 'cetech-woocommerce-delivery-engine' ),
						$country
					)
				);
				return;
			}

			if ( isset( $_FILES['pack_file'] ) && is_array( $_FILES['pack_file'] ) && (int) ( $_FILES['pack_file']['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
				$tmp  = (string) ( $_FILES['pack_file']['tmp_name'] ?? '' );
				$name = sanitize_file_name( (string) ( $_FILES['pack_file']['name'] ?? '' ) );
				$path = $this->packs->store_upload( $country, $tmp, $name );
				if ( '' === $path ) {
					$this->action_handler->notices()->add_error( __( 'The uploaded pack could not be stored. Use a country .txt or .zip file.', 'cetech-woocommerce-delivery-engine' ) );
					return;
				}
			} else {
				$path = sanitize_text_field( wp_unslash( (string) ( $_POST['source_path'] ?? '' ) ) );
			}

			$op   = sanitize_key( (string) ( $_POST['pack_op'] ?? 'install' ) );
			$pack = match ( $op ) {
				'update' => $this->packs->update( $country, $path ),
				'retry' => $this->packs->retry( (int) ( $_POST['pack_id'] ?? 0 ), $path ),
				default => $this->packs->install( $country, $path ),
			};
			$this->action_handler->notices()->add_success(
				sprintf(
					/* translators: %s country code */
					__( 'Location pack for %s queued. Import continues in the background.', 'cetech-woocommerce-delivery-engine' ),
					$country
				)
			);
			if ( '' !== $path && is_readable( $path ) ) {
				$this->packs->tick( $pack->id, $path, 100 );
			}
		}

		if ( $this->action_handler->verify_post( self::ACTION_TICK, self::ACTION_TICK, 'manage_delivery_zones', self::SLUG ) ) {
			$pack_id = (int) ( $_POST['pack_id'] ?? 0 );
			$op      = sanitize_key( (string) ( $_POST['pack_op'] ?? 'retry' ) );
			if ( 'retry' === $op ) {
				$this->packs->retry( $pack_id );
			}
			$this->packs->tick( $pack_id, '', 150 );
		}

		if ( $this->action_handler->verify_post( self::ACTION_RECONCILE, self::ACTION_RECONCILE, 'manage_delivery_zones', self::SLUG ) ) {
			if ( ! $this->upgrade instanceof Schema6CoverageUpgradeService ) {
				$this->action_handler->notices()->add_error( __( 'Legacy coverage reconciliation is not available.', 'cetech-woocommerce-delivery-engine' ) );
				return;
			}
			$result = $this->upgrade->reconcile( true );
			$migration = is_array( $result['migration'] ?? null ) ? $result['migration'] : [];
			$this->action_handler->notices()->add_success(
				sprintf(
					/* translators: 1: scanned, 2: skipped manual, 3: reconciled, 4: still review, 5: activated */
					__( 'Safe reconciliation finished. Scanned %1$d, skipped manual %2$d, reconciled %3$d, still review required %4$d, activated %5$d.', 'cetech-woocommerce-delivery-engine' ),
					(int) ( $migration['scanned'] ?? 0 ),
					(int) ( $migration['skipped_manual'] ?? 0 ),
					(int) ( $migration['reconciled'] ?? 0 ),
					(int) ( $migration['still_review_required'] ?? 0 ),
					(int) ( $migration['activated'] ?? 0 )
				)
			);
			$warnings = is_array( $migration['warnings'] ?? null ) ? $migration['warnings'] : [];
			if ( [] !== $warnings ) {
				$this->action_handler->notices()->add_warning(
					sprintf(
						/* translators: %d warning count */
						_n( '%d reconciliation warning was recorded.', '%d reconciliation warnings were recorded.', count( $warnings ), 'cetech-woocommerce-delivery-engine' ),
						count( $warnings )
					)
				);
			}
		}
	}

	public function render(): void {
		AdminPageAccess::require_capability( 'manage_delivery_zones' );
		$this->action_handler->notices()->render_notices();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Location Packs', 'cetech-woocommerce-delivery-engine' ) . '</h1>';
		echo '<p>' . esc_html__( 'Install country locality packs used by Delivery Areas and the product-page city selector. Shopper requests never call GeoNames. Packs are stored in WordPress uploads, not inside the plugin.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<p class="description">' . esc_html( \CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter::ATTRIBUTION ) . '</p>';

		echo '<h2>' . esc_html__( 'Install or update a pack', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( self::ACTION_INSTALL, self::ACTION_INSTALL );
		echo '<table class="form-table"><tr><th><label for="cetech-de-pack-country">' . esc_html__( 'Country', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<input type="text" id="cetech-de-pack-country" name="country_code" value="GH" maxlength="2" class="regular-text" />';
		echo '</td></tr><tr><th><label for="cetech-de-pack-file">' . esc_html__( 'Upload gazetteer file', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<input type="file" id="cetech-de-pack-file" name="pack_file" accept=".txt,.zip,text/plain,application/zip" />';
		echo '<p class="description">' . esc_html__( 'Upload the extracted GeoNames country .txt file, or the official country .zip. Files are stored under wp-content/uploads/cetech-delivery-engine/geography/.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '</td></tr><tr><th>' . esc_html__( 'Official download', 'cetech-woocommerce-delivery-engine' ) . '</th><td>';
		echo '<label><input type="checkbox" name="download_official" value="1" /> ' . esc_html__( 'Fetch the official GeoNames country ZIP in the background (download.geonames.org only).', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '</td></tr><tr><th><label for="cetech-de-pack-op">' . esc_html__( 'Operation', 'cetech-woocommerce-delivery-engine' ) . '</label></th><td>';
		echo '<select id="cetech-de-pack-op" name="pack_op"><option value="install">' . esc_html__( 'Install or continue', 'cetech-woocommerce-delivery-engine' ) . '</option><option value="update">' . esc_html__( 'Update with a new dataset', 'cetech-woocommerce-delivery-engine' ) . '</option><option value="retry">' . esc_html__( 'Retry / resume current dataset', 'cetech-woocommerce-delivery-engine' ) . '</option></select>';
		echo '</td></tr></table>';
		submit_button( __( 'Install / update pack', 'cetech-woocommerce-delivery-engine' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Installed packs', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Country', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Provider', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Version', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Checksum', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Installed', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'License', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Progress', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'cetech-woocommerce-delivery-engine' ) . '</th>';
		echo '</tr></thead><tbody>';
		$rows = $this->packs->list_public();
		if ( [] === $rows ) {
			echo '<tr><td colspan="9">' . esc_html__( 'No location packs installed yet.', 'cetech-woocommerce-delivery-engine' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['country_code'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['provider'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['dataset_version'] ) . '</td>';
			$checksum = (string) ( $row['checksum'] ?? '' );
			echo '<td><code>' . esc_html( '' !== $checksum ? substr( $checksum, 0, 16 ) : '—' ) . '</code></td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['installed_at'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['license_name'] ) . '</td>';
			$progress = is_array( $row['updated_progress'] ?? null ) ? $row['updated_progress'] : [];
			echo '<td>' . esc_html( (string) ( $progress['imported'] ?? 0 ) ) . ' / ' . esc_html( (string) ( $progress['processed'] ?? 0 ) );
			if ( '' !== (string) ( $progress['phase'] ?? '' ) ) {
				echo ' (' . esc_html( (string) $progress['phase'] ) . ')';
			}
			echo '</td><td>';
			if ( GeographyPackStatus::Failed->value === $status || GeographyPackStatus::Importing->value === $status || GeographyPackStatus::Pending->value === $status ) {
				echo '<form method="post" style="display:inline">';
				wp_nonce_field( self::ACTION_TICK, self::ACTION_TICK );
				echo '<input type="hidden" name="pack_id" value="' . esc_attr( (string) $row['id'] ) . '" />';
				echo '<input type="hidden" name="pack_op" value="retry" />';
				submit_button( __( 'Continue / retry', 'cetech-woocommerce-delivery-engine' ), 'secondary', 'submit', false );
				echo '</form>';
			}
			if ( '' !== (string) ( $row['last_error'] ?? '' ) ) {
				echo '<p class="description">' . esc_html( (string) $row['last_error'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Safe post-pack reconciliation', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		echo '<p>' . esc_html__( 'Revisit only Delivery Areas with no coverage, migration-generated review-required groups, or unresolved migration records. Active manually created canonical coverage is never replaced.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<form method="post">';
		wp_nonce_field( self::ACTION_RECONCILE, self::ACTION_RECONCILE );
		submit_button( __( 'Run safe legacy reconciliation', 'cetech-woocommerce-delivery-engine' ), 'secondary' );
		echo '</form>';
		echo '</div>';
	}
}
