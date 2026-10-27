<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\CustomerPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual pinned native item/datastore hydration over explicitly declared memory services. */
final class PolicyPinnedDeletedProductReadTest extends TestCase{
 #[DataProvider('cases')]
 public function test_native_read_can_drop_catalog_ids_while_saved_metadata_is_intact(string$mode,array$before,array$after,array$errors):void{
  $r=$this->probe($mode);self::assertFalse($r['physical_qualification']);self::assertTrue($r['native_product_and_item_classes']);self::assertTrue($r['native_datastore_read']);self::assertSame('declared_memory',$r['metadata_post_cache_crud_ports']);self::assertSame($before,$r['before']);self::assertSame($after,$r['after']);self::assertSame($after,$r['raw_native_ids']);self::assertSame([],$r['native_changes']);self::assertTrue($r['object_read']);self::assertTrue($r['raw_metadata_preserved']);self::assertSame(['33',$mode==='simple-deleted'?'0':'34'],$r['raw_ids']);self::assertSame($mode==='simple-deleted'?2:4,$r['post_reads']);self::assertSame(0,$r['writes']);self::assertSame($errors,$r['caught_setter_codes']);self::assertSame([true,true],$r['history_coordinates']);self::assertSame(0,$r['history_extra_post_reads']);self::assertSame(0,$r['history_extra_writes']);self::assertTrue($r['history_raw_unchanged']);self::assertTrue($r['history_changes_unchanged']);
 }
 public static function cases():array{
  $p='order_item_product_invalid_product_id';$v='order_item_product_invalid_variation_id';return[
   'untouched'=>['untouched',[33,34],[33,34],[]],
   'simple-deleted'=>['simple-deleted',[33,0],[0,0],[$p]],
   'parent-deleted'=>['parent-deleted',[33,34],[0,34],[$p]],
   'variation-deleted'=>['variation-deleted',[33,34],[33,0],[$v]],
   'both-deleted'=>['both-deleted',[33,34],[0,0],[$p,$v]],
   'wrong-parent-type'=>['wrong-parent-type',[33,34],[0,34],[$p]],
   'wrong-variation-type'=>['wrong-variation-type',[33,34],[33,0],[$v]],
  ];
 }
 public function test_genuine_native_sources_match_existing_installed_woocommerce_11_1_2_bytes():void{
  $r=$this->probe('untouched');$base=dirname(__DIR__,2).'/Support/CustomerPolicy/native-woocommerce/';$expected=[
   'includes/class-wc-data-exception.php'=>'136f8715992a46bef76a5f6e2bc93fc0517d0ed5ae992a828e4735313dae18fb',
   'includes/class-wc-order-item.php'=>'f629c793e5a46c1c7c16d0e16fc02087eacdf784444475a385f9a766c7ba35da',
   'includes/class-wc-order-item-product.php'=>'ff8f400d950c6211edf289a493d3bc07dbf66504e782ed4a471b2f43f15316a4',
   'includes/interfaces/class-wc-order-item-product-data-store-interface.php'=>'7e68eccf46a891208e41bd7a0191b0aa0d60fa68d5ca71c0016bb2f37810b768',
   'includes/interfaces/class-wc-order-item-type-data-store-interface.php'=>'0a2ef7e69b6036d11cc7e1c2abfceba4f1e8d2387a453f8017f6314718947b7c',
   'includes/data-stores/abstract-wc-order-item-type-data-store.php'=>'5b3a5ab657cd07652354458fa7ebecc5ffd858360ffdeb5b795aec576f0ac7e4',
   'includes/data-stores/class-wc-order-item-product-data-store.php'=>'ffe84111096169ab1fddfd34a972cdfaae6b402b2a465eb89fee51aa165bd34e',
   'src/Utilities/NumberUtil.php'=>'238df72809f8bb8d05cc4f0e68b2b6267e4df529ada2608d1deb34f833806024',
  ];self::assertCount(13,$r['pinned_sources']);foreach($expected as$file=>$hash){self::assertSame($hash,$r['pinned_sources'][$file]);self::assertSame($hash,hash_file('sha256',$base.$file));}
 }
 private function probe(string$mode):array{
  $process=proc_open([PHP_BINARY,dirname(__DIR__,2).'/Support/CustomerPolicy/policy-pinned-deleted-product-read-probe.php',$mode],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);self::assertIsResource($process);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$stderr.' '.$stdout);return json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
 }
}
