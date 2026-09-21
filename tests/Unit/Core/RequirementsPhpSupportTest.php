<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Core;

use CetechDeliveryEngine\Core\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * Locks the owner PHP support floor to WordPress/WooCommerce recommended PHP 8.3.
 */
final class RequirementsPhpSupportTest extends TestCase {

	public function test_minimum_supported_php_is_eight_three(): void {
		$requirements = new Requirements();

		self::assertSame( '8.3', $requirements->minimum_php_version() );
		self::assertTrue( $requirements->is_php_version_supported() );
		self::assertStringContainsString( 'PHP 8.3 or higher', $requirements->php_version_notice_message() );
	}
}
