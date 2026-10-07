<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Native owner checks happen outside the fresh, acknowledged C07 read owners. */
final class NativeQuotePreparationAccess implements QuotePreparationAccess {
	private EmergencyControlService $control;
	private \Closure $current_owner;
	/** Optional collaborators are trusted fixture seams; defaults use native authority. */
	public function __construct( OperationConnectionFactory $factory, ?EmergencyControlStore $store = null, ?callable $current_owner = null ) {
		$this->control = new EmergencyControlService( $factory, static fn(): bool => false, $store );
		$this->current_owner = null === $current_owner ? static fn(): QuoteOwner => ( new QuoteNativeOwnerResolver() )->current() : \Closure::fromCallable( $current_owner );
	}
	public function observe( QuoteOwner $owner ): ?int {
		try {
			if ( ! $this->matches_owner( $owner ) ) { return null; }
			$read = $this->control->read( $owner->site_id() );
			if ( ! $read->available || null === $read->state || ! $read->state->enabled() || ! $this->matches_owner( $owner ) ) { return null; }
			return $read->state->revision;
		} catch ( \Throwable ) { return null; }
	}
	public function confirm( QuoteOwner $owner, int $opened_revision ): bool {
		try {
			if ( $opened_revision < 1 || ! $this->matches_owner( $owner ) ) { return false; }
			$read = $this->control->confirm_enabled( $owner->site_id(), $opened_revision );
			return $read->available && null !== $read->state && $read->state->enabled() && $read->state->revision === $opened_revision && $this->matches_owner( $owner );
		} catch ( \Throwable ) { return false; }
	}
	private function matches_owner( QuoteOwner $owner ): bool { $current = ( $this->current_owner )(); return $current instanceof QuoteOwner && $owner->equals( $current ); }
}
