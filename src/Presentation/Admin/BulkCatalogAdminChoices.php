<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;

/**
 * Labeled selector options and POST merging for Bulk Tools Catalog.
 *
 * IDs/codes remain authoritative internally. This class does not change
 * BulkJobEngine, targeting SQL, or inheritance semantics.
 */
final class BulkCatalogAdminChoices {

	public const SELECTOR_PAGE = 100;

	public const SELECTOR_MAX_PAGES = 20;

	/** @var list<string> */
	private const LABEL_KEYS = [
		'public_label',
		'location_name',
		'internal_name',
		'name',
		'label',
		'internal_code',
		'code',
	];

	public function __construct(
		private readonly ?object $delivery_offers = null,
		private readonly ?object $logistics_profiles = null,
		private readonly ?object $pickup_locations = null,
		private readonly ?object $suppliers = null,
		private readonly ?object $origins = null
	) {
	}

	public function can_view_private_sources(): bool {
		return function_exists( 'current_user_can' )
			&& ( current_user_can( 'manage_private_sources' ) || current_user_can( 'view_private_origins' ) );
	}

	/**
	 * @return array<int, string>
	 */
	public function delivery_options(): array {
		return $this->labeled_from_repo( $this->delivery_offers );
	}

	/**
	 * @return array<int, string>
	 */
	public function logistics_profiles(): array {
		return $this->labeled_from_repo( $this->logistics_profiles );
	}

	/**
	 * @return array<int, string>
	 */
	public function pickup_locations(): array {
		return $this->labeled_from_repo( $this->pickup_locations );
	}

	/**
	 * @return array<int, string>
	 */
	public function suppliers(): array {
		return $this->can_view_private_sources() ? $this->labeled_from_repo( $this->suppliers ) : [];
	}

	/**
	 * @return array<int, string>
	 */
	public function origins(): array {
		return $this->can_view_private_sources() ? $this->labeled_from_repo( $this->origins ) : [];
	}

	/**
	 * @param array<string, mixed> $post
	 *
	 * @return list<int>
	 */
	public static function merge_product_ids( array $post ): array {
		$ids = [];
		$selected = $post['selected_product_ids'] ?? [];
		if ( is_array( $selected ) ) {
			foreach ( $selected as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		} elseif ( is_scalar( $selected ) ) {
			$ids = array_merge( $ids, self::parse_positive_ids( (string) $selected ) );
		}

		$ids = array_merge( $ids, self::parse_positive_ids( (string) ( $post['product_ids'] ?? '' ) ) );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string, mixed> $post
	 *
	 * @return list<int|string>
	 */
	public static function merge_offer_members( array $post ): array {
		$members = [];
		$posted  = $post['offer_ids'] ?? [];
		if ( is_array( $posted ) ) {
			foreach ( $posted as $part ) {
				$token = self::member_token( (string) $part );
				if ( null !== $token ) {
					$members[] = $token;
				}
			}
		} elseif ( is_scalar( $posted ) ) {
			$members = array_merge( $members, self::parse_member_tokens( (string) $posted ) );
		}

		$members = array_merge( $members, self::parse_member_tokens( (string) ( $post['offer_ids_advanced'] ?? '' ) ) );

		$unique = [];
		foreach ( $members as $member ) {
			$unique[ is_int( $member ) ? 'i:' . $member : 's:' . $member ] = $member;
		}

		return array_values( $unique );
	}

	public static function first_positive_id( mixed $primary, mixed $advanced = 0 ): int {
		foreach ( [ $primary, $advanced ] as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * SKU paste is a large-list alternative. Selected-ID enumeration ignores SKUs,
	 * so an SKU-only submission is treated as matching-filters (existing engine path).
	 *
	 * @param list<int>    $ids
	 * @param list<string> $skus
	 */
	public static function resolve_target_scope( string $scope, array $ids, array $skus ): string {
		if ( BulkTargetScope::SelectedIds->value === $scope && [] === $ids && [] !== $skus ) {
			return BulkTargetScope::MatchingFilters->value;
		}

		return $scope;
	}

	/**
	 * Phrases that must not appear in the normal Catalog workflow.
	 *
	 * @return list<string>
	 */
	public static function forbidden_normal_catalog_phrases(): array {
		return [
			'term taxonomy ID',
			'Configured Fulfilment Availability',
			'Effective Fulfilment Availability',
			'Site-wide vs Product Exception',
			'Variation override vs parent inheritance',
			'Selected product IDs on this form',
		];
	}

	/**
	 * @param object|null $repo
	 *
	 * @return array<int, string>
	 */
	private function labeled_from_repo( ?object $repo ): array {
		if ( null === $repo ) {
			return [];
		}

		try {
			$rows = $this->load_rows( $repo );
		} catch ( \Throwable ) {
			return [];
		}

		return self::map_labels( $rows );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function load_rows( object $repo ): array {
		if ( method_exists( $repo, 'page_after' ) ) {
			$out   = [];
			$after = 0;
			for ( $page = 0; $page < self::SELECTOR_MAX_PAGES; ++$page ) {
				$chunk = $repo->page_after( $after, self::SELECTOR_PAGE );
				if ( ! is_array( $chunk ) || [] === $chunk ) {
					break;
				}
				foreach ( $chunk as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$out[] = $row;
					$after = (int) ( $row['id'] ?? $after );
				}
				if ( count( $chunk ) < self::SELECTOR_PAGE ) {
					break;
				}
			}

			return $out;
		}

		if ( method_exists( $repo, 'list' ) ) {
			$list = $repo->list( [ 'limit' => 500 ] );

			return is_array( $list ) ? $list : [];
		}

		return [];
	}

	/**
	 * @param list<array<string, mixed>> $records
	 *
	 * @return array<int, string>
	 */
	private static function map_labels( array $records ): array {
		$options = [];
		foreach ( $records as $record ) {
			$id = (int) ( $record['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$name = '';
			foreach ( self::LABEL_KEYS as $key ) {
				if ( ! isset( $record[ $key ] ) ) {
					continue;
				}
				$candidate = trim( (string) $record[ $key ] );
				if ( '' !== $candidate ) {
					$name = $candidate;
					break;
				}
			}
			$options[ $id ] = '' !== $name ? $name : sprintf( '#%d', $id );
		}

		return $options;
	}

	/**
	 * @return list<int>
	 */
	private static function parse_positive_ids( string $raw ): array {
		$ids = [];
		foreach ( preg_split( '/[\s,]+/', $raw ) ?: [] as $part ) {
			$part = trim( $part );
			if ( ctype_digit( $part ) ) {
				$id = (int) $part;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * @return list<int|string>
	 */
	private static function parse_member_tokens( string $raw ): array {
		$members = [];
		foreach ( preg_split( '/[\s,]+/', $raw ) ?: [] as $part ) {
			$token = self::member_token( $part );
			if ( null !== $token ) {
				$members[] = $token;
			}
		}

		return $members;
	}

	private static function member_token( string $part ): int|string|null {
		$part = trim( $part );
		if ( '' === $part ) {
			return null;
		}
		if ( ctype_digit( $part ) ) {
			$id = (int) $part;

			return $id > 0 ? $id : null;
		}
		$key = strtolower( $part );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';

		return '' !== $key ? $key : null;
	}
}
