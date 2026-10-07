<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** No historical absence is inferred before an exact reference producer is registered. */
final class QuoteRetentionUnknownReferences implements QuoteRetentionReferenceInspector {
 public function policy_digest():string { return hash('sha256','cetech-quote-reference-unknown-v1'); }
 public function transactional_tables(OperationSession $session):array { return []; }
 public function authorize_owner(QuoteOwner $owner):bool { return false; }
 public function inspect(OperationSession $session,QuoteStoredRow $quote):string { return 'unknown'; }
}
