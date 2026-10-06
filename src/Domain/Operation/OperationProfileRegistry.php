<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

/** Empty by default. Only explicit reviewed internal profile instances exist. */
final readonly class OperationProfileRegistry {
	private array $registered;

	/** @param list<OperationProfile> $profiles */
	public function __construct( array $profiles = [] ) {
		if ( ! array_is_list( $profiles ) || count( $profiles ) > 64 ) {
			throw new \InvalidArgumentException( 'Invalid operation profile registry.' );
		}
		$registered = [];
		foreach ( $profiles as $profile ) {
			if ( ! $profile instanceof OperationProfile || 1 !== preg_match( '/\A[a-z][a-z0-9_.-]{0,95}\z/D', $profile->operation() ) || $profile->version() < 1 ) {
				throw new \InvalidArgumentException( 'Invalid operation profile.' );
			}
			$key = $profile->operation() . '@' . $profile->version();
			if ( isset( $registered[ $key ] ) ) {
				throw new \InvalidArgumentException( 'Duplicate operation profile.' );
			}
			$profile->result_schema();
			$publication = $profile->publication_schema();
			if ( $profile->actor_schema()->is_empty() || $profile->target_schema()->is_empty() || ( null !== $publication && $publication->is_empty() ) ) {
				throw new \InvalidArgumentException( 'Operation profile requires explicit identity facts.' );
			}
			OperationSchema::vocabulary( $profile->reason_codes() );
			OperationSchema::vocabulary( $profile->changed_fields() );
			$registered[ $key ] = $profile;
		}
		$this->registered = $registered;
	}

	public function get( string $operation, int $version ): OperationProfile {
		if ( $version < 1 || strlen( $operation ) > 96 || 1 !== preg_match( '/\A[a-z][a-z0-9_.-]{0,95}\z/D', $operation ) ) {
			throw new \InvalidArgumentException( 'Invalid operation profile identity.' );
		}
		$key = $operation . '@' . $version;
		if ( ! isset( $this->registered[ $key ] ) ) {
			throw new \InvalidArgumentException( 'Operation profile is not registered.' );
		}
		$profile = $this->registered[ $key ];
		if ( $operation !== $profile->operation() || $version !== $profile->version() ) {
			throw new \InvalidArgumentException( 'Operation profile identity changed.' );
		}
		return $profile;
	}

	/** @return list<OperationProfile> */
	public function profiles(): array {
		return array_values( $this->registered );
	}
}
