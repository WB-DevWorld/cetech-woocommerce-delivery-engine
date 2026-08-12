<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * Windows-safe gate: production PSR-4 class files must match Linux-case paths.
 */
final class Psr4PathCaseTest extends TestCase {

	public function test_src_php_files_match_declared_namespace_and_type_name(): void {
		$src_root = dirname( __DIR__, 3 ) . '/src';
		$failures = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src_root, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src_root ) + 1 ) );
			$source   = (string) file_get_contents( $file->getPathname() );

			if ( ! preg_match( '/^namespace\s+(CetechDeliveryEngine\\\\[^;]+);/m', $source, $namespace_match ) ) {
				$failures[] = $relative . ' is missing a CetechDeliveryEngine namespace.';
				continue;
			}

			if ( ! preg_match( '/^(?:final\s+|abstract\s+)?(class|interface|enum|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $source, $type_match ) ) {
				continue;
			}

			$expected_relative = str_replace( '\\', '/', substr( $namespace_match[1], strlen( 'CetechDeliveryEngine\\' ) ) ) . '/' . $type_match[2] . '.php';

			if ( $expected_relative !== $relative ) {
				$failures[] = $relative . ' does not match PSR-4 path ' . $expected_relative;
			}
		}

		self::assertSame( [], $failures, implode( PHP_EOL, $failures ) );
	}

	public function test_runtime_contract_paths_use_exact_linux_case(): void {
		$plugin_root = dirname( __DIR__, 3 );

		foreach (
			[
				'src/Application/Runtime/VariationRelationshipInspectorInterface.php',
				'src/Application/Runtime/WooCommerceVariationRelationshipInspector.php',
				'src/Core/Versioning/VerifiableMigrationInterface.php',
				'src/Core/Versioning/MigrationInterface.php',
				'database/migrations/20260810160000_create_scoped_configuration_tables.php',
			] as $relative
		) {
			self::assertFileExists( $plugin_root . '/' . $relative );
			self::assertSame( $relative, str_replace( '\\', '/', $relative ) );
		}
	}
}
