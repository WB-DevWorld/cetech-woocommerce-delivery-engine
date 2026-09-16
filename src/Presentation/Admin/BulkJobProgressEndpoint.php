<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;

final class BulkJobProgressEndpoint {

	public const ACTION = 'cetech_de_bulk_job_status';

	public function __construct(
		private readonly BulkJobEngine $engine
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( AdminPageAccess::current_user_is_restricted() || ! current_user_can( 'manage_product_delivery_rules' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );
		$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( (string) $_POST['job_id'] ) ) : 0;
		$job    = $this->engine->find( $job_id );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => 'unknown_job' ], 404 );
		}

		$advance = isset( $_POST['advance'] ) && '1' === (string) wp_unslash( (string) $_POST['advance'] );
		if ( $advance ) {
			$job = $this->engine->continue_job( $job_id );
		}

		wp_send_json_success( BulkJobAdminCopy::progress_payload( $job ) );
	}
}
