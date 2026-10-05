<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Versioning;

/**
 * Persists the last migration run outcome for diagnostics.
 */
final class MigrationStatus {

	public const OPTION_NAME = 'cetech_de_last_migration_status';

	/**
	 * @param array<string, mixed> $data
	 */
	public static function record( array $data ): void {
		$payload = array_merge(
			[
				'recorded_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			$data
		);

		update_option( self::OPTION_NAME, $payload, false );
		if ( $payload !== get_option( self::OPTION_NAME, null ) ) {
			throw new \RuntimeException( 'Migration status could not be persisted.' );
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get(): ?array {
		$stored = get_option( self::OPTION_NAME, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		return $stored;
	}
}
