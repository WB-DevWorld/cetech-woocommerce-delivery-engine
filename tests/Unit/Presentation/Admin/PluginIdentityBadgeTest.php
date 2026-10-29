<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsPage;
use CetechDeliveryEngine\Presentation\Admin\PluginIdentityBadge;
use PHPUnit\Framework\TestCase;

/**
 * M2: the Settings and Site-wide Defaults pages show the installed plugin version and schema.
 */
final class PluginIdentityBadgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [];
		$GLOBALS['cetech_de_test_caps']      = [ 'manage_delivery_settings' => true ];
		$GLOBALS['cetech_de_test_is_admin']  = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_redirects'] = [];
		$_POST = [];
		if ( ! function_exists( 'disabled' ) ) {
			// WordPress form helper that the bootstrap does not stub; mirrors wp-includes/general-template.php.
			eval( 'function disabled( $disabled, $current = true, $display = true ) { $result = (string) $disabled === (string) $current ? " disabled=\'disabled\'" : ""; if ( $display ) { echo $result; } return $result; }' );
		}
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_line_names_the_version_and_the_installed_schema(): void {
		$GLOBALS['cetech_de_test_options'][ SchemaVersion::OPTION_NAME ] = SchemaVersion::TARGET;

		$line = PluginIdentityBadge::line();

		self::assertStringContainsString( 'Delivery Engine ' . PluginIdentityBadge::version(), $line );
		self::assertStringContainsString( 'schema ' . SchemaVersion::TARGET, $line );
		self::assertStringNotContainsString( 'migration pending', $line );
		self::assertTrue( PluginIdentityBadge::schema_is_current() );
	}

	public function test_line_flags_a_schema_behind_the_target(): void {
		$GLOBALS['cetech_de_test_options'][ SchemaVersion::OPTION_NAME ] = '5';

		$line = PluginIdentityBadge::line();

		self::assertStringContainsString( 'schema 5', $line );
		self::assertStringContainsString( '(target ' . SchemaVersion::TARGET . ', migration pending)', $line );
		self::assertFalse( PluginIdentityBadge::schema_is_current() );
	}

	public function test_render_exposes_version_and_schema_as_data_attributes(): void {
		$GLOBALS['cetech_de_test_options'][ SchemaVersion::OPTION_NAME ] = SchemaVersion::TARGET;

		ob_start();
		PluginIdentityBadge::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="cetech-de-plugin-identity description"', $html );
		self::assertStringContainsString( 'data-cetech-de-plugin-version="' . PluginIdentityBadge::version() . '"', $html );
		self::assertStringContainsString( 'data-cetech-de-schema="' . SchemaVersion::TARGET . '"', $html );
		self::assertStringNotContainsString( 'cetech-de-plugin-identity--pending', $html );

		$GLOBALS['cetech_de_test_options'][ SchemaVersion::OPTION_NAME ] = '5';
		ob_start();
		PluginIdentityBadge::render();
		self::assertStringContainsString( 'cetech-de-plugin-identity--pending', (string) ob_get_clean() );
	}

	public function test_settings_page_renders_the_identity_line_under_its_header(): void {
		$GLOBALS['cetech_de_test_options'][ SchemaVersion::OPTION_NAME ] = SchemaVersion::TARGET;
		$flags = new FeatureFlags();
		$page  = new DeliverySettingsPage(
			$flags,
			new Requirements(),
			$this->createStub( RateCardRepositoryInterface::class ),
			new ShippingRateCalculationGate( $flags, new Requirements() ),
			new AdminActionHandler( new AdminNoticeService() )
		);

		ob_start();
		try {
			$page->render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}

		self::assertStringContainsString( 'data-cetech-de-plugin-version="' . PluginIdentityBadge::version() . '"', $html );
		self::assertStringContainsString( 'schema ' . SchemaVersion::TARGET, $html );
		self::assertGreaterThan( strpos( $html, '</header>' ), strpos( $html, 'cetech-de-plugin-identity' ), 'identity line follows the page header' );
	}

	public function test_site_wide_defaults_page_renders_the_identity_line_on_both_views(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsHomePage.php' );

		self::assertSame( 2, substr_count( $source, 'PluginIdentityBadge::render();' ), 'landing and profile editor both show the identity line' );
	}
}
