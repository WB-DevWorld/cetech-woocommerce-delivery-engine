<?php

declare(strict_types=1);
use CetechDeliveryEngine\Core\Versioning\{MigrationRunner, MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Infrastructure\Persistence\FixedPromotionBudgetInstallationReadiness;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetInstallationSqlFixture as F, PromotionBudgetInstallationWpdb};

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetInstallationSqlFixture.php';
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/installation-wp-admin/' );
function get_current_blog_id(): int { return 1; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['wpdb']->option( $name, $default ); }
function update_option( string $name, mixed $value, mixed $autoload = false ): bool { return $GLOBALS['wpdb']->update_option( $name, $value ); }
function add_option( string $name, mixed $value, string $deprecated = '', mixed $autoload = false ): bool { return update_option( $name, $value, $autoload ); }
function dbDelta( string $sql ): array { $GLOBALS['wpdb']->delta( $sql ); return []; }

try {
	$args = json_decode( $argv[1] ?? '', true, 8, JSON_THROW_ON_ERROR ); DB::validate_prefix( $args['prefix'] );
	$db = DB::connect(); $GLOBALS['wpdb'] = new PromotionBudgetInstallationWpdb( $db, $args['prefix'], $args['fault'] ?? '' );
	$inspection = new FixedPromotionBudgetInstallationReadiness( $GLOBALS['wpdb'] );
	if ( 'run' === ( $args['mode'] ?? 'run' ) ) { $migration = require dirname( __DIR__, 3 ) . '/database/migrations/' . F::MIGRATION_FILE; $runner = new MigrationRunner( new Logger() ); $runner->set_migrations( [ $migration ] ); $runner->run(); }
	$records = null;
	if ( 'verify' === ( $args['mode'] ?? 'run' ) ) { try { $inspection->verify_stored_records(); $records = true; } catch ( Throwable ) { $records = false; } }
	$output = [ 'process_id' => getmypid(), 'connection_id' => $db->thread_id, 'schema' => SchemaVersion::get(), 'status' => MigrationStatus::get(), 'readiness' => $inspection->get_status(), 'ddl_calls' => $GLOBALS['wpdb']->ddl_calls, 'ddl_creates' => $GLOBALS['wpdb']->ddl_creates, 'records' => $records ];
	$db->close(); echo json_encode( $output, JSON_THROW_ON_ERROR );
} catch ( Throwable ) { fwrite( STDERR, 'Owned installation child failed.' ); exit( 1 ); }
