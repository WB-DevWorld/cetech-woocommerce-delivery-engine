<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseConfigurationService;
use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffDraft;
use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffIntent;
use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseJson;
use CetechDeliveryEngine\Domain\ServicePromise\PublicPromiseView;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDeliveryOfferRepository;

/** Staff workflow for one supported delivery-time rule. It never enables customer admission. */
final class PromiseStaffGuide {
	public const ACTION = 'cetech_de_promise_guide';
	private const INDEX = 'cetech_de_promise_staff_index';
	/** @var array<string, string> */
	private array $values = [];
	/** @var list<string> */
	private array $missing = [];
	/** @var list<string> */
	private array $preview = [];
	private string $message = '';
	private string $type = 'info';

	public function __construct( private PromiseConfigurationService $service, private AdminActionHandler $actions ) {}

	public function handles(): bool {
		return ( $_POST['cetech_de_action'] ?? '' ) === self::ACTION;
	}

	public function handle(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}
		$cap = current_user_can( 'manage_delivery_settings' ) ? 'manage_delivery_settings' : 'manage_product_delivery_rules';
		if ( ! $this->actions->verify_post( self::ACTION, self::ACTION, $cap, PromiseConfigurationPage::SLUG ) ) {
			return;
		}
		$this->values = self::fields( $_POST );
		if ( 'resume' === $this->values['guide_step'] || 'retry' === $this->values['guide_step'] ) { $this->resume( 'retry' === $this->values['guide_step'] ); return; }
		if ( 'acknowledge' === $this->values['guide_step'] ) { $this->acknowledge(); return; }
		if ( 'review' === $this->values['guide_step'] ) {
			$this->review( $this->values['review_id'] );
			return;
		}
		if ( ! in_array( $this->values['guide_step'], [ 'preview', 'draft' ], true ) ) {
			$this->message = __( 'The form is unchanged. Choose a variation if needed, then preview or save the draft.', 'cetech-woocommerce-delivery-engine' );
			return;
		}
		$offer = $this->offer( $this->values['offer_code'] );
		$this->values['target_id'] = (string) $this->target_id();
		$draft = PromiseStaffDraft::assemble( $this->values, $offer ?? [] );
		$this->missing = $draft['missing'];
		if ( [] !== $this->missing ) {
			$this->type = 'warning';
			$this->message = __( 'This delivery rule is not ready. The list below is the next thing to complete. Nothing was saved.', 'cetech-woocommerce-delivery-engine' );
			return;
		}
		if ( 'preview' === $this->values['guide_step'] ) {
			$this->preview( $draft );
			return;
		}
		if ( 'draft' === $this->values['guide_step'] ) {
			$this->save( $draft, $offer ?? [] );
		}
	}

	public function render(): void {
		$this->styles();
		echo '<div class="cetech-de-guide">';
		echo '<p>' . esc_html__( 'A delivery-time rule tells customers how long preparation and delivery are expected to take. It does not change the delivery price, and saving it does not turn estimates on for customers.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->adoption();
		if ( '' !== $this->message ) {
			echo '<div class="notice notice-' . esc_attr( $this->type ) . '"><p role="status">' . esc_html( $this->message ) . '</p></div>';
		}
		$this->pending_form();
		$this->saved_rules();
		echo '<p><a class="button button-primary" href="#cetech-de-create-rule">' . esc_html__( 'Create delivery rule', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
		$this->form();
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=cetech-delivery-engine-shipments' ) ) . '">' . esc_html__( 'Update an estimate on an existing shipment', 'cetech-woocommerce-delivery-engine' ) . '</a></p>';
		echo '</div>';
	}

	private function adoption(): void {
		try {
			$status = $this->service->adoption_status();
			if ( true !== ( $status['available'] ?? null ) ) { throw new \RuntimeException( 'Customer estimate status is unavailable.' ); }
		} catch ( \Throwable ) {
			echo '<p role="status">' . esc_html__( 'Customer estimates: status could not be confirmed. No change was made.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}
		$enabled = ! empty( $status['enabled'] );
		echo '<p role="status"><strong>' . esc_html( $enabled ? __( 'Customer estimates are on.', 'cetech-woocommerce-delivery-engine' ) : __( 'Customer estimates are off.', 'cetech-woocommerce-delivery-engine' ) ) . '</strong> ';
		echo esc_html__( 'Drafts, previews and publication stay separate from that switch.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
	}

	private function saved_rules(): void {
		try { $index = $this->index(); } catch ( \Throwable ) { $index = []; }
		echo '<h2 id="cetech-de-saved-rules">' . esc_html__( 'Saved rules', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		if ( [] === $index ) {
			echo '<p>' . esc_html__( 'No delivery-time rule has been created here yet. A rule that already exists only in advanced configuration is left as it is.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Rule', 'cetech-woocommerce-delivery-engine' ) . '</th><th>' . esc_html__( 'Applies to', 'cetech-woocommerce-delivery-engine' ) . '</th><th>' . esc_html__( 'Saved state', 'cetech-woocommerce-delivery-engine' ) . '</th><th>' . esc_html__( 'Next action', 'cetech-woocommerce-delivery-engine' ) . '</th></tr></thead><tbody>';
		foreach ( $index as $id => $row ) {
			if ( ! is_string( $id ) || ! is_array( $row ) ) {
				continue;
			}
			try { $binding = $this->service->binding( PromiseStaffDraft::SITE_KEY ); if ( ! $this->service->can_disclose( $binding, $this->row_scope( $row ) ) ) { continue; } } catch ( \Throwable ) { continue; }
			$opened = $this->confirm( $row );
			if ( ! $this->service->can_disclose( $binding, $this->row_scope( $row ) ) ) { continue; }
			echo '<tr><td>' . esc_html( (string) ( $row['label'] ?? $id ) ) . '</td><td>' . esc_html( (string) ( $row['applies'] ?? '' ) ) . '</td><td>' . esc_html( $opened['label'] ) . '</td><td>';
			if ( $opened['editable'] ) {
				echo '<form method="post" action="">';
				AdminFormHelper::nonce_field( self::ACTION );
				echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" />';
				echo '<input type="hidden" name="guide_step" value="review" /><input type="hidden" name="review_id" value="' . esc_attr( $id ) . '" />';
				echo '<button class="button" type="submit">' . esc_html__( 'Review saved rule', 'cetech-woocommerce-delivery-engine' ) . '</button></form>';
			}
			if ( 'draft' === $opened['code'] && $this->calendar_action( $row ) ) {
				$this->protected_action( $row, 'seal', __( 'Mark ready to publish', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'sealed' === $opened['code'] && $this->calendar_action( $row ) ) {
				$this->protected_action( $row, 'publish', __( 'Publish this rule', 'cetech-woocommerce-delivery-engine' ) );
			} elseif ( 'published' === $opened['code'] ) {
				$this->assignment_action( $row );
			} elseif ( 'preserved' === $opened['code'] ) {
				echo esc_html__( 'Open advanced configuration. This guided editor will not change it.', 'cetech-woocommerce-delivery-engine' );
			} else {
				echo esc_html( $opened['next'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function form(): void {
		$offers = $this->offers();
		echo '<h2 id="cetech-de-create-rule">' . esc_html__( 'Create delivery rule', 'cetech-woocommerce-delivery-engine' ) . '</h2>';
		if ( [] !== $this->missing ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Still needed', 'cetech-woocommerce-delivery-engine' ) . '</strong></p><ul>';
			foreach ( $this->missing as $item ) {
				echo '<li>' . esc_html( $item ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( [] !== $this->preview ) {
			echo '<div class="notice notice-info" role="status"><p><strong>' . esc_html__( 'Customer wording preview', 'cetech-woocommerce-delivery-engine' ) . '</strong></p>';
			foreach ( $this->preview as $line ) {
				echo '<p>' . esc_html( $line ) . '</p>';
			}
			echo '<p>' . esc_html__( 'This preview is not shown to customers and does not reserve a delivery date.', 'cetech-woocommerce-delivery-engine' ) . '</p></div>';
		}
		if ( [] === $offers ) {
			echo '<p>' . esc_html__( 'Create a delivery option first. This guide uses an option that already exists and does not invent one.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
		echo '<form method="post" action="" class="cetech-de-guide-form">';
		AdminFormHelper::nonce_field( self::ACTION );
		echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="review_id" value="' . esc_attr( $this->values['review_id'] ?? '' ) . '" /><input type="hidden" name="review_stamp" value="' . esc_attr( $this->values['review_stamp'] ?? '' ) . '" />';
		if ( '' !== ( $this->values['review_id'] ?? '' ) ) { echo '<label><input type="checkbox" name="replace_draft" value="yes" /> ' . esc_html__( 'Retire any existing draft of this rule or its working calendar and create a new immutable version with these entries.', 'cetech-woocommerce-delivery-engine' ) . '</label>'; }
		echo '<fieldset><legend>' . esc_html__( '1. Delivery option', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<label for="cetech-de-offer">' . esc_html__( 'Which existing delivery option does this rule describe?', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<select id="cetech-de-offer" name="offer_code"><option value="">' . esc_html__( 'Select a delivery option', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $offers as $offer ) {
			echo '<option value="' . esc_attr( $offer['internal_code'] ) . '"' . selected( $this->values['offer_code'] ?? '', $offer['internal_code'], false ) . '>' . esc_html( $offer['public_label'] ) . '</option>';
		}
		echo '</select></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '2. Time ranges', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p>' . esc_html__( 'Enter the shortest and longest time. These are estimates, not guaranteed dates.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		$this->number( 'processing_min', __( 'Preparation, at least', 'cetech-woocommerce-delivery-engine' ) );
		$this->number( 'processing_max', __( 'Preparation, at most', 'cetech-woocommerce-delivery-engine' ) );
		$this->number( 'delivery_min', __( 'Delivery, at least', 'cetech-woocommerce-delivery-engine' ) );
		$this->number( 'delivery_max', __( 'Delivery, at most', 'cetech-woocommerce-delivery-engine' ) );
		echo '<label for="cetech-de-unit">' . esc_html__( 'Unit', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-unit" name="unit">';
		echo '<option value="elapsed_minutes"' . selected( $this->values['unit'] ?? '', 'elapsed_minutes', false ) . '>' . esc_html__( 'Elapsed minutes, including time outside working hours', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="business_days"' . selected( $this->values['unit'] ?? '', 'business_days', false ) . '>' . esc_html__( 'Business days, using the working calendar below', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '</select></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '3. Working calendar', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<p>' . esc_html__( 'Leave a day blank when it is closed. Blank days are not turned into opening hours.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<label for="cetech-de-timezone">' . esc_html__( 'Timezone', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-timezone" name="timezone"><option value="">' . esc_html__( 'Select a timezone', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( \DateTimeZone::listIdentifiers() as $zone ) {
			echo '<option value="' . esc_attr( $zone ) . '"' . selected( $this->values['timezone'] ?? '', $zone, false ) . '>' . esc_html( str_replace( '_', ' ', $zone ) ) . '</option>';
		}
		echo '</select>';
		foreach ( [ 'mon' => __( 'Monday', 'cetech-woocommerce-delivery-engine' ), 'tue' => __( 'Tuesday', 'cetech-woocommerce-delivery-engine' ), 'wed' => __( 'Wednesday', 'cetech-woocommerce-delivery-engine' ), 'thu' => __( 'Thursday', 'cetech-woocommerce-delivery-engine' ), 'fri' => __( 'Friday', 'cetech-woocommerce-delivery-engine' ), 'sat' => __( 'Saturday', 'cetech-woocommerce-delivery-engine' ), 'sun' => __( 'Sunday', 'cetech-woocommerce-delivery-engine' ) ] as $day => $label ) {
			echo '<div class="cetech-de-day"><span>' . esc_html( $label ) . '</span>';
			echo '<label>' . esc_html( sprintf( /* translators: %s: weekday name. */ __( '%s opens', 'cetech-woocommerce-delivery-engine' ), $label ) ) . ' <input name="' . esc_attr( $day . '_open' ) . '" value="' . esc_attr( $this->values[ $day . '_open' ] ?? '' ) . '" placeholder="09:00" inputmode="numeric" /></label>';
			echo '<label>' . esc_html( sprintf( /* translators: %s: weekday name. */ __( '%s closes', 'cetech-woocommerce-delivery-engine' ), $label ) ) . ' <input name="' . esc_attr( $day . '_close' ) . '" value="' . esc_attr( $this->values[ $day . '_close' ] ?? '' ) . '" placeholder="17:00" inputmode="numeric" /></label></div>';
		}
		echo '<label for="cetech-de-closures">' . esc_html__( 'Closure dates, optional', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<textarea id="cetech-de-closures" name="closures" rows="3" placeholder="2026-12-25">' . esc_textarea( $this->values['closures'] ?? '' ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'The placeholder shows the date format. It is not saved unless you enter a date.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		echo '<label for="cetech-de-cutoff">' . esc_html__( 'Order cutoff, optional', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<input id="cetech-de-cutoff" name="cutoff" value="' . esc_attr( $this->values['cutoff'] ?? '' ) . '" placeholder="15:00" inputmode="numeric" />';
		echo '<label for="cetech-de-cutoff-miss">' . esc_html__( 'If the cutoff has passed', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-cutoff-miss" name="cutoff_miss">';
		echo '<option value="next_opening"' . selected( $this->values['cutoff_miss'] ?? '', 'next_opening', false ) . '>' . esc_html__( 'Use the next working period', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		echo '<option value="reject"' . selected( $this->values['cutoff_miss'] ?? '', 'reject', false ) . '>' . esc_html__( 'Do not offer this estimate', 'cetech-woocommerce-delivery-engine' ) . '</option></select></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '4. Where it applies', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		foreach ( [ 'global' => __( 'Whole store', 'cetech-woocommerce-delivery-engine' ), 'product' => __( 'One product', 'cetech-woocommerce-delivery-engine' ), 'variation' => __( 'One variation', 'cetech-woocommerce-delivery-engine' ) ] as $scope => $label ) {
			echo '<label><input type="radio" name="scope" value="' . esc_attr( $scope ) . '"' . checked( $this->values['scope'] ?? 'global', $scope, false ) . ' /> ' . esc_html( $label ) . '</label> ';
		}
		echo '<div class="cetech-de-products"><label for="cetech-de-product">' . esc_html__( 'Product', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-product" name="product_id"><option value="">' . esc_html__( 'Select a product', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->products() as $product ) {
			echo '<option value="' . esc_attr( (string) $product['id'] ) . '"' . selected( $this->values['product_id'] ?? '', (string) $product['id'], false ) . '>' . esc_html( $product['name'] ) . '</option>';
		}
		echo '</select>';
		echo '<label for="cetech-de-variation">' . esc_html__( 'Variation', 'cetech-woocommerce-delivery-engine' ) . '</label><select id="cetech-de-variation" name="variation_id"><option value="">' . esc_html__( 'Select a variation', 'cetech-woocommerce-delivery-engine' ) . '</option>';
		foreach ( $this->variations() as $variation ) {
			echo '<option value="' . esc_attr( (string) $variation['id'] ) . '"' . selected( $this->values['variation_id'] ?? '', (string) $variation['id'], false ) . '>' . esc_html( $variation['name'] ) . '</option>';
		}
		echo '</select><button class="button" type="submit" name="guide_step" value="variations">' . esc_html__( 'Show this product’s variations', 'cetech-woocommerce-delivery-engine' ) . '</button></div></fieldset>';
		echo '<fieldset><legend>' . esc_html__( '5. Customer requirement', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
		echo '<label><input type="radio" name="estimate_required" value="no"' . checked( $this->values['estimate_required'] ?? 'no', 'no', false ) . ' /> ' . esc_html__( 'The estimate is optional', 'cetech-woocommerce-delivery-engine' ) . '</label> ';
		echo '<label><input type="radio" name="estimate_required" value="yes"' . checked( $this->values['estimate_required'] ?? '', 'yes', false ) . ' /> ' . esc_html__( 'Checkout needs an estimate for this rule', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'This guided rule starts at checkout, does not reserve capacity, and is refused when the time cannot be met. Payment-confirmed timing and capacity booking stay in advanced configuration.', 'cetech-woocommerce-delivery-engine' ) . '</p></fieldset>';
		echo '<p><button class="button" type="submit" name="guide_step" value="preview">' . esc_html__( 'Preview customer wording', 'cetech-woocommerce-delivery-engine' ) . '</button> ';
		echo '<button class="button button-primary" type="submit" name="guide_step" value="draft">' . esc_html__( 'Save draft', 'cetech-woocommerce-delivery-engine' ) . '</button></p>';
		echo '</form>';
	}

	/** @param array{missing: list<string>, calendar: mixed, policy: mixed, summary: string} $draft */
	private function preview( array $draft ): void {
		try {
			$policy = $draft['policy'];
			if ( ! $policy instanceof ServicePromisePolicy ) {
				throw new \InvalidArgumentException( 'Draft policy is absent.' );
			}
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$result  = $this->service->preview( $binding, $policy->to_private_json(), null === $draft['calendar'] ? [] : [ $draft['calendar']->private_facts() ] );
			$format  = new PublicPromiseFormatter();
			foreach ( $result['views'] as $view ) {
				$this->preview[] = $format->text( PublicPromiseView::from_array( $view ) );
			}
			$this->message = __( 'Preview only. Nothing was saved and customer estimates were not turned on.', 'cetech-woocommerce-delivery-engine' );
		} catch ( \Throwable ) {
			$this->type = 'warning';
			$this->message = $draft['summary'] . ' ' . __( 'A customer date could not be calculated from these entries. Nothing was saved.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	/** @param array{missing: list<string>, calendar: mixed, policy: mixed, summary: string} $draft @param array<string, mixed> $offer */
	private function save( array $draft, array $offer ): void {
		if ( ! $draft['policy'] instanceof ServicePromisePolicy ) { return; }
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$store = new PromiseStaffIntent();
			if ( null !== $store->read( $this->intent_name() ) ) { throw new \RuntimeException( 'Resolve the previous request first.' ); }
			$facts = $draft['policy']->private_facts();
			$id = $facts['policy_id'];
			$scope = [ 'kind' => $facts['scope']['kind'], 'target_id' => (int) ( $facts['scope']['target_id'] ?? 0 ) ];
			$prior = $this->index()[ $id ] ?? null;
			$prior = is_array( $prior ) ? $prior : null;
			$opened = $this->service->inspect_version( $binding, 'policy', $id, max( 1, (int) ( $prior['version'] ?? 1 ) ), $scope );
			$calendar_opened = null;
			if ( null === $prior ) {
				if ( 0 !== $opened['object_revision'] || null !== $opened['version'] ) { throw new \RuntimeException( 'An existing document is not owned by this guide.' ); }
			} else {
				$this->assert_owned( $prior, $opened );
				$calendar_opened = $this->open_calendar( $prior, $opened );
				if ( $id !== $this->values['review_id'] || ! hash_equals( $this->review_stamp( $opened, $calendar_opened ), $this->values['review_stamp'] ) ) {
					throw new \RuntimeException( 'Reopen the saved rule before replacing it; its revisions may have changed.' );
				}
			}
			$calendar = $draft['calendar'];
			if ( $calendar instanceof BusinessCalendarVersion && null === $calendar_opened ) {
				// Calendar authority, sequence and CAS are independent of the policy's scope/head.
				$calendar_opened = $this->service->inspect_version( $binding, 'calendar', $calendar->private_facts()['calendar_id'], 1, [ 'kind' => 'global', 'target_id' => 0 ] );
				if ( 0 !== $calendar_opened['object_revision'] || null !== $calendar_opened['version'] ) { throw new \RuntimeException( 'An existing calendar is not owned by this guide.' ); }
			}
			$version = $opened['next_version'];
			$calendar_version = $calendar instanceof BusinessCalendarVersion ? $calendar_opened['next_version'] : null;
			$draft = PromiseStaffDraft::assemble( $this->values, $offer, $version, $calendar_version );
			if ( [] !== $draft['missing'] || ! $draft['policy'] instanceof ServicePromisePolicy ) { throw new \RuntimeException( 'The draft cannot be represented.' ); }
			$steps = [];
			$policy_pre = $this->create_preconditions( $opened );
			$calendar = $draft['calendar'];
			if ( $calendar instanceof BusinessCalendarVersion ) {
				$calendar_pre = $this->create_preconditions( $calendar_opened );
				$this->replacement_step( $steps, 'calendar', $calendar->private_facts()['calendar_id'], [ 'kind' => 'global', 'target_id' => 0 ], $calendar_opened, $calendar_pre );
				$steps[] = $this->step( 'promise.version.create', $this->payload( 'calendar', $calendar->private_facts(), $calendar->to_private_json(), $calendar->digest(), [ 'kind' => 'global', 'target_id' => 0 ], $calendar_pre ) );
			}
			$this->replacement_step( $steps, 'policy', $id, $scope, $opened, $policy_pre );
			$policy = $draft['policy']; $facts = $policy->private_facts();
			$steps[] = $this->step( 'promise.version.create', $this->payload( 'policy', $facts, $policy->to_private_json(), $policy->digest(), $scope, $policy_pre ) );
			$row = $this->index_row( $facts, $policy->digest(), $calendar, $prior, (string) ( $offer['public_label'] ?? '' ) );
			$plan = [ 'format_version' => 1, 'owner' => get_current_user_id(), 'site_id' => $binding->site_id(), 'site_key' => $binding->site_key(), 'scope' => $scope,
				'intent_id' => RequestContext::create()->request_id, 'state' => 'ready', 'cursor' => 0, 'steps' => $steps, 'row' => $row, 'prior' => $prior, 'fields' => $this->values ];
			if ( ! $store->insert( $this->intent_name(), PromiseStaffIntent::encode( $plan ) ) ) { throw new \RuntimeException( 'A previous or concurrent request needs review.' ); }
			$this->resume();
		} catch ( \Throwable ) {
			$this->type = 'warning';
			$this->message = __( 'The draft could not be confirmed. Reopen the saved rule and explicitly confirm replacement of a draft, or resolve the retained request below. Existing advanced documents are preserved.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	/** @param array<string, mixed> $opened @return array<string, int> */
	private function create_preconditions( array $opened ): array {
		return [ 'object_revision' => (int) $opened['object_revision'], 'version_revision' => 0, 'published_version_id' => (int) $opened['published_version_id'] ];
	}

	/** Retiring a draft is an explicit immutable lifecycle operation, never an in-place edit. */
	private function replacement_step( array &$steps, string $kind, string $id, array $scope, array $opened, array &$pre ): void {
		$saved = $opened['version'];
		if ( ! is_array( $saved ) ) { return; }
		if ( ! in_array( $saved['state'], [ 'draft', 'sealed', 'published', 'retired' ], true ) ) { throw new \RuntimeException( 'This state cannot be edited here.' ); }
		if ( in_array( $saved['state'], [ 'draft', 'sealed' ], true ) ) {
			if ( 'yes' !== $this->values['replace_draft'] ) { throw new \RuntimeException( 'Draft replacement was not confirmed.' ); }
			$payload = $this->lifecycle_payload( $kind, $id, $scope, $opened );
			$payload['reason'] = __( 'Staff explicitly replaced the saved draft from the delivery rule guide.', 'cetech-woocommerce-delivery-engine' );
			$steps[] = $this->step( 'promise.version.retire', $payload );
			++$pre['object_revision'];
		}
	}

	private function step( string $operation, array $payload ): array {
		return [ 'operation' => $operation, 'token' => RequestContext::create()->request_id, 'payload' => $payload ];
	}

	private function intent_name(): string {
		$site = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		return 'cetech_de_staff_intent_' . hash( 'sha256', $site . ':' . PromiseStaffDraft::SITE_KEY . ':' . get_current_user_id() );
	}

	/** Only the current native owner/site may disclose or execute its private UI journal. */
	private function pending( ?PromiseStaffIntent $store = null ): ?array {
		$bytes = ( $store ?? new PromiseStaffIntent() )->read( $this->intent_name() );
		if ( null === $bytes ) { return null; }
		$plan = PromiseStaffIntent::decode( $bytes );
		$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
		if ( $plan['owner'] !== get_current_user_id() || $plan['site_id'] !== $binding->site_id() || $plan['site_key'] !== $binding->site_key() || ! $this->service->can_disclose( $binding, $plan['scope'] ) ) { throw new \RuntimeException( 'Staff intent is not currently available.' ); }
		foreach ( $plan['steps'] as $step ) {
			if ( ! $this->service->can_disclose( $binding, $step['payload']['scope'] ) ) { throw new \RuntimeException( 'Staff intent scope is not currently available.' ); }
		}
		return [ 'bytes' => $bytes, 'plan' => $plan ];
	}

	private function resume( bool $retry_original = false ): void {
		try {
			$store = new PromiseStaffIntent();
			$store->locked( $this->intent_name(), function () use ( $store, $retry_original ): void {
			$pending = $this->pending( $store );
			if ( null === $pending ) { throw new \RuntimeException( 'No retained request.' ); }
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$state = $store->run( $this->intent_name(), $pending['bytes'], fn( array $step, bool $reconcile ) => $this->service->version( $binding, $step['operation'], $step['token'], $step['payload'], $reconcile ), $retry_original );
			$pending = $this->pending( $store );
			if ( null === $pending ) { throw new \RuntimeException( 'Retained request changed.' ); }
			$plan = $pending['plan'];
			if ( 'complete' === $state && 'complete' === $plan['state'] ) {
				if ( ! $store->merge_index( self::INDEX, $plan['row']['policy_id'], $plan['prior'], $plan['row'] ) || ! $store->replace( $this->intent_name(), $pending['bytes'], null ) ) { throw new \RuntimeException( 'Saved projection is pending.' ); }
				$this->type = 'success';
				$this->message = __( 'Draft saved. Customer estimates remain unchanged. Review and publish the working calendar first, then the rule, using the separate protected actions below.', 'cetech-woocommerce-delivery-engine' );
				return;
			}
			$this->type = 'warning';
			$this->message = 'rejected' === $plan['state']
				? __( 'The request was refused. Earlier accepted steps are retained. Acknowledge the stopped request below before preparing a new draft.', 'cetech-woocommerce-delivery-engine' )
				: __( 'The outcome is not confirmed. The exact original request is retained below. Reconcile it before making another draft.', 'cetech-woocommerce-delivery-engine' );
			} );
		} catch ( \Throwable ) {
			$this->type = 'warning';
			$this->message = __( 'The retained request could not be confirmed. It was kept for reconciliation; no replacement request was created.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	/** A known rejection can be acknowledged; accepted calendar/retirement steps remain recoverable. */
	private function acknowledge(): void {
		try {
			$store = new PromiseStaffIntent();
			$store->locked( $this->intent_name(), function () use ( $store ): void {
			$pending = $this->pending( $store );
			if ( null === $pending || 'rejected' !== $pending['plan']['state'] || ! empty( $pending['plan']['had_uncertainty'] ) ) { throw new \RuntimeException( 'Only a known refusal can be acknowledged.' ); }
			$plan = $pending['plan'];
			if ( 0 === $plan['cursor'] ) {
				if ( ! $store->replace( $this->intent_name(), $pending['bytes'], null ) ) { throw new \RuntimeException( 'Stopped request acknowledgement was not confirmed.' ); }
				$this->message = __( 'Stopped request acknowledged. No accepted step needed a saved-rule projection.', 'cetech-woocommerce-delivery-engine' );
				return;
			}
			$row = $plan['prior'] ?? $plan['row'];
			if ( null === $plan['prior'] ) { $row['version'] = 0; $row['digest'] = ''; $row['uses_calendar'] = false; $row['calendar_id'] = ''; $row['calendar_version'] = 0; $row['calendar_digest'] = ''; }
			for ( $i = 0; $i < $plan['cursor']; ++$i ) {
				$step = $plan['steps'][ $i ]; $payload = $step['payload'];
				if ( 'calendar' === $payload['kind'] && 'promise.version.create' === $step['operation'] ) {
					$row['calendar_id'] = $payload['logical_id']; $row['calendar_version'] = $payload['domain_version']; $row['calendar_digest'] = $payload['body_digest'];
				}
			}
			$row['fields'] = $plan['fields'];
			if ( ! $store->merge_index( self::INDEX, $row['policy_id'], $plan['prior'], $row ) || ! $store->replace( $this->intent_name(), $pending['bytes'], null ) ) { throw new \RuntimeException( 'Recovery projection remains pending.' ); }
			$this->message = __( 'Stopped request acknowledged. Accepted steps are preserved below. Reopen the rule to prepare a new draft against its current native revisions.', 'cetech-woocommerce-delivery-engine' );
			} );
		} catch ( \Throwable ) {
			$this->type = 'warning'; $this->message = __( 'The stopped request remains retained. Nothing was discarded.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	private function pending_form(): void {
		try {
			$pending = $this->pending();
			if ( null === $pending ) { return; }
			$rejected = 'rejected' === $pending['plan']['state'];
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'A saved draft request needs reconciliation or acknowledgement. Another save is blocked until it is resolved.', 'cetech-woocommerce-delivery-engine' ) . '</p><form method="post" action="">';
			AdminFormHelper::nonce_field( self::ACTION );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="guide_step" value="' . ( $rejected ? 'acknowledge' : 'resume' ) . '" />';
			echo '<button class="button" type="submit">' . esc_html( $rejected ? __( 'Acknowledge stopped request', 'cetech-woocommerce-delivery-engine' ) : __( 'Reconcile original request', 'cetech-woocommerce-delivery-engine' ) ) . '</button></form>';
			if ( ! $rejected && in_array( $pending['plan']['state'], [ 'inflight', 'uncertain' ], true ) ) {
				echo '<form method="post" action="">'; AdminFormHelper::nonce_field( self::ACTION );
				echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="guide_step" value="retry" /><button class="button" type="submit">' . esc_html__( 'Retry the exact original request', 'cetech-woocommerce-delivery-engine' ) . '</button></form>';
			}
			echo '</div>';
		} catch ( \Throwable ) {
			echo '<p>' . esc_html__( 'Private draft recovery is unavailable under the current permissions or storage state.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
	}

	private function review( string $id ): void {
		try {
			$row = $this->index()[ $id ] ?? null;
			if ( ! is_array( $row ) || ! is_array( $row['fields'] ?? null ) ) { throw new \RuntimeException( 'No guided rule.' ); }
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$scope = $this->row_scope( $row );
			$opened = $this->service->inspect_version( $binding, 'policy', $id, max( 1, (int) $row['version'] ), $scope );
			$this->assert_owned( $row, $opened );
			$calendar = $this->open_calendar( $row, $opened );
			if ( is_array( $opened['version'] ) && ! in_array( $opened['version']['state'], [ 'draft', 'published', 'retired' ], true ) ) { throw new \RuntimeException( 'This saved state cannot be edited here.' ); }
			if ( ! $this->service->can_disclose( $binding, $scope ) || ( null !== $calendar && ! $this->service->can_disclose( $binding, [ 'kind' => 'global', 'target_id' => 0 ] ) ) ) { throw new \RuntimeException( 'Current disclosure permission changed.' ); }
			foreach ( $row['fields'] as $key => $value ) {
				if ( is_string( $key ) && is_string( $value ) ) { $this->values[ $key ] = $value; }
			}
			$this->values['review_id'] = $id;
			$this->values['review_stamp'] = $this->review_stamp( $opened, $calendar );
			$this->values['replace_draft'] = '';
			$this->message = __( 'Saved rule reopened. Changed entries create a new immutable version. Confirm retirement of an existing draft before saving its replacement.', 'cetech-woocommerce-delivery-engine' );
		} catch ( \Throwable ) {
			$this->type = 'warning';
			$this->message = __( 'This rule could not be confirmed under the current permissions and revisions. No saved values were copied into the form.', 'cetech-woocommerce-delivery-engine' );
		}
	}

	private function row_scope( array $row ): array {
		return [ 'kind' => (string) ( $row['scope_kind'] ?? '' ), 'target_id' => (int) ( $row['scope_id'] ?? 0 ) ];
	}

	/** An index entry proves no authority: confirm exact current owned native content and sequence. */
	private function assert_owned( array $row, array $opened ): void {
		$version = (int) ( $row['version'] ?? 0 );
		$saved = $opened['version'];
		if ( 0 === $version ) {
			if ( 0 !== $opened['object_revision'] || null !== $saved || 1 !== $opened['next_version'] ) { throw new \RuntimeException( 'Policy absence was not confirmed.' ); }
			return;
		}
		if ( ! is_array( $saved ) || $saved['body_digest'] !== ( $row['digest'] ?? '' ) || $saved['domain_version'] !== $version || $opened['next_version'] !== $version + 1 ) { throw new \RuntimeException( 'Guided content changed outside its saved receipt.' ); }
	}

	/** @return array<string, mixed>|null */
	private function open_calendar( array $row, array $policy_opened ): ?array {
		$version = (int) ( $row['calendar_version'] ?? 0 );
		if ( $version < 1 ) { return null; }
		$id = (string) ( $row['calendar_id'] ?? '' );
		$digest = (string) ( $row['calendar_digest'] ?? '' );
		// Older guide projections can be rebuilt only from their exact policy reference.
		if ( '' === $id || '' === $digest ) {
			$body = ServicePromisePolicy::from_json( $policy_opened['version']['body_json'] );
			$refs = $body->private_facts()['calendars'];
			if ( 1 !== count( $refs ) || $refs[0]['version'] !== $version ) { throw new \RuntimeException( 'Calendar ownership is unavailable.' ); }
			$id = $refs[0]['calendar_id']; $digest = $refs[0]['digest'];
		}
		$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
		$opened = $this->service->inspect_version( $binding, 'calendar', $id, $version, [ 'kind' => 'global', 'target_id' => 0 ] );
		if ( ! is_array( $opened['version'] ) || $opened['version']['body_digest'] !== $digest || $opened['next_version'] !== $version + 1 ) { throw new \RuntimeException( 'Calendar changed outside its saved receipt.' ); }
		return $opened;
	}

	private function review_stamp( array $opened, ?array $calendar ): string {
		return hash( 'sha256', PromiseStaffIntent::encode( [ 'policy' => $opened, 'calendar' => $calendar ] ) );
	}

	/** @return array{label: string, code: string, editable: bool, next: string} */
	private function confirm( array $row ): array {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$opened = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], max( 1, (int) $row['version'] ), $this->row_scope( $row ) );
			$this->assert_owned( $row, $opened );
			$state = null === $opened['version'] ? 'incomplete' : (string) $opened['version']['state'];
			return [ 'label' => 'incomplete' === $state ? __( 'Stopped request; accepted steps preserved', 'cetech-woocommerce-delivery-engine' ) : $this->state_label( $state ), 'code' => $state,
				'editable' => in_array( $state, [ 'draft', 'published', 'retired', 'incomplete' ], true ), 'next' => __( 'Review this state in advanced configuration.', 'cetech-woocommerce-delivery-engine' ) ];
		} catch ( \Throwable ) {
			return [ 'label' => __( 'Not confirmed', 'cetech-woocommerce-delivery-engine' ), 'code' => 'unknown', 'editable' => false, 'next' => __( 'Refresh before trying again. Existing documents are preserved.', 'cetech-woocommerce-delivery-engine' ) ];
		}
	}

	/** @param array<string, mixed> $row */
	private function calendar_action( array $row ): bool {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$opened = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], (int) $row['version'], $this->row_scope( $row ) );
			$this->assert_owned( $row, $opened );
			$policy = ServicePromisePolicy::from_json( $opened['version']['body_json'] );
			$refs = $policy->private_facts()['calendars'];
			if ( [] === $refs ) { return true; }
			$calendar = $this->open_calendar( $row, $opened );
			if ( null === $calendar || 1 !== count( $refs ) || $refs[0]['version'] !== $calendar['version']['domain_version'] || $refs[0]['digest'] !== $calendar['version']['body_digest'] ) { throw new \RuntimeException( 'Dependent calendar is not owned.' ); }
			$state = $calendar['version']['state'];
			if ( 'published' === $state ) { return true; }
			echo '<p>' . esc_html__( 'Publish the working calendar before marking this rule ready.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			if ( 'draft' === $state || 'sealed' === $state ) {
				$this->protected_action( $row, 'draft' === $state ? 'seal' : 'publish', 'draft' === $state ? __( 'Mark working calendar ready', 'cetech-woocommerce-delivery-engine' ) : __( 'Publish working calendar', 'cetech-woocommerce-delivery-engine' ), 'calendar' );
			}
			return false;
		} catch ( \Throwable ) {
			echo '<p>' . esc_html__( 'The dependent working calendar cannot currently be confirmed. No publication is offered.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
			return false;
		}
	}

	private function lifecycle_payload( string $kind, string $id, array $scope, array $opened ): array {
		$saved = $opened['version'];
		return [ 'kind' => $kind, 'logical_id' => $id, 'domain_version' => (int) $saved['domain_version'], 'version_uuid' => (string) $saved['version_uuid'],
			'body_digest' => (string) $saved['body_digest'], 'scope' => $scope, 'body_json' => null, 'declared_from' => $saved['declared_from'], 'declared_until' => $saved['declared_until'],
			'reason' => '', 'scheduled_author_user_id' => null,
			'preconditions' => [ 'object_revision' => (int) $opened['object_revision'], 'version_revision' => (int) $saved['row_revision'], 'published_version_id' => (int) $opened['published_version_id'] ] ];
	}

	/** @param array<string, mixed> $row */
	private function protected_action( array $row, string $verb, string $label, string $kind = 'policy' ): void {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$scope = $this->row_scope( $row );
			$opened = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], (int) $row['version'], $scope );
			$this->assert_owned( $row, $opened );
			$id = (string) $row['policy_id'];
			if ( 'calendar' === $kind ) {
				$policy = ServicePromisePolicy::from_json( $opened['version']['body_json'] );
				$refs = $policy->private_facts()['calendars'];
				$opened = $this->open_calendar( $row, $opened );
				if ( null === $opened || 1 !== count( $refs ) || $refs[0]['digest'] !== $opened['version']['body_digest'] ) { return; }
				$id = $refs[0]['calendar_id']; $scope = [ 'kind' => 'global', 'target_id' => 0 ];
			}
			$payload = $this->lifecycle_payload( $kind, $id, $scope, $opened );
			if ( ! $this->service->can_disclose( $binding, $this->row_scope( $row ) ) || ! $this->service->can_disclose( $binding, $scope ) ) { return; }
			$action = PromiseConfigurationPage::ACTION_PREFIX . $verb;
			echo '<form method="post" action="">';
			AdminFormHelper::nonce_field( $action );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
			echo '<input type="hidden" name="site_key" value="' . esc_attr( PromiseStaffDraft::SITE_KEY ) . '" />';
			echo '<input type="hidden" name="command_json" value="' . esc_attr( PromiseJson::encode( $payload ) ) . '" />';
			echo '<input type="hidden" name="request_token" value="' . esc_attr( RequestContext::create()->request_id ) . '" />';
			echo '<label>' . esc_html__( 'Reason', 'cetech-woocommerce-delivery-engine' ) . ' <input name="reason" required /></label>';
			echo '<button class="button" type="submit">' . esc_html( $label ) . '</button></form>';
		} catch ( \Throwable ) {
			echo '<p>' . esc_html__( 'The protected action is unavailable until this saved rule can be confirmed.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
	}

	/** @param array<string, mixed> $row */
	private function assignment_action( array $row ): void {
		try {
			$binding = $this->service->binding( PromiseStaffDraft::SITE_KEY );
			$scope   = [ 'kind' => (string) ( $row['scope_kind'] ?? 'global' ), 'target_id' => (int) ( $row['scope_id'] ?? 0 ) ];
			$opened  = $this->service->inspect_version( $binding, 'policy', (string) $row['policy_id'], (int) $row['version'], $scope );
			$saved   = is_array( $opened['version'] ?? null ) ? $opened['version'] : null;
			if ( null === $saved || ( $saved['body_digest'] ?? '' ) !== ( $row['digest'] ?? '' ) || ! is_string( $saved['body_json'] ?? null ) ) {
				return;
			}
			$policy = ServicePromisePolicy::from_json( $saved['body_json'] );
			$facts  = $policy->private_facts();
			$key    = [ 'scope_kind' => $scope['kind'], 'scope_id' => $scope['target_id'], 'service_kind' => (string) $facts['service']['kind'], 'service_code' => (string) $facts['service']['code'], 'endpoint' => (string) $facts['endpoint'], 'endpoint_kind' => (string) $facts['endpoint_kind'] ];
			$assignment = $this->service->inspect_assignment( $binding, $key );
			$current = [ 'revision' => (int) $assignment['revision'], 'generation' => (int) $assignment['generation'] ];
			if ( ! $this->service->can_disclose( $binding, $scope ) ) { return; }
			$action = PromiseConfigurationPage::ACTION_PREFIX . 'assignment';
			echo '<form method="post" action="">';
			AdminFormHelper::nonce_field( $action );
			echo '<input type="hidden" name="cetech_de_action" value="' . esc_attr( $action ) . '" />';
			echo '<input type="hidden" name="site_key" value="' . esc_attr( PromiseStaffDraft::SITE_KEY ) . '" />';
			foreach ( $key as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
			}
			echo '<input type="hidden" name="mode" value="assigned" />';
			echo '<input type="hidden" name="policy_reference_json" value="' . esc_attr( PromiseJson::encode( $policy->reference()->private_facts() ) ) . '" />';
			echo '<input type="hidden" name="expected_revision" value="' . esc_attr( (string) $current['revision'] ) . '" />';
			echo '<input type="hidden" name="expected_generation" value="' . esc_attr( (string) $current['generation'] ) . '" />';
			echo '<input type="hidden" name="request_token" value="' . esc_attr( RequestContext::create()->request_id ) . '" />';
			echo '<label>' . esc_html__( 'Reason for assigning this published rule', 'cetech-woocommerce-delivery-engine' ) . ' <input name="reason" required /></label>';
			echo '<button class="button" type="submit">' . esc_html__( 'Assign published rule', 'cetech-woocommerce-delivery-engine' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'Assignment does not turn customer estimates on.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		} catch ( \Throwable ) {
			echo '<p>' . esc_html__( 'Assignment is unavailable until this published rule can be confirmed.', 'cetech-woocommerce-delivery-engine' ) . '</p>';
		}
	}

	private function state_label( string $state ): string {
		return match ( $state ) {
			'draft' => __( 'Draft', 'cetech-woocommerce-delivery-engine' ),
			'sealed' => __( 'Ready to publish', 'cetech-woocommerce-delivery-engine' ),
			'scheduled' => __( 'Scheduled', 'cetech-woocommerce-delivery-engine' ),
			'published' => __( 'Published', 'cetech-woocommerce-delivery-engine' ),
			'retired' => __( 'Retired', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Saved', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	/** @param array<string, mixed> $facts @param array<string, int> $preconditions @return array<string, mixed> */
	private function payload( string $kind, array $facts, string $json, string $digest, array $scope, array $preconditions ): array {
		$id = 'policy' === $kind ? (string) $facts['policy_id'] : (string) $facts['calendar_id'];
		return [
			'kind' => $kind, 'logical_id' => $id, 'domain_version' => (int) $facts['version'], 'version_uuid' => RequestContext::create()->request_id,
			'body_digest' => $digest, 'scope' => $scope, 'body_json' => $json,
			'declared_from' => 'policy' === $kind ? (string) $facts['effective_from'] : gmdate( 'Y-m-d H:i:s' ) . '.000000',
			'declared_until' => 'policy' === $kind ? $facts['effective_until'] : null,
			'reason' => __( 'Saved from the delivery rule guide.', 'cetech-woocommerce-delivery-engine' ),
			'scheduled_author_user_id' => null, 'preconditions' => $preconditions,
		];
	}

	/** The index is a rebuildable projection of confirmed commands, never lifecycle authority. */
	private function index_row( array $facts, string $digest, ?BusinessCalendarVersion $calendar, ?array $prior, string $label ): array {
		$scope = (string) $facts['scope']['kind']; $target = (int) ( $facts['scope']['target_id'] ?? 0 );
		$fields = $this->values;
		foreach ( [ 'guide_step', 'review_id', 'review_stamp', 'replace_draft' ] as $key ) { unset( $fields[ $key ] ); }
		return [ 'policy_id' => (string) $facts['policy_id'], 'version' => (int) $facts['version'],
			'calendar_id' => null === $calendar ? ( $prior['calendar_id'] ?? '' ) : $calendar->private_facts()['calendar_id'],
			'calendar_version' => null === $calendar ? (int) ( $prior['calendar_version'] ?? 0 ) : $calendar->private_facts()['version'],
			'calendar_digest' => null === $calendar ? ( $prior['calendar_digest'] ?? '' ) : $calendar->digest(), 'uses_calendar' => null !== $calendar,
			'scope_kind' => $scope, 'scope_id' => $target, 'label' => '' !== $label ? $label : (string) $facts['service']['customer_label'],
			'applies' => 'global' === $scope ? __( 'Whole store', 'cetech-woocommerce-delivery-engine' ) : ( 'product' === $scope ? __( 'One product', 'cetech-woocommerce-delivery-engine' ) : __( 'One variation', 'cetech-woocommerce-delivery-engine' ) ),
			'digest' => $digest, 'fields' => $fields ];
	}

	/** @return array<string, mixed> */
	private function index(): array {
		$bytes = ( new PromiseStaffIntent() )->read( self::INDEX );
		if ( null === $bytes ) { return []; }
		$index = unserialize( $bytes, [ 'allowed_classes' => false ] );
		if ( ! is_array( $index ) ) { throw new \RuntimeException( 'The guided projection is unavailable.' ); }
		return $index;
	}

	/** @return array<string, mixed>|null */
	private function offer( string $code ): ?array {
		foreach ( $this->offers() as $offer ) {
			if ( $offer['internal_code'] === $code ) {
				return $offer;
			}
		}
		return null;
	}

	/** @return list<array{internal_code: string, public_label: string, route: string}> */
	private function offers(): array {
		try {
			$rows = ( new WpdbDeliveryOfferRepository() )->list( [ 'status' => RecordStatus::Active->value, 'limit' => 200 ] );
		} catch ( \Throwable ) {
			return [];
		}
		$offers = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$code = trim( (string) ( $row['internal_code'] ?? '' ) );
			$label = trim( (string) ( $row['public_label'] ?? '' ) );
			if ( '' === $code || '' === $label ) {
				continue;
			}
			$offers[] = [ 'internal_code' => $code, 'public_label' => $label, 'route' => (string) ( $row['route'] ?? '' ) ];
		}
		usort( $offers, static fn( array $a, array $b ): int => strcasecmp( $a['public_label'], $b['public_label'] ) );
		return $offers;
	}

	/** @return list<array{id: int, name: string}> */
	private function products(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}
		$products = wc_get_products( [ 'status' => 'publish', 'limit' => 100, 'orderby' => 'title', 'order' => 'ASC', 'return' => 'objects' ] );
		$out = [];
		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product && ! $product->is_type( 'variation' ) ) {
				$out[] = [ 'id' => $product->get_id(), 'name' => $product->get_name() ];
			}
		}
		return $out;
	}

	/** @return list<array{id: int, name: string}> */
	private function variations(): array {
		$product_id = (int) ( $this->values['product_id'] ?? 0 );
		if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return [];
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
			return [];
		}
		$out = [];
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof \WC_Product && $child->is_type( 'variation' ) ) {
				$out[] = [ 'id' => $child->get_id(), 'name' => wc_get_formatted_variation( $child, true, false, false ) ];
			}
		}
		return $out;
	}

	private function target_id(): int {
		$scope = $this->values['scope'] ?? '';
		if ( 'global' === $scope ) {
			return 0;
		}
		$id = (int) ( 'variation' === $scope ? ( $this->values['variation_id'] ?? 0 ) : ( $this->values['product_id'] ?? 0 ) );
		if ( $id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}
		if ( 'product' === $scope ) {
			return $product->is_type( 'variation' ) ? 0 : $id;
		}
		return $product->is_type( 'variation' ) && $product->get_parent_id() === (int) ( $this->values['product_id'] ?? 0 ) ? $id : 0;
	}

	/** @param array<string, mixed> $post @return array<string, string> */
	private static function fields( array $post ): array {
		$keys = [ 'guide_step', 'review_id', 'review_stamp', 'replace_draft', 'offer_code', 'processing_min', 'processing_max', 'delivery_min', 'delivery_max', 'unit', 'timezone', 'closures', 'cutoff', 'cutoff_miss', 'scope', 'product_id', 'variation_id', 'estimate_required', 'mon_open', 'mon_close', 'tue_open', 'tue_close', 'wed_open', 'wed_close', 'thu_open', 'thu_close', 'fri_open', 'fri_close', 'sat_open', 'sat_close', 'sun_open', 'sun_close' ];
		$out = [];
		foreach ( $keys as $key ) {
			$value = $post[ $key ] ?? '';
			if ( ! is_string( $value ) || strlen( $value ) > 2000 ) {
				$value = '';
			}
			$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
			if ( 'closures' === $key && function_exists( 'sanitize_textarea_field' ) ) {
				$out[ $key ] = sanitize_textarea_field( $raw );
				continue;
			}
			$out[ $key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : trim( $raw );
		}
		return $out;
	}

	private function number( string $name, string $label ): void {
		echo '<label>' . esc_html( $label ) . ' <input name="' . esc_attr( $name ) . '" inputmode="numeric" value="' . esc_attr( $this->values[ $name ] ?? '' ) . '" /></label> ';
	}

	private function styles(): void {
		echo '<style>.cetech-de-guide{max-width:46rem}.cetech-de-guide form,.cetech-de-guide select,.cetech-de-guide input,.cetech-de-guide textarea{max-width:100%}.cetech-de-guide fieldset{margin:1rem 0;padding:1rem}.cetech-de-guide label,.cetech-de-guide select,.cetech-de-guide input,.cetech-de-guide textarea{display:block;margin:.4rem 0}.cetech-de-day{display:flex;flex-wrap:wrap;gap:.75rem;align-items:end}.cetech-de-guide .cetech-de-products{margin-top:.75rem}.cetech-de-guide-form:not(:has(input[name=scope][value=product]:checked)):not(:has(input[name=scope][value=variation]:checked)) .cetech-de-products{display:none}.cetech-de-guide-form:not(:has(input[name=scope][value=variation]:checked)) #cetech-de-variation{display:none}@media (max-width:782px){.cetech-de-guide .button{display:block;margin:.5rem 0}}.cetech-de-guide{max-width:min(46rem,100%);overflow-wrap:anywhere}.cetech-de-guide fieldset{min-width:0;max-width:100%;box-sizing:border-box}.cetech-de-guide select,.cetech-de-guide input,.cetech-de-guide textarea{min-width:0;width:100%;max-width:100%;box-sizing:border-box}.cetech-de-guide table{display:block;max-width:100%;overflow-x:auto}.cetech-de-day label{width:auto;min-width:8rem;flex:1 1 8rem}</style>';
	}
}
