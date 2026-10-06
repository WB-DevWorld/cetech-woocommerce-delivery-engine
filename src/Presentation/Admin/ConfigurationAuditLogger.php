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
			$previous_value = null !== $previous ? $this->sanitize_payload( $previous ) : null;
			$new_value      = null !== $new ? $this->sanitize_payload( $new ) : null;
			if ( ( null !== $previous && null === $previous_value ) || ( null !== $new && null === $new_value ) ) {
				return false;
			}
			$audit_id = $this->audit_log_repository->append(
				[
					'actor_user_id'  => get_current_user_id() > 0 ? get_current_user_id() : null,
					'action'         => $action,
					'entity_type'    => $entity_type,
					'entity_id'      => $entity_id,
					'previous_value' => $previous_value,
					'new_value'      => $new_value,
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

	public function recorded_completion( string $request_token ): ?array {
		$request_token = trim( $request_token );
		if ( '' === $request_token ) {
			return null;
		}

		foreach ( $this->audit_log_repository->list( [ 'limit' => 100 ] ) as $row ) {
			$new = $this->decode_audit_value( $row['new_value'] ?? null );
			if ( ! is_array( $new ) || (string) ( $new['request_token'] ?? '' ) !== $request_token ) {
				continue;
			}
			$previous = $this->decode_audit_value( $row['previous_value'] ?? null );

			return [
				'version_before' => (int) ( is_array( $previous ) ? ( $previous['config_version'] ?? 0 ) : 0 ),
				'version_after'  => (int) ( $new['config_version'] ?? 0 ),
				'intent_hash'    => (string) ( $new['intent_hash'] ?? '' ),
				'action'         => (string) ( $row['action'] ?? '' ),
			];
		}

		return null;
	}

	private function decode_audit_value( mixed $value ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : $value;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function sanitize_payload( array $payload ): ?string {
		unset( $payload['internal_notes'] );

		$encoded = wp_json_encode( $payload );

		return false !== $encoded ? $encoded : null;
	}
}

