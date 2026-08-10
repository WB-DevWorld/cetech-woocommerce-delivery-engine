<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Maps internal provenance labels to administrator-facing language.
 */
final class ProvenanceLabelMapper {

	public static function map( string $source_label ): string {
		return match ( $source_label ) {
			'global' => 'Global',
			'product' => 'Product override',
			'variation' => 'Variation override',
			'explicit_disable' => 'Explicitly disabled',
			'system_default' => 'System default',
			default => $source_label,
		};
	}

	/**
	 * @param list<\CetechDeliveryEngine\Domain\Configuration\CollectionMutationStep> $steps
	 * @param callable(int): string|null                                              $member_labeler
	 *
	 * @return list<string>
	 */
	public static function mutation_summaries( array $steps, ?callable $member_labeler = null ): array {
		$lines = [];

		foreach ( $steps as $step ) {
			$scope = match ( $step->scope->value ) {
				'global' => 'Global',
				'product' => 'Product',
				'variation' => 'Variation',
				default => $step->scope->value,
			};

			$members = self::format_members( $step->members, $member_labeler );

			$lines[] = match ( $step->mode->value ) {
				'replace' => sprintf(
					'%s: Replaced with %s',
					$scope,
					'' === $members ? 'explicitly no values' : $members
				),
				'add' => sprintf( '%s: Added %s', $scope, $members ),
				'remove' => sprintf( '%s: Removed %s', $scope, $members ),
				default => sprintf( '%s: %s (%s)', $scope, $step->mode->value, $members ),
			};
		}

		return $lines;
	}

	/**
	 * @param list<int>                  $members
	 * @param callable(int): string|null $member_labeler
	 */
	private static function format_members( array $members, ?callable $member_labeler ): string {
		if ( [] === $members ) {
			return '';
		}

		$labels = [];

		foreach ( $members as $member ) {
			$labels[] = null !== $member_labeler ? $member_labeler( $member ) : (string) $member;
		}

		return implode( ', ', $labels );
	}
}
