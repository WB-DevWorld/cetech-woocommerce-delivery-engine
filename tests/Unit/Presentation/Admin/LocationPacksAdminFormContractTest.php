<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\LocationPacksPage;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Issue #23 geo.16 — Location Packs admin forms must satisfy the shared
 * AdminActionHandler / AdminFormHelper POST contract.
 *
 * Assertions parse actual rendered HTML, not PHP source strings.
 */
final class LocationPacksAdminFormContractTest extends TestCase {

	private const ACTION_INSTALL = 'cetech_de_install_location_pack';

	private const ACTION_TICK = 'cetech_de_tick_location_pack';

	private const ACTION_RECONCILE = 'cetech_de_reconcile_legacy_coverage';

	protected function setUp(): void {
		parent::setUp();
		$_POST  = [];
		$_GET   = [];
		$_FILES = [];
		$GLOBALS['cetech_de_test_is_admin']          = true;
		$GLOBALS['cetech_de_test_logged_in']         = true;
		$GLOBALS['cetech_de_test_user_id']           = 7;
		$GLOBALS['cetech_de_test_caps']              = [ 'manage_delivery_zones' => true ];
		$GLOBALS['cetech_de_test_redirects']         = [];
		$GLOBALS['cetech_de_test_transients']        = [];
		$GLOBALS['cetech_de_test_options']           = [];
		$GLOBALS['cetech_de_test_as_enqueue_attempts'] = 0;
		$GLOBALS['wpdb']                             = new FakeWpdb();
	}

	protected function tearDown(): void {
		$_POST  = [];
		$_GET   = [];
		$_FILES = [];
		unset(
			$GLOBALS['cetech_de_test_is_admin'],
			$GLOBALS['cetech_de_test_logged_in'],
			$GLOBALS['cetech_de_test_user_id'],
			$GLOBALS['cetech_de_test_caps'],
			$GLOBALS['cetech_de_test_redirects'],
			$GLOBALS['cetech_de_test_transients'],
			$GLOBALS['cetech_de_test_options'],
			$GLOBALS['cetech_de_test_as_enqueue_attempts'],
			$GLOBALS['wpdb']
		);
		parent::tearDown();
	}

	public function test_action_constants_match_the_shared_handler_contract(): void {
		$reflection = new ReflectionClass( LocationPacksPage::class );
		self::assertSame( self::ACTION_INSTALL, $reflection->getConstant( 'ACTION_INSTALL' ) );
		self::assertSame( self::ACTION_TICK, $reflection->getConstant( 'ACTION_TICK' ) );
		self::assertSame( self::ACTION_RECONCILE, $reflection->getConstant( 'ACTION_RECONCILE' ) );
	}

	public function test_install_form_html_contains_shared_action_and_nonce(): void {
		$payload = $this->form_payload( $this->render_html( $this->harness()['page'] ), self::ACTION_INSTALL );
		self::assertSame( self::ACTION_INSTALL, $payload['cetech_de_action'] ?? null );
		self::assertSame( 'test-nonce-' . self::ACTION_INSTALL, $payload['cetech_de_nonce'] ?? null );
	}

	public function test_continue_retry_form_html_contains_shared_action_and_nonce(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$payload = $this->form_payload( $this->render_html( $harness['page'] ), self::ACTION_TICK );
		self::assertSame( self::ACTION_TICK, $payload['cetech_de_action'] ?? null );
		self::assertSame( 'test-nonce-' . self::ACTION_TICK, $payload['cetech_de_nonce'] ?? null );
		self::assertSame( '1', $payload['pack_id'] ?? null );
		self::assertSame( 'retry', $payload['pack_op'] ?? null );
	}

	public function test_reconciliation_form_html_contains_shared_action_and_nonce(): void {
		$payload = $this->form_payload( $this->render_html( $this->harness()['page'] ), self::ACTION_RECONCILE );
		self::assertSame( self::ACTION_RECONCILE, $payload['cetech_de_action'] ?? null );
		self::assertSame( 'test-nonce-' . self::ACTION_RECONCILE, $payload['cetech_de_nonce'] ?? null );
	}

	public function test_natural_install_post_invokes_location_pack_install(): void {
		$harness = $this->harness();
		$_POST   = $this->install_post();
		$harness['page']->handle_actions();

		$pack = $harness['packs']->find_by_country_provider( 'GH', GeographyProvider::GeoNames );
		self::assertNotNull( $pack );
		self::assertSame( 'GH', $pack->country_code );
		self::assertGreaterThan( 0, (int) $GLOBALS['cetech_de_test_as_enqueue_attempts'] );
		self::assertStringContainsString( 'queued', (string) ( $this->notice()['message'] ?? '' ) );
	}

	public function test_natural_retry_post_invokes_retry_and_tick(): void {
		$harness = $this->harness();
		$pack    = $this->seed_failed_pack( $harness['packs'] );
		$_POST   = $this->tick_post( $pack->id );
		$harness['page']->handle_actions();

		$after = $harness['packs']->find_by_id( $pack->id );
		self::assertNotNull( $after );
		self::assertGreaterThan( 0, (int) $GLOBALS['cetech_de_test_as_enqueue_attempts'] );
		self::assertStringContainsString( 'Gazetteer file is not readable', $after->last_error );
		self::assertNotSame( 'original-fail', $after->last_error );
	}

	public function test_natural_reconcile_post_reaches_schema6_reconcile(): void {
		$harness = $this->harness();
		$_POST   = $this->reconcile_post();
		$harness['page']->handle_actions();

		$state = get_option( Schema6CoverageUpgradeService::OPTION_KEY, [] );
		self::assertIsArray( $state );
		self::assertSame( Schema6CoverageUpgradeService::PASS_RECONCILE, (string) ( $state['pass_kind'] ?? '' ) );
		self::assertStringContainsString( 'reconciliation', strtolower( (string) ( $this->notice()['message'] ?? '' ) ) );
	}

	public function test_missing_action_does_not_mutate(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$_POST = $this->install_post();
		unset( $_POST['cetech_de_action'] );
		$harness['page']->handle_actions();
		$this->assert_no_mutation( $harness, 'original-fail' );
	}

	public function test_wrong_action_does_not_mutate(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$_POST = $this->install_post(
			[
				'cetech_de_action' => 'cetech_de_save_delivery_offer',
				'cetech_de_nonce'  => 'test-nonce-cetech_de_save_delivery_offer',
			]
		);
		$harness['page']->handle_actions();
		$this->assert_no_mutation( $harness, 'original-fail' );
	}

	public function test_missing_nonce_does_not_mutate(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$_POST = $this->install_post();
		unset( $_POST['cetech_de_nonce'] );
		$this->capture_redirect( static fn () => $harness['page']->handle_actions() );
		$this->assert_no_mutation( $harness, 'original-fail' );
	}

	public function test_invalid_nonce_does_not_mutate(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$_POST = $this->install_post( [ 'cetech_de_nonce' => 'not-a-valid-nonce' ] );
		$this->capture_redirect( static fn () => $harness['page']->handle_actions() );
		$this->assert_no_mutation( $harness, 'original-fail' );
	}

	public function test_capability_denial_does_not_mutate(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_options' => true ];
		$_POST                          = $this->install_post();
		$this->capture_redirect( static fn () => $harness['page']->handle_actions() );
		$this->assert_no_mutation( $harness, 'original-fail' );
	}

	public function test_get_request_does_not_mutate(): void {
		$harness = $this->harness();
		$this->seed_failed_pack( $harness['packs'] );
		$_GET  = $this->install_post();
		$_POST = [];
		$harness['page']->handle_actions();
		$this->assert_no_mutation( $harness, 'original-fail' );
	}

	/**
	 * @return array{page:LocationPacksPage,packs:InMemoryGeographyPackRepository}
	 */
	private function harness(): array {
		$packs     = new InMemoryGeographyPackRepository();
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$importer  = new GeoNamesPackImporter( $locations, $locations, $locations, $packs, $bootstrap );
		$service   = new GeographyPackService( $packs, $importer, $bootstrap );
		$upgrade   = new Schema6CoverageUpgradeService(
			$zones,
			$rules,
			$bootstrap,
			new LegacyDestinationCoverageMigrator(
				$zones,
				$rules,
				$groups,
				$locations,
				new CanonicalLocationResolver( $locations, $locations )
			)
		);

		return [
			'page'  => new LocationPacksPage(
				$service,
				new AdminActionHandler( new AdminNoticeService() ),
				$upgrade
			),
			'packs' => $packs,
		];
	}

	private function seed_failed_pack( InMemoryGeographyPackRepository $packs ): \CetechDeliveryEngine\Domain\Geography\GeographyPack {
		return $packs->save(
			[
				'country_code'     => 'NG',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Failed->value,
				'last_error'       => 'original-fail',
				'source_reference' => '',
			]
		);
	}

	private function render_html( LocationPacksPage $page ): string {
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();
		self::assertNotSame( '', $html );

		return $html;
	}

	/**
	 * @return array<string, string>
	 */
	private function form_payload( string $html, string $action ): array {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		self::assertTrue( $loaded, 'Rendered Location Packs markup must parse as HTML.' );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//form[.//input[@name="cetech_de_action" and @value="' . $action . '"]]' );
		self::assertNotFalse( $nodes );
		self::assertGreaterThan( 0, $nodes->length, 'Rendered HTML must contain a form for ' . $action );
		$form = $nodes->item( 0 );
		self::assertInstanceOf( DOMElement::class, $form );

		$payload = [];
		$inputs  = $xpath->query( './/input[@name] | .//select[@name]', $form );
		self::assertNotFalse( $inputs );
		foreach ( $inputs as $input ) {
			if ( ! $input instanceof DOMElement || $input->hasAttribute( 'disabled' ) ) {
				continue;
			}
			$name = $input->getAttribute( 'name' );
			if ( '' === $name ) {
				continue;
			}
			if ( 'select' === strtolower( $input->tagName ) ) {
				$selected = $xpath->query( './/option[@selected]', $input );
				$option   = $selected instanceof \DOMNodeList && $selected->item( 0 ) instanceof DOMElement
					? $selected->item( 0 )
					: $xpath->query( './/option', $input )->item( 0 );
				$payload[ $name ] = $option instanceof DOMElement ? $option->getAttribute( 'value' ) : '';
				continue;
			}
			$payload[ $name ] = $input->getAttribute( 'value' );
		}

		return $payload;
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>
	 */
	private function install_post( array $overrides = [] ): array {
		return array_merge(
			[
				'cetech_de_action' => self::ACTION_INSTALL,
				'cetech_de_nonce'  => 'test-nonce-' . self::ACTION_INSTALL,
				'country_code'     => 'GH',
				'pack_op'          => 'install',
			],
			$overrides
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function tick_post( int $pack_id ): array {
		return [
			'cetech_de_action' => self::ACTION_TICK,
			'cetech_de_nonce'  => 'test-nonce-' . self::ACTION_TICK,
			'pack_id'          => (string) $pack_id,
			'pack_op'          => 'retry',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function reconcile_post(): array {
		return [
			'cetech_de_action' => self::ACTION_RECONCILE,
			'cetech_de_nonce'  => 'test-nonce-' . self::ACTION_RECONCILE,
		];
	}

	private function capture_redirect( callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected a wp_safe_redirect after a rejected POST.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	/**
	 * @param array{page:LocationPacksPage,packs:InMemoryGeographyPackRepository} $harness
	 */
	private function assert_no_mutation( array $harness, string $expected_error ): void {
		self::assertNull( $harness['packs']->find_by_country_provider( 'GH', GeographyProvider::GeoNames ) );
		$existing = $harness['packs']->find_by_id( 1 );
		self::assertNotNull( $existing );
		self::assertSame( GeographyPackStatus::Failed, $existing->status );
		self::assertSame( $expected_error, $existing->last_error );
		self::assertSame( 0, (int) $GLOBALS['cetech_de_test_as_enqueue_attempts'] );
		self::assertFalse( get_option( Schema6CoverageUpgradeService::OPTION_KEY, false ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function notice(): array {
		$stored = get_transient( 'cetech_de_admin_notice_7' );

		return is_array( $stored ) ? $stored : [];
	}
}
