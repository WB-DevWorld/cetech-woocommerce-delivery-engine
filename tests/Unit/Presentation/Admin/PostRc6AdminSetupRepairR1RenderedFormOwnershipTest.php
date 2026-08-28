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
use CetechDeliveryEngine\Presentation\Admin\AdminPageLayout;
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
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Parses rendered wp-admin HTML with DOMDocument. PHP source-order checks are
 * not sufficient: QA.1 failed because the browser did not own the same form
 * the unit tests assumed.
 */
final class PostRc6AdminSetupRepairR1RenderedFormOwnershipTest extends TestCase {

	private const FORM_ID = AdminPageLayout::ENTITY_FORM_ID;

	private const COUNTRY_LABELS = [
		'GH' => 'Ghana',
		'NG' => 'Nigeria',
		'GB' => 'United Kingdom',
		'US' => 'United States',
		'DE' => 'Germany',
		'CN' => 'China',
	];

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

	public function test_delivery_option_add_form_ownership(): void {
		$html = $this->render_offers_add();
		$this->assert_entity_form_ownership(
			$html,
			[
				'public_label',
				'code',
				'cetech_de_action',
				'cetech_de_nonce',
				'cetech_de_save',
			],
			'Create Delivery Option',
			'Back to Delivery Options'
		);
	}

	public function test_delivery_option_edit_form_ownership(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$offers->save(
			[
				'internal_code' => 'same-day',
				'public_label'  => 'Same-Day Delivery',
				'route'         => DeliveryRoute::LocalDelivery->value,
				'status'        => RecordStatus::Active->value,
			]
		);
		$page = $this->offers_page( $offers );
		$_GET = [
			'page'   => DeliveryOffersPage::SLUG,
			'action' => 'edit',
			'id'     => '1',
		];
		$html = $this->capture_render( $page );
		$this->assert_entity_form_ownership(
			$html,
			[
				'public_label',
				'code',
				'cetech_de_action',
				'cetech_de_nonce',
				'cetech_de_save',
			],
			'Save Delivery Option',
			'Back to Delivery Options'
		);
	}

	public function test_delivery_area_edit_form_ownership(): void {
		$zones   = new InMemoryDestinationZoneRepository();
		$rules   = new InMemoryDestinationRuleRepository();
		$zone_id = $zones->save(
			[
				'internal_code' => 'germany-area',
				'internal_name' => 'Germany Area',
				'status'        => RecordStatus::Active->value,
			]
		);
		$rules->replaceForZone(
			$zone_id,
			[
				[
					'rule_type'  => 'country',
					'rule_value' => 'DE',
					'match_mode' => 'exact',
					'priority'   => 100,
				],
			]
		);
		$page = $this->areas_page( $zones, $rules );
		$_GET = [
			'page'   => DestinationZonesPage::SLUG,
			'action' => 'edit',
			'id'     => (string) $zone_id,
		];
		$html = $this->capture_render( $page );
		$dom  = $this->assert_entity_form_ownership(
			$html,
			[
				'name',
				'code',
				'destination_rules[0][country_code]',
				'cetech_de_save',
			],
			'Save Delivery Area',
			'Back to Delivery Areas'
		);
		$country = $this->named_control( $dom, 'destination_rules[0][country_code]' );
		self::assertNotNull( $country );
		$selected = $this->selected_option( $country );
		self::assertNotNull( $selected );
		self::assertSame( 'DE', $selected->getAttribute( 'value' ) );
		self::assertStringContainsString( 'Germany', (string) $selected->textContent );
	}

	public function test_delivery_area_add_form_ownership_and_country_iso_options(): void {
		$html = $this->render_areas_add();
		$dom  = $this->assert_entity_form_ownership(
			$html,
			[
				'name',
				'code',
				'cetech_de_action',
				'cetech_de_nonce',
				'cetech_de_save',
				'destination_rules[0][country_code]',
				'destination_rules[0][rule_value]',
			],
			'Create Delivery Area',
			'Back to Delivery Areas'
		);

		$payload = $this->successful_controls( $dom, self::FORM_ID );
		self::assertArrayHasKey( 'destination_rules[0][country_code]', $payload );
		self::assertSame( '', $payload['destination_rules[0][rule_value]'] ?? null );

		$country = $this->named_control( $dom, 'destination_rules[0][country_code]' );
		self::assertNotNull( $country );
		self::assertSame( 'select', strtolower( $country->tagName ) );
		self::assertFalse( $country->hasAttribute( 'disabled' ) );

		foreach ( self::COUNTRY_LABELS as $iso => $label ) {
			$option = $this->option_for_value( $country, $iso );
			self::assertNotNull( $option, 'Missing ISO option ' . $iso );
			self::assertSame( $iso, $option->getAttribute( 'value' ) );
			self::assertStringContainsString( $label, (string) $option->textContent );
			self::assertNotSame( $label, $option->getAttribute( 'value' ) );
		}

		$template_country = $this->named_control( $dom, 'destination_rules[{{index}}][country_code]' );
		self::assertNotNull( $template_country );
		self::assertTrue( $template_country->hasAttribute( 'disabled' ) );
		self::assertNotNull( $this->option_for_value( $template_country, 'DE' ) );
		self::assertNull( $this->successful_control( $dom, self::FORM_ID, 'destination_rules[{{index}}][country_code]' ) );
	}

	public function test_error_state_preserves_form_ownership_and_blank_numeric_advanced_fields(): void {
		$offers = new InMemoryDeliveryOfferRepository();
		$page   = $this->offers_page( $offers );

		$_POST = $this->offer_post(
			[
				'public_label'        => 'Delivery to Madina Zone',
				'code'                => '!!!',
				'processing_min_days' => '',
				'processing_max_days' => '',
				'transit_min_days'    => '',
				'transit_max_days'    => '',
				'final_mile_min_days' => '',
				'final_mile_max_days' => '',
			]
		);
		$this->capture_redirect( static fn () => $page->handle_actions() );

		$_GET = [
			'page'   => DeliveryOffersPage::SLUG,
			'action' => 'add',
		];
		$html = $this->capture_render( $page );

		self::assertStringNotContainsString( 'There has been a critical error', $html );
		$dom = $this->assert_entity_form_ownership(
			$html,
			[
				'public_label',
				'code',
				'processing_min_days',
				'cetech_de_save',
			],
			'Create Delivery Option',
			'Back to Delivery Options'
		);

		$payload = $this->successful_controls( $dom, self::FORM_ID );
		self::assertSame( 'Delivery to Madina Zone', $payload['public_label'] ?? null );
		self::assertSame( '!!!', $payload['code'] ?? null );
		self::assertSame( '', $payload['processing_min_days'] ?? 'missing' );
		self::assertSame( '', $payload['transit_min_days'] ?? 'missing' );
		self::assertSame( '', $payload['final_mile_min_days'] ?? 'missing' );
		self::assertArrayHasKey( 'processing_min_days', $payload );
	}

	public function test_delivery_area_error_state_keeps_country_iso_field(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$rules = new InMemoryDestinationRuleRepository();
		$page  = $this->areas_page( $zones, $rules );

		$_POST = $this->area_post(
			[
				'name' => 'Germany Test Area',
				'code' => '!!!',
				'destination_rules' => [
					0 => [
						'rule_type'    => 'country',
						'country_code' => 'DE',
						'rule_value'   => '',
						'match_mode'   => 'exact',
						'priority'     => 100,
					],
				],
			]
		);
		$this->capture_redirect( static fn () => $page->handle_actions() );

		$_GET = [
			'page'   => DestinationZonesPage::SLUG,
			'action' => 'add',
		];
		$html = $this->capture_render( $page );
		$dom  = $this->assert_entity_form_ownership(
			$html,
			[
				'name',
				'code',
				'destination_rules[0][country_code]',
				'cetech_de_save',
			],
			'Create Delivery Area',
			'Back to Delivery Areas'
		);

		$country = $this->named_control( $dom, 'destination_rules[0][country_code]' );
		self::assertNotNull( $country );
		$selected = $this->selected_option( $country );
		self::assertNotNull( $selected );
		self::assertSame( 'DE', $selected->getAttribute( 'value' ) );
		self::assertStringContainsString( 'Germany', (string) $selected->textContent );
	}

	/**
	 * @param list<string> $required_names
	 */
	private function assert_entity_form_ownership(
		string $html,
		array $required_names,
		string $primary_label,
		string $back_label
	): DOMDocument {
		$dom   = $this->parse_html( $html );
		$xpath = new DOMXPath( $dom );

		$entity_forms = $xpath->query( '//form[@id="' . self::FORM_ID . '"]' );
		self::assertNotFalse( $entity_forms );
		self::assertSame( 1, $entity_forms->length, 'Exactly one entity form id is required.' );
		$entity_form = $entity_forms->item( 0 );
		self::assertInstanceOf( DOMElement::class, $entity_form );

		foreach ( $xpath->query( '//form' ) as $form ) {
			self::assertInstanceOf( DOMElement::class, $form );
			$parent = $form->parentNode;
			while ( $parent instanceof DOMElement ) {
				self::assertNotSame( 'form', strtolower( $parent->tagName ), 'Nested form detected.' );
				$parent = $parent->parentNode;
			}
		}

		$header_submits = $xpath->query( '//header//input[@type="submit"][@name="cetech_de_save"]' );
		self::assertNotFalse( $header_submits );
		self::assertSame( 1, $header_submits->length, 'Exactly one header Create/Save submit is required.' );
		$header_submit = $header_submits->item( 0 );
		self::assertInstanceOf( DOMElement::class, $header_submit );
		self::assertSame( self::FORM_ID, $header_submit->getAttribute( 'form' ) );
		self::assertSame( $primary_label, $header_submit->getAttribute( 'value' ) );
		self::assertNull( $this->ancestor_form( $header_submit ), 'Header submit must sit outside the entity form and use the form attribute.' );
		self::assertStringContainsString( 'button-primary', $header_submit->getAttribute( 'class' ) );

		$visible_primaries = $xpath->query( '//input[@type="submit"][@name="cetech_de_save"][contains(concat(" ", normalize-space(@class), " "), " button-primary ")]' );
		self::assertNotFalse( $visible_primaries );
		self::assertSame( 1, $visible_primaries->length, 'Only one visible primary Create/Save action is allowed.' );

		$toolbar = $xpath->query( './/div[contains(concat(" ", normalize-space(@class), " "), " cetech-de-entity-form-toolbar ")]//input[@type="submit"][@name="cetech_de_save"]', $entity_form );
		self::assertNotFalse( $toolbar );
		self::assertSame( 0, $toolbar->length, 'Duplicate in-form toolbar Create/Save must not be present.' );

		$footer_submits = $xpath->query( './/div[contains(concat(" ", normalize-space(@class), " "), " cetech-de-form-actions ")]//input[@type="submit"]', $entity_form );
		self::assertNotFalse( $footer_submits );
		self::assertSame( 0, $footer_submits->length, 'Footer must not show a competing primary submit.' );

		$native = $xpath->query( './/input[@type="submit"][@name="cetech_de_save"][contains(concat(" ", normalize-space(@class), " "), " cetech-de-entity-form-native-submit ")]', $entity_form );
		self::assertNotFalse( $native );
		self::assertSame( 1, $native->length, 'A hidden native submit must remain inside the entity form.' );
		$native_submit = $native->item( 0 );
		self::assertInstanceOf( DOMElement::class, $native_submit );
		self::assertStringNotContainsString( 'button-primary', $native_submit->getAttribute( 'class' ) );

		$cancel = $xpath->query( './/div[contains(concat(" ", normalize-space(@class), " "), " cetech-de-form-actions ")]//a[contains(normalize-space(.), "Cancel")]', $entity_form );
		self::assertNotFalse( $cancel );
		self::assertGreaterThan( 0, $cancel->length, 'Cancel must remain as a secondary action.' );

		$back_links = $xpath->query( '//header//a[contains(normalize-space(.), "' . $back_label . '")]' );
		self::assertNotFalse( $back_links );
		self::assertGreaterThan( 0, $back_links->length );

		$payload = $this->successful_controls( $dom, self::FORM_ID );
		foreach ( $required_names as $name ) {
			self::assertArrayHasKey( $name, $payload, 'Successful control missing from entity form: ' . $name );
		}

		self::assertArrayHasKey( 'cetech_de_action', $payload );
		self::assertArrayHasKey( 'cetech_de_nonce', $payload );
		self::assertTrue(
			array_key_exists( 'public_label', $payload ) || array_key_exists( 'name', $payload ),
			'Entity name/label must belong to the entity form.'
		);
		self::assertGreaterThan( 4, count( $payload ), 'Header submit must not post only nonce/action.' );

		return $dom;
	}

	private function parse_html( string $html ): DOMDocument {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		self::assertTrue( $loaded, 'Rendered markup must parse as HTML.' );

		return $dom;
	}

	/**
	 * HTML5 form-owner algorithm subset used by browsers: explicit form=""
	 * attribute, otherwise nearest ancestor form. Disabled controls do not
	 * submit.
	 *
	 * @return array<string, string>
	 */
	private function successful_controls( DOMDocument $dom, string $form_id ): array {
		$payload = [];
		$xpath   = new DOMXPath( $dom );
		$nodes   = $xpath->query( '//input[@name] | //select[@name] | //textarea[@name]' );
		self::assertNotFalse( $nodes );

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			if ( $node->hasAttribute( 'disabled' ) ) {
				continue;
			}
			$owner = $this->form_owner( $dom, $node );
			if ( ! $owner instanceof DOMElement || $owner->getAttribute( 'id' ) !== $form_id ) {
				continue;
			}
			$name = $node->getAttribute( 'name' );
			if ( '' === $name ) {
				continue;
			}
			$payload[ $name ] = $this->control_value( $node );
		}

		return $payload;
	}

	private function successful_control( DOMDocument $dom, string $form_id, string $name ): ?DOMElement {
		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//*[@name="' . $name . '"]' );
		if ( false === $nodes ) {
			return null;
		}
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof DOMElement || $node->hasAttribute( 'disabled' ) ) {
				continue;
			}
			$owner = $this->form_owner( $dom, $node );
			if ( $owner instanceof DOMElement && $owner->getAttribute( 'id' ) === $form_id ) {
				return $node;
			}
		}

		return null;
	}

	private function form_owner( DOMDocument $dom, DOMElement $control ): ?DOMElement {
		if ( $control->hasAttribute( 'form' ) ) {
			$id = $control->getAttribute( 'form' );
			if ( '' === $id ) {
				return null;
			}
			$match = ( new DOMXPath( $dom ) )->query( '//form[@id="' . $id . '"]' );

			return $match && $match->item( 0 ) instanceof DOMElement ? $match->item( 0 ) : null;
		}

		return $this->ancestor_form( $control );
	}

	private function ancestor_form( DOMElement $node ): ?DOMElement {
		$parent = $node->parentNode;
		while ( $parent instanceof DOMElement ) {
			if ( 'form' === strtolower( $parent->tagName ) ) {
				return $parent;
			}
			$parent = $parent->parentNode;
		}

		return null;
	}

	private function named_control( DOMDocument $dom, string $name ): ?DOMElement {
		$nodes = ( new DOMXPath( $dom ) )->query( '//*[@name="' . $name . '"]' );
		if ( false === $nodes || 0 === $nodes->length ) {
			return null;
		}
		$item = $nodes->item( 0 );

		return $item instanceof DOMElement ? $item : null;
	}

	private function option_for_value( DOMElement $select, string $value ): ?DOMElement {
		foreach ( $select->getElementsByTagName( 'option' ) as $option ) {
			if ( $option instanceof DOMElement && $option->getAttribute( 'value' ) === $value ) {
				return $option;
			}
		}

		return null;
	}

	private function selected_option( DOMElement $select ): ?DOMElement {
		foreach ( $select->getElementsByTagName( 'option' ) as $option ) {
			if ( $option instanceof DOMElement && $option->hasAttribute( 'selected' ) ) {
				return $option;
			}
		}

		return null;
	}

	private function control_value( DOMElement $node ): string {
		$tag = strtolower( $node->tagName );
		if ( 'textarea' === $tag ) {
			return (string) $node->textContent;
		}
		if ( 'select' === $tag ) {
			$selected = $this->selected_option( $node );
			if ( $selected instanceof DOMElement ) {
				return $selected->getAttribute( 'value' );
			}
			$first = $node->getElementsByTagName( 'option' )->item( 0 );

			return $first instanceof DOMElement ? $first->getAttribute( 'value' ) : '';
		}

		return $node->getAttribute( 'value' );
	}

	private function render_offers_add(): string {
		$_GET = [
			'page'   => DeliveryOffersPage::SLUG,
			'action' => 'add',
		];

		return $this->capture_render( $this->offers_page( new InMemoryDeliveryOfferRepository() ) );
	}

	private function render_areas_add(): string {
		$_GET = [
			'page'   => DestinationZonesPage::SLUG,
			'action' => 'add',
		];

		return $this->capture_render(
			$this->areas_page( new InMemoryDestinationZoneRepository(), new InMemoryDestinationRuleRepository() )
		);
	}

	private function capture_render( DeliveryOffersPage|DestinationZonesPage $page ): string {
		ob_start();
		try {
			$page->render();

			return (string) ob_get_clean();
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			throw $exception;
		}
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
}
