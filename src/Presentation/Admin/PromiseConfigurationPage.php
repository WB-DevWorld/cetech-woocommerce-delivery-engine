<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseConfigurationService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseJson, PromiseLimits, PromiseShape, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseVersionCommand;

/** Native protected configuration. No save or preview grants checkout admission. */
final class PromiseConfigurationPage {
	public const SLUG = 'cetech-de-promises';
	public const ACTION_PREFIX = 'cetech_de_promise_';
	public const VERBS = [ 'create', 'seal', 'publish', 'schedule', 'activate', 'retire', 'assignment', 'preview', 'inspect', 'assignment_inspect', 'configure', 'enable', 'disable', 'reconcile' ];
	private array $input = [];
	private ?array $opened = null;
	private ?array $preview = null;
	private ?array $assignment = null;
	private ?array $uncertain = null;
	private ?\CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding $disclosure_binding = null;
	private ?array $disclosure_scope = null;
	private string $message = '';
	private string $message_type = 'info';
	private ?PromiseShipmentConfigurationSection $shipments;

	public function __construct( private PromiseConfigurationService $service, private AdminActionHandler $actions, ?\CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseShipmentConfigurationService $shipment_service = null ) {
		$this->shipments = null === $shipment_service ? null : new PromiseShipmentConfigurationSection( $shipment_service, $actions );
	}

	/** Called on admin_init; a closed action and its own nonce gate every POST, including preview. */
	public function handle_actions(): void {
		if ( $this->shipments?->handles_action() ) { $this->shipments->handle_actions(); return; }
		$action = $_POST['cetech_de_action'] ?? null;
		if ( ! is_string( $action ) || ! str_starts_with( $action, self::ACTION_PREFIX ) ) { return; }
		$verb = substr( $action, strlen( self::ACTION_PREFIX ) );
		if ( ! in_array( $verb, self::VERBS, true ) || ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) { return; }
		$cap = current_user_can( 'manage_delivery_settings' ) ? 'manage_delivery_settings' : 'manage_product_delivery_rules';
		if ( ! $this->actions->verify_post( $action, $action, $cap, self::SLUG ) ) { return; }
		try {
			$this->input = self::parse_form( $_POST ); $binding = $this->service->binding( $this->input['site_key'] );
			if ( 'inspect' === $verb ) {
				$this->opened = $this->service->inspect_version( $binding, $this->input['kind'], $this->input['logical_id'], self::integer( $this->input['domain_version'], 1 ), $this->scope() );
				$this->disclosure_binding = $binding; $this->disclosure_scope = $this->scope();
				$this->message = __( 'The exact saved version and current revision are shown below. Changes create a new version.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( 'assignment_inspect' === $verb ) {
				$this->assignment = $this->service->inspect_assignment( $binding, $this->assignment_key() );
				$this->disclosure_binding = $binding; $this->disclosure_scope = $this->scope();
				$this->message = __( 'The exact assignment and its current revision are shown below.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( 'preview' === $verb ) {
				$calendar_document = PromiseJson::decode( $this->input['calendars_json'] ); PromiseShape::fields( $calendar_document, [ 'calendars' ] );
				$this->preview = $this->service->preview( $binding, $this->input['body_json'], $calendar_document['calendars'] );
				$this->disclosure_binding = $binding; $this->disclosure_scope = PromiseVersionCommand::policy_scope( ServicePromisePolicy::from_json( $this->input['body_json'] ) );
				$this->message = __( 'Hypothetical preview only. Nothing was published or accepted for checkout.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( 'configure' === $verb ) {
				$okay = $this->service->configure_adoption( $binding, PromiseJson::decode( $this->input['registry_json'] ), self::integer( $this->input['adoption_revision'], 0 ), $this->input['request_token'] );
				$this->adoption_result( $okay, __( 'Service mapping saved. Promise adoption is OFF. Enable it separately after review.', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'enable' === $verb || 'disable' === $verb ) {
				$okay = $this->service->change_adoption( $binding, 'enable' === $verb, self::integer( $this->input['adoption_revision'], 0 ), $this->input['request_token'] );
				$this->adoption_result( $okay, 'enable' === $verb ? __( 'Promise adoption enabled for the explicit saved service mapping.', 'cetech-woocommerce-delivery-engine' ) : __( 'Promise adoption is OFF. Original order promises are preserved.', 'cetech-woocommerce-delivery-engine' ) );
			} else {
				$original = 'reconcile' === $verb ? $this->input['original_action'] : $verb;
				if ( ! in_array( $original, [ 'create', 'seal', 'publish', 'schedule', 'activate', 'retire', 'assignment' ], true ) ) { PromiseShape::invalid(); }
				$payload = 'create' === $verb ? $this->create_payload() : ( 'assignment' === $verb ? $this->assignment_payload() : PromiseJson::decode( $this->input['command_json'] ) );
				// For an original retry/reconciliation even the original reason remains fixed.
				if ( 'reconcile' !== $verb && ! in_array( $verb, [ 'create', 'assignment' ], true ) ) { $payload['reason'] = $this->input['reason']; }
				$result = 'assignment' === $original
					? $this->service->assignment( $binding, $this->input['request_token'], $payload, 'reconcile' === $verb )
					: $this->service->version( $binding, 'promise.version.' . $original, $this->input['request_token'], $payload, 'reconcile' === $verb );
				if ( ! $this->service->can_access() ) { throw new \RuntimeException( 'Current permission is unavailable.' ); }
				$this->message_type = 'accepted' === $result->outcome->state ? 'success' : ( 'unconfirmed' === $result->outcome->state ? 'warning' : 'error' );
				$this->message = match ( $result->outcome->state ) {
					'accepted' => $result->replayed ? __( 'This original request was already completed. Its immutable receipt was verified.', 'cetech-woocommerce-delivery-engine' ) : __( 'The request was saved with an immutable audit receipt. Open the saved version before the next step.', 'cetech-woocommerce-delivery-engine' ),
					'unconfirmed' => __( 'The result is uncertain. Reconcile this original request before starting another change.', 'cetech-woocommerce-delivery-engine' ),
					default => __( 'The request was refused. Open the current version or assignment and review permissions, effective dates and revisions before trying again.', 'cetech-woocommerce-delivery-engine' ),
				};
				if ( 'unconfirmed' === $result->outcome->state ) { $this->uncertain = [ 'original_action' => $original, 'command_json' => PromiseJson::encode( $payload ), 'request_token' => $this->input['request_token'], 'site_key' => $this->input['site_key'] ]; }
			}
		} catch ( \Throwable ) {
			$this->opened = $this->preview = $this->assignment = null;
			$this->message_type = 'error'; $this->message = __( 'The request could not be confirmed. Check the entered settings, permissions and saved revisions. No private result is disclosed.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	public function render(): void {
		if ( ! $this->service->can_access() && ! $this->shipments?->can_access() ) { AdminPageAccess::require_capability( 'manage_delivery_settings' ); return; }
		if ( null !== $this->disclosure_binding && null !== $this->disclosure_scope && ! $this->service->can_disclose( $this->disclosure_binding, $this->disclosure_scope ) ) { $this->opened = $this->preview = $this->assignment = null; }
		AdminPageLayout::open_page( 'cetech-de-promises' );
		AdminPageLayout::render_page_header( __( 'Delivery Engine', 'cetech-woocommerce-delivery-engine' ), __( 'Delivery promises', 'cetech-woocommerce-delivery-engine' ), __( 'Define calendars and delivery policies, preview their results, then publish and assign exact versions.', 'cetech-woocommerce-delivery-engine' ) );
		if ( '' !== $this->message ) { echo '<div class="notice notice-' . esc_attr( $this->message_type ) . '"><p role="status">' . esc_html( $this->message ) . '</p></div>'; }
		if ( null !== $this->uncertain ) {
			$this->form_start( 'reconcile' ); foreach ( $this->uncertain as $key => $value ) { $this->hidden( $key, $value ); }
			echo '<p><button class="button button-primary" type="submit">' . esc_html__( 'Reconcile original request', 'cetech-woocommerce-delivery-engine' ) . '</button></p></form>';
		}
		if ( $this->service->can_access() ) { $this->render_inspector(); $this->render_editor(); $this->render_preview(); $this->render_assignment(); $this->render_adoption(); }
		$this->shipments?->render();
		AdminPageLayout::close_page();
	}

	private function render_inspector(): void {
		AdminPageLayout::open_section( __( '1. Open an exact version', 'cetech-woocommerce-delivery-engine' ), __( 'Open a policy or calendar by its saved identity. A global policy needs global permissions; a variation also needs permission to edit its actual parent.', 'cetech-woocommerce-delivery-engine' ) );
		$this->form_start( 'inspect' ); $this->field( 'site_key', 'Policy site key', $this->value( 'site_key' ) ); $this->select( 'kind', 'Record kind', [ 'policy' => 'Delivery policy', 'calendar' => 'Business calendar' ], $this->value( 'kind', 'policy' ) );
		$this->field( 'logical_id', 'Policy or calendar ID', $this->value( 'logical_id' ) ); $this->field( 'domain_version', 'Version number', $this->value( 'domain_version', '1' ), 'number' ); $this->scope_fields(); $this->submit( 'Open saved version' );
		if ( null !== $this->opened ) {
			$v = $this->opened['version'];
			echo '<p>' . esc_html( sprintf( __( 'Object revision: %1$d. Next new version: %2$d.', 'cetech-woocommerce-delivery-engine' ), $this->opened['object_revision'], $this->opened['next_version'] ) ) . '</p>';
			if ( null !== $v ) {
				echo '<p>' . esc_html( sprintf( __( 'Saved state: %1$s. Author: %2$d. Reason: %3$s', 'cetech-woocommerce-delivery-engine' ), $v['state'], $v['author_user_id'], $v['reason'] ) ) . '</p>';
				if ( 'policy' === $this->input['kind'] && 'published' === $v['state'] ) {
					$reference = ServicePromisePolicy::from_json( $v['body_json'] )->reference()->private_facts();
					echo '<p>' . esc_html__( 'Exact published reference for assignment:', 'cetech-woocommerce-delivery-engine' ) . ' <code>' . esc_html( PromiseJson::encode( $reference ) ) . '</code></p>';
				}
				$verbs = match ( $v['state'] ) { 'draft' => [ 'seal', 'retire' ], 'sealed' => [ 'publish', 'schedule', 'retire' ], 'scheduled' => [ 'activate', 'retire' ], 'published' => [ 'retire' ], default => [] };
				foreach ( $verbs as $verb ) {
					$payload = [ 'kind' => $this->input['kind'], 'logical_id' => $this->input['logical_id'], 'domain_version' => $v['domain_version'], 'version_uuid' => $v['version_uuid'], 'body_digest' => $v['body_digest'], 'scope' => $this->scope(), 'body_json' => null,
						'declared_from' => $v['declared_from'], 'declared_until' => $v['declared_until'], 'reason' => '', 'scheduled_author_user_id' => 'activate' === $verb ? $v['scheduled_author_user_id'] : null,
						'preconditions' => [ 'object_revision' => $this->opened['object_revision'], 'version_revision' => $v['row_revision'], 'published_version_id' => $this->opened['published_version_id'] ] ];
					$this->form_start( $verb ); $this->hidden( 'site_key', $this->input['site_key'] ); $this->hidden( 'command_json', PromiseJson::encode( $payload ) ); $this->hidden( 'request_token', RequestContext::create()->request_id );
					$this->field( 'reason', 'Reason for this change', '' ); $this->submit( ucfirst( $verb ) . ' exact version' );
				}
			}
		}
		AdminPageLayout::close_section();
	}
	private function render_editor(): void {
		AdminPageLayout::open_section( __( '2. Create an immutable version', 'cetech-woocommerce-delivery-engine' ), __( 'Edit the settings document to create a new draft. Published bodies are never overwritten. Enter real calendars, cutoffs, endpoint and source versions; no demo rules are supplied.', 'cetech-woocommerce-delivery-engine' ) );
		$v = $this->opened['version'] ?? null; $body = $this->value( 'body_json', $v['body_json'] ?? '' );
		if ( null !== $v && '' === ( $this->input['body_json'] ?? '' ) ) { $copy = PromiseJson::decode( $body ); $copy['version'] = $this->opened['next_version']; $body = PromiseJson::encode( $copy ); }
		$this->form_start( 'create' ); $this->field( 'site_key', 'Policy site key', $this->value( 'site_key' ) ); $this->select( 'kind', 'Record kind', [ 'policy' => 'Delivery policy', 'calendar' => 'Business calendar' ], $this->value( 'kind', 'policy' ) );
		$this->scope_fields(); $this->textarea( 'body_json', 'Policy or calendar settings (JSON)', $body, 16 );
		echo '<p class="description">' . esc_html__( 'Policies declare service identity and label, scope, anchor, endpoint kind, timezone, component graph, calendar references, cutoff, required/optional behavior and capacity mode. Calendars declare an IANA timezone, its timezone-data version, weekly openings, closures and exceptions. Unknown or extra settings are refused.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->field( 'declared_from', 'Calendar effective from (UTC; policies use their document)', $this->value( 'declared_from' ) ); $this->field( 'declared_until', 'Calendar effective until (UTC; blank means no end)', $this->value( 'declared_until' ) );
		$this->field( 'object_revision', 'Opened object revision (0 for a new identity)', (string) ( $this->opened['object_revision'] ?? $this->value( 'object_revision', '0' ) ), 'number' );
		$this->field( 'published_version_id', 'Opened published version ID (0 if none)', (string) ( $this->opened['published_version_id'] ?? $this->value( 'published_version_id', '0' ) ), 'number' );
		$this->field( 'reason', 'Reason for the new version', $this->value( 'reason' ) ); $this->hidden( 'version_uuid', $this->value( 'version_uuid', RequestContext::create()->request_id ) ); $this->hidden( 'request_token', $this->value( 'request_token', RequestContext::create()->request_id ) ); $this->submit( 'Create new draft' );
		AdminPageLayout::close_section();
	}
	private function render_preview(): void {
		AdminPageLayout::open_section( __( '3. Preview a hypothetical policy', 'cetech-woocommerce-delivery-engine' ), __( 'Uses the same calculator as checkout and the server clock. The result is hypothetical; it cannot publish, reserve capacity or authorize payment.', 'cetech-woocommerce-delivery-engine' ) );
		$this->form_start( 'preview' ); $this->field( 'site_key', 'Policy site key', $this->value( 'site_key' ) ); $this->textarea( 'body_json', 'Policy settings to preview (JSON)', $this->value( 'body_json', $this->opened['version']['body_json'] ?? '' ) );
		$this->textarea( 'calendars_json', 'Exact calendar documents (JSON: {"calendars": [...]})', $this->value( 'calendars_json', '{"calendars":[]}' ) ); $this->submit( 'Preview without saving' );
		if ( null !== $this->preview ) {
			echo '<div role="status"><strong>' . esc_html__( 'Hypothetical preview', 'cetech-woocommerce-delivery-engine' ) . '</strong>';
			foreach ( $this->preview['views'] as $view ) {
				$formatter = new \CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter();
				echo '<p>' . esc_html( $formatter->text( \CetechDeliveryEngine\Domain\ServicePromise\PublicPromiseView::from_array( $view ) ) ) . '</p>';
			} echo '</div>';
		}
		AdminPageLayout::close_section();
	}
	private function render_assignment(): void {
		AdminPageLayout::open_section( __( '4. Assign a published policy', 'cetech-woocommerce-delivery-engine' ), __( 'Choose an exact service, endpoint and scope. Inherit and disabled are explicit choices; a policy never changes a product price or delivery fee.', 'cetech-woocommerce-delivery-engine' ) );
		$this->form_start( 'assignment_inspect' ); $this->assignment_fields(); $this->submit( 'Open current assignment' );
		$this->form_start( 'assignment' ); $this->assignment_fields(); $this->select( 'mode', 'Assignment mode', [ 'assigned' => 'Assign exact policy', 'inherit' => 'Inherit from broader scope', 'disabled' => 'Disable this exact assignment' ], $this->assignment['mode'] ?? $this->value( 'mode', 'inherit' ) );
		$this->textarea( 'policy_reference_json', 'Published policy reference (site_id, policy_id, version, digest)', null !== ( $this->assignment['policy_reference'] ?? null ) ? PromiseJson::encode( $this->assignment['policy_reference'] ) : $this->value( 'policy_reference_json' ), 4 );
		$this->field( 'expected_revision', 'Opened assignment revision', (string) ( $this->assignment['revision'] ?? $this->value( 'expected_revision', '0' ) ), 'number' ); $this->field( 'expected_generation', 'Opened assignment generation', (string) ( $this->assignment['generation'] ?? $this->value( 'expected_generation', '0' ) ), 'number' );
		$this->field( 'reason', 'Reason for the assignment', '' ); $this->hidden( 'request_token', RequestContext::create()->request_id ); $this->submit( 'Save exact assignment' ); AdminPageLayout::close_section();
	}
	private function render_adoption(): void {
		if ( ! current_user_can( 'manage_delivery_settings' ) ) { return; }
		AdminPageLayout::open_section( __( 'Promise adoption', 'cetech-woocommerce-delivery-engine' ), __( 'Save an explicit native service mapping with adoption OFF. Enabling it is a separate protected decision after reader, storage and checkout readiness pass. Turning it off preserves existing original order promises.', 'cetech-woocommerce-delivery-engine' ) );
		try { $status = $this->service->adoption_status(); } catch ( \Throwable ) { $status = [ 'available' => false, 'revision' => 0, 'enabled' => false ]; }
		echo '<p role="status">' . esc_html( ! $status['available'] ? __( 'Adoption status unavailable. Saved configuration is preserved.', 'cetech-woocommerce-delivery-engine' ) : ( $status['enabled'] ? __( 'Adoption requested ON.', 'cetech-woocommerce-delivery-engine' ) : __( 'Adoption OFF.', 'cetech-woocommerce-delivery-engine' ) ) ) . '</p>';
		if ( $status['available'] ) {
			$this->form_start( 'configure' ); $this->field( 'site_key', 'Policy site key', $this->value( 'site_key', $status['binding']['site_key'] ?? '' ) );
			$this->textarea( 'registry_json', 'Explicit native service mapping (JSON)', $this->value( 'registry_json', isset( $status['registry'] ) ? PromiseJson::encode( $status['registry'] ) : '' ) );
			$this->hidden( 'adoption_revision', (string) $status['revision'] ); $this->hidden( 'request_token', RequestContext::create()->request_id ); $this->submit( 'Save mapping with adoption OFF' );
			if ( null !== ( $status['binding'] ?? null ) ) {
				foreach ( [ 'enable', 'disable' ] as $verb ) { $this->form_start( $verb ); $this->hidden( 'site_key', $status['binding']['site_key'] ); $this->hidden( 'adoption_revision', (string) $status['revision'] ); $this->hidden( 'request_token', RequestContext::create()->request_id ); $this->submit( 'enable' === $verb ? 'Enable saved promise mapping' : 'Turn promise adoption OFF' ); }
			}
		}
		AdminPageLayout::close_section();
	}
	private function create_payload(): array {
		$body = 'policy' === $this->input['kind'] ? ServicePromisePolicy::from_json( $this->input['body_json'] ) : BusinessCalendarVersion::from_json( $this->input['body_json'] ); $facts = $body->private_facts();
		$scope = $this->scope(); if ( $body instanceof ServicePromisePolicy && PromiseVersionCommand::policy_scope( $body ) !== $scope ) { PromiseShape::invalid(); }
		return [ 'kind' => $this->input['kind'], 'logical_id' => $facts[ $body instanceof ServicePromisePolicy ? 'policy_id' : 'calendar_id' ], 'domain_version' => $facts['version'], 'version_uuid' => $this->input['version_uuid'], 'body_digest' => $body->digest(), 'scope' => $scope, 'body_json' => $body->to_private_json(),
			'declared_from' => $body instanceof ServicePromisePolicy ? $facts['effective_from'] : $this->input['declared_from'], 'declared_until' => $body instanceof ServicePromisePolicy ? $facts['effective_until'] : ( '' === $this->input['declared_until'] ? null : $this->input['declared_until'] ), 'reason' => $this->input['reason'], 'scheduled_author_user_id' => null,
			'preconditions' => [ 'object_revision' => self::integer( $this->input['object_revision'], 0 ), 'version_revision' => 0, 'published_version_id' => self::integer( $this->input['published_version_id'], 0 ) ] ];
	}
	private function assignment_payload(): array {
		return [ 'key' => $this->assignment_key(), 'mode' => $this->input['mode'], 'policy_reference' => 'assigned' === $this->input['mode'] ? PromiseJson::decode( $this->input['policy_reference_json'] ) : null,
			'expected_revision' => self::integer( $this->input['expected_revision'], 0 ), 'expected_generation' => self::integer( $this->input['expected_generation'], 0 ), 'reason' => $this->input['reason'] ];
	}
	private function assignment_key(): array { return [ 'scope_kind' => $this->input['scope_kind'], 'scope_id' => self::integer( $this->input['scope_id'], 0 ), 'service_kind' => $this->input['service_kind'], 'service_code' => $this->input['service_code'], 'endpoint' => $this->input['endpoint'], 'endpoint_kind' => $this->input['endpoint_kind'] ]; }
	private function scope(): array { return [ 'kind' => $this->input['scope_kind'], 'target_id' => self::integer( $this->input['scope_id'], 0 ) ]; }
	private function adoption_result( bool $okay, string $success ): void { $this->message_type = $okay ? 'success' : 'warning'; $this->message = $okay ? $success : __( 'The adoption change was not confirmed. Check its current saved status and revision before another decision.', 'cetech-woocommerce-delivery-engine' ); }
	/** Strict scalar transport; identity, principal and author are never accepted. */
	public static function parse_form( array $post ): array {
		$fields = [ 'site_key', 'kind', 'logical_id', 'domain_version', 'scope_kind', 'scope_id', 'body_json', 'calendars_json', 'declared_from', 'declared_until', 'object_revision', 'published_version_id', 'reason', 'version_uuid', 'request_token', 'command_json', 'original_action', 'service_kind', 'service_code', 'endpoint', 'endpoint_kind', 'mode', 'policy_reference_json', 'expected_revision', 'expected_generation', 'registry_json', 'adoption_revision' ]; $out = [];
		foreach ( $fields as $field ) { $value = $post[$field] ?? ''; if ( ! is_string( $value ) || strlen( $value ) > ( str_ends_with( $field, '_json' ) ? PromiseLimits::PACKET_BYTES : 512 ) ) { PromiseShape::invalid(); } $out[$field] = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value; }
		foreach ( [ 'site_id', 'principal', 'authority', 'author_user_id' ] as $forbidden ) { if ( isset( $post[$forbidden] ) ) { PromiseShape::invalid(); } }
		PromiseShape::id( $out['site_key'] ); return $out;
	}
	private static function integer( string $raw, int $minimum ): int { if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]{0,18})\z/D', $raw ) || (string) (int) $raw !== $raw ) { PromiseShape::invalid(); } return PromiseShape::integer( (int) $raw, $minimum, PHP_INT_MAX - 1 ); }
	private function form_start( string $verb ): void { echo '<form method="post" action="" class="cetech-de-entity-form" data-promise-action="' . esc_attr( $verb ) . '">'; AdminFormHelper::nonce_field( self::ACTION_PREFIX . $verb ); $this->hidden( 'cetech_de_action', self::ACTION_PREFIX . $verb ); }
	private function hidden( string $key, string $value ): void { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />'; }
	private function field( string $key, string $label, string $value, string $type = 'text' ): void { $id = 'promise-' . $key . '-' . ++$this->field_counter; echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</label><br /><input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( 'number' === $type ? ' min="0" step="1"' : '' ) . ' /></p>'; }
	private int $field_counter = 0;
	private function textarea( string $key, string $label, string $value, int $rows = 8 ): void { $id = 'promise-' . $key . '-' . ++$this->field_counter; echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</label><br /><textarea class="large-text code" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" rows="' . esc_attr( (string) $rows ) . '" spellcheck="false">' . esc_textarea( $value ) . '</textarea></p>'; }
	private function select( string $key, string $label, array $options, string $value ): void { $id = 'promise-' . $key . '-' . ++$this->field_counter; echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</label> <select id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '">'; foreach ( $options as $code => $text ) { echo '<option value="' . esc_attr( $code ) . '"' . selected( $value, $code, false ) . '>' . esc_html( __( $text, 'cetech-woocommerce-delivery-engine' ) ) . '</option>'; } echo '</select></p>'; }
	private function scope_fields(): void { $this->select( 'scope_kind', 'Scope', [ 'global' => 'Whole store', 'product' => 'Product', 'variation' => 'Variation' ], $this->value( 'scope_kind', 'global' ) ); $this->field( 'scope_id', 'Product or variation ID (0 for whole store)', $this->value( 'scope_id', '0' ), 'number' ); }
	private function assignment_fields(): void { $this->field( 'site_key', 'Policy site key', $this->value( 'site_key' ) ); $this->scope_fields(); $this->select( 'service_kind', 'Service kind', [ 'built_in' => 'Built-in', 'merchant' => 'Merchant-defined' ], $this->value( 'service_kind', 'merchant' ) ); $this->field( 'service_code', 'Exact service code', $this->value( 'service_code' ) ); $this->field( 'endpoint', 'Exact endpoint ID', $this->value( 'endpoint' ) ); $this->select( 'endpoint_kind', 'Endpoint kind', [ 'doorstep' => 'Doorstep', 'pickup' => 'Pickup', 'port' => 'Port arrival', 'handover' => 'Handover' ], $this->value( 'endpoint_kind', 'doorstep' ) ); }
	private function submit( string $label ): void { echo '<p><button type="submit" class="button button-primary">' . esc_html( __( $label, 'cetech-woocommerce-delivery-engine' ) ) . '</button></p></form>'; }
	private function value( string $key, string $default = '' ): string { return isset( $this->input[$key] ) && '' !== $this->input[$key] ? $this->input[$key] : $default; }
}
