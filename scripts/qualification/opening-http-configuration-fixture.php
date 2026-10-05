<?php

declare(strict_types=1);

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

/**
 * Qualification-only preparation and SQL observer for real COR-002 HTTP forms.
 *
 * Native WP-CLI setup is separate from HTTP writes under the restricted principal.
 * All products are synthetic, tracked and disposable. No HTTP handlers are patched.
 * Ordinary successful audit only: no COR-007 transaction/failure/revision claim.
 */
require __DIR__ . '/opening-http-fixture-common.php';

/** @var list<string> $args WP-CLI eval-file positional arguments. */
if ( ! isset( $args ) || ! is_array( $args ) || count( $args ) < 3 ) {
	throw new RuntimeException( 'Expected MODE STATE OUTPUT [CAPABILITY_ENABLED].' );
}
[ $config_mode, $config_state_path, $config_output_path ] = $args;
if ( ! in_array( $config_mode, [ 'prepareconfig', 'snapshotconfig', 'configcaps', 'cleanupconfig' ], true ) ) {
	throw new RuntimeException( 'Unknown HTTP configuration fixture mode.' );
}

function opening_http_configuration_snapshot( array $state ): array {
	global $wpdb;
	$user_id = (int) $state['user_id'];
	$original_user_id = get_current_user_id();
	wp_set_current_user( 0 );
	wp_set_current_user( $user_id );
	try {
	$options_sql = $wpdb->prepare(
		"SELECT option_name, option_value, autoload FROM `{$wpdb->options}` WHERE option_name IN (%s,%s,%s,%s) ORDER BY option_name",
		ScopedConfigurationSchema::GLOBAL_VERSION_OPTION,
		'cetech_de_db_version',
		'cetech_de_capabilities_version',
		'cetech_opening_qualification_disposable'
	);
	$options = $wpdb->get_results( $options_sql, ARRAY_A );
	if ( '' !== $wpdb->last_error || ! is_array( $options ) ) {
		throw new RuntimeException( 'Configuration options evidence query failed.' );
	}
	$scopes = opening_http_sql_rows( ScopedConfigurationSchema::SCOPES_SUFFIX );
	$fields = opening_http_sql_rows( ScopedConfigurationSchema::FIELDS_SUFFIX );
	$collections = opening_http_sql_rows( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );
	$audit = opening_http_sql_rows( 'audit_log', 'entity_type = %s AND actor_user_id = %d', [ 'configuration_scope', $user_id ] );
	$role_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", $wpdb->prefix . 'user_roles' ) );
	$user_raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM `{$wpdb->usermeta}` WHERE user_id = %d AND meta_key = %s", $user_id, $wpdb->prefix . 'capabilities' ) );
	$notice_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", '_transient_cetech_de_admin_notice_' . $user_id ) );
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Configuration principal or notice SQL evidence query failed.' );
	}
	$roles = maybe_unserialize( $role_raw );
	$user_caps = maybe_unserialize( $user_raw );
	$notice = maybe_unserialize( $notice_raw );
	if ( ! is_array( $roles ) || ! is_array( $user_caps ) ) {
		throw new RuntimeException( 'Native persisted configuration capability evidence missing.' );
	}
	$role_grants = ! empty( $roles[ $state['role'] ]['capabilities']['manage_product_delivery_rules'] );
	$user_override = array_key_exists( 'manage_product_delivery_rules', $user_caps ) ? (bool) $user_caps['manage_product_delivery_rules'] : null;
	$effective_gate = $role_grants && false !== $user_override;
	return [
		'scopes' => $scopes,
		'fields' => $fields,
		'collections' => $collections,
		'audit' => $audit,
		'options' => $options,
		'hashes' => [
			'scopes' => opening_http_hash( $scopes ),
			'fields' => opening_http_hash( $fields ),
			'collections' => opening_http_hash( $collections ),
			'audit' => opening_http_hash( $audit ),
			'options' => opening_http_hash( $options ),
		],
		'authority' => [
			'product_gate' => current_user_can( 'manage_product_delivery_rules' ),
			'persisted_role_product_gate' => $role_grants,
			'persisted_user_override' => $user_override,
			'persisted_effective_matches_native' => $effective_gate === current_user_can( 'manage_product_delivery_rules' ),
			'own_parent' => current_user_can( 'edit_post', (int) $state['parent_id'] ),
			'wrong_owned_parent' => current_user_can( 'edit_post', (int) $state['wrong_parent_id'] ),
			'foreign_parent' => current_user_can( 'edit_post', (int) $state['foreign_parent_id'] ),
			'own_variation' => current_user_can( 'edit_post', (int) $state['variation_id'] ),
			'foreign_parent_variation_native_primitive' => current_user_can( 'edit_post', (int) $state['foreign_variation_id'] ),
			'global_gate' => current_user_can( 'manage_site_wide_defaults' ),
			'private_sources' => current_user_can( 'manage_private_sources' ),
		],
		'flash_notice' => is_array( $notice ) ? [ 'type' => (string) ( $notice['type'] ?? '' ), 'message' => (string) ( $notice['message'] ?? '' ) ] : null,
		'private_snapshot' => opening_http_private_snapshot(),
	];
	} finally {
		wp_set_current_user( 0 );
		wp_set_current_user( $original_user_id );
	}
}

if ( 'prepareconfig' === $config_mode ) {
	if ( file_exists( $config_state_path ) ) {
		throw new RuntimeException( 'Refusing to overwrite a configuration fixture principal.' );
	}
	$identity = opening_http_identity();
	$probe = (string) getenv( 'CETECH_DE_HTTP_PROBE_TOKEN' );
	if ( 1 !== preg_match( '/^[a-f0-9]{48}$/D', $probe ) ) {
		throw new RuntimeException( 'Configuration preparation requires the guarded listener probe token.' );
	}
	update_option( 'cetech_opening_http_qualification_disposable', '1', false );
	$suffix = strtolower( wp_generate_password( 10, false, false ) );
	$role_name = 'cetech_opening_http_config_' . $suffix;
	$username = $role_name . '_user';
	$password = wp_generate_password( 40, true, true );
	$product_type = get_post_type_object( 'product' );
	$variation_type = get_post_type_object( 'product_variation' );
	if ( ! $product_type instanceof WP_Post_Type || ! $variation_type instanceof WP_Post_Type ) {
		throw new RuntimeException( 'Native WooCommerce product types are unavailable.' );
	}
	$positive_caps = array_values( array_unique( [
		'read', 'view_admin_dashboard', 'view_delivery_engine', 'manage_product_delivery_rules',
		$product_type->cap->edit_posts, $product_type->cap->edit_published_posts,
		$variation_type->cap->edit_post,
	] ) );
	$role_caps = array_fill_keys( $positive_caps, true );
	$role_caps[ $product_type->cap->edit_others_posts ] = false;
	$role_caps['manage_site_wide_defaults'] = false;
	$role_caps['manage_private_sources'] = false;
	if ( null === add_role( $role_name, 'Opening HTTP configuration qualification', $role_caps ) ) {
		throw new RuntimeException( 'Native configuration role creation failed.' );
	}
	$user_id = wp_insert_user( [
		'user_login' => $username, 'user_pass' => $password,
		'user_email' => $username . '@example.invalid', 'role' => $role_name,
	] );
	if ( is_wp_error( $user_id ) || (int) $user_id <= 0 ) {
		remove_role( $role_name );
		throw new RuntimeException( 'Native configuration principal creation failed.' );
	}
	$administrator_ids = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC' ] );
	$foreign_author = (int) ( $administrator_ids[0] ?? 0 );
	if ( $foreign_author <= 0 || $foreign_author === (int) $user_id ) {
		throw new RuntimeException( 'Disposable administrator fixture author missing.' );
	}
	$baseline = [
		'scopes' => opening_http_sql_rows( ScopedConfigurationSchema::SCOPES_SUFFIX ),
		'fields' => opening_http_sql_rows( ScopedConfigurationSchema::FIELDS_SUFFIX ),
		'collections' => opening_http_sql_rows( ScopedConfigurationSchema::COLLECTIONS_SUFFIX ),
		'private_snapshot' => opening_http_private_snapshot(),
	];
	// A failure during the allocations below before private state is complete
	// is cleaned by disposal of the entire dedicated CI site/database, not by
	// an untracked product/configuration sweep or a claimed successful cleanup.
	$make_product = static function ( string $label, int $author, ?int $parent = null ): int {
		$product = null === $parent ? new WC_Product_Variable() : new WC_Product_Variation();
		$product->set_name( $label );
		$product->set_status( 'publish' );
		if ( null !== $parent ) {
			$product->set_parent_id( $parent );
			$product->set_regular_price( '1' );
		}
		$id = $product->save();
		$updated = wp_update_post( [ 'ID' => $id, 'post_author' => $author ], true );
		if ( $id <= 0 || is_wp_error( $updated ) || (int) get_post_field( 'post_author', $id ) !== $author ) {
			throw new RuntimeException( 'Synthetic native configuration product creation failed.' );
		}
		return $id;
	};
	$parent_id = $make_product( $role_name . ' own parent', (int) $user_id );
	$wrong_parent_id = $make_product( $role_name . ' wrong owned parent', (int) $user_id );
	$foreign_parent_id = $make_product( $role_name . ' foreign parent', $foreign_author );
	$variation_id = $make_product( $role_name . ' own variation', (int) $user_id, $parent_id );
	$foreign_variation_id = $make_product( $role_name . ' own variation foreign parent', (int) $user_id, $foreign_parent_id );
	$repository = Plugin::instance()->container()->get( ScopedConfigurationRepositoryInterface::class );
	$seed = static function ( ConfigurationScopeType $type, int $id, string $slice, string $estimate, ?int $parent = null ) use ( $repository ): ScopedConfiguration {
		return $repository->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, $type, $id, $slice, $parent, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, $estimate ) ],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] ) ]
		) );
	};
	// No default variation slice: the actual exception row represents in_store,
	// first in native repository order, with in_warehouse as protected sibling.
	$selected = $seed( ConfigurationScopeType::Variation, $variation_id, 'in_store', 'Opening HTTP before save', $parent_id );
	$seed( ConfigurationScopeType::Variation, $variation_id, 'in_warehouse', 'Opening HTTP protected sibling', $parent_id );
	$seed( ConfigurationScopeType::Product, $parent_id, 'in_store', 'Opening HTTP protected parent' );
	$seed( ConfigurationScopeType::Variation, $foreign_variation_id, 'in_store', 'Opening HTTP foreign parent variation', $foreign_parent_id );
	$state = [
		'format' => 'cetech-opening-http-configuration-state-v1',
		'site_path' => realpath( ABSPATH ), 'database_name' => DB_NAME,
		'base_url' => home_url( '/' ), 'username' => $username, 'password' => $password,
		'user_id' => (int) $user_id, 'role' => $role_name, 'positive_caps' => $positive_caps,
		'probe_token' => $probe,
		'parent_id' => $parent_id, 'wrong_parent_id' => $wrong_parent_id,
		'foreign_parent_id' => $foreign_parent_id, 'foreign_author_id' => $foreign_author,
		'variation_id' => $variation_id, 'foreign_variation_id' => $foreign_variation_id,
		'selected_scope_row_id' => $selected->scope->id,
		'product_ids' => [ $variation_id, $foreign_variation_id, $parent_id, $wrong_parent_id, $foreign_parent_id ],
		'identity' => $identity,
		'baseline' => $baseline,
		'tracked_scope_row_ids' => array_map( static fn ( array $row ): int => (int) $row['id'], array_values( array_filter(
			opening_http_sql_rows( ScopedConfigurationSchema::SCOPES_SUFFIX ),
			static fn ( array $row ): bool => in_array( (int) $row['scope_id'], [ $parent_id, $variation_id, $foreign_variation_id ], true )
		) ) ),
	];
	opening_http_write_json( $config_state_path, $state, true );
	$snapshot = opening_http_configuration_snapshot( $state );
	opening_http_write_json( $config_output_path, [
		'identity' => $identity,
		'fixture' => array_diff_key( $state, array_flip( [ 'password', 'username', 'probe_token', 'baseline' ] ) ),
		'grants' => opening_http_grants( (int) $user_id, $role_name, array_merge( $positive_caps, [ $product_type->cap->edit_others_posts, 'manage_site_wide_defaults', 'manage_private_sources' ] ) ),
		'post_type_capabilities' => [ 'product' => (array) $product_type->cap, 'variation' => (array) $variation_type->cap ],
		'posts' => array_map( static fn ( int $id ): array => [
			'id' => $id, 'type' => get_post_type( $id ), 'status' => get_post_status( $id ),
			'author' => (int) get_post_field( 'post_author', $id ), 'parent' => (int) get_post_field( 'post_parent', $id ),
		], $state['product_ids'] ),
		'snapshot' => $snapshot,
		'limit' => 'Synthetic published WooCommerce fixtures, separate restricted principal; ordinary successful audit only.',
	] );
} else {
	$state = opening_http_read_state( $config_state_path );
	opening_http_identity();
	if ( 'cetech-opening-http-configuration-state-v1' !== ( $state['format'] ?? '' )
		|| 1 !== preg_match( '/^cetech_opening_http_config_[a-z0-9]{10}$/D', (string) ( $state['role'] ?? '' ) )
		|| ( $state['username'] ?? '' ) !== $state['role'] . '_user'
		|| ! is_int( $state['user_id'] ?? null ) || $state['user_id'] <= 0
		|| ! is_array( $state['product_ids'] ?? null ) || 5 !== count( $state['product_ids'] )
		|| 5 !== count( array_unique( $state['product_ids'] ) )
		|| array_filter( $state['product_ids'], static fn ( mixed $id ): bool => ! is_int( $id ) || $id <= 0 )
		|| ! is_array( $state['tracked_scope_row_ids'] ?? null ) || [] === $state['tracked_scope_row_ids']
		|| 4 !== count( $state['tracked_scope_row_ids'] ) || 4 !== count( array_unique( $state['tracked_scope_row_ids'] ) )
		|| array_filter( $state['tracked_scope_row_ids'], static fn ( mixed $id ): bool => ! is_int( $id ) || $id <= 0 )
		|| ! is_array( $state['baseline'] ?? null )
		|| array_diff( [ 'scopes', 'fields', 'collections', 'private_snapshot' ], array_keys( $state['baseline'] ) )
		|| ! is_int( $state['selected_scope_row_id'] ?? null ) || ! in_array( $state['selected_scope_row_id'], $state['tracked_scope_row_ids'], true )
		|| $state['product_ids'] !== [ $state['variation_id'] ?? null, $state['foreign_variation_id'] ?? null, $state['parent_id'] ?? null, $state['wrong_parent_id'] ?? null, $state['foreign_parent_id'] ?? null ]
	) {
		throw new RuntimeException( 'Malformed tracked configuration fixture state.' );
	}
	$tracked_user = get_user_by( 'id', $state['user_id'] );
	if ( false === $tracked_user || $tracked_user->user_login !== $state['username'] || ! in_array( $state['role'], $tracked_user->roles, true ) ) {
		throw new RuntimeException( 'Configuration state no longer matches its exclusive principal.' );
	}
	if ( 'configcaps' === $config_mode ) {
		$enabled = $args[3] ?? null;
		if ( ! in_array( $enabled, [ '0', '1' ], true ) ) {
			throw new RuntimeException( 'configcaps requires 0 or 1.' );
		}
		$user = new WP_User( (int) $state['user_id'] );
		$user->add_cap( 'manage_product_delivery_rules', '1' === $enabled );
		wp_cache_delete( (int) $state['user_id'], 'user_meta' );
		opening_http_write_json( $config_output_path, [ 'snapshot' => opening_http_configuration_snapshot( $state ) ] );
	} elseif ( 'snapshotconfig' === $config_mode ) {
		opening_http_write_json( $config_output_path, [ 'snapshot' => opening_http_configuration_snapshot( $state ) ] );
	} else {
		$repository = Plugin::instance()->container()->get( ScopedConfigurationRepositoryInterface::class );
		foreach ( $state['product_ids'] as $id ) {
			foreach ( [ ConfigurationScopeType::Product, ConfigurationScopeType::Variation ] as $type ) {
				foreach ( $repository->findByScope( $type, (int) $id ) as $scope ) {
					if ( ! in_array( $scope->scope->id, $state['tracked_scope_row_ids'], true )
						|| ! $repository->deleteScope( $type, (int) $id, $scope->scope->slice_key ) ) {
						throw new RuntimeException( 'Tracked configuration scope cleanup failed or encountered an untracked scope.' );
					}
				}
			}
			$product = wc_get_product( (int) $id );
			if ( $product instanceof WC_Product ) {
				$product->delete( true );
			}
			if ( null !== get_post( (int) $id ) ) {
				throw new RuntimeException( 'Tracked native configuration product cleanup failed.' );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		delete_transient( 'cetech_de_admin_notice_' . $state['user_id'] );
		if ( ! wp_delete_user( (int) $state['user_id'] ) ) {
			throw new RuntimeException( 'Tracked native configuration principal cleanup failed.' );
		}
		remove_role( (string) $state['role'] );
		$remaining_scopes = opening_http_sql_rows( ScopedConfigurationSchema::SCOPES_SUFFIX );
		$remaining_fields = opening_http_sql_rows( ScopedConfigurationSchema::FIELDS_SUFFIX );
		$remaining_collections = opening_http_sql_rows( ScopedConfigurationSchema::COLLECTIONS_SUFFIX );
		$baseline = $state['baseline'];
		$tracked_row_ids = $state['tracked_scope_row_ids'];
		$no_orphan_fields = [] === array_filter( $remaining_fields, static fn ( array $row ): bool => in_array( (int) $row['scope_row_id'], $tracked_row_ids, true ) );
		$no_orphan_collections = [] === array_filter( $remaining_collections, static fn ( array $row ): bool => in_array( (int) $row['scope_row_id'], $tracked_row_ids, true ) );
		$unrelated_preserved = $remaining_scopes === $baseline['scopes'] && $remaining_fields === $baseline['fields']
			&& $remaining_collections === $baseline['collections'] && opening_http_private_snapshot() === $baseline['private_snapshot'];
		if ( ! $no_orphan_fields || ! $no_orphan_collections || ! $unrelated_preserved
			|| false !== get_userdata( (int) $state['user_id'] ) || null !== get_role( (string) $state['role'] ) ) {
			throw new RuntimeException( 'Configuration fixture cleanup residual or unrelated mutation detected.' );
		}
		opening_http_write_json( $config_output_path, [
			'cleanup_restored' => $no_orphan_fields && $no_orphan_collections && $unrelated_preserved,
			'user_exists' => false !== get_userdata( (int) $state['user_id'] ),
			'role_exists' => null !== get_role( (string) $state['role'] ),
			'cleaned_user' => false === get_userdata( (int) $state['user_id'] ),
			'cleaned_role' => null === get_role( (string) $state['role'] ),
			'cleaned_products' => array_map( static fn ( int $id ): bool => null === get_post( $id ), $state['product_ids'] ),
			'no_tracked_field_orphans' => $no_orphan_fields,
			'no_tracked_collection_orphans' => $no_orphan_collections,
			'unrelated_configuration_and_private_sources_preserved' => $unrelated_preserved,
			'restored_hashes' => [ 'scopes' => opening_http_hash( $remaining_scopes ), 'fields' => opening_http_hash( $remaining_fields ), 'collections' => opening_http_hash( $remaining_collections ) ],
			'cleanup_scope' => 'Tracked synthetic configuration scopes/fields/collections, products, user and role removed; unrelated configuration/private rows preserved. Scoped audit and ancillary native WordPress state remain until CI site disposal; not whole-database restoration.',
			'limit' => 'Scoped audit evidence retained until the disposable CI site is discarded; no COR-007 failure/transaction/revision policy claim.',
		] );
		unlink( $config_state_path );
	}
}
echo wp_json_encode( [ 'mode' => $config_mode, 'output' => basename( $config_output_path ), 'credentials_printed' => false ] ) . "\n";
