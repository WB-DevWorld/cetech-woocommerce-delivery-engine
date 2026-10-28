<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Frontend;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentCard;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerShipmentRenderer;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class CustomerDeliveryPresentationRefinementTest extends TestCase {

	public function test_product_names_and_public_rows_stay_together_without_injecting_markup(): void {
		$html = $this->summary_html( [
			$this->line( 'Fan <script>alert(1)</script>', 'Air <b>service</b>', 'Estimated 3–5 business days' ),
			$this->line( 'Pump & accessories', 'Sea delivery', 'Estimated 2–3 weeks' ),
		] );
		$xpath = $this->xpath( $html );
		$blocks = $xpath->query( '//div[@class="cetech-de-order-delivery-summary__line"]' );

		self::assertCount( 2, $blocks );
		self::assertStringContainsString( 'Fan <script>alert(1)</script>', $blocks->item( 0 )->textContent );
		self::assertStringContainsString( 'Air <b>service</b>', $blocks->item( 0 )->textContent );
		self::assertStringContainsString( '3–5 business days', $blocks->item( 0 )->textContent );
		self::assertStringNotContainsString( 'Sea delivery', $blocks->item( 0 )->textContent );
		self::assertStringContainsString( 'Pump & accessories', $blocks->item( 1 )->textContent );
		self::assertStringContainsString( 'Sea delivery', $blocks->item( 1 )->textContent );
		self::assertCount( 0, $xpath->query( '//script|//b' ) );
		self::assertStringNotContainsString( 'Internal availability', $html );
		self::assertStringNotContainsString( 'Internal description', $html );
		self::assertStringNotContainsString( 'PRIVATE-CHARGE', $html );
	}

	public function test_a_line_without_public_rows_does_not_leave_an_orphaned_product_heading(): void {
		$html = $this->summary_html( [
			$this->line( 'No public delivery details', '', '' ),
			$this->line( 'Available item', 'Standard delivery', 'Estimated 1–2 days' ),
		] );

		self::assertStringNotContainsString( 'No public delivery details', $html );
		self::assertStringContainsString( 'Available item', $html );
		self::assertCount( 1, $this->xpath( $html )->query( '//div[@class="cetech-de-order-delivery-summary__line"]' ) );
	}

	public function test_tracking_link_announces_the_new_tab_and_keeps_safe_link_attributes(): void {
		$options = $GLOBALS['cetech_de_test_options'] ?? [];
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_tracking_links'] = true;
		try {
			$renderer = new CustomerShipmentRenderer(
				new FeatureFlags(),
				new Requirements(),
				( new ReflectionClass( CustomerShipmentQuery::class ) )->newInstanceWithoutConstructor()
			);
			$html = $this->invoke_html( $renderer, 'render_card', new CustomerShipmentCard(
				'904-D1', 'Standard delivery', 'Dispatched', '3–5 days', false,
				1, [ [ 'name' => '<script>Fan</script>', 'quantity' => 1 ] ],
				'Carrier', 'TRACK-904', 'https://carrier.example/TRACK-904', null, null
			) );
		} finally {
			$GLOBALS['cetech_de_test_options'] = $options;
		}

		$xpath = $this->xpath( $html );
		$link = $xpath->query( '//a' )->item( 0 );
		self::assertSame( '_blank', $link->getAttribute( 'target' ) );
		self::assertSame( 'noopener noreferrer', $link->getAttribute( 'rel' ) );
		self::assertSame( 'Track shipment 904-D1 (opens in a new tab)', $link->getAttribute( 'aria-label' ) );
		self::assertStringContainsString( 'Opens in a new tab', $html );
		self::assertCount( 0, $xpath->query( '//script' ) );
	}

	/** @param list<CustomerOrderDeliveryLineSummary> $lines */
	private function summary_html( array $lines ): string {
		$renderer = new CustomerOrderDeliverySummaryRenderer(
			new FeatureFlags(),
			new Requirements(),
			( new ReflectionClass( CustomerOrderDeliverySummaryBuilder::class ) )->newInstanceWithoutConstructor()
		);

		return $this->invoke_html( $renderer, 'render_summary', new CustomerOrderDeliverySummary( $lines, null ) );
	}

	private function line( string $product, string $offer, string $estimate ): CustomerOrderDeliveryLineSummary {
		return new CustomerOrderDeliveryLineSummary(
			$product, $offer, 'Internal availability', 'Delivery', 'Internal description',
			$estimate, null, 'PRIVATE-CHARGE', null
		);
	}

	private function invoke_html( object $renderer, string $method_name, object $argument ): string {
		$method = new ReflectionMethod( $renderer, $method_name );
		if ( PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}
		ob_start();
		try {
			$method->invoke( $renderer, $argument );

			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private function xpath( string $html ): DOMXPath {
		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			self::assertTrue( $dom->loadHTML( '<?xml encoding="UTF-8">' . $html ) );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}

		return new DOMXPath( $dom );
	}
}
