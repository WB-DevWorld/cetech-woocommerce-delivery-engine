<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Private trusted final-placement fence. No getter, callback or provider runs here. */
final readonly class QuotePlacementProof implements \JsonSerializable {
	private function __construct( private QuoteBinding $verified, private int $revision, private EmergencyCheckoutLocalBinding $local, private QuotePlacementSavedEvidenceGuard $saved ) {}
	public static function capture( QuoteBinding $verified, int $control_revision, EmergencyCheckoutLocalBinding $local, QuotePlacementSavedEvidenceGuard $saved ): self {
		if ( 'prepared' !== $verified->state() || 2 !== $verified->revision() || $control_revision < 1 || ! $local->unchanged() ) { throw new \InvalidArgumentException( 'Verified placement evidence is required.' ); }
		return new self( $verified, $control_revision, $local, $saved );
	}
	public function control_revision(): int { return $this->revision; }
	public function tables( OperationSession $session ): array { return $this->saved->tables( $session ); }
	public function matches( QuoteBinding $binding ): bool {
		$a = $this->verified->row(); $b = $binding->row();
		foreach ( array_diff( QuoteBinding::FIELDS, [ 'state', 'revision', 'sealed_at' ] ) as $field ) { if ( $a[$field] !== $b[$field] ) { return false; } }
		return ( 'prepared' === $binding->state() && 2 === $binding->revision() ) || ( 'sealed' === $binding->state() && 3 === $binding->revision() );
	}
	public function verify( OperationSession $session, QuoteBinding $binding, int $control_revision ): bool {
		return $session->in_transaction() && ! $session->is_retired() && $session->site_id() === $binding->site_id() && $this->revision === $control_revision && $this->verify_saved( $session, $binding );
	}
	/** Receipt replay protects saved bytes without retroactively withdrawing admission. */
	public function verify_saved( OperationSession $session, QuoteBinding $binding ): bool { return $this->matches( $binding ) && $this->unchanged() && $this->saved->verify( $session, $binding ) && $this->unchanged(); }
	public function unchanged(): bool { return $this->local->unchanged(); }
	public function jsonSerialize(): never { throw new \LogicException( 'Placement proof is private.' ); }
	public function __serialize(): array { throw new \LogicException( 'Placement proof is private.' ); }
}
