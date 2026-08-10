<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationChangeAuditorInterface;

/**
 * Writes configuration audit entries without sensitive operational data.
 *
 * internal_notes are omitted from all audit payloads. Supplier and origin
 * audit entries remain wp-admin private operational history only.
 */
final class ConfigurationAuditLogger implements ConfigurationChangeAuditorInterface {

	public function __construct(
		private AuditLogRepositoryInterface $audit_log_repository,
		private Logger $logger
	) {
	}

	/**
	 * @param array<string, mixed>|null $previous
	 * @param array<string, mixed>|null $new
	 */
	public function log(
		string $action,
		string $entity_type,
		int $entity_id,
		?array $previous = null,
		?array $new = null
	): bool {
		try {
			$audit_id = $this->audit_log_repository->append(
				[
					'actor_user_id'  => get_current_user_id() > 0 ? get_current_user_id() : null,
					'action'         => $action,
					'entity_type'    => $entity_type,
					'entity_id'      => $entity_id,
					'previous_value' => null !== $previous ? $this->sanitize_payload( $previous ) : null,
					'new_value'      => null !== $new ? $this->sanitize_payload( $new ) : null,
					'site_context'   => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
				]
			);

			if ( $audit_id <= 0 ) {
				$this->logger->error(
					'Configuration audit log append failed.',
					[
						'action'      => $action,
						'entity_type' => $entity_type,
						'entity_id'   => $entity_id,
					]
				);

				return false;
			}

			return true;
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Configuration audit log append threw an exception.',
				[
					'action'           => $action,
					'entity_type'      => $entity_type,
					'entity_id'        => $entity_id,
					'exception_class'  => get_class( $exception ),
				]
			);

			return false;
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function sanitize_payload( array $payload ): string {
		unset( $payload['internal_notes'] );

		$encoded = wp_json_encode( $payload );

		return false !== $encoded ? $encoded : '';
	}
}

