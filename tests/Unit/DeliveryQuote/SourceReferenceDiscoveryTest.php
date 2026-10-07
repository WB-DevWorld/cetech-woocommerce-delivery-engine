<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\TestCase;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteCapturedSourceView;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteNativeSourcePreparer;
use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSnapshot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';
require_once __DIR__ . '/../../Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures as F;

/** Query-shape/plan proof only; no native WordPress or database execution claim. */
final class SourceReferenceDiscoveryTest extends TestCase {
	public function test_seed_captures_retained_global_references_before_member_resolution(): void {
		self::assertSame( 'PASS', $this->probe( 'global' ) );
	}
	public function test_exact_two_hundred_offer_references_are_bounded_but_complete(): void { self::assertSame( 'PASS', $this->probe( 'offers_200' ) ); }
	public function test_derived_capture_retains_references_and_a_new_unfenced_reference_refuses(): void {
		$rules = [ [ 'delivery_offer_ids' => '[20]', 'origin_id' => null, 'supplier_id' => null, 'logistics_profile_id' => null ] ]; $fields = [ [ 'field_key' => 'origin_id', 'mode' => 'override', 'value_type' => 'int', 'value_text' => '100' ] ]; $collections = [ [ 'field_key' => 'delivery_offer_ids', 'mode' => 'replace', 'members_json' => '[1]' ] ];
		[ $offers, $dimensions ] = ( new \ReflectionMethod( LegacyQuoteNativeSourcePreparer::class, 'reference_sources' ) )->invoke( null, $rules, $fields, $collections, [ 20 => 20 ], [ 'origins' => [ 40 => 40 ], 'suppliers' => [], 'profiles' => [] ] );
		self::assertEqualsCanonicalizing( [ 1, 20 ], array_values( $offers ) ); self::assertEqualsCanonicalizing( [ 40, 100 ], array_values( $dimensions['origins'] ) );
		$plan = F::plan(); $rows = [ 'offers' => [ [ 'id' => '1' ], [ 'id' => '20' ] ], 'legacy_rules' => $rules, 'scope_fields' => [], 'scope_collections' => $collections ]; $snapshot = LegacyQuoteSourceSnapshot::captured( $plan, F::context(), $rows, [], QuoteTime::parse( '2026-10-07 05:00:00.000000' ) );
		self::assertSame( [ 'id' => '1' ], ( new LegacyQuoteCapturedSourceView( $snapshot ) )->offers()->findById( 1 ) );
		$rows['scope_collections'][0]['members_json'] = '[1,21]'; $changed = LegacyQuoteSourceSnapshot::captured( $plan, F::context(), $rows, [], QuoteTime::parse( '2026-10-07 05:00:00.000000' ) ); self::assertFalse( $snapshot->matches( $changed ) );
		$this->expectException( \RuntimeException::class ); ( new LegacyQuoteCapturedSourceView( $changed ) )->offers();
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused' )]
	public function test_discovery_refuses_bounded_overflow_before_source_capture( string $kind ): void {
		self::assertSame( 'PASS', $this->probe( $kind ) );
	}
	public static function refused(): array { return [ [ 'offers_201' ], [ 'rows_1001' ], [ 'text_overflow' ] ]; }
	private function probe( string $kind ): string {
		$root = dirname( __DIR__, 3 );
		$code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/tests/Support/DeliveryQuote/QuoteFixtures.php';
require $argv[1].'/tests/Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';
class wpdb {
 public string $prefix='proof_';public string $term_relationships='proof_term_relationships';public string $term_taxonomy='proof_term_taxonomy';public string $last_error='';public array $queries=[];
 public function prepare(string $sql,mixed ...$values):string {foreach($values as $value){$sql=preg_replace('/%[ds]/',is_int($value)?(string)$value:"'".str_replace("'","''",$value)."'",$sql,1);}return $sql;}
 public function get_results(string $sql,mixed $mode=null):array {
  $this->queries[]=$sql;
  if(str_contains($sql,'term_relationships')){return [];}
  if(str_contains($sql,'configuration_scopes')){return [['id'=>'2','source_oversized'=>'0']];}
  if(str_contains($sql,'product_delivery_rules')){return [['delivery_offer_ids'=>'[20]','origin_id'=>null,'supplier_id'=>null,'logistics_profile_id'=>null,'source_oversized'=>'0']];}
  if(str_contains($sql,'configuration_fields')){return [['field_key'=>'origin_id','mode'=>'override','value_type'=>'int','value_text'=>'100','source_oversized'=>'0'],['field_key'=>'supplier_id','mode'=>'override','value_type'=>'int','value_text'=>'101','source_oversized'=>'0'],['field_key'=>'logistics_profile_id','mode'=>'override','value_type'=>'int','value_text'=>'102','source_oversized'=>'0']];}
  if(str_contains($sql,'configuration_collections')){
   $row=['field_key'=>'delivery_offer_ids','mode'=>'replace','members_json'=>in_array($GLOBALS['kind'],['offers_200','offers_201'],true)?json_encode(range(1,$GLOBALS['kind']==='offers_200'?200:201)):'[1]','source_oversized'=>$GLOBALS['kind']==='text_overflow'?'1':'0'];
   return $GLOBALS['kind']==='rows_1001'?array_fill(0,1001,$row):[$row];
  }
  throw new RuntimeException('Unexpected discovery query.');
 }
}
if(!defined('ARRAY_A')){define('ARRAY_A','ARRAY_A');}
$GLOBALS['wpdb']=new wpdb();$GLOBALS['kind']=$argv[2];
$factory=new class implements CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory {public function open():CetechDeliveryEngine\Domain\Operation\OperationSession{throw new LogicException('Discovery must not open or mutate an owned source unit.');}};
$preparer=new CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteNativeSourcePreparer($factory);
try{
 $plan=(new ReflectionMethod($preparer,'seed_plan'))->invoke($preparer,CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures::owner(),CetechDeliveryEngine\Tests\Support\DeliveryQuote\LegacyQuoteProviderFixtures::context());
 if(!in_array($argv[2],['global','offers_200'],true)){echo 'OVERFLOW-NOT-REFUSED';exit;}
 $selectors=[];foreach($plan->fences() as $fence){$selectors[$fence['source']]=$fence;}
 if($selectors['offers']['ids']!==($argv[2]==='offers_200'?range(1,200):[1,20])||$selectors['origins']['ids']!==[40,100]||$selectors['suppliers']['ids']!==[101]||$selectors['profiles']['ids']!==[102]){echo 'REFERENCES-MISSING';exit;}
 foreach($GLOBALS['wpdb']->queries as $query){if(!str_starts_with($query,'SELECT ')||!str_contains($query,'LIMIT ')){echo 'DISCOVERY-NOT-BOUNDED-READ';exit;}}
 echo 'PASS';
}catch(RuntimeException){echo in_array($argv[2],['global','offers_200'],true)?'GLOBAL-REFUSED':'PASS';}
PHP;
		$pipes = []; $process = proc_open( [ PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', $code, $root, $kind ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		self::assertSame( 0, $exit, 'Isolated reference discovery failed; stderr_present=' . ( '' === $err ? 'false' : 'true' ) ); return $out;
	}
}
