<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyAdminWorkflow;
use CetechDeliveryEngine\Application\PolicyAssignment\PolicyAssignmentAdminWorkflow;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPolicyAssignmentAdminIntent;
use CetechDeliveryEngine\Presentation\Admin\PolicyAssignmentPage;
use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyAdminWorkflow;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Bootstrap\ServiceContainer;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRefundPolicyAdminIntent;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbReturnPolicyAdminIntent;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Presentation\Admin\RefundPolicyPage;
use CetechDeliveryEngine\Presentation\Admin\ReturnPolicyPage;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual Plugin factories and AdminMenu APIs; WordPress hooks/menu storage are unit doubles. */
final class PolicyAssignmentNativeCompositionTest extends TestCase {

	use RestoresWordPressFixtureGlobals;
	private bool $had_database;
	private mixed $previous_database;
	private object $database;
	private mixed $previous_vendor_isolation;

	protected function setUp(): void {
		$this->remember_fixture_globals();
		$this->had_database = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$this->database = new class {
			public string $prefix = 'wp_';
			public string $base_prefix = 'wp_';
			public string $options = 'wp_options';
			public int $database_calls = 0;
			public function __call( string $method, array $arguments ): never {
				++$this->database_calls;
				throw new \LogicException( 'A boot/menu request must not open policy storage.' );
			}
		};
		$GLOBALS['wpdb'] = $this->database;
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_delivery_settings' => true, Capabilities::SITE_WIDE => true ];
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_user_id'] = 7;
		$GLOBALS['cetech_de_test_actions'] = [];
		$GLOBALS['cetech_de_test_filters'] = [];
		$GLOBALS['cetech_de_test_menus'] = [];
		$GLOBALS['cetech_de_test_submenus'] = [];
		$_GET = [];
		$this->previous_vendor_isolation = ( new \ReflectionProperty( AdminPageAccess::class, 'vendor_isolation' ) )->getValue();
		AdminPageAccess::bind( null );
		if ( ! defined( 'CETECH_DE_PATH' ) ) { define( 'CETECH_DE_PATH', dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR ); }
		if ( ! defined( 'CETECH_DE_FILE' ) ) { define( 'CETECH_DE_FILE', CETECH_DE_PATH . 'cetech-woocommerce-delivery-engine.php' ); }
		if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', CETECH_DE_PATH ); }
	}

	protected function tearDown(): void {
		AdminPageAccess::bind( $this->previous_vendor_isolation );
		$this->restore_fixture_globals();
		if ( $this->had_database ) { $GLOBALS['wpdb'] = $this->previous_database; } else { unset( $GLOBALS['wpdb'] ); }
	}

	public function test_real_container_resolves_all_three_editors_without_binding_native_storage(): void {
		$container = $this->container();
		$return = $container->get( ReturnPolicyPage::class );
		$refund = $container->get( RefundPolicyPage::class );
		$assignment = $container->get( PolicyAssignmentPage::class );
		self::assertInstanceOf( PolicyAssignmentPage::class, $assignment );
		self::assertSame( $assignment, $container->get( PolicyAssignmentPage::class ) );
		self::assertInstanceOf( ReturnPolicyPage::class, $return );
		self::assertInstanceOf( RefundPolicyPage::class, $refund );
		self::assertSame( $return, $container->get( ReturnPolicyPage::class ) );
		self::assertSame( $refund, $container->get( RefundPolicyPage::class ) );
		self::assertNotSame( $return, $refund );
		self::assertInstanceOf( AdminMenu::class, $container->get( AdminMenu::class ) );
		self::assertTrue( $container->get( ReturnPolicyAdminWorkflow::class )->can_access() );
		self::assertTrue( $container->get( RefundPolicyAdminWorkflow::class )->can_access() );
		self::assertTrue( $container->get( PolicyAssignmentAdminWorkflow::class )->can_access() );
		self::assertSame( 0, $this->database->database_calls );
		self::assertSame( [], $GLOBALS['cetech_de_test_options'] );
	}

	public function test_actual_admin_registration_mounts_each_distinct_editor_once_without_running_it(): void {
		$container = $this->container();
		$menu = $container->get( AdminMenu::class );
		$menu->register();
		$callbacks = array_column( $GLOBALS['cetech_de_test_actions']['admin_init'], 'callback' );
		foreach ( [ ReturnPolicyPage::class, RefundPolicyPage::class, PolicyAssignmentPage::class ] as $page ) {
			$expected = [ $container->get( $page ), 'handle_actions' ];
			self::assertCount( 1, array_filter( $callbacks, static fn ( mixed $callback ): bool => $callback === $expected ) );
		}
		self::assertCount( 1, array_filter( $GLOBALS['cetech_de_test_actions']['admin_menu'], static fn ( array $hook ): bool => [ $menu, 'add_menus' ] === $hook['callback'] ) );
		self::assertSame( 0, $this->database->database_calls );
		self::assertSame( [], $GLOBALS['cetech_de_test_options'] );
	}

	#[DataProvider( 'menu_capabilities' )]
	public function test_all_three_submenus_require_the_existing_capability_pair( array $caps, bool $allowed ): void {
		$GLOBALS['cetech_de_test_caps'] = $caps;
		$container = $this->container();
		$container->get( AdminMenu::class )->add_menus();
		foreach ( [ ReturnPolicyPage::SLUG => 'Return policies', RefundPolicyPage::SLUG => 'Refund policies', PolicyAssignmentPage::SLUG => 'Policy assignments' ] as $slug => $title ) {
			$rows = array_values( array_filter( $GLOBALS['cetech_de_test_submenus'], static fn ( array $row ): bool => $slug === $row['menu_slug'] ) );
			self::assertCount( $allowed ? 1 : 0, $rows );
			if ( $allowed ) {
				self::assertSame( $title, $rows[0]['page_title'] );
				self::assertSame( 'manage_delivery_settings', $rows[0]['capability'] );
			}
		}
		self::assertSame( $allowed, $container->get( ReturnPolicyAdminWorkflow::class )->can_access() );
		self::assertSame( $allowed, $container->get( RefundPolicyAdminWorkflow::class )->can_access() );
		self::assertSame( $allowed, $container->get( PolicyAssignmentAdminWorkflow::class )->can_access() );
		self::assertSame( 0, $this->database->database_calls );
		self::assertSame( [], $GLOBALS['cetech_de_test_options'] );
	}

	public static function menu_capabilities(): array {
		return [
			'both existing grants' => [ [ 'manage_delivery_settings' => true, Capabilities::SITE_WIDE => true ], true ],
			'settings only' => [ [ 'manage_delivery_settings' => true ], false ],
			'site-wide only' => [ [ Capabilities::SITE_WIDE => true ], false ],
			'ordinary administrator options grant' => [ [ 'administrator' => true, 'manage_options' => true ], false ],
		];
	}

	#[DataProvider( 'native_journals' )]
	public function test_unsupported_native_route_is_refused_only_when_the_corresponding_journal_is_used( string $class ): void {
		$container = $this->container();
		self::assertInstanceOf( AdminMenu::class, $container->get( AdminMenu::class ) );
		$journal = $container->get( $class );
		$called = false;
		try {
			$journal->locked( $class::name( 1, 7 ), static function () use ( &$called ): void { $called = true; } );
			self::fail( 'An unsupported native database route was accepted.' );
		} catch ( \RuntimeException ) {
			self::assertFalse( $called );
		}
		self::assertSame( 0, $this->database->database_calls );
		self::assertSame( [], $GLOBALS['cetech_de_test_options'] );
	}

	public static function native_journals(): array {
		return [ 'return' => [ WpdbReturnPolicyAdminIntent::class ], 'refund' => [ WpdbRefundPolicyAdminIntent::class ], 'assignments' => [ WpdbPolicyAssignmentAdminIntent::class ] ];
	}

	private function container(): ServiceContainer {
		$reflection = new \ReflectionClass( Plugin::class );
		$plugin = $reflection->newInstanceWithoutConstructor();
		$container = new ServiceContainer();
		$reflection->getProperty( 'container' )->setValue( $plugin, $container );
		$reflection->getMethod( 'register_services' )->invoke( $plugin );
		return $container;
	}
}
