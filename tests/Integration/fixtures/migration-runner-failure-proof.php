<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Core\Versioning {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['migration_proof_options'][ $key ] ?? $default;
	}

	function update_option( string $key, mixed $value, mixed $autoload = true ): bool {
		unset( $autoload );
		$scenario = $GLOBALS['migration_proof_scenario'];
		$blocked  = ! empty( $GLOBALS['migration_proof_fault'] ) && (
			( 'version' === $scenario && SchemaVersion::OPTION_NAME === $key && '2' === $value )
			|| ( 'status' === $scenario && MigrationStatus::OPTION_NAME === $key && '2' === ( $value['to_version'] ?? null ) && 'success' === ( $value['status'] ?? null ) )
			|| ( 'status-all' === $scenario && MigrationStatus::OPTION_NAME === $key && '2' === ( $value['to_version'] ?? null ) )
		);
		if ( $blocked || $value === get_option( $key, null ) ) {
			return false;
		}
		$GLOBALS['migration_proof_options'][ $key ] = $value;
		return true;
	}

	function add_option( string $key, mixed $value, mixed $deprecated = '', mixed $autoload = true ): bool {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $key, $GLOBALS['migration_proof_options'] ) ) {
			return false;
		}
		$GLOBALS['migration_proof_options'][ $key ] = $value;
		return true;
	}
}

namespace {
	use CetechDeliveryEngine\Core\Versioning\MigrationDiscovery;
	use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
	use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
	use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
	use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
	use CetechDeliveryEngine\Support\Logger;

	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

	function wc_get_logger(): object {
		return new class() {
			public function log( string $level, string $message, array $context ): void {
				$GLOBALS['migration_proof_logs'][] = [ 'level' => $level, 'message' => $message, 'context' => $context ];
			}
		};
	}

	function trailingslashit( string $path ): string {
		return rtrim( $path, '/\\' ) . '/';
	}

	final class FailureProofMigration implements VerifiableMigrationInterface {
		public int $runs = 0;
		public int $verifications = 0;
		public bool $fail_up = false;
		public bool $fail_verify = false;

		public function __construct( public string $id, public string $version ) {
		}
		public function get_id(): string {
			return $this->id;
		}
		public function get_version(): string {
			return $this->version;
		}
		public function up(): void {
			++$this->runs;
			$GLOBALS['migration_proof_trace'][] = 'up:' . $this->id;
			$GLOBALS['migration_proof_data'][ $this->id ] = 'retained';
			if ( $this->fail_up ) {
				throw new \RuntimeException( 'middle up failure' );
			}
		}
		public function verify(): void {
			++$this->verifications;
			$GLOBALS['migration_proof_trace'][] = 'verify:' . $this->id;
			if ( $this->fail_verify ) {
				throw new \RuntimeException( 'middle verification failure' );
			}
		}
	}

	$scenario = $argv[1] ?? 'success';
	$GLOBALS['migration_proof_scenario'] = $scenario;
	$GLOBALS['migration_proof_fault'] = true;
	$GLOBALS['migration_proof_options'] = [];
	$GLOBALS['migration_proof_data'] = [ 'sentinel' => 'keep-me' ];
	$GLOBALS['migration_proof_trace'] = [];
	$GLOBALS['migration_proof_logs'] = [];

	$one = new FailureProofMigration( 'one', '1' );
	$two = new FailureProofMigration( 'two', '2' );
	$three = new FailureProofMigration( 'three', '3' );
	$two->fail_up = 'up' === $scenario;
	$two->fail_verify = 'verify' === $scenario;
	if ( 'duplicate-version' === $scenario ) {
		$two->version = '1';
	}
	if ( 'duplicate-id' === $scenario ) {
		$two->id = 'one';
	}

	$runner = new MigrationRunner( new Logger() );
	$runner->set_migrations( [ $three, $one, $two ] );
	$snapshot = static fn (): array => [
		'version' => SchemaVersion::get(),
		'status' => MigrationStatus::get(),
		'runs' => [ $one->runs, $two->runs, $three->runs ],
		'verifications' => [ $one->verifications, $two->verifications, $three->verifications ],
		'trace' => $GLOBALS['migration_proof_trace'],
		'data' => $GLOBALS['migration_proof_data'],
		'logs' => $GLOBALS['migration_proof_logs'],
	];

	if ( str_starts_with( $scenario, 'discovery-' ) ) {
		$directory = sys_get_temp_dir() . '/cetech-migration-proof-' . bin2hex( random_bytes( 8 ) );
		mkdir( $directory );
		try {
			file_put_contents( $directory . '/01.php', '<?php return $GLOBALS["migration_proof_one"];' );
			file_put_contents( $directory . '/02.php', 'discovery-throw' === $scenario ? '<?php throw new RuntimeException("load failure");' : ( 'discovery-success' === $scenario ? '<?php return $GLOBALS["migration_proof_two"];' : '<?php return false;' ) );
			file_put_contents( $directory . '/03.php', '<?php return $GLOBALS["migration_proof_three"];' );
			$GLOBALS['migration_proof_one'] = $one;
			$GLOBALS['migration_proof_two'] = $two;
			$GLOBALS['migration_proof_three'] = $three;
			$runner->set_migrations( MigrationDiscovery::discover( $directory, new Logger() ) );
			$runner->run();
			$result = [ 'first' => $snapshot() ];
		} finally {
			foreach ( glob( $directory . '/*.php' ) ?: [] as $file ) {
				unlink( $file );
			}
			rmdir( $directory );
		}
	} else {
		$runner->run();
		$result = [ 'first' => $snapshot() ];
		$GLOBALS['migration_proof_fault'] = false;
		$two->fail_up = false;
		$two->fail_verify = false;
		$runner->run();
		$result['retry'] = $snapshot();
		$runner->run();
		$result['repeat'] = $snapshot();
		if ( 'success' === $scenario ) {
			SchemaVersion::set( '3' );
			SchemaVersion::set( '3' );
			$result['noop_version'] = SchemaVersion::get();
			$fixed_status = [ 'recorded_at' => 'fixed', 'status' => 'success', 'migration_id' => 'three', 'from_version' => '2', 'to_version' => '3' ];
			MigrationStatus::record( $fixed_status );
			MigrationStatus::record( $fixed_status );
			$result['noop_status'] = MigrationStatus::get();
		}
	}
	print json_encode( $result, JSON_THROW_ON_ERROR );
}
