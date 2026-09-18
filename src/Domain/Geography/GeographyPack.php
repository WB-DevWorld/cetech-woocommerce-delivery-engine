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

	public function active_generation(): int {
		return (int) ( $this->progress['active_generation'] ?? 0 );
	}

	public function target_generation(): int {
		return (int) ( $this->progress['target_generation'] ?? 0 );
	}

	public function target_token(): string {
		return (string) ( $this->progress['target_token'] ?? '' );
	}

	public function has_usable_dataset(): bool {
		$successful = $this->last_successful();
		if ( [] !== $successful && '' !== (string) ( $successful['checksum'] ?? '' ) ) {
			return true;
		}

		return GeographyPackStatus::Ready === $this->status && '' !== $this->checksum;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function last_successful(): array {
		$raw = $this->progress['last_successful'] ?? [];

		return is_array( $raw ) ? $raw : [];
	}

	public function active_dataset_version(): string {
		$successful = $this->last_successful();
		$version    = (string) ( $successful['dataset_version'] ?? '' );
		if ( '' !== $version ) {
			return $version;
		}

		return GeographyPackStatus::Ready === $this->status ? $this->dataset_version : '';
	}

	public function active_checksum(): string {
		$successful = $this->last_successful();
		$checksum   = (string) ( $successful['checksum'] ?? '' );
		if ( '' !== $checksum ) {
			return $checksum;
		}

		return GeographyPackStatus::Ready === $this->status ? $this->checksum : '';
	}

	public function assert_expected_target_token( string $expected_target_token ): void {
		$expected = trim( $expected_target_token );
		$current  = $this->target_token();
		if ( '' === $expected || '' === $current || $current === $expected ) {
			return;
		}

		throw new GeographyPackTokenFenceException(
			'Pack target token fence rejected expected ' . $expected . ' against current ' . $current . '.'
		);
	}

	/**
	 * @param array<string, mixed> $progress
	 * @return array<string, mixed>
	 */
	public function apply_progress_update( array $progress, GeographyPackStatus $status, ?string $installed_at, string $expected_target_token = '' ): array {
		$this->assert_expected_target_token( $expected_target_token );
		if ( ! array_key_exists( 'last_successful', $progress ) && [] !== $this->last_successful() ) {
			$progress['last_successful'] = $this->last_successful();
		}
		if ( GeographyPackStatus::Ready === $status ) {
			$progress['last_successful'] = $this->ready_last_successful( $progress, $installed_at );
		}

		return $progress;
	}

	/**
	 * @param array<string, mixed> $progress
	 * @return array<string, mixed>
	 */
	public function ready_last_successful( array $progress, ?string $installed_at ): array {
		$supplied = isset( $progress['last_successful'] ) && is_array( $progress['last_successful'] )
			? $progress['last_successful']
			: [];
		$prior = $this->last_successful();
		$token = self::first_non_empty_string(
			(string) ( $supplied['generation_token'] ?? '' ),
			(string) ( $supplied['attempt_token'] ?? '' ),
			(string) ( $progress['staging_identity'] ?? '' ),
			(string) ( $progress['target_token'] ?? '' ),
			$this->target_token()
		);

		return [
			'checksum'          => self::first_non_empty_string(
				(string) ( $supplied['checksum'] ?? '' ),
				(string) ( $progress['dataset_checksum'] ?? '' ),
				$this->checksum,
				(string) ( $prior['checksum'] ?? '' )
			),
			'dataset_version'   => self::first_non_empty_string(
				(string) ( $supplied['dataset_version'] ?? '' ),
				$this->dataset_version,
				(string) ( $prior['dataset_version'] ?? '' )
			),
			'source_reference'  => self::first_non_empty_string(
				(string) ( $supplied['source_reference'] ?? '' ),
				(string) ( $supplied['source'] ?? '' ),
				$this->source_reference,
				(string) ( $prior['source_reference'] ?? '' ),
				(string) ( $prior['source'] ?? '' )
			),
			'source'            => self::first_non_empty_string(
				(string) ( $supplied['source'] ?? '' ),
				(string) ( $supplied['source_reference'] ?? '' ),
				$this->source_reference,
				(string) ( $prior['source'] ?? '' ),
				(string) ( $prior['source_reference'] ?? '' )
			),
			'installed_at'      => self::first_non_empty_string(
				(string) ( $supplied['installed_at'] ?? '' ),
				(string) ( $installed_at ?? '' ),
				(string) ( $this->installed_at ?? '' ),
				(string) ( $prior['installed_at'] ?? '' )
			),
			'generation_token'  => $token,
			'attempt_token'     => self::first_non_empty_string(
				(string) ( $supplied['attempt_token'] ?? '' ),
				$token
			),
			'active_generation' => (int) ( $supplied['active_generation'] ?? $progress['active_generation'] ?? $this->active_generation() ),
		];
	}

	private static function first_non_empty_string( string ...$values ): string {
		foreach ( $values as $value ) {
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
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
			'dataset_version'  => '' !== $this->active_dataset_version() ? $this->active_dataset_version() : '—',
			'checksum'         => $this->active_checksum(),
			'source_url'       => $this->source_url,
			'source_file'      => $source,
			'status'           => $this->status->value,
			'usable'           => $this->has_usable_dataset(),
			'update_status'    => $this->status->value,
			'target_dataset_version' => $this->dataset_version,
			'target_checksum'  => $this->checksum,
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
