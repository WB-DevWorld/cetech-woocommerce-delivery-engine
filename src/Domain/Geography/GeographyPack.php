<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;

final class GeographyPack {

	/**
	 * @param array<string, mixed> $progress
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $country_code,
		public readonly GeographyProvider $provider,
		public readonly string $dataset_name,
		public readonly string $dataset_version,
		public readonly string $source_url,
		public readonly string $source_reference,
		public readonly string $checksum,
		public readonly string $license_name,
		public readonly string $license_url,
		public readonly string $attribution_text,
		public readonly GeographyPackStatus $status,
		public readonly string $import_cursor,
		public readonly array $progress,
		public readonly string $last_error,
		public readonly ?string $installed_at
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow( array $row ): self {
		$progress = [];
		if ( isset( $row['progress_json'] ) && is_string( $row['progress_json'] ) && '' !== $row['progress_json'] ) {
			$decoded = json_decode( $row['progress_json'], true );
			$progress = is_array( $decoded ) ? $decoded : [];
		} elseif ( isset( $row['progress'] ) && is_array( $row['progress'] ) ) {
			$progress = $row['progress'];
		}

		$provider = GeographyProvider::tryFrom( (string) ( $row['provider'] ?? '' ) ) ?? GeographyProvider::GeoNames;
		$status   = GeographyPackStatus::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? GeographyPackStatus::Pending;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			strtoupper( (string) ( $row['country_code'] ?? '' ) ),
			$provider,
			(string) ( $row['dataset_name'] ?? '' ),
			(string) ( $row['dataset_version'] ?? '' ),
			(string) ( $row['source_url'] ?? '' ),
			(string) ( $row['source_reference'] ?? '' ),
			(string) ( $row['checksum'] ?? '' ),
			(string) ( $row['license_name'] ?? '' ),
			(string) ( $row['license_url'] ?? '' ),
			(string) ( $row['attribution_text'] ?? '' ),
			$status,
			(string) ( $row['import_cursor'] ?? '0' ),
			$progress,
			(string) ( $row['last_error'] ?? '' ),
			isset( $row['installed_at'] ) && is_string( $row['installed_at'] ) && '' !== $row['installed_at']
				? $row['installed_at']
				: null
		);
	}

	public function publicAdminRow(): array {
		$source = $this->source_reference;
		if ( '' !== $source ) {
			$source = basename( str_replace( '\\', '/', $source ) );
		}

		return [
			'id'               => $this->id,
			'country_code'     => $this->country_code,
			'provider'         => $this->provider->value,
			'dataset_name'     => $this->dataset_name,
			'dataset_version'  => '' !== $this->dataset_version ? $this->dataset_version : '—',
			'checksum'         => $this->checksum,
			'source_url'       => $this->source_url,
			'source_file'      => $source,
			'status'           => $this->status->value,
			'license_name'     => $this->license_name,
			'license_url'      => $this->license_url,
			'attribution_text' => $this->attribution_text,
			'installed_at'     => $this->installed_at,
			'updated_progress' => [
				'processed' => (int) ( $this->progress['processed'] ?? 0 ),
				'imported'  => (int) ( $this->progress['imported'] ?? 0 ),
				'skipped'   => (int) ( $this->progress['skipped'] ?? 0 ),
				'total'     => (int) ( $this->progress['total'] ?? 0 ),
				'phase'     => (string) ( $this->progress['phase'] ?? '' ),
			],
			'last_error'       => $this->last_error,
		];
	}
}
