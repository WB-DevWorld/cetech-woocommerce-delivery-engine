<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\ServicePromise;

use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteEnvironment;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding};

/** Request-local native grant. Invoked before source ownership and after its retirement only. */
final readonly class NativePromiseCaptureAuthorizer implements PromisePersistenceAuthorizer {
	public function __construct( private NativeCartQuoteEnvironment $environment ) {}
	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool {
		try {
			if ( 0 !== $author_user_id || 'service_promise.native_capture.v1' !== $identity->authority || 'promise.assignment.read' !== $identity->operation || 1 !== $identity->operation_version || $identity->site_id !== $binding->site_id() || 1 !== preg_match( '/\Apromise-assignment:' . preg_quote( $binding->site_key(), '/' ) . ':[a-f0-9]{64}\z/D', $identity->target_key ) || count( $scope ) !== 2 || ! array_key_exists( 'kind', $scope ) || ! array_key_exists( 'target_id', $scope ) || ! is_int( $scope['target_id'] ) ) { return false; }
			$draft = $this->environment->draft(); if ( null === $draft ) { return false; } $owner = $draft->owner();
			if ( $owner->site_id() !== $binding->site_id() || ! hash_equals( $owner->digest(), $identity->principal ) || ! $this->environment->authorize( $owner, 'delivery_quote.read' ) ) { return false; }
			if ( 'global' === $scope['kind'] ) { return 0 === $scope['target_id']; }
			if ( ! in_array( $scope['kind'], [ 'product', 'variation' ], true ) || $scope['target_id'] < 1 ) { return false; }
			$field = 'product' === $scope['kind'] ? 'product_id' : 'variation_id'; foreach ( $draft->private_facts()['lines'] as $line ) { if ( $scope['target_id'] === $line[$field] ) { return true; } } return false;
		} catch ( \Throwable ) { return false; }
	}
	/** Checkout has no policy publication or publishing-author mutation grant. */
	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return false; }
}
