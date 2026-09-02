<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Support;

/**
 * Thin logging wrapper. Uses WooCommerce logger when available.
 */
final class Logger {

	public const CHANNEL = 'cetech-delivery-engine';

	/**
	 * @param array<string, mixed> $context
	 */
	public function log( string $level, string $message, array $context = [] ): void {
		$context = $this->sanitize_context( $context );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( [ 'source' => self::CHANNEL ], $context ) );

			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[%s][%s] %s %s',
					self::CHANNEL,
					$level,
					$message,
					$context ? wp_json_encode( $context ) : ''
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ): array {
		$blocked_keys = [
			'supplier',
			'origin',
			'supplier_id',
			'origin_id',
			'internal_cost',
			'payment_token',
			'card_number',
			'first_name',
			'last_name',
			'phone',
			'address_1',
			'address_2',
			'address',
			'recipient',
		];

		foreach ( $blocked_keys as $key ) {
			unset( $context[ $key ] );
		}

		return $context;
	}
}
