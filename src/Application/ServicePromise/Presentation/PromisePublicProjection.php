<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Presentation;

use CetechDeliveryEngine\Domain\ServicePromise\{PublicPromiseView, PromiseShape};
use CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseQuotePacket;

/** Presentation only. Calling this supplies neither owner authorization nor acceptance authority. */
final class PromisePublicProjection {
	public static function quote( PromiseQuotePacket $packet ): array {
		$groups = [];
		foreach ( $packet->public_groups() as $group ) {
			$views = array_map( static fn( array $fields ): array => PublicPromiseView::from_array( $fields )->fields(), $group['views'] );
			// Original text is frozen at native capture. Locale changes never rewrite history.
			$groups[] = [ 'views' => $views, 'customer_text' => $group['customer_text'] ];
		}
		return [ 'contract_version' => 1, 'original' => true, 'groups' => $groups ];
	}
	public static function hypothetical( array $views, bool $destination_complete, ?PublicPromiseFormatter $formatter = null ): array {
		PromiseShape::list( $views, 0, 16 ); $formatter ??= new PublicPromiseFormatter(); $public = []; $texts = [];
		foreach ( $views as $view ) { if ( ! $view instanceof PublicPromiseView ) { PromiseShape::invalid(); } }
		if ( $destination_complete ) { foreach ( $views as $view ) { $public[] = $view->fields(); $texts[] = $formatter->text( $view ); } }
		$notice = function_exists( '__' ) ? __( 'Preliminary estimate. Confirm complete delivery details and review the final delivery window at checkout.', 'cetech-woocommerce-delivery-engine' ) : 'Preliminary estimate. Confirm complete delivery details and review the final delivery window at checkout.';
		$text = implode( '; ', $texts ); if ( '' !== $text ) { PromiseShape::text( $text, 2048 ); }
		return [ 'contract_version' => 1, 'preliminary' => true, 'admission' => false, 'destination_complete' => $destination_complete, 'views' => $public, 'text' => $text, 'notice' => $notice ];
	}
}
