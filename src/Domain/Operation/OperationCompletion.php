<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Immutable version-1 durable facts; replay errors use the current attempt IDs. */
final readonly class OperationCompletion {
	public const FORMAT = 1;

	private function __construct(
		public string $state,
		public ?array $result,
		public ?array $publication,
		private ?array $safe_error,
		private string $encoded
	) {}

	public static function accepted( OperationProfile $profile, array $result, ?array $publication = null ): self {
		$schema = $profile->result_schema();
		$result = $schema->validate( $result );
		$publication_schema = $profile->publication_schema();
		if ( ( null === $publication_schema ) !== ( null === $publication ) || ( null !== $publication_schema && $publication_schema->is_empty() ) ) {
			throw new \InvalidArgumentException( 'Publication descriptor does not match operation profile.' );
		}
		$publication = null === $publication ? null : $publication_schema->validate( $publication );
		return self::make( 'accepted', $result, $publication, null, $schema, $publication_schema );
	}

	public static function not_applicable( OperationProfile $profile, array $result ): self {
		return self::make( 'not_applicable', $profile->result_schema()->validate( $result ), null, null, $profile->result_schema(), null );
	}

	public static function rejected( ContractError $error ): self {
		if ( 'rejected' !== $error->completion_outcome ) {
			throw new \InvalidArgumentException( 'Stored rejection requires known no-effect truth.' );
		}
		$safe = [ 'code' => $error->code, 'recovery_action' => $error->recovery_action, 'parameters' => $error->parameters, 'field_violations' => $error->field_violations ];
		return self::make( 'rejected', null, null, $safe, null, null );
	}

	public static function from_json( string $json, OperationProfile $profile ): self {
		$data = OperationJson::exact_fields( OperationJson::decode( $json ), [ 'format', 'outcome', 'result', 'error', 'publication' ] );
		if ( self::FORMAT !== $data['format'] || ! in_array( $data['outcome'], [ 'accepted', 'rejected', 'not_applicable' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported operation completion format or state.' );
		}
		if ( 'rejected' === $data['outcome'] ) {
			if ( null !== $data['result'] || null !== $data['publication'] || ! $data['error'] instanceof \stdClass ) {
				throw new \InvalidArgumentException( 'Invalid rejected operation completion.' );
			}
			$error = OperationJson::exact_fields( $data['error'], [ 'code', 'recovery_action', 'parameters', 'field_violations' ] );
			if ( ! is_string( $error['code'] ) || ! is_string( $error['recovery_action'] ) || ! $error['parameters'] instanceof \stdClass || ! is_array( $error['field_violations'] ) ) {
				throw new \InvalidArgumentException( 'Invalid stored safe error.' );
			}
			$violations = [];
			foreach ( $error['field_violations'] as $violation ) {
				if ( ! $violation instanceof \stdClass ) {
					throw new \InvalidArgumentException( 'Invalid stored safe field violation.' );
				}
				$violations[] = OperationJson::exact_fields( $violation, [ 'field', 'code' ] );
			}
			return self::rejected( new ContractError( $error['code'], RequestContext::create(), $error['recovery_action'], get_object_vars( $error['parameters'] ), $violations ) );
		}
		if ( null !== $data['error'] ) {
			throw new \InvalidArgumentException( 'Accepted completion cannot contain an error.' );
		}
		$result = $profile->result_schema()->from_json_value( $data['result'] );
		if ( 'not_applicable' === $data['outcome'] ) {
			if ( null !== $data['publication'] ) {
				throw new \InvalidArgumentException( 'No-change completion cannot publish.' );
			}
			return self::not_applicable( $profile, $result );
		}
		$publication = null;
		if ( null !== $data['publication'] ) {
			$schema = $profile->publication_schema();
			if ( null === $schema ) {
				throw new \InvalidArgumentException( 'Unexpected operation publication.' );
			}
			$publication = $schema->from_json_value( $data['publication'] );
		}
		return self::accepted( $profile, $result, $publication );
	}

	public function to_json(): string {
		return $this->encoded;
	}

	public function error( RequestContext $context ): ?ContractError {
		return null === $this->safe_error ? null : new ContractError( $this->safe_error['code'], $context, $this->safe_error['recovery_action'], $this->safe_error['parameters'], $this->safe_error['field_violations'] );
	}

	public function outcome( RequestContext $context, string $publication_state ): OperationOutcome {
		if ( 'accepted' === $this->state ) {
			if ( null === $this->publication && 'none' === $publication_state ) {
				return OperationOutcome::accepted();
			}
			if ( null !== $this->publication && 'published' === $publication_state ) {
				return OperationOutcome::accepted();
			}
			if ( null !== $this->publication && 'pending' === $publication_state ) {
				return OperationOutcome::awaiting_publication( new ContractError( 'outcome_unknown', $context, 'reconcile_original_request' ) );
			}
		} elseif ( 'none' === $publication_state ) {
			return 'rejected' === $this->state ? OperationOutcome::rejected( $this->error( $context ) ) : OperationOutcome::not_applicable();
		}
		throw new \InvalidArgumentException( 'Invalid operation publication state.' );
	}

	private static function make( string $state, ?array $result, ?array $publication, ?array $error, ?OperationSchema $result_schema, ?OperationSchema $publication_schema ): self {
		$error_json = null;
		if ( null !== $error ) {
			$error_json = (object) [ 'code' => $error['code'], 'recovery_action' => $error['recovery_action'], 'parameters' => (object) $error['parameters'], 'field_violations' => array_map( static fn( array $violation ): \stdClass => (object) $violation, $error['field_violations'] ) ];
		}
		$encoded = OperationJson::encode( (object) [
			'format' => self::FORMAT, 'outcome' => $state,
			'result' => null === $result ? null : $result_schema->json_value( $result ),
			'error' => $error_json,
			'publication' => null === $publication ? null : $publication_schema->json_value( $publication ),
		] );
		return new self( $state, $result, $publication, $error, $encoded );
	}
}
