<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\ServicePromise\Shipment;

use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseVersionReadService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseCalendarReference;
use CetechDeliveryEngine\Domain\ServicePromise\Port\PromiseCalendarVersionLoader;
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromisePersistenceAuthorizer, PromiseSiteBinding};

/** Only exact immutable calendar references already captured by this authorized native order. */
final class NativeShipmentPromiseCalendarLoader implements PromiseCalendarVersionLoader, PromisePersistenceAuthorizer {
	private \Closure $order_authority;
	private array $allowed = [];
	private OperationIdentity $identity;
	public function __construct( private PromiseSiteBinding $binding, private OperationConnectionFactory $connections, private \WC_Order $order, callable $authority, array $references, string $packet_digest ) {
		$this->order_authority = \Closure::fromCallable( $authority );
		foreach ( $references as $reference ) { $value = PromiseCalendarReference::from_array( $reference ); $this->allowed[$value->digest()] = true; }
		$this->identity = new OperationIdentity( $binding->site_id(), 'native-shipment-calendar', 'order:' . $order->get_id(), 'promise.versions.read', 1, 'promise-versions:' . $binding->site_key(), $packet_digest );
	}
	public function load_versions( array $references ): array {
		if ( ! array_is_list( $references ) || count( $references ) > 200 ) { throw new \RuntimeException( 'Original calendars unavailable.' ); }
		foreach ( $references as $reference ) { if ( ! $reference instanceof PromiseCalendarReference || ! isset( $this->allowed[$reference->digest()] ) ) { throw new \RuntimeException( 'Original calendars unavailable.' ); } }
		return ( new PromiseVersionReadService( $this->binding, $this->connections, $this ) )->load_calendar_versions( $this->identity, $references );
	}
	/** Read service invokes native reauthorization before its SQL owner and after retirement. */
	public function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, int $author_user_id ): bool {
		return $identity === $this->identity && $binding === $this->binding && 0 === $author_user_id && [ 'kind' => 'global', 'target_id' => 0 ] === $scope && true === ( $this->order_authority )( $this->order, 'payment_confirmed', null );
	}
	public function authorize_author( PromiseSiteBinding $binding, int $author_user_id, array $scope ): bool { return false; }
}
