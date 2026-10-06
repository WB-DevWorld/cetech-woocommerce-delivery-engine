<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationChangeAuditorInterface;

final class RecordingConfigurationAuditLogger implements ConfigurationChangeAuditorInterface {

	/** @var list<array{action: string, entity_type: string, entity_id: int, previous: ?array, new: ?array}> */
	public array $calls = [];

	private InMemoryAuditLogRepository $memory;

	public function __construct( ?InMemoryAuditLogRepository $memory = null ) {
		$this->memory = $memory ?? new InMemoryAuditLogRepository();
	}

	public function log(
		string $action,
		string $entity_type,
		int $entity_id,
		?array $previous = null,
		?array $new = null
	): bool {
		$this->calls[] = [
			'action'      => $action,
			'entity_type' => $entity_type,
			'entity_id'   => $entity_id,
			'previous'    => $previous,
			'new'         => $new,
		];

		$this->memory->append(
			[
				'action'         => $action,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'previous_value' => $previous,
				'new_value'      => $new,
			]
		);

		return true;
	}

	public function recorded_completion( string $request_token ): ?array {
		foreach ( array_reverse( $this->calls ) as $call ) {
			$new = $call['new'];
			if ( ! is_array( $new ) || (string) ( $new['request_token'] ?? '' ) !== $request_token ) {
				continue;
			}
			$previous = $call['previous'];

			return [
				'version_before' => (int) ( is_array( $previous ) ? ( $previous['config_version'] ?? 0 ) : 0 ),
				'version_after'  => (int) ( $new['config_version'] ?? 0 ),
				'intent_hash'    => (string) ( $new['intent_hash'] ?? '' ),
				'action'         => (string) $call['action'],
			];
		}

		return null;
	}
}
