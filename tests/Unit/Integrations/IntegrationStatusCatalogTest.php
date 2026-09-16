<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations;

use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutAdapter;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Integrations\Status\IntegrationStatus;
use CetechDeliveryEngine\Integrations\Status\IntegrationStatusCatalog;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IntegrationStatusCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	public function test_uninstalled_plugins_report_not_installed_and_no_adapter(): void {
		$catalog = $this->catalog();

		foreach ( [ 'wpml', 'wcml', 'wcfm', 'vitepos' ] as $key ) {
			$status = $catalog->by_key( $key );
			self::assertNotNull( $status );
			self::assertSame( IntegrationStatus::STATE_NOT_INSTALLED, $status->state );
			self::assertFalse( $status->adapter_implemented );
			self::assertFalse( $status->currently_in_use );
		}

		$wcfm = $catalog->by_key( 'wcfm' );
		self::assertNotNull( $wcfm );
		self::assertStringContainsString( 'administrative isolation', $wcfm->detail );
	}

	public function test_woodmart_absent_is_not_the_active_theme(): void {
		$status = $this->catalog()->woodmart();

		self::assertSame( IntegrationStatus::STATE_NOT_INSTALLED, $status->state );
		self::assertFalse( $status->adapter_implemented );
		self::assertStringContainsString( 'generic WooCommerce', $status->detail );
	}

	public function test_blocks_status_is_implemented(): void {
		$status = $this->catalog()->blocks();

		self::assertTrue( $status->adapter_implemented );
		self::assertNotSame( IntegrationStatus::STATE_ADAPTER_NOT_IMPLEMENTED, $status->state );
		self::assertSame( 'blocks', $status->key );
	}

	private function catalog(): IntegrationStatusCatalog {
		$adapter = ( new ReflectionClass( BlocksCheckoutAdapter::class ) )->newInstanceWithoutConstructor();

		return new IntegrationStatusCatalog(
			new IntegrationRegistry( new Logger() ),
			new \CetechDeliveryEngine\Integrations\Blocks\BlocksUsageDetector(),
			$adapter
		);
	}
}
