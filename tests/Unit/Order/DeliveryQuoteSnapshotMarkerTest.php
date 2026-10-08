<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Faithful isolated native metadata protocol. Actual Woo/HPOS proof remains native CI. */
final class DeliveryQuoteSnapshotMarkerTest extends TestCase {
	#[DataProvider( 'cases' )]
	public function test_raw_native_presence_is_bounded_and_never_invokes_metadata_magic( string $mode, string $expected ): void {
		$autoload = var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true );
		$script = <<<'CODE'
class WC_Meta_Data {
 protected $current_data; protected $data; public static int $magic_calls=0;
 function __construct(array $facts){$this->data=$this->current_data=$facts;}
 function mutate(array $facts){$this->current_data=$facts;}
 function __get($key){++self::$magic_calls; return $this->current_data[$key]??null;}
}
abstract class WC_Data {
 protected $meta_data;
 function __construct($entries){$this->meta_data=$entries;}
 function meta_exists($key){return false;}
}
class Carrier extends WC_Data {}
class UnknownMetadata extends WC_Meta_Data {}
CODE;
		$script .= "\nrequire {$autoload};\n";
		$script .= '$mode=' . var_export( $mode, true ) . '; $key="_cetech_de_delivery_quote_format"; $entry=new WC_Meta_Data(["id"=>123,"key"=>$key,"value"=>null]); $entries=[$entry];';
		$script .= <<<'CODE'
if($mode==='pending-deletion'){$entry->mutate(['id'=>123,'key'=>'renamed','value'=>null]);}
if($mode==='string-id'){$entries=[new WC_Meta_Data(['id'=>'123','key'=>$key,'value'=>null])];}
if($mode==='absent'){$entries=[new WC_Meta_Data(['id'=>123,'key'=>'foreign','value'=>'retained'])];}
if($mode==='new-null'){$entries=[new WC_Meta_Data(['key'=>$key,'value'=>null])];}
if($mode==='unknown-class'){$entries=[new UnknownMetadata(['id'=>123,'key'=>$key,'value'=>null])];}
if($mode==='unknown-shape'){$entries=[new WC_Meta_Data(['id'=>123,'key'=>$key,'value'=>null,'private'=>'hidden'])];}
if($mode==='over-bound'){$entries=array_fill(0,513,new WC_Meta_Data(['id'=>123,'key'=>'foreign','value'=>'retained']));}
if($mode==='bad-id'){$entries=[new WC_Meta_Data(['id'=>'0123','key'=>$key,'value'=>null])];}
$result=CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotMarker::exists(new Carrier($entries));
if(WC_Meta_Data::$magic_calls!==0){fwrite(STDOUT,'CALLBACK-INVOKED');exit(12);}fwrite(STDOUT,$result===null?'unknown':($result?'present':'absent'));
CODE;
		$command = [ PHP_BINARY, '-d', 'display_errors=0', '-r', $script ]; $pipes = []; $process = proc_open( $command, [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $exit = proc_close( $process );
		self::assertSame( 0, $exit, 'Isolated metadata protocol did not finish.' ); self::assertSame( $expected, $out ); self::assertSame( '', $error );
	}
	public static function cases(): iterable { foreach ( [ 'persisted-null' => 'present', 'pending-deletion' => 'present', 'string-id' => 'present', 'absent' => 'absent', 'new-null' => 'absent', 'unknown-class' => 'unknown', 'unknown-shape' => 'unknown', 'over-bound' => 'unknown', 'bad-id' => 'unknown' ] as $mode => $expected ) { yield $mode => [ $mode, $expected ]; } }
}
