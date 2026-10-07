<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceGuard;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteOwner};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** Exact synthetic source fence, not proof of native cart/rate evidence. */
final class QuoteLifecycleProofEvidence implements QuoteCurrentEvidenceGuard {
 public function tables(OperationSession $session):array { return [$session->table_prefix().'operation_fixture_counter']; }
 public function verify(OperationSession $session,QuoteOwner $owner,QuoteContext $context):bool {
  $row=$session->get_row('SELECT id,revision,value FROM `'.$session->table_prefix().'operation_fixture_counter` WHERE id=1 FOR UPDATE');
  return is_array($row)&&'1'===(string)$row['id']&&'1'===(string)$row['revision']&&'0'===(string)$row['value']&&$owner->site_id()===$session->site_id()&&$context->material_evidence_available();
 }
}
