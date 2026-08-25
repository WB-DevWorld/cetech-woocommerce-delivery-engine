<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;
use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CarrierVisibility;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminRecordDependencyChecker;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
use CetechDeliveryEngine\Presentation\Admin\DeliveryOffersPage;
use CetechDeliveryEngine\Presentation\Admin\DestinationZoneTestMatcher;
use CetechDeliveryEngine\Presentation\Admin\DestinationZonesPage;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PostRc6AdminSetupRepairR1RequestPathTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_POST = [];
		$_GET  = [];
		$GLOBALS['cetech_de_test_is_admin']          = true;
		$GLOBALS['cetech_de_test_logged_in']         = true;
		$GLOBALS['cetech_de_test_user_id']           = 7;
		$GLOBALS['cetech_de_test_caps']              = [ '*' => true ];
		$GLOBALS['cetech_de_test_redirects']         = [];
		$GLOBALS['cetech_de_test_transients']        = [];
		WooCommerceCountryCatalog::override_for_tests(
			[
				'GH' => 'Ghana',
				'NG' => 'Nigeria',
				'GB' => 'United Kingdom (UK)',
				'US' => 'United States (US)',
				'DE' => 'Germany',
				'CN' => 'China',
			]
		);
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
		WooCommerceCountryCatalog::override_for_tests( null );
		parent::tearDown();
	}

	public function test_delivery_option_wp_admin_post_generates_blank_code(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$page   = $this->offers_page( $offers );

		$_POST = $this->offer_post(
			[
				'public_label' => 'Delivery to Madina Zone',
				'code'         => '',
			]
		);

		$this->capture_redirect( static fn () => $page->handle_actions() );

		$saved = $offers->findByCode( 'delivery-to-madina-zone' );
		self::assertNotNull( $saved );
		self::assertSame( 'Delivery to Madina Zone', $saved['public_label'] ?? null );
		$notices = implode( ' ', $this->flashed_messages() );
		self::assertStringNotContainsString( 'Code is required', $notices );
	}

	public function test_delivery_area_wp_admin_post_generates_blank_code(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$rules = new InMemoryDestinationRuleRepository();
		$page  = $this->areas_page( $zones, $rules );

		$_POST = $this->area_post(
			[
				'name' => 'Greater Accra',
				'code' => '',
			]
		);

		$this->capture_redirect( static fn () => $page->handle_actions() );

		$saved = $zones->findByCode( 'greater-accra' );
		self::assertNotNull( $saved );
		self::assertSame( 'Greater Accra', $saved['internal_name'] ?? null );
	}

	public function test_delivery_area_germany_label_or_iso_persists_de(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$rules = new InMemoryDestinationRuleRepository();
		$page  = $this->areas_page( $zones, $rules );

		$_POST = $this->area_post(
			[
				'name' => 'Germany Area',
				'code' => '',
				'destination_rules' => [
					0 => [
						'rule_type'    => 'country',
						'country_code' => 'DE',
						'rule_value'   => 'Germany',
						'match_mode'   => 'exact',
						'priority'     => 100,
					],
				],
			]
		);
		$this->capture_redirect( static fn () => $page->handle_actions() );
		$by_select = $zones->findByCode( 'germany-area' );
		self::assertNotNull( $by_select );
		self::assertSame( 'DE', $rules->listByZoneId( (int) $by_select['id'] )[0]['rule_value'] ?? null );

		$zones2 = new InMemoryDestinationZoneRepository();
		$rules2 = new InMemoryDestinationRuleRepository();
		$page2  = $this->areas_page( $zones2, $rules2 );
		$_POST  = $this->area_post(
			[
				'name' => 'Germany Label Area',
				'code' => '',
				'destination_rules' => [
					0 => [
						'rule_type'  => 'country',
						'rule_value' => 'Germany',
						'match_mode' => 'exact',
						'priority'   => 100,
					],
				],
			]
		);
		$this->capture_redirect( static fn () => $page2->handle_actions() );
		$by_label = $zones2->findByCode( 'germany-label-area' );
		self::assertNotNull( $by_label );
		self::assertSame( 'DE', $rules2->listByZoneId( (int) $by_label['id'] )[0]['rule_value'] ?? null );
	}

	public function test_failed_validation_rerenders_without_fatal_and_keeps_primary_submit(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$page   = $this->offers_page( $offers );

		$_POST = $this->offer_post(
			[
				'public_label'         => 'Broken Option',
				'code'                 => '',
				'route'                => 'not-a-route',
				'processing_min_days'  => '',
				'processing_max_days'  => '',
				'transit_min_days'     => '',
				'display_priority'     => '',
			]
		);
		$this->capture_redirect( static fn () => $page->handle_actions() );

		$_GET = [
			'page'   => DeliveryOffersPage::SLUG,
			'action' => 'add',
		];
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Create Delivery Option', $html );
		self::assertStringContainsString( 'name="cetech_de_save"', $html );
		self::assertStringContainsString( 'form="' . \CetechDeliveryEngine\Presentation\Admin\AdminPageLayout::ENTITY_FORM_ID . '"', $html );
		self::assertStringContainsString( 'Back to Delivery Options', $html );
		self::assertStringContainsString( 'Broken Option', $html );
		self::assertStringNotContainsString( 'There has been a critical error', $html );
		self::assertStringContainsString( 'name="processing_min_days"', $html );
		self::assertDoesNotMatchRegularExpression( '/id="processing_min_days"[^>]*value="[^"]+"/', $html );
		self::assertSame( [], $offers->list() );
	}

	public function test_delivery_option_numeric_advanced_value_saves(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$page   = $this->offers_page( $offers );

		$_POST = $this->offer_post(
			[
				'public_label'        => 'Timed Option',
				'code'                => '',
				'processing_min_days' => '3',
				'processing_max_days' => '5',
			]
		);
		$this->capture_redirect( static fn () => $page->handle_actions() );

		$saved = $offers->findByCode( 'timed-option' );
		self::assertNotNull( $saved );
		self::assertSame( 3, $saved['default_processing_min'] ?? null );
		self::assertSame( 5, $saved['default_processing_max'] ?? null );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function offer_post( array $overrides ): array {
		return array_merge(
			[
				'cetech_de_action'     => 'cetech_de_save_delivery_offer',
				'cetech_de_nonce'      => 'test-nonce-cetech_de_save_delivery_offer',
				'public_label'         => 'Standard Delivery',
				'code'                 => '',
				'description'          => '',
				'route'                => DeliveryRoute::LocalDelivery->value,
				'service_level'        => '',
				'carrier_visibility'   => CarrierVisibility::AssignedByStore->value,
				'carrier_display_name' => '',
				'status'               => RecordStatus::Active->value,
				'display_priority'     => '100',
			],
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function area_post( array $overrides ): array {
		return array_merge(
			[
				'cetech_de_action'  => 'cetech_de_save_destination_zone',
				'cetech_de_nonce'   => 'test-nonce-cetech_de_save_destination_zone',
				'name'              => 'Local Area',
				'code'              => '',
				'public_label'      => '',
				'status'            => RecordStatus::Active->value,
				'priority'          => '100',
				'destination_rules' => [
					0 => [
						'rule_type'    => 'country',
						'country_code' => 'GH',
						'rule_value'   => '',
						'match_mode'   => 'exact',
						'priority'     => 100,
					],
				],
			],
			$overrides
		);
	}

	private function offers_page( InMemoryDeliveryOfferRepository $offers ): DeliveryOffersPage {
		return new DeliveryOffersPage(
			$offers,
			new DeliveryOfferValidator(),
			new AdminActionHandler( new AdminNoticeService() ),
			$this->audit_logger(),
			$this->dependency_checker()
		);
	}

	private function areas_page(
		InMemoryDestinationZoneRepository $zones,
		InMemoryDestinationRuleRepository $rules
	): DestinationZonesPage {
		return new DestinationZonesPage(
			$zones,
			$rules,
			$this->createStub( RateCardRepositoryInterface::class ),
			new DestinationZoneValidator(),
			new DestinationRuleValidator(),
			new DestinationZoneTestMatcher( new DestinationZoneMatcher( $zones, $rules ) ),
			new AdminActionHandler( new AdminNoticeService() ),
			$this->audit_logger(),
			$this->dependency_checker()
		);
	}

	private function dependency_checker(): AdminRecordDependencyChecker {
		return new AdminRecordDependencyChecker(
			$this->createStub( RateCardRepositoryInterface::class ),
			$this->createStub( OriginRepositoryInterface::class ),
			$this->createStub( ProductDeliveryRuleRepositoryInterface::class )
		);
	}

	private function audit_logger(): ConfigurationAuditLogger {
		$repo = new class() implements AuditLogRepositoryInterface {
			public function findById( int $id ): ?array {
				return null;
			}

			public function append( array $data ): int {
				return 1;
			}

			public function list( array $criteria = [] ): array {
				return [];
			}
		};

		return new ConfigurationAuditLogger( $repo, new Logger() );
	}

	private function capture_redirect( callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected wp-admin redirect after POST.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	/**
	 * @return list<string>
	 */
	private function flashed_messages(): array {
		$messages = [];
		foreach ( $GLOBALS['cetech_de_test_transients'] ?? [] as $value ) {
			if ( is_array( $value ) && isset( $value['message'] ) ) {
				$messages[] = (string) $value['message'];
			}
		}

		return $messages;
	}
}
