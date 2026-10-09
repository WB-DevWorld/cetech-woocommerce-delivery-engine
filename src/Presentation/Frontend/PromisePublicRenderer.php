<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;

/** Escaped shared native markup for exact captured customer text. */
final class PromisePublicRenderer {
	public static function original( array $projection ): string {
		PromiseShape::fields( $projection, [ 'contract_version', 'original', 'groups' ] ); if ( 1 !== $projection['contract_version'] || true !== $projection['original'] ) { PromiseShape::invalid(); }
		$html = '<ul class="cetech-de-promise-original" aria-label="' . esc_attr__( 'Original recorded delivery estimate', 'cetech-woocommerce-delivery-engine' ) . '">';
		foreach ( PromiseShape::list( $projection['groups'], 1, 200 ) as $group ) { PromiseShape::fields( $group, [ 'views', 'customer_text' ] ); $html .= '<li data-cetech-de-original-promise="1">' . esc_html( PromiseShape::text( $group['customer_text'], 2048 ) ) . '</li>'; }
		return $html . '</ul>';
	}
	public static function preliminary( array $projection ): string {
		PromiseShape::fields( $projection, [ 'contract_version', 'preliminary', 'admission', 'destination_complete', 'views', 'text', 'notice' ] ); if ( 1 !== $projection['contract_version'] || true !== $projection['preliminary'] || false !== $projection['admission'] ) { PromiseShape::invalid(); }
		return '<div class="cetech-de-promise-preliminary" data-cetech-de-promise-preliminary="1" role="note"><p>' . esc_html( $projection['notice'] ) . '</p>' . ( '' !== $projection['text'] ? '<p>' . esc_html( $projection['text'] ) . '</p>' : '' ) . '</div>';
	}
}
