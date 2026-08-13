<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * Persisted site-wide default policy (WP option; not a schema table).
 */
final class SiteWideDefaultsSettings implements SiteWideDefaultsPolicyInterface {

	public const OPTION_NAME = 'cetech_de_sitewide_defaults';

	/**
	 * @return array{
	 *     setup_completed: bool,
	 *     active_profiles: list<string>,
	 *     primary_profile: string,
	 *     applied_at: string|null
	 * }
	 */
	public function read(): array {
		$stored = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$active = [];
		foreach ( (array) ( $stored['active_profiles'] ?? [] ) as $key ) {
			$key = sanitize_key( (string) $key );
			if ( FulfilmentProfileRegistry::has( $key ) ) {
				$active[] = $key;
			}
		}
		$active = array_values( array_unique( $active ) );

		$primary = sanitize_key( (string) ( $stored['primary_profile'] ?? '' ) );
		if ( '' === $primary || ! FulfilmentProfileRegistry::has( $primary ) ) {
			$primary = $active[0] ?? '';
		}

		$applied_at = isset( $stored['applied_at'] ) ? (string) $stored['applied_at'] : '';

		return [
			'setup_completed'  => ! empty( $stored['setup_completed'] ),
			'active_profiles'  => $active,
			'primary_profile'  => $primary,
			'applied_at'       => '' !== $applied_at ? $applied_at : null,
		];
	}

	/**
	 * @param array{
	 *     setup_completed?: bool,
	 *     active_profiles?: list<string>,
	 *     primary_profile?: string,
	 *     applied_at?: string|null
	 * } $data
	 */
	public function save( array $data ): void {
		$current = $this->read();
		$merged  = array_merge( $current, $data );

		$active = [];
		foreach ( (array) ( $merged['active_profiles'] ?? [] ) as $key ) {
			$key = sanitize_key( (string) $key );
			if ( FulfilmentProfileRegistry::has( $key ) ) {
				$active[] = $key;
			}
		}
		$active = array_values( array_unique( $active ) );

		$primary = sanitize_key( (string) ( $merged['primary_profile'] ?? '' ) );
		if ( '' === $primary || ! in_array( $primary, $active, true ) ) {
			$primary = $active[0] ?? '';
		}

		update_option(
			self::OPTION_NAME,
			[
				'setup_completed' => ! empty( $merged['setup_completed'] ),
				'active_profiles' => $active,
				'primary_profile' => $primary,
				'applied_at'      => $merged['applied_at'] ?? $current['applied_at'],
			],
			false
		);
	}

	public function primary_profile_key(): ?string {
		$key = $this->read()['primary_profile'];

		return '' !== $key ? $key : null;
	}

	public function is_setup_complete(): bool {
		return $this->read()['setup_completed'];
	}

	/**
	 * @return list<string>
	 */
	public function active_profile_keys(): array {
		return $this->read()['active_profiles'];
	}

	public function is_active_profile( string $key ): bool {
		return in_array( $key, $this->active_profile_keys(), true );
	}
}
