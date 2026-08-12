<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping;

use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingMethodLabel;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Bootstrap\Plugin;
use WC_Shipping_Method;

/**
 * WooCommerce shipping method for validated selected delivery offers.
 *
 * Customer-facing labels stay operational (Delivery / Delivery N / Store pickup).
 */
final class SelectedOfferShippingMethod extends WC_Shipping_Method {

	public const METHOD_ID = 'delivery_engine_selected_offer';

	public const RATE_LABEL = 'Delivery';

	public function __construct( int $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		// Operational staff/customer label — never show implementation method ids here.
		$this->method_title       = __( 'Delivery', 'cetech-woocommerce-delivery-engine' );
		$this->method_description = __(
			'Uses the customer’s selected delivery option and configured rate cards.',
			'cetech-woocommerce-delivery-engine'
		);
		$this->supports           = [
			'shipping-zones',
			'instance-settings',
		];

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title      = self::RATE_LABEL;
		$this->tax_status = $this->get_option( 'tax_status', 'taxable' );

		add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			[ $this, 'process_admin_options' ]
		);
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title' => [
				'title'       => __( 'Method title', 'cetech-woocommerce-delivery-engine' ),
				'type'        => 'text',
				'description' => __( 'Customer-facing shipping label. Defaults to "Delivery".', 'cetech-woocommerce-delivery-engine' ),
				'default'     => self::RATE_LABEL,
				'desc_tip'    => true,
			],
			'tax_status' => [
				'title'   => __( 'Tax status', 'cetech-woocommerce-delivery-engine' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => [
					'taxable' => __( 'Taxable', 'cetech-woocommerce-delivery-engine' ),
					'none'    => _x( 'None', 'Tax status', 'cetech-woocommerce-delivery-engine' ),
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public function calculate_shipping( $package = [] ): void {
		$calculator = $this->calculator();

		if ( null === $calculator || ! $calculator->is_runtime_active() ) {
			return;
		}

		if ( ! is_array( $package ) ) {
			return;
		}

		$result = $calculator->calculate_for_package( $package );

		if ( ! $result->success || null === $result->total_amount ) {
			return;
		}

		$meta  = DeliveryGroupIdentity::package_meta( $package );
		$label = $this->label_for_package( $meta );
		$suffix = $this->rate_suffix_for_package( $meta );

		$this->add_rate(
			[
				'id'    => $this->get_rate_id( $suffix ),
				'label' => $label,
				'cost'  => max( 0, (float) $result->total_amount ),
				'meta_data' => [
					'cetech_de_group_id' => is_array( $meta ) ? (string) ( $meta['group_id'] ?? '' ) : '',
				],
			]
		);
	}

	/**
	 * @param array<string, mixed>|null $meta
	 */
	private function label_for_package( ?array $meta ): string {
		if ( is_array( $meta ) && isset( $meta['rate_label'] ) && is_string( $meta['rate_label'] ) && '' !== trim( $meta['rate_label'] ) ) {
			return (string) $meta['rate_label'];
		}

		if ( is_string( $this->title ) && '' !== trim( (string) $this->title ) ) {
			return (string) $this->title;
		}

		return SelectedOfferShippingMethodLabel::default_delivery_label();
	}

	/**
	 * Unique rate id suffix per managed package so multi-package carts do not collide.
	 *
	 * @param array<string, mixed>|null $meta
	 */
	private function rate_suffix_for_package( ?array $meta ): string {
		if ( ! is_array( $meta ) ) {
			return '';
		}

		$group_id = isset( $meta['group_id'] ) ? (string) $meta['group_id'] : '';

		if ( '' === $group_id ) {
			return '';
		}

		return substr( hash( 'sha256', $group_id ), 0, 12 );
	}

	private function calculator(): ?SelectedOfferShippingRateCalculator {
		if ( ! class_exists( Plugin::class ) ) {
			return null;
		}

		$plugin = Plugin::instance();

		if ( ! $plugin->container()->has( SelectedOfferShippingRateCalculator::class ) ) {
			return null;
		}

		return $plugin->container()->get( SelectedOfferShippingRateCalculator::class );
	}
}
