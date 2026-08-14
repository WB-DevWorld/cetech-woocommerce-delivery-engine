<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration;

use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;

/**
 * First-time setup wizard progress. Separate from the applied site-wide policy.
 *
 * Does not enable customer-facing runtime by itself.
 */
final class SetupWizardProgress {

	public const OPTION_NAME = 'cetech_de_setup_wizard';

	public const STATUS_NOT_STARTED     = 'not_started';
	public const STATUS_IN_PROGRESS     = 'in_progress';
	public const STATUS_READY_TO_APPLY  = 'ready_to_apply';
	public const STATUS_COMPLETE        = 'complete';

	public function __construct(
		private readonly SiteWideDefaultsSettings $settings
	) {
	}

	/**
	 * @return array{
	 *     status: string,
	 *     step: int,
	 *     profile_index: int,
	 *     review_mode: bool,
	 *     saved_at: string|null,
	 *     draft: array<string, mixed>
	 * }
	 */
	public function read(): array {
		$stored = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$status = sanitize_key( (string) ( $stored['status'] ?? self::STATUS_NOT_STARTED ) );
		if ( ! in_array( $status, $this->statuses(), true ) ) {
			$status = self::STATUS_NOT_STARTED;
		}

		if ( $this->settings->is_setup_complete() && self::STATUS_COMPLETE !== $status && empty( $stored['review_mode'] ) ) {
			$status = self::STATUS_COMPLETE;
		}

		$step = isset( $stored['step'] ) ? absint( $stored['step'] ) : 1;
		$step = min( 6, max( 1, $step ) );

		$profile_index = isset( $stored['profile_index'] ) ? absint( $stored['profile_index'] ) : 0;

		$draft = isset( $stored['draft'] ) && is_array( $stored['draft'] ) ? $stored['draft'] : [];

		return [
			'status'         => $status,
			'step'           => $step,
			'profile_index'  => $profile_index,
			'review_mode'    => ! empty( $stored['review_mode'] ),
			'saved_at'       => isset( $stored['saved_at'] ) && is_string( $stored['saved_at'] ) && '' !== $stored['saved_at']
				? $stored['saved_at']
				: null,
			'draft'          => $this->normalize_draft( $draft ),
		];
	}

	/**
	 * @param array{
	 *     status?: string,
	 *     step?: int,
	 *     profile_index?: int,
	 *     review_mode?: bool,
	 *     draft?: array<string, mixed>
	 * } $data
	 */
	public function save( array $data ): void {
		$current = $this->read();
		$merged  = array_merge( $current, $data );

		$status = sanitize_key( (string) ( $merged['status'] ?? self::STATUS_NOT_STARTED ) );
		if ( ! in_array( $status, $this->statuses(), true ) ) {
			$status = self::STATUS_NOT_STARTED;
		}

		$step = min( 6, max( 1, absint( $merged['step'] ?? 1 ) ) );
		$profile_index = max( 0, absint( $merged['profile_index'] ?? 0 ) );
		$draft = $this->normalize_draft( is_array( $merged['draft'] ?? null ) ? $merged['draft'] : [] );

		update_option(
			self::OPTION_NAME,
			[
				'status'        => $status,
				'step'          => $step,
				'profile_index' => $profile_index,
				'review_mode'   => ! empty( $merged['review_mode'] ),
				'saved_at'      => gmdate( 'c' ),
				'draft'         => $draft,
			],
			false
		);
	}

	public function mark_complete(): void {
		$current = $this->read();
		$this->save(
			[
				'status'       => self::STATUS_COMPLETE,
				'step'         => 6,
				'review_mode'  => false,
				'draft'        => $current['draft'],
			]
		);
	}

	public function begin_review(): void {
		$state   = $this->settings->read();
		$current = $this->read();
		$draft   = $current['draft'];
		$draft['primary_profile'] = (string) $state['primary_profile'];
		$draft['active_profiles'] = $state['active_profiles'];

		$this->save(
			[
				'status'        => self::STATUS_COMPLETE,
				'step'          => 1,
				'profile_index' => 0,
				'review_mode'   => true,
				'draft'         => $draft,
			]
		);
	}

	public function is_incomplete(): bool {
		$status = $this->read()['status'];

		return in_array(
			$status,
			[ self::STATUS_NOT_STARTED, self::STATUS_IN_PROGRESS, self::STATUS_READY_TO_APPLY ],
			true
		) && ! $this->settings->is_setup_complete() && ! $this->has_prior_operational_install();
	}

	public function should_open_on_entry(): bool {
		if ( $this->read()['review_mode'] ) {
			return true;
		}

		return $this->is_incomplete();
	}

	/**
	 * Existing RC.2 (or later) stores that already run Delivery Engine must not
	 * be treated as a brand-new first-time setup.
	 *
	 * Does not mark Stage 13 setup complete and does not apply Site-wide Defaults.
	 */
	public function has_prior_operational_install(): bool {
		if ( $this->settings->is_setup_complete() ) {
			return true;
		}

		foreach ( $this->prior_install_flag_options() as $option ) {
			if ( 1 === (int) get_option( $option, 0 ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fresh first-time install vs existing RC.2+ store needing Stage 13 migration/review.
	 */
	public function is_fresh_install(): bool {
		return ! $this->has_prior_operational_install() && ! $this->settings->is_setup_complete();
	}

	/**
	 * @return list<string>
	 */
	private function prior_install_flag_options(): array {
		$flags = ClassicCheckoutRuntimeActivation::CHAIN;
		$out   = [];
		foreach ( $flags as $flag ) {
			if ( 'enable_classic_checkout_adapter' === $flag ) {
				continue;
			}
			$out[] = \CetechDeliveryEngine\Bootstrap\FeatureFlags::OPTION_PREFIX . $flag;
		}

		return $out;
	}

	public function status_label( string $status = '' ): string {
		$status = '' !== $status ? $status : $this->read()['status'];

		return match ( $status ) {
			self::STATUS_IN_PROGRESS => 'In progress',
			self::STATUS_READY_TO_APPLY => 'Ready to apply',
			self::STATUS_COMPLETE => 'Setup complete',
			default => 'Not started',
		};
	}

	/**
	 * @return list<string>
	 */
	private function statuses(): array {
		return [
			self::STATUS_NOT_STARTED,
			self::STATUS_IN_PROGRESS,
			self::STATUS_READY_TO_APPLY,
			self::STATUS_COMPLETE,
		];
	}

	/**
	 * @param array<string, mixed> $draft
	 *
	 * @return array{
	 *     primary_profile: string,
	 *     active_profiles: list<string>,
	 *     pickup_location_ids: array<string, int>
	 * }
	 */
	private function normalize_draft( array $draft ): array {
		$active = [];
		foreach ( (array) ( $draft['active_profiles'] ?? [] ) as $key ) {
			$key = sanitize_key( (string) $key );
			if ( FulfilmentProfileRegistry::has( $key ) ) {
				$active[] = $key;
			}
		}
		$active = array_values( array_unique( $active ) );

		$primary = sanitize_key( (string) ( $draft['primary_profile'] ?? '' ) );
		if ( '' !== $primary && ! FulfilmentProfileRegistry::has( $primary ) ) {
			$primary = '';
		}

		$pickup = [];
		if ( isset( $draft['pickup_location_ids'] ) && is_array( $draft['pickup_location_ids'] ) ) {
			foreach ( $draft['pickup_location_ids'] as $profile_key => $location_id ) {
				$profile_key = sanitize_key( (string) $profile_key );
				if ( FulfilmentProfileRegistry::has( $profile_key ) ) {
					$pickup[ $profile_key ] = absint( $location_id );
				}
			}
		}

		return [
			'primary_profile'     => $primary,
			'active_profiles'     => $active,
			'pickup_location_ids' => $pickup,
		];
	}
}
