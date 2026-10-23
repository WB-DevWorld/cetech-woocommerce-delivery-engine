<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fresh isolated native-class models prove reset scope, not a physical Woo installation. */
final class NativePromotionShippingResetTest extends TestCase {
	public function test_admitted_promotion_calculation_clears_only_current_package_packets_before_native_totals(): void {
		$out = $this->probe( 'valid' );
		self::assertTrue( $out['available'] ); self::assertSame( [ 'shipping_for_package_0', 'shipping_for_package_2' ], $out['cleared'] );
		self::assertSame( 1, $out['totals'] ); self::assertSame( 1, $out['saves'] ); self::assertTrue( $out['cache_empty_at_totals'] );
		self::assertSame( 'private-unrelated-value', $out['other'] ); self::assertSame( 'unrelated-package', $out['unrelated_package'] );
		self::assertSame( [ 'retained-choice' ], $out['chosen'] ); self::assertSame( [ 'shipping_total' => '12.50' ], $out['cart_totals'] );
	}

	#[DataProvider( 'invalid_layouts' )]
	public function test_unbounded_native_package_layout_is_refused_before_any_packet_mutation( string $mode ): void {
		$out = $this->probe( $mode ); self::assertFalse( $out['available'] ); self::assertSame( [], $out['cleared'] );
		self::assertSame( 0, $out['totals'] ); self::assertSame( 0, $out['saves'] );
	}

	public static function invalid_layouts(): iterable {
		foreach ( [ 'empty', 'overflow', 'negative', 'string_key', 'high_index', 'non_package' ] as $mode ) { yield $mode => [ $mode ]; }
	}

	public function test_a_native_calculation_failure_does_not_publish_a_session_packet(): void {
		$out = $this->probe( 'native_failure' ); self::assertFalse( $out['available'] ); self::assertSame( 1, $out['totals'] ); self::assertSame( 0, $out['saves'] ); self::assertTrue( $out['cache_empty_at_totals'] );
	}

	private function probe( string $mode ): array {
		$program = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';
class Calls { public static array $cleared=[]; public static int $totals=0,$saves=0; public static bool $empty=false; }
class WC_Cart {
 public array $cart_contents=['line_one'=>['product_id'=>10,'quantity'=>2]];
 public function get_shipping_packages():array {
  $p=['contents'=>$this->cart_contents,'destination'=>['country'=>'GH']];
  return match($GLOBALS['mode']) { 'empty'=>[], 'overflow'=>array_fill(0,201,$p), 'negative'=>[-1=>$p], 'string_key'=>['foreign'=>$p], 'high_index'=>[200=>$p], 'non_package'=>[0=>null], default=>[0=>$p,2=>$p] };
 }
 public function calculate_totals():void { ++Calls::$totals; $data=$GLOBALS['woocommerce']->session->data(); Calls::$empty=!isset($data['shipping_for_package_0'])&&!isset($data['shipping_for_package_2']); if('native_failure'===$GLOBALS['mode']){throw new RuntimeException('Native calculation failure.');} }
}
class WC_Session_Handler {
 protected array $_data=['shipping_for_package_0'=>'old-base','shipping_for_package_2'=>'old-promoted','shipping_for_package_199'=>'unrelated-package','other'=>'private-unrelated-value','chosen_shipping_methods'=>['retained-choice'],'cart_totals'=>['shipping_total'=>'12.50']];
 public function __unset(string $key):void { Calls::$cleared[]=$key; unset($this->_data[$key]); }
 public function save_data():void { ++Calls::$saves; }
 public function data():array { return $this->_data; }
}
$GLOBALS['mode']=$argv[2]; $session=new WC_Session_Handler(); $GLOBALS['woocommerce']=(object)['cart'=>new WC_Cart(),'session'=>$session]; $available=false;
try { CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuoteShipping::prepare_promotion_after_admission(); $available=true; } catch(Throwable){}
$data=$session->data(); echo json_encode(['available'=>$available,'cleared'=>Calls::$cleared,'totals'=>Calls::$totals,'saves'=>Calls::$saves,'cache_empty_at_totals'=>Calls::$empty,'other'=>$data['other'],'unrelated_package'=>$data['shipping_for_package_199'],'chosen'=>$data['chosen_shipping_methods'],'cart_totals'=>$data['cart_totals']],JSON_THROW_ON_ERROR);
PHP;
		$process = proc_open( [ PHP_BINARY, '-r', $program, dirname( __DIR__, 3 ), $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
