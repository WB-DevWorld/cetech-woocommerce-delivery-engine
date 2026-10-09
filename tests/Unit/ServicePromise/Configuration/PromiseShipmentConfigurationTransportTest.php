<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot};
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Presentation\Admin\{PromiseShipmentConfigurationSection, PromiseShipmentLegacyEtaGuard};
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromiseShipmentConfigurationTransportTest extends TestCase {

	use RestoresWordPressFixtureGlobals;
	protected function setUp(): void { $this->remember_fixture_globals(); }
	protected function tearDown(): void { $this->restore_fixture_globals(); }

	#[DataProvider( 'legacy_declarations' )]
	public function test_native_order_and_line_declarations_keep_legacy_editor_out_of_required_promise_history( array $meta, bool $allows ): void {
		self::assertSame( $allows, PromiseShipmentLegacyEtaGuard::allows_order( new \WC_Order( [ 'id' => 40, 'meta' => $meta ] ) ) );
		$line_meta = $meta;
		foreach ( [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => OrderDeliverySnapshot::META_LINE_SNAPSHOT, OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ] as $from => $to ) {
			if ( array_key_exists( $from, $line_meta ) ) { $line_meta[$to] = $line_meta[$from]; unset( $line_meta[$from] ); }
		}
		self::assertSame( $allows, PromiseShipmentLegacyEtaGuard::allows_order( new \WC_Order( [ 'id' => 40, 'items' => [ new \WC_Order_Item_Product( [ 'id' => 41, 'meta' => $line_meta ] ) ] ] ) ) );
	}
	public static function legacy_declarations(): array {
		return [
			'missing established legacy' => [ [], true ],
			'legacy one' => [ [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '1', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"snapshot_version":"1"}' ], true ],
			'legacy two' => [ [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '2', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '1', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"snapshot_version":"2","delivery_quote":{"format":1}}' ], true ],
			'malformed non-required legacy' => [ [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '1', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => 'malformed legacy bytes' ], true ],
			'required outer metadata with missing packet' => [ [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '3' ], false ],
			'required marker with malformed packet' => [ [ DeliveryQuoteSnapshotEnvelope::META_FORMAT => '2', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => 'malformed required bytes' ], false ],
			'required marker null' => [ [ DeliveryQuoteSnapshotEnvelope::META_FORMAT => null ], false ],
			'future required marker' => [ [ DeliveryQuoteSnapshotEnvelope::META_FORMAT => '3' ], false ],
			'required raw outer declaration' => [ [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"snapshot_version":"3"}' ], false ],
			'required raw inner declaration' => [ [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => '{"snapshot_version":"2","delivery_quote":{"format":2}}' ], false ],
			'malformed required array declaration' => [ [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => [ 'snapshot_version' => '3' ] ], false ],
		];
	}
	public function test_missing_order_cannot_grant_legacy_text_edit_and_exact_real_legacy_order_can(): void {
		$shipment = Shipment::create( 40, 'g-1', id: 3 ); $GLOBALS['cetech_de_test_wc_orders'] = [];
		self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_text_edit( $shipment ) );
		$GLOBALS['cetech_de_test_wc_orders'][40] = new \WC_Order( [ 'id' => 40 ] ); self::assertTrue( PromiseShipmentLegacyEtaGuard::allows_text_edit( $shipment ) );
		$GLOBALS['cetech_de_test_wc_orders'][40] = new \WC_Order( [ 'id' => 41 ] ); self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_text_edit( $shipment ) );
	}
	public function test_original_command_scalar_transport_preserves_envelope_token_revision_and_reason(): void {
		$input = [ 'shipment_id' => '40', 'expected_revision' => '2', 'request_token' => '5e71ce00-0000-4000-8000-000000000001', 'original_envelope' => 'signed-original-envelope', 'state' => 'unavailable', 'reason' => 'Exact original reason' ];
		$output = PromiseShipmentConfigurationSection::parse_form( $input ); foreach ( $input as $key => $value ) { self::assertSame( $value, $output[$key] ); }
	}
	#[DataProvider( 'forged_transport' )]
	public function test_browser_cannot_supply_event_author_runtime_original_digest_or_array( array $forged ): void { $this->expectException( \InvalidArgumentException::class ); PromiseShipmentConfigurationSection::parse_form( $forged ); }
	public static function forged_transport(): array { return [ [ [ 'author_user_id' => '8' ] ], [ [ 'event_at' => '2026-10-01 12:00:00.000000' ] ], [ [ 'runtime' => 'forged' ] ], [ [ 'original_packet_digest' => str_repeat( 'a', 64 ) ] ], [ [ 'original_envelope' => [ 'forged' ] ] ] ]; }

	public function test_stateless_original_command_authentication_rejects_tamper_actor_site_session_expiry_and_malformed_envelopes(): void {
		$program = <<<'PHP'
function wp_salt(string $scheme='auth'): string { return str_repeat('isolated-native-key', 4); }
function wp_get_session_token(): string { return $GLOBALS['native_session']; }
require 'tests/bootstrap.php';
$GLOBALS['blog_id']=1; $GLOBALS['cetech_de_test_user_id']=7; $GLOBALS['native_session']='native-session-original';
$class=CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseShipmentConfigurationService::class;
$service=(new ReflectionClass($class))->newInstanceWithoutConstructor(); $seal=new ReflectionMethod($class,'seal'); $verify=new ReflectionMethod($class,'verify');
$facts=['format'=>1,'kind'=>'command','site_id'=>1,'author_user_id'=>7,'session_hash'=>hash('sha256',$GLOBALS['native_session']),'shipment_id'=>40,'order_id'=>41,'expected_revision'=>2,'request_token'=>'5e71ce00-0000-4000-8000-000000000001','original_packet_digest'=>str_repeat('a',64),'event_key'=>str_repeat('b',64),'event_at'=>'2026-10-09 10:00:00.000000','runtime'=>['server'=>'captured-original'],'expires_at'=>'2099-01-01 00:00:00.000000','semantic_json'=>'{"reason":"Original reason"}','current_json'=>'{"event_at":"2026-10-09 10:00:00.000000"}'];
$encoded=$seal->invoke($service,$facts); $checks=['roundtrip'=>CetechDeliveryEngine\Domain\ServicePromise\PromiseJson::encode($facts)===CetechDeliveryEngine\Domain\ServicePromise\PromiseJson::encode($verify->invoke($service,$encoded))];
$reject=function($candidate)use($verify,$service):bool{try{$verify->invoke($service,$candidate);return false;}catch(Throwable){return true;}};
$tampered=json_decode(base64_decode($encoded),true,16,JSON_THROW_ON_ERROR); $tampered['facts']['current_json']='{"event_at":"2099-01-01 00:00:00.000000"}'; $checks['tamper']=$reject(base64_encode(json_encode($tampered,JSON_THROW_ON_ERROR)));
$GLOBALS['cetech_de_test_user_id']=8; $checks['actor']=$reject($encoded); $GLOBALS['cetech_de_test_user_id']=7;
$GLOBALS['blog_id']=2; $checks['site']=$reject($encoded); $GLOBALS['blog_id']=1;
$GLOBALS['native_session']='replacement-native-session'; $checks['session']=$reject($encoded); $GLOBALS['native_session']='native-session-original';
$expired=$facts; $expired['expires_at']='2000-01-01 00:00:00.000000'; $checks['expiry']=$reject($seal->invoke($service,$expired));
$extra=$facts; $extra['client_author']='forged'; $checks['closed']=$reject($seal->invoke($service,$extra));
$missing=$facts; $missing['current_json']=null; $checks['missing_original']=$reject($seal->invoke($service,$missing));
$checks['malformed']=$reject('not-base64-envelope'); $checks['missing']=$reject(null); $checks['oversized']=$reject(str_repeat('x',131073));
$again=$verify->invoke($service,$encoded); $checks['original_time_unchanged']=$again['event_at']===$facts['event_at']&&$again['current_json']===$facts['current_json']&&$again['request_token']===$facts['request_token'];
echo json_encode($checks,JSON_THROW_ON_ERROR);
PHP;
		$process = proc_open( [ PHP_BINARY, '-r', $program ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, dirname( __DIR__, 4 ) ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr );
		$facts = json_decode( $stdout, true, 8, JSON_THROW_ON_ERROR ); self::assertCount( 12, $facts ); foreach ( $facts as $check => $passed ) { self::assertTrue( $passed, $check ); }
	}
}
