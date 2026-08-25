<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Presentation\Admin\BulkJobAdminCopy;
use PHPUnit\Framework\TestCase;

final class BulkToolsUiConsistencyTest extends TestCase {

	public function test_bulk_tools_css_uses_a_scoped_spacing_scale(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/delivery-engine-admin.css' );

		self::assertStringContainsString( '.cetech-de-bulk-tools {', $css );
		self::assertStringContainsString( '--cetech-de-bulk-space-2: 8px;', $css );
		self::assertStringContainsString( '--cetech-de-bulk-space-3: 12px;', $css );
		self::assertStringContainsString( '--cetech-de-bulk-space-4: 16px;', $css );
		self::assertStringContainsString( '--cetech-de-bulk-space-5: 24px;', $css );
		self::assertStringContainsString( '.cetech-de-bulk-tools .cetech-de-summary-stat', $css );
		self::assertStringContainsString( '.cetech-de-bulk-compare-pane--proposed', $css );
		self::assertStringContainsString( 'notice notice-info inline', (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/BulkToolsPage.php' ) );
	}

	public function test_bulk_tools_css_does_not_globally_restyle_core_admin_controls(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/delivery-engine-admin.css' );
		$unscoped = [];
		if ( preg_match( '/^\.button\s*\{/m', $css ) ) {
			$unscoped[] = '.button';
		}
		if ( preg_match( '/^table\s*\{/m', $css ) ) {
			$unscoped[] = 'table';
		}
		if ( preg_match( '/^h2\s*\{/m', $css ) ) {
			$unscoped[] = 'h2';
		}

		self::assertSame( [], $unscoped );
	}

	public function test_empty_states_explain_the_next_action(): void {
		self::assertSame( 'No bulk jobs yet.', BulkJobAdminCopy::empty_jobs_title() );
		self::assertStringContainsString( 'Preview a Catalog change', BulkJobAdminCopy::empty_jobs_text() );
		self::assertStringContainsString( 'header row', BulkJobAdminCopy::empty_csv_text() );
		self::assertStringContainsString( 'does not activate checkout', BulkJobAdminCopy::empty_package_text() );
	}

	public function test_all_bulk_tabs_use_the_shared_workspace_and_panels(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/BulkToolsPage.php' );

		self::assertStringContainsString( "open_page( 'cetech-de-bulk-tools' )", $source );
		self::assertStringContainsString( 'cetech-de-bulk-workspace', $source );
		self::assertStringContainsString( 'cetech-de-bulk-tabs', $source );
		self::assertStringContainsString( 'Catalog CSV', $source );
		self::assertStringContainsString( 'General configuration package', $source );
		self::assertStringContainsString( 'Change Delivery Charges', $source );
		self::assertStringContainsString( 'cetech-de-bulk-compare-pane', $source );
		self::assertStringContainsString( 'Go to Catalog', $source );
	}
}
