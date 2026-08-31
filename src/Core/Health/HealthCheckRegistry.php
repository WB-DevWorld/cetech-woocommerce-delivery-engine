<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Health;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\FeaturesCompatibility;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;

/**
 * Collects core health check results for future diagnostics UI.
 */
final class HealthCheckRegistry {

	/** @var array<string, array{status: string, message: string, value?: mixed}> */
	private array $results = [];

	public function __construct(
		private Requirements $requirements,
		private FeatureFlags $feature_flags,
		private IntegrationRegistry $integration_registry
	) {
	}

	public function run(): void {
		$this->results = [];

		$this->add(
			'php_version',
			$this->requirements->is_php_version_supported() ? 'pass' : 'fail',
			$this->requirements->is_php_version_supported()
				? sprintf( 'PHP %s meets the minimum requirement.', PHP_VERSION )
				: $this->requirements->php_version_notice_message(),
			PHP_VERSION
		);

		$this->add(
			'woocommerce_active',
			$this->requirements->is_woocommerce_active() ? 'pass' : 'fail',
			$this->requirements->is_woocommerce_active()
				? 'WooCommerce is active.'
				: $this->requirements->woocommerce_missing_notice_message()
		);

		if ( $this->requirements->is_woocommerce_active() && defined( 'WC_VERSION' ) ) {
			$this->add(
				'woocommerce_version',
				'info',
				'WooCommerce version detected.',
				WC_VERSION
			);
		}

		$this->add(
			'hpos_declaration',
			FeaturesCompatibility::hpos_declaration_attempted() ? 'pass' : 'info',
			FeaturesCompatibility::hpos_declaration_attempted()
				? 'HPOS compatibility declaration hook registered.'
				: 'HPOS compatibility declaration hook not registered.',
			FeaturesCompatibility::hpos_declaration_attempted()
		);

		$this->add(
			'feature_flags',
			'pass',
			'Feature flags loaded.',
			$this->feature_flags->all()
		);

		$this->add(
			'integrations',
			'info',
			'Optional integration detection.',
			$this->integration_registry->get_detection_statuses()
		);
	}

	/**
	 * @return array<string, array{status: string, message: string, value?: mixed}>
	 */
	public function results(): array {
		if ( [] === $this->results ) {
			$this->run();
		}

		return $this->results;
	}

	public function is_healthy(): bool {
		foreach ( $this->results() as $result ) {
			if ( 'fail' === $result['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $value
	 */
	private function add( string $key, string $status, string $message, $value = null ): void {
		$entry = [
			'status'  => $status,
			'message' => $message,
		];

		if ( null !== $value ) {
			$entry['value'] = $value;
		}

		$this->results[ $key ] = $entry;
	}
}
