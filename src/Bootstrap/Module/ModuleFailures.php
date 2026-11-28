<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap\Module;

/**
 * Lane modules that failed to register or boot on this request.
 *
 * One faulty module must never stop the plugin (and checkout) from booting: it is skipped, the
 * failure is written to the PHP error log for staff, and the other modules carry on. Kept per
 * service container, so tests stay independent.
 */
final class ModuleFailures {

	/** @var array<string, array{phase: string, error: string}> */
	private array $failures = [];

	public function record( string $module_id, string $phase, \Throwable $error ): void {
		$this->failures[ $module_id ] = [
			'phase' => $phase,
			'error' => get_class( $error ),
		];
		if ( function_exists( 'error_log' ) ) {
			// Class and a short message only; module code must not put private data in exceptions.
			error_log( sprintf( 'CETECH Delivery Engine: module "%s" failed during %s (%s: %s); it is skipped on this request.', $module_id, $phase, get_class( $error ), substr( $error->getMessage(), 0, 200 ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public function has( string $module_id ): bool {
		return isset( $this->failures[ $module_id ] );
	}

	/**
	 * @return array<string, array{phase: string, error: string}>
	 */
	public function all(): array {
		return $this->failures;
	}
}
