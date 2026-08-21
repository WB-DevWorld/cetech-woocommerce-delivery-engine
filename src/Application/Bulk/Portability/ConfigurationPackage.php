<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

/**
 * Versioned Delivery Engine configuration package. Database row IDs are never identity.
 */
final class ConfigurationPackage {

	public const FORMAT_NAME = 'cetech-de-config-package';

	public const FORMAT_VERSION = 1;

	/**
	 * @param array<string, mixed> $manifest
	 * @param array<string, mixed> $sections
	 */
	public function __construct(
		public readonly array $manifest,
		public readonly array $sections
	) {
	}

	/**
	 * @param array<string, mixed> $sections
	 */
	public static function create( string $plugin_version, string $schema_version, array $sections, bool $include_private_sources ): self {
		$counts = [];
		foreach ( $sections as $key => $value ) {
			$counts[ $key ] = is_array( $value ) ? count( $value ) : 1;
		}

		return new self(
			[
				'format_name'              => self::FORMAT_NAME,
				'format_version'           => self::FORMAT_VERSION,
				'plugin_version'           => $plugin_version,
				'source_schema_version'    => $schema_version,
				'exported_at'              => gmdate( 'c' ),
				'exported_sections'      => array_keys( $sections ),
				'counts'                   => $counts,
				'include_private_sources'  => $include_private_sources,
			],
			$sections
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'manifest' => $this->manifest,
			'sections' => $this->sections,
		];
	}

	public function to_json(): string {
		return wp_json_encode( $this->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}';
	}

	public static function from_json( string $json ): self {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['manifest'] ) || ! is_array( $decoded['manifest'] ) ) {
			throw new \InvalidArgumentException( 'Configuration package JSON is malformed.' );
		}
		$manifest = $decoded['manifest'];
		if ( ( $manifest['format_name'] ?? '' ) !== self::FORMAT_NAME ) {
			throw new \InvalidArgumentException( 'Unsupported configuration package format.' );
		}
		$version = (int) ( $manifest['format_version'] ?? 0 );
		if ( $version < 1 || $version > self::FORMAT_VERSION ) {
			throw new \InvalidArgumentException( sprintf( 'Unsupported configuration format version %d.', $version ) );
		}
		$sections = isset( $decoded['sections'] ) && is_array( $decoded['sections'] ) ? $decoded['sections'] : [];

		return new self( $manifest, $sections );
	}
}
