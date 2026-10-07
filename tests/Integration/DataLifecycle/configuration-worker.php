<?php

declare(strict_types=1);

use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofConfiguration;

require dirname( __DIR__, 2 ) . '/bootstrap.php';
try {
	$a = json_decode( $argv[1] ?? '', true, 8, JSON_THROW_ON_ERROR ); DataLifecycleProofConfiguration::open( $a['prefix'] ); $result = DataLifecycleProofConfiguration::service()->save( DataLifecycleProofConfiguration::command( $a['priority'], $a['token'], $a['revision'] ) );
	echo json_encode( [ 'success' => $result->success, 'replayed' => $result->replayed, 'revision' => $result->version_after ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) { echo '{"proof_error":"configuration_worker_failed"}'; exit( 1 ); }
