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
		if ( ! current_user_can( 'manage_product_delivery_rules' ) ) {
			wp_send_json_error( [ 'message' => 'forbidden' ], 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );
		$job_id = isset( $_POST['job_id'] ) ? absint( wp_unslash( (string) $_POST['job_id'] ) ) : 0;
		$job    = $this->engine->find( $job_id );
		if ( ! $job ) {
			wp_send_json_error( [ 'message' => 'unknown_job' ], 404 );
		}
		wp_send_json_success(
			[
				'code'      => $job->job_code,
				'status'    => $job->status->value,
				'total'     => $job->total_count,
				'processed' => $job->processed_count,
				'changed'   => $job->changed_count,
				'skipped'   => $job->skipped_count,
				'failed'    => $job->failed_count,
				'terminal'  => $job->status->is_terminal() || $job->status->allows_apply(),
			]
		);
	}
}
