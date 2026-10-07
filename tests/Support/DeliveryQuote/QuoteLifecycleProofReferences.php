<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteRetentionReferenceInspector;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteOwner,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** Finite registered synthetic history participant; no HPOS/native order claim. */
final class QuoteLifecycleProofReferences implements QuoteRetentionReferenceInspector {
 public string $mode='exact'; public ?\Closure $after_lock=null;
 public function policy_digest():string { return hash('sha256','q03-native-synthetic-history-v1'); }
 public function transactional_tables(OperationSession $session):array { return [$session->table_prefix().'quote_fixture_history_refs']; }
 public function authorize_owner(QuoteOwner $owner):bool { return $owner->site_id()===1; }
 public function inspect(OperationSession $session,QuoteStoredRow $quote):string {
  if('unknown'===$this->mode) { return 'unknown'; }
  $table=$session->table_prefix().'quote_fixture_history_refs';
  $rows=$session->get_results($session->prepare("SELECT id,quote_uuid FROM `{$table}` WHERE site_id=%d AND quote_uuid=%s LIMIT 2 FOR UPDATE",$quote->site_id(),$quote->header()->id()->value()));
  if(null!==$this->after_lock) { ($this->after_lock)($session,$quote); }
  if(false===$rows||!array_is_list($rows)||count($rows)>1) { return 'unknown'; }
  return []===$rows?'absent':'protected';
 }
}
