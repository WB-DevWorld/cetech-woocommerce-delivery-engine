<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Persistence;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, ServicePromisePolicy};

/** Authorized private capture of one exact key; no inheritance resolution or promise calculation. */
final readonly class PromiseEffectiveAssignment implements \JsonSerializable {
	public function __construct( private string $status, private ?PromiseStoredAssignment $stored, private ?ServicePromisePolicy $selected, private array $calendar_versions, private RuleTime $at ) {
		if ( ! in_array( $status, [ 'absent', 'assigned', 'inherit', 'disabled', 'unavailable' ], true ) || ( 'absent' === $status ) !== ( null === $stored ) || ( 'assigned' === $status ) !== ( null !== $selected ) || ! array_is_list( $calendar_versions ) || count( $calendar_versions ) > 16 || ( null === $selected && [] !== $calendar_versions ) ) { throw new \InvalidArgumentException( 'Invalid exact assignment capture.' ); }
		foreach ( $calendar_versions as $calendar ) { if ( ! $calendar instanceof BusinessCalendarVersion ) { throw new \InvalidArgumentException( 'Invalid captured calendar.' ); } }
		if ( null !== $selected && $stored->reference()?->to_private_json() !== $selected->reference()->to_private_json() ) { throw new \InvalidArgumentException( 'Captured assignment is not its exact selected policy.' ); }
	}
	public function state(): string { return $this->status; }
	public function generation(): int { return $this->stored?->generation() ?? 0; }
	public function revision(): int { return $this->stored?->revision() ?? 0; }
	public function policy(): ?ServicePromisePolicy { return $this->selected; }
	public function calendars(): array { return $this->calendar_versions; }
	public function assignment(): ?PromiseStoredAssignment { return $this->stored; }
	public function captured_at(): RuleTime { return $this->at; }
	public function jsonSerialize(): never { throw new \LogicException( 'Private captures require an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private captures cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private captures require a validated owned read.' ); }
}
