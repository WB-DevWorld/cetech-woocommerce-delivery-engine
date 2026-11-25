<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Selector\DeliveryEstimatePrecedence;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Presentation\Admin\DeliveryEstimateHelpText;
use PHPUnit\Framework\TestCase;

/**
 * Staff testing on staging (1.0.0-m2.5) found that the Delivery Option form promised its
 * "Estimated delivery" text was "shown to customers when this delivery option is selected",
 * while customers saw the Site-wide Defaults estimate on every option. The screens now describe
 * the M2 precedence rule; these tests hold the wording to the rule it describes.
 */
final class DeliveryEstimateHelpTextTest extends TestCase {

	/** A Delivery Option row with a free-text estimate and day counts, as the repository returns it. */
	private const OFFER_WITH_BOTH = [
		'service_level'          => '1 business day',
		'default_processing_min' => 1,
		'default_processing_max' => 1,
		'default_transit_min'    => 1,
		'default_transit_max'    => 3,
		'duration_unit'          => 'business_days',
	];

	public function test_the_option_text_is_described_as_a_staff_note_because_customers_never_get_it(): void {
		$help = DeliveryEstimateHelpText::option_text_field();

		self::assertStringContainsString( 'For staff only', $help );
		self::assertStringContainsString( 'customers never see this text', $help );
		self::assertStringNotContainsString( 'Shown to customers when this delivery option is selected', $help );

		// The rule the sentence describes: with no saved estimate and no day counts, the free text is not used.
		self::assertNull(
			DeliveryEstimatePrecedence::for_delivery( null, [ 'service_level' => '1 business day' ] ),
			'the option text alone never becomes a customer estimate'
		);
		// And it never wins over a saved estimate or the day counts either.
		self::assertSame( '3-6 business days', DeliveryEstimatePrecedence::for_delivery( '3-6 business days', self::OFFER_WITH_BOTH ) );
		self::assertStringNotContainsString( '1 business day', (string) DeliveryEstimatePrecedence::for_delivery( null, self::OFFER_WITH_BOTH ) );
	}

	public function test_the_day_counts_are_described_as_the_fallback_they_are(): void {
		$help = DeliveryEstimateHelpText::option_day_counts();

		self::assertStringContainsString( 'added together', $help );
		self::assertStringContainsString( 'only when no Estimated delivery is saved', $help );

		// Processing 1 + transit 1–3 is shown as one range, and only when nothing is saved for the item.
		self::assertSame( '2–4 business days', DeliveryEstimatePrecedence::for_delivery( null, self::OFFER_WITH_BOTH ) );
		self::assertSame( '2–4 business days', DeliveryEstimatePrecedence::for_delivery( '   ', self::OFFER_WITH_BOTH ) );
		self::assertSame( '2-3 business days', DeliveryEstimatePrecedence::for_delivery( '2-3 business days', self::OFFER_WITH_BOTH ) );
	}

	public function test_site_wide_and_product_help_say_one_estimate_covers_every_option(): void {
		$site_wide = DeliveryEstimateHelpText::site_wide_field();
		self::assertStringContainsString( 'every Delivery Option', $site_wide );
		self::assertStringContainsString( 'in place of each option’s own day counts', $site_wide );
		self::assertStringContainsString( 'Store Pickup uses the Pickup Location’s readiness', $site_wide, 'the pickup sentence staff already rely on is kept' );

		$product = ConfigurationFieldCatalog::description( ConfigurationFieldKey::ESTIMATED_DELIVERY );
		self::assertStringContainsString( 'shown for every delivery option of this product', $product );
		self::assertStringContainsString( 'does not freeze the delivery option or charge', $product );
	}

	public function test_the_screens_use_the_shared_wording_and_dropped_the_old_promise(): void {
		$root    = dirname( __DIR__, 4 );
		$options = (string) file_get_contents( $root . '/src/Presentation/Admin/DeliveryOffersPage.php' );
		$home    = (string) file_get_contents( $root . '/src/Presentation/Admin/DeliverySettingsHomePage.php' );

		self::assertStringNotContainsString( 'Shown to customers when this delivery option is selected', $options );
		self::assertStringContainsString( 'DeliveryEstimateHelpText::option_text_field()', $options );
		self::assertStringContainsString( 'DeliveryEstimateHelpText::option_day_counts()', $options );
		self::assertStringContainsString( 'DeliveryEstimateHelpText::site_wide_field()', $home );
		self::assertStringNotContainsString( 'Shown to customers for Delivery. Store Pickup', $home );
	}
}
