<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationTargetGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;

/**
 * Native COR-002 qualification in a disposable WordPress/WooCommerce database.
 *
 * This composes the production target guard with production save/reset services.
 * It does not dispatch an HTTP form, browser cookie, page redirect or admin hook.
 * Audit failure coupling, transactions and stale revisions are not exercised.
 * Native fixtures remain in this database until the runner discards the site.
 *
 * @return Closure(callable(string, bool, array<string, mixed>): void): void
 */
return static function ( callable $check ): void {
	global $wpdb;

	$check(
		'NATIVE-COR002-DISPOSABLE-ENVIRONMENT',
		'1' === getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
			&& '1' === (string) get_option( 'cetech_opening_qualification_disposable', '' )
			&& defined( 'DB_NAME' ) && str_starts_with( DB_NAME, 'cetech_wp_opening_qualification' ),
		[ 'database' => defined( 'DB_NAME' ) ? DB_NAME : null, 'proof_level' => 'native helpers and persisted services; no HTTP dispatch' ]
	);
	$check(
		'NATIVE-COR002-NATIVE-BOOTSTRAP',
		$wpdb instanceof wpdb && function_exists( 'wc_get_product' ) && class_exists( 'WC_Product_Variation' )
			&& is_admin() && class_exists( Plugin::class ),
		[ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null ]
	);

	$container = Plugin::instance()->container();
	$repository = $container->get( ScopedConfigurationRepositoryInterface::class );
	$service = $container->get( ScopedConfigurationAdminService::class );
	$authorization = $container->get( ScopedConfigurationAuthorization::class );
	$scope_guard = $container->get( ProductVariationScopeGuard::class );
	$target_guard = new ScopedConfigurationTargetGuard( $scope_guard, $authorization );
	$check(
		'NATIVE-COR002-PRODUCTION-SERVICES',
		$repository instanceof WpdbScopedConfigurationRepository && $service instanceof ScopedConfigurationAdminService
			&& $authorization instanceof ScopedConfigurationAuthorization && $scope_guard instanceof ProductVariationScopeGuard,
		[ 'repository' => get_class( $repository ), 'construction' => 'Plugin::instance()->container(); native, non-injected authorization' ]
	);

	$scopes_table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX );
	$fields_table = TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX );
	$collections_table = TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );
	$audit_table = TableNames::for( 'audit_log' );
	$sql_rows = static function ( string $sql ) use ( $wpdb ): array {
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Native configuration SQL evidence query failed: ' . $wpdb->last_error );
		}
		return $rows;
	};
	$old_user_id = get_current_user_id();
	$old_post = $_POST;
	$suffix = strtolower( wp_generate_password( 8, false, false ) );
	$prefix = 'cetech_opening_qa_config_' . $suffix;

	try {
		$make_user = static function ( string $name ) use ( $prefix, $check ): int {
			$user_id = wp_insert_user( [
				'user_login' => $prefix . '_' . $name,
				'user_pass' => wp_generate_password( 32, true, true ),
				'user_email' => $prefix . '_' . $name . '@example.invalid',
				'display_name' => $prefix . '_' . $name,
				'role' => 'subscriber',
			] );
			$check( 'NATIVE-COR002-PRINCIPAL-' . strtoupper( $name ), ! is_wp_error( $user_id ) && (int) $user_id > 0, [ 'persisted_user_id' => is_wp_error( $user_id ) ? null : $user_id ] );
			$user = new WP_User( (int) $user_id );
			$product_type = get_post_type_object( 'product' );
			if ( ! $product_type instanceof WP_Post_Type ) {
				throw new RuntimeException( 'WooCommerce product post type is not registered.' );
			}
			$variation_type = get_post_type_object( 'product_variation' );
			if ( ! $variation_type instanceof WP_Post_Type ) {
				throw new RuntimeException( 'WooCommerce variation post type is not registered.' );
			}
			// Product/parent ownership uses mapped primitives. WooCommerce 11.1.2
			// variations have map_meta_cap=false and require singular edit_product.
			// This native primitive alone never authorizes the parent in our guard.
			$user->add_cap( $variation_type->cap->edit_post );
			$user->add_cap( $product_type->cap->edit_posts );
			$user->add_cap( $product_type->cap->edit_published_posts );
			$user->add_cap( $product_type->cap->edit_others_posts, false );
			$user->add_cap( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT );
			return (int) $user_id;
		};
		$owner_id = $make_user( 'owner' );
		$foreign_id = $make_user( 'foreign' );
		$select_user = static function ( int $user_id ): void {
			wp_set_current_user( 0 );
			wp_set_current_user( $user_id );
		};
		$select_user( $owner_id );
		$make_product = static function ( string $name, int $author, bool $variable = false, ?int $parent = null ) use ( $prefix, $check ): int {
			$product = null !== $parent ? new WC_Product_Variation() : ( $variable ? new WC_Product_Variable() : new WC_Product_Simple() );
			$product->set_name( $prefix . '_' . $name );
			$product->set_status( 'publish' );
			if ( null !== $parent ) {
				$product->set_parent_id( $parent );
			}
			$product_id = $product->save();
			$updated = wp_update_post( [ 'ID' => $product_id, 'post_author' => $author ], true );
			$check(
				'NATIVE-COR002-FIXTURE-' . strtoupper( $name ),
				$product_id > 0 && ! is_wp_error( $updated ) && (int) get_post_field( 'post_author', $product_id ) === $author
					&& ( null === $parent || (int) wc_get_product( $product_id )->get_parent_id() === $parent ),
				[ 'post_id' => $product_id, 'post_type' => get_post_type( $product_id ), 'author' => (int) get_post_field( 'post_author', $product_id ), 'parent' => (int) get_post_field( 'post_parent', $product_id ) ]
			);
			return $product_id;
		};
		$owned_product = $make_product( 'owned_product', $owner_id );
		$foreign_product = $make_product( 'foreign_product', $foreign_id );
		$owned_parent = $make_product( 'owned_parent', $owner_id, true );
		$other_owned_parent = $make_product( 'other_owned_parent', $owner_id, true );
		$foreign_parent = $make_product( 'foreign_parent', $foreign_id, true );
		$owned_variation = $make_product( 'owned_variation', $owner_id, false, $owned_parent );
		$foreign_variation = $make_product( 'foreign_variation', $foreign_id, false, $foreign_parent );
		$own_authored_foreign_parent_variation = $make_product( 'own_authored_foreign_parent_variation', $owner_id, false, $foreign_parent );
		$fixture_ids = [ $owned_product, $foreign_product, $owned_parent, $other_owned_parent, $foreign_parent, $owned_variation, $foreign_variation, $own_authored_foreign_parent_variation ];
		$id_placeholders = implode( ', ', array_fill( 0, count( $fixture_ids ), '%d' ) );
		$scope_condition = $wpdb->prepare( "(s.scope_type = 'global' OR s.scope_id IN ({$id_placeholders}))", ...$fixture_ids );
		$options_sql = $wpdb->prepare(
			"SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name IN (%s, %s, %s, %s) ORDER BY option_name",
			ScopedConfigurationSchema::GLOBAL_VERSION_OPTION, 'cetech_de_db_version', 'cetech_de_capabilities_version', 'cetech_opening_qualification_disposable'
		);
		$audit_sql = $wpdb->prepare( "SELECT * FROM `{$audit_table}` WHERE entity_type = 'configuration_scope' AND actor_user_id IN (%d, %d) ORDER BY id", $owner_id, $foreign_id );
		$snapshot = static function () use ( $sql_rows, $scopes_table, $fields_table, $collections_table, $scope_condition, $options_sql, $audit_sql ): array {
			return [
				'scopes' => $sql_rows( "SELECT s.* FROM `{$scopes_table}` s WHERE {$scope_condition} ORDER BY s.id" ),
				'fields' => $sql_rows( "SELECT f.* FROM `{$fields_table}` f JOIN `{$scopes_table}` s ON f.scope_row_id = s.id WHERE {$scope_condition} ORDER BY f.id" ),
				'collections' => $sql_rows( "SELECT c.* FROM `{$collections_table}` c JOIN `{$scopes_table}` s ON c.scope_row_id = s.id WHERE {$scope_condition} ORDER BY c.id" ),
				'audit' => $sql_rows( $audit_sql ),
				'options' => $sql_rows( $options_sql ),
			];
		};
		$cap_rows = $sql_rows( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM `{$wpdb->usermeta}` WHERE user_id IN (%d, %d) AND meta_key = %s ORDER BY user_id", $owner_id, $foreign_id, $wpdb->prefix . 'capabilities' ) );
		$product_type = get_post_type_object( 'product' );
		$variation_type = get_post_type_object( 'product_variation' );
		$matrix = [
			'plugin_gate_allowed' => current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ),
			'owned_product_edit_allowed' => current_user_can( 'edit_post', $owned_product ),
			'foreign_product_edit_denied' => ! current_user_can( 'edit_post', $foreign_product ),
			'owned_parent_edit_allowed' => current_user_can( 'edit_post', $owned_parent ),
			'foreign_parent_edit_denied' => ! current_user_can( 'edit_post', $foreign_parent ),
			'owned_variation_native_primitive_allowed' => current_user_can( 'edit_post', $owned_variation ),
			'foreign_variation_native_primitive_allowed' => current_user_can( 'edit_post', $foreign_variation ),
			'own_authored_foreign_parent_variation_native_primitive_allowed' => current_user_can( 'edit_post', $own_authored_foreign_parent_variation ),
			'global_gate_denied' => ! current_user_can( ScopedConfigurationAuthorization::CAPABILITY_GLOBAL ),
			'two_persisted_capability_rows' => count( $cap_rows ) === 2,
			'product_ownership_mapping_enabled' => true === $product_type->map_meta_cap,
			'variation_primitive_mapping_observed' => false === $variation_type->map_meta_cap && 'edit_product' === $variation_type->cap->edit_post,
		];
		$check(
			'NATIVE-COR002-NATIVE-META-CAP-MATRIX',
			! in_array( false, $matrix, true ),
			[
				'actor' => $owner_id, 'conditions' => $matrix, 'persisted_capability_rows' => $cap_rows,
				'post_type_metadata' => [
					'product' => [ 'map_meta_cap' => $product_type->map_meta_cap, 'capabilities' => (array) $product_type->cap ],
					'product_variation' => [ 'map_meta_cap' => $variation_type->map_meta_cap, 'capabilities' => (array) $variation_type->cap ],
				],
				'own_product_mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $owned_product ),
				'foreign_product_mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $foreign_product ),
				'own_parent_mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $owned_parent ),
				'foreign_parent_mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $foreign_parent ),
				'own_variation_mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $owned_variation ),
				'foreign_variation_mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $foreign_variation ),
				'limit' => 'Native Product/Parent ownership and a separate native Variation primitive; the production guard separately enforces parent access.',
			]
		);

		$seed = static function ( ConfigurationScopeType $type, int $id, string $slice, int $priority, ?int $parent = null ) use ( $repository ): ScopedConfiguration {
			return $repository->saveScopedConfiguration( new ScopedConfiguration(
				new ConfigurationScope( null, $type, $id, $slice, $parent, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority ) ],
				[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] ) ]
			) );
		};
		$foreign_config = $seed( ConfigurationScopeType::Product, $foreign_product, 'in_store', 4 );
		$default = $seed( ConfigurationScopeType::Variation, $owned_variation, '', 5, $owned_parent );
		$selected = $seed( ConfigurationScopeType::Variation, $owned_variation, 'in_store', 6, $owned_parent );
		$warehouse = $seed( ConfigurationScopeType::Variation, $owned_variation, 'in_warehouse', 7, $owned_parent );
		$protected_before = $snapshot();
		$check( 'NATIVE-COR002-PERSISTED-SCOPE-FIXTURES', count( $protected_before['fields'] ) >= 4 && count( $protected_before['collections'] ) >= 4 && $selected->scope->id > 0, [ 'rows' => $protected_before ] );

		$deny = static function ( string $id, array $input, bool $allow_picker = false, ?string $expected_reason = null ) use ( $target_guard, $snapshot, $check ): void {
			$before = $snapshot();
			$denied = false;
			$message = null;
			try {
				$target_guard->resolve( $input, $allow_picker );
			} catch ( InvalidArgumentException $exception ) {
				$denied = true;
				$message = $exception->getMessage();
			}
			$after = $snapshot();
			$check( 'NATIVE-COR002-' . $id, $denied && $before === $after && ( null === $expected_reason || str_contains( (string) $message, $expected_reason ) ), [ 'input' => $input, 'rejected' => $denied, 'reason' => $message, 'expected_reason_contains' => $expected_reason, 'before' => $before, 'after' => $after, 'proof_level' => 'native guard denies before composed service call; no HTTP dispatch' ] );
		};
		$deny( 'FOREIGN-PRODUCT-DENIED', [ 'scope_type' => 'product', 'scope_id' => $foreign_product, 'slice_key' => 'in_store' ] );
		$deny( 'FOREIGN-VARIATION-DENIED', [ 'scope_type' => 'variation', 'scope_id' => $foreign_variation, 'parent_product_id' => $foreign_parent, 'slice_key' => 'in_store' ], false, 'permission to edit the parent product' );
		$deny( 'WRONG-AUTHORIZED-PARENT-DENIED', [ 'scope_type' => 'variation', 'scope_id' => $owned_variation, 'parent_product_id' => $other_owned_parent, 'slice_key' => 'in_store' ] );
		$deny( 'PARENT-AUTHORITY-DENIED', [ 'scope_type' => 'variation', 'scope_id' => $own_authored_foreign_parent_variation, 'parent_product_id' => $foreign_parent, 'slice_key' => 'in_store' ], false, 'permission to edit the parent product' );
		$deny( 'PARENT-BEARING-EMPTY-PICKER-DENIED', [ 'scope_type' => 'variation', 'parent_product_id' => $foreign_parent ], true );
		$check( 'NATIVE-COR002-PARENT-NATIVE-PERMISSIONS', current_user_can( 'edit_post', $own_authored_foreign_parent_variation ) && ! current_user_can( 'edit_post', $foreign_parent ), [ 'actor' => $owner_id, 'parent' => $foreign_parent, 'variation' => $own_authored_foreign_parent_variation, 'native_variation_edit_allowed' => current_user_can( 'edit_post', $own_authored_foreign_parent_variation ), 'native_parent_edit_allowed' => current_user_can( 'edit_post', $foreign_parent ), 'note' => 'Variation native edit is permitted by its singular primitive; the production guard rejects the actual foreign parent explicitly.' ] );
		$deny( 'EXPLICIT-GLOBAL-CAPABILITY-DENIED', [ 'scope_type' => 'global', 'scope_id' => 0 ] );
		$missing_product = (int) $wpdb->get_var( "SELECT MAX(ID) FROM `{$wpdb->posts}`" ) + 10000;
		$deny( 'MISSING-PRODUCT-DENIED', [ 'scope_type' => 'product', 'scope_id' => $missing_product ] );

		$owned_input = [ 'scope_type' => 'product', 'scope_id' => $owned_product, 'slice_key' => '' ];
		$malformed = [
			'UNKNOWN-SCOPE' => [ 'scope_type' => 'unknown' ],
			'ARRAY-SCOPE' => [ 'scope_type' => [ 'product' ] ],
			'NEGATIVE-ID' => [ 'scope_id' => -$owned_product ],
			'NEGATIVE-STRING-ID' => [ 'scope_id' => '-' . $owned_product ],
			'LOSSY-STRING-ID' => [ 'scope_id' => $owned_product . 'junk' ],
			'LEADING-ZERO-ID' => [ 'scope_id' => '0' . $owned_product ],
			'OVERFLOW-ID' => [ 'scope_id' => '92233720368547758070' ],
			'ARRAY-ID' => [ 'scope_id' => [ $owned_product ] ],
			'FLOAT-ID' => [ 'scope_id' => (float) $owned_product ],
			'ZERO-PRODUCT-ID' => [ 'scope_id' => 0 ],
			'UNKNOWN-SLICE' => [ 'slice_key' => 'unknown' ],
			'ARRAY-SLICE' => [ 'slice_key' => [ 'in_store' ] ],
			'PRODUCT-WITH-PARENT' => [ 'parent_product_id' => $owned_parent ],
			'ARRAY-PARENT' => [ 'parent_product_id' => [ $owned_parent ] ],
			'PRODUCT-WITH-VARIATION-ID' => [ 'scope_id' => $owned_variation ],
		];
		foreach ( $malformed as $name => $override ) {
			$deny( 'MALFORMED-' . $name . '-DENIED', array_replace( $owned_input, $override ) );
		}
		$deny( 'VARIATION-WITH-PRODUCT-ID-DENIED', [ 'scope_type' => 'variation', 'scope_id' => $owned_product, 'parent_product_id' => $owned_parent ] );
		$deny( 'VARIATION-WITHOUT-PARENT-DENIED', [ 'scope_type' => 'variation', 'scope_id' => $owned_variation ] );
		$pick_before = $snapshot();
		$picker = $target_guard->resolve( [ 'scope_type' => 'variation' ], true );
		$check( 'NATIVE-COR002-PLAIN-PICKER-POSITIVE', ConfigurationScopeType::Variation === $picker['scope_type'] && 0 === $picker['scope_id'] && null === $picker['parent_product_id'] && $snapshot() === $pick_before, [ 'target' => [ 'scope_type' => $picker['scope_type']->value, 'scope_id' => $picker['scope_id'], 'parent_product_id' => $picker['parent_product_id'] ], 'before' => $pick_before, 'after' => $snapshot() ] );

		// Native nonce helper is real, but this is not a browser/session submission.
		$_POST = [ 'cetech_de_nonce' => wp_create_nonce( ScopedConfigurationPage::ACTION_SAVE ) ];
		$check( 'NATIVE-COR002-NATIVE-SAVE-NONCE-POSITIVE', [] === $authorization->verify_write( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT, ScopedConfigurationPage::ACTION_SAVE, true ), [ 'native_nonce_verified' => true, 'proof_level' => 'native helper in WP-CLI principal context; no cookie or HTTP form' ] );
		$check( 'NATIVE-COR002-SWAPPED-NONCE-DENIED', [] !== $authorization->verify_write( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT, ScopedConfigurationPage::ACTION_RESET, true ) );
		$_POST = [];
		$check( 'NATIVE-COR002-MISSING-NONCE-DENIED', [] !== $authorization->verify_write( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT, ScopedConfigurationPage::ACTION_SAVE, true ) );

		$before_save = $snapshot();
		$target = $target_guard->resolve( $owned_input );
		$saved = $service->save( new ScopedConfigurationWriteCommand( $target['scope_type'], $target['scope_id'], $target['slice_key'], $target['parent_product_id'], [ ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '9' ] ], false, 0, null, 0 ) );
		$after_save = $snapshot();
		$reloaded = $repository->findByScopeAndSlice( ConfigurationScopeType::Product, $owned_product, '' );
		$check(
			'NATIVE-COR002-OWNED-PRODUCT-SAVE-PERSISTED',
			$saved->success && $saved->audit_recorded && null !== $reloaded && 9 === $reloaded->scalars[ ConfigurationFieldKey::PRIORITY ]->value
				&& count( $after_save['audit'] ) === count( $before_save['audit'] ) + 1 && $after_save['options'] === $before_save['options']
				&& $repository->findByScopeAndSlice( ConfigurationScopeType::Product, $foreign_product, 'in_store' )?->fingerprint() === $foreign_config->fingerprint(),
			[ 'actor' => $owner_id, 'scope_row_id' => $reloaded?->scope->id, 'before' => $before_save, 'after' => $after_save, 'proof_level' => 'guard + production service, not page POST handler' ]
		);

		$select_user( $foreign_id );
		$check( 'NATIVE-COR002-SECOND-PRINCIPAL-MATRIX', current_user_can( 'edit_post', $foreign_product ) && ! current_user_can( 'edit_post', $owned_product ), [ 'actor' => $foreign_id, 'owned_post' => $foreign_product, 'foreign_post' => $owned_product ] );
		$deny( 'SECOND-PRINCIPAL-FOREIGN-PRODUCT-DENIED', $owned_input );
		$second_target = $target_guard->resolve( [ 'scope_type' => 'product', 'scope_id' => $foreign_product, 'slice_key' => 'in_store' ] );
		$check( 'NATIVE-COR002-SECOND-PRINCIPAL-OWNED-POSITIVE', $second_target['scope_id'] === $foreign_product, [ 'actor' => $foreign_id, 'scope_id' => $second_target['scope_id'] ] );
		$select_user( $owner_id );

		$principal = new WP_User( $owner_id );
		$principal->remove_cap( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT );
		$select_user( $owner_id );
		$revoked_caps = $sql_rows( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key = %s", $owner_id, $wpdb->prefix . 'capabilities' ) );
		$revoked_stored_caps = 1 === count( $revoked_caps ) ? maybe_unserialize( $revoked_caps[0]['meta_value'] ) : null;
		$check( 'NATIVE-COR002-PERSISTED-CAPABILITY-REVOCATION', ! current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ) && current_user_can( 'edit_post', $owned_product ) && is_array( $revoked_stored_caps ) && ! isset( $revoked_stored_caps[ ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ] ), [ 'actor' => $owner_id, 'native_edit_post_remains_allowed' => current_user_can( 'edit_post', $owned_product ), 'plugin_capability_now_allowed' => current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ), 'persisted_capability_rows' => $revoked_caps ] );
		$deny( 'CURRENT-CAPABILITY-REVOCATION-DENIED', $owned_input );
		$principal->add_cap( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT );
		$select_user( $owner_id );
		$product_type = get_post_type_object( 'product' );
		$principal->remove_cap( $product_type->cap->edit_published_posts );
		$select_user( $owner_id );
		$check( 'NATIVE-COR002-NATIVE-OBJECT-CAPABILITY-REVOCATION', current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ) && ! current_user_can( 'edit_post', $owned_product ), [ 'actor' => $owner_id, 'revoked_native_primitive' => $product_type->cap->edit_published_posts, 'plugin_capability_remains_allowed' => current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ), 'mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $owned_product ) ] );
		$deny( 'CURRENT-OBJECT-CAPABILITY-REVOCATION-DENIED', $owned_input );
		$principal->add_cap( $product_type->cap->edit_published_posts );
		$select_user( $owner_id );

		// Qualify native variation permission independently from parent ownership.
		$variation_primitive = $variation_type->cap->edit_post;
		$principal->remove_cap( $variation_primitive );
		$select_user( $owner_id );
		$variation_revoked_rows = $sql_rows( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key = %s", $owner_id, $wpdb->prefix . 'capabilities' ) );
		$variation_revoked_stored_caps = 1 === count( $variation_revoked_rows ) ? maybe_unserialize( $variation_revoked_rows[0]['meta_value'] ) : null;
		$check(
			'NATIVE-COR002-NATIVE-VARIATION-PRIMITIVE-REVOCATION',
			current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ) && current_user_can( 'edit_post', $owned_parent )
				&& ! current_user_can( 'edit_post', $owned_variation ) && is_array( $variation_revoked_stored_caps ) && ! isset( $variation_revoked_stored_caps[ $variation_primitive ] ),
			[ 'actor' => $owner_id, 'revoked_native_primitive' => $variation_primitive, 'plugin_gate_allowed' => current_user_can( ScopedConfigurationAuthorization::CAPABILITY_PRODUCT ), 'owned_parent_edit_allowed' => current_user_can( 'edit_post', $owned_parent ), 'owned_variation_edit_allowed' => current_user_can( 'edit_post', $owned_variation ), 'mapped_caps' => map_meta_cap( 'edit_post', $owner_id, $owned_variation ), 'persisted_capability_rows' => $variation_revoked_rows ]
		);
		$variation_input = [ 'scope_type' => 'variation', 'scope_id' => $owned_variation, 'parent_product_id' => $owned_parent, 'slice_key' => 'in_store' ];
		$deny( 'OWNED-VARIATION-MISSING-NATIVE-PRIMITIVE-DENIED', $variation_input, false, 'permission to edit this item' );
		$principal->add_cap( $variation_primitive );
		$select_user( $owner_id );
		$variation_positive_before = $snapshot();
		$variation_positive = $target_guard->resolve( $variation_input );
		$check( 'NATIVE-COR002-NATIVE-VARIATION-PRIMITIVE-RESTORED-POSITIVE', current_user_can( 'edit_post', $owned_variation ) && current_user_can( 'edit_post', $owned_parent ) && $variation_positive['scope_id'] === $owned_variation && $variation_positive['parent_product_id'] === $owned_parent && $variation_positive_before === $snapshot(), [ 'actor' => $owner_id, 'native_primitive_restored' => $variation_primitive, 'variation' => $owned_variation, 'parent' => $owned_parent, 'before' => $variation_positive_before, 'after' => $snapshot() ] );

		// Exact native variation slice reset, with field/collection SQL deletion and siblings retained.
		$before_reset = $snapshot();
		$reset_target = $target_guard->resolve( [ 'scope_type' => 'variation', 'scope_id' => $owned_variation, 'parent_product_id' => $owned_parent, 'slice_key' => 'in_store' ] );
		$reset = $service->reset( $reset_target['scope_type'], $reset_target['scope_id'], $reset_target['slice_key'], $reset_target['parent_product_id'], $selected->scope->config_version, null, $selected->scope->id );
		$after_reset = $snapshot();
		$selected_row_id = (int) $selected->scope->id;
		$selected_fields_after = $sql_rows( $wpdb->prepare( "SELECT * FROM `{$fields_table}` WHERE scope_row_id = %d ORDER BY id", $selected_row_id ) );
		$selected_collections_after = $sql_rows( $wpdb->prepare( "SELECT * FROM `{$collections_table}` WHERE scope_row_id = %d ORDER BY id", $selected_row_id ) );
		$reset_audit = $sql_rows( $wpdb->prepare( "SELECT * FROM `{$audit_table}` WHERE entity_type = 'configuration_scope' AND entity_id = %d AND action = 'scoped_configuration_reset' ORDER BY id", $selected_row_id ) );
		$retained_scopes = array_values( array_filter( $before_reset['scopes'], static fn ( array $row ): bool => (int) $row['id'] !== $selected_row_id ) );
		$retained_fields = array_values( array_filter( $before_reset['fields'], static fn ( array $row ): bool => (int) $row['scope_row_id'] !== $selected_row_id ) );
		$retained_collections = array_values( array_filter( $before_reset['collections'], static fn ( array $row ): bool => (int) $row['scope_row_id'] !== $selected_row_id ) );
		$check(
			'NATIVE-COR002-EXACT-SLICE-RESET-PERSISTED',
			$reset && null === $repository->findByScopeAndSlice( ConfigurationScopeType::Variation, $owned_variation, 'in_store' )
				&& [] === $selected_fields_after && [] === $selected_collections_after
				&& $repository->findByScopeAndSlice( ConfigurationScopeType::Variation, $owned_variation, '' )?->fingerprint() === $default->fingerprint()
				&& $repository->findByScopeAndSlice( ConfigurationScopeType::Variation, $owned_variation, 'in_warehouse' )?->fingerprint() === $warehouse->fingerprint()
				&& $after_reset['scopes'] === $retained_scopes && $after_reset['fields'] === $retained_fields && $after_reset['collections'] === $retained_collections
				&& count( $after_reset['scopes'] ) === count( $before_reset['scopes'] ) - 1 && $after_reset['options'] === $before_reset['options'],
			[ 'scope_row_id' => $selected_row_id, 'selected_slice' => 'in_store', 'before' => $before_reset, 'after' => $after_reset, 'deleted_fields_remaining' => $selected_fields_after, 'deleted_collections_remaining' => $selected_collections_after ]
		);
		$previous_audit = 1 === count( $reset_audit ) ? json_decode( (string) $reset_audit[0]['previous_value'], true ) : null;
		$new_audit = 1 === count( $reset_audit ) ? json_decode( (string) $reset_audit[0]['new_value'], true ) : null;
		$check(
			'NATIVE-COR002-ORDINARY-RESET-AUDIT-PERSISTED',
			1 === count( $reset_audit ) && (int) $reset_audit[0]['actor_user_id'] === $owner_id
				&& count( $after_reset['audit'] ) === count( $before_reset['audit'] ) + 1
				&& is_array( $previous_audit ) && 'variation' === ( $previous_audit['scope_type'] ?? null )
				&& $owned_variation === ( $previous_audit['scope_id'] ?? null ) && 'in_store' === ( $previous_audit['slice_key'] ?? null )
				&& $owned_parent === ( $previous_audit['parent_product_id'] ?? null ) && $selected->scope->config_version === ( $previous_audit['config_version'] ?? null )
				&& 6 === ( $previous_audit['scalars'][ ConfigurationFieldKey::PRIORITY ]['value'] ?? null )
				&& [] === ( $previous_audit['collections'][ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]['members'] ?? null )
				&& is_array( $new_audit ) && true === ( $new_audit['inherits'] ?? null ) && 'in_store' === ( $new_audit['slice_key'] ?? null )
				&& 'variation' === ( $new_audit['scope_type'] ?? null ) && $owned_variation === ( $new_audit['scope_id'] ?? null )
				&& $owned_parent === ( $new_audit['parent_product_id'] ?? null ),
			[ 'audit_rows' => $reset_audit, 'decoded_previous' => $previous_audit, 'decoded_new' => $new_audit, 'limit' => 'ordinary successful append only; no transaction or audit failure policy proof' ]
		);
		$before_noop = $snapshot();
		$noop = $service->reset( $reset_target['scope_type'], $reset_target['scope_id'], $reset_target['slice_key'], $reset_target['parent_product_id'] );
		$check( 'NATIVE-COR002-RESET-NOOP-NO-AUDIT', ! $noop && $before_noop === $snapshot(), [ 'before' => $before_noop, 'after' => $snapshot() ] );
	} finally {
		$_POST = $old_post;
		wp_set_current_user( 0 );
		wp_set_current_user( $old_user_id );
	}
};
