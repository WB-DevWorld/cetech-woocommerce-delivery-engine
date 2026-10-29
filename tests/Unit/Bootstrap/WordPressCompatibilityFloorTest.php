<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * M2 decision: the advertised WordPress floor is 6.5 in both the plugin header and readme.txt.
 */
final class WordPressCompatibilityFloorTest extends TestCase {

	private const WORDPRESS_FLOOR = '6.5';

	public function test_plugin_header_requires_wordpress_floor(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 3 ) . '/cetech-woocommerce-delivery-engine.php' );

		self::assertMatchesRegularExpression(
			'/^ \* Requires at least: ' . preg_quote( self::WORDPRESS_FLOOR, '/' ) . '\r?$/m',
			$header,
			'Plugin header must advertise WordPress ' . self::WORDPRESS_FLOOR . ' as the minimum.'
		);
	}

	public function test_readme_requires_the_same_wordpress_floor(): void {
		$readme = (string) file_get_contents( dirname( __DIR__, 3 ) . '/readme.txt' );

		self::assertMatchesRegularExpression(
			'/^Requires at least: ' . preg_quote( self::WORDPRESS_FLOOR, '/' ) . '\r?$/m',
			$readme,
			'readme.txt must advertise the same WordPress floor as the plugin header.'
		);
	}

	public function test_header_and_readme_floors_agree(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 3 ) . '/cetech-woocommerce-delivery-engine.php' );
		$readme = (string) file_get_contents( dirname( __DIR__, 3 ) . '/readme.txt' );

		preg_match( '/^ \* Requires at least: (\S+)\r?$/m', $header, $header_match );
		preg_match( '/^Requires at least: (\S+)\r?$/m', $readme, $readme_match );

		self::assertSame( $header_match[1] ?? null, $readme_match[1] ?? null );
	}
}
