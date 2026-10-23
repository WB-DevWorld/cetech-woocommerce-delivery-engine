<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteNativeTaxSource;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence as Native;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeHandoffSqlFixture as Rows;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;

$database = null; $factory = null;
try {
	$args = json_decode( $argv[1] ?? '', true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $args ) || ! in_array( $args['mode'] ?? null, [ 'writer', 'recapture' ], true ) || ! is_string( $args['prefix'] ?? null ) || ! is_bool( $args['hpos'] ?? null ) || ! is_string( $args['directory'] ?? null ) || ! str_starts_with( basename( $args['directory'] ), 'cetech-operation-proof-' ) || realpath( $args['directory'] ) !== $args['directory'] ) { throw new RuntimeException( 'Owned native handoff worker refused.' ); }
	require dirname( __DIR__, 2 ) . '/bootstrap.php';
	require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeHandoffSqlFixture.php';
	require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteLifecycleProofFactory.php';
	DB::validate_prefix( $args['prefix'] ); $prefix = $args['prefix']; $directory = $args['directory']; $hpos = $args['hpos'];
	if ( 'writer' === $args['mode'] ) {
		if ( ! in_array( $args['kind'] ?? null, [ 'candidate_order', 'candidate_item', 'saved_packet' ], true ) ) { throw new RuntimeException( 'Owned native writer mode refused.' ); }
		$database = DB::connect(); DB::execute( $database, 'SET SESSION innodb_lock_wait_timeout=2' ); DB::execute( $database, 'START TRANSACTION' );
		$sql = match ( $args['kind'] ) {
			'candidate_order' => $hpos ? "UPDATE `{$prefix}wc_orders` SET status='wc-failed' WHERE id=100" : "UPDATE `{$prefix}posts` SET post_status='wc-failed' WHERE ID=100",
			'candidate_item' => "UPDATE `{$prefix}woocommerce_order_itemmeta` SET meta_value='3' WHERE order_item_id=901 AND meta_key='_qty'",
			default => 'UPDATE `' . $prefix . ( $hpos ? 'wc_orders_meta`' : 'postmeta`' ) . " SET meta_value='changed-after-lock' WHERE " . ( $hpos ? 'order_id' : 'post_id' ) . "=100 AND meta_key='_cetech_de_n17_fixture_packet'",
		};
		$signal = json_encode( [ 'connection_id' => $database->thread_id, 'process_id' => getmypid() ], JSON_THROW_ON_ERROR ); if ( strlen( $signal ) !== file_put_contents( $directory . '/dispatched', $signal, LOCK_EX ) ) { throw new RuntimeException( 'Owned native dispatch failed.' ); }
		DB::execute( $database, $sql ); $affected = $database->affected_rows; DB::execute( $database, 'COMMIT' ); $database->close(); $database = null;
		if ( false === file_put_contents( $directory . '/finished', 'committed', LOCK_EX ) ) { throw new RuntimeException( 'Owned native finish failed.' ); }
		echo json_encode( [ 'process_id' => getmypid(), 'state' => 'committed', 'affected' => $affected ], JSON_THROW_ON_ERROR ), PHP_EOL;
	} else {
		$bytes = file_get_contents( $directory . '/native-recovery.json' ); if ( ! is_string( $bytes ) || strlen( $bytes ) > 262144 ) { throw new RuntimeException( 'Owned native recovery refused.' ); }
		$p = json_decode( $bytes, true, 28, JSON_THROW_ON_ERROR ); if ( ! is_array( $p ) || array_keys( $p ) !== [ 'quote', 'mapping', 'tax_source', 'group', 'component', 'destination', 'binding' ] || json_encode( $p, JSON_THROW_ON_ERROR ) !== $bytes ) { throw new RuntimeException( 'Owned native recovery shape refused.' ); }
		$quote = QuoteStoredRow::from_row( $p['quote'] ); $binding = QuoteBinding::from_row( $p['binding'], $quote ); $tax = QuoteNativeTaxSource::from_private_json( $p['tax_source'] ); $order = Rows::order( $quote, $p['mapping'], [ $p['component'] => $p['group'] ], $p['destination'] );
		$GLOBALS['current_user'] = (object) [ 'ID' => 0, 'caps' => [], 'allcaps' => [], 'roles' => [] ]; $GLOBALS['blog_id'] = 1; unset( $GLOBALS['woocommerce'] );
		$factory = new Factory( $prefix ); $guard = Native::capture( $factory, $order, $quote, $p['mapping'], $tax, static fn( WC_Order $actual ): bool => $actual === $order ); if ( null === $guard ) { throw new RuntimeException( 'Owned native recapture refused.' ); }
		$order->poison = true; $session = $factory->open(); if ( ! $session->begin() ) { throw new RuntimeException( 'Owned native proof refused.' ); } $verified = $guard->verify( $session, $quote, $binding );
		if ( ! $session->rollback() || ! $session->retire() ) { throw new RuntimeException( 'Owned native proof cleanup refused.' ); } $factory->close_all();
		echo json_encode( [ 'process_id' => getmypid(), 'verified' => $verified, 'digest' => $guard->evidence_digest() ], JSON_THROW_ON_ERROR ), PHP_EOL;
	}
} catch ( Throwable ) {
	try { $database?->rollback(); $database?->close(); } catch ( Throwable ) {} $factory?->close_all(); fwrite( STDERR, 'Owned native handoff worker failed.' ); exit( 1 );
}
