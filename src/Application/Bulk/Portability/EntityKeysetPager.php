<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

/**
 * Iterates every matching entity in bounded keyset pages.
 * Admin list() caps remain for screens; complete export must use this.
 */
final class EntityKeysetPager {

	public const PAGE_SIZE = 100;

	public const MAX_PAGE_SIZE = 250;

	/**
	 * @param array<string, mixed> $criteria
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public static function iterate( object $repository, array $criteria = [], int $page_size = self::PAGE_SIZE ): \Generator {
		$page_size = max( 1, min( self::MAX_PAGE_SIZE, $page_size ) );

		if ( method_exists( $repository, 'page_after' ) ) {
			$after = 0;
			$seen  = [];
			do {
				$page = $repository->page_after( $after, $page_size, $criteria );
				if ( ! is_array( $page ) || [] === $page ) {
					return;
				}
				$count = 0;
				foreach ( $page as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$id = (int) ( $row['id'] ?? 0 );
					if ( $id > 0 ) {
						if ( isset( $seen[ $id ] ) ) {
							continue;
						}
						$seen[ $id ] = true;
						$after       = $id;
					}
					++$count;
					yield $row;
				}
				if ( 0 === $count ) {
					return;
				}
			} while ( count( $page ) >= $page_size );

			return;
		}

		$rows = self::legacy_complete_list( $repository, $criteria );
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				yield $row;
			}
		}
	}

	/**
	 * In-memory test doubles that return the full set from list(). Never used as a
	 * silent 500-row production cap — wpdb repositories must implement page_after().
	 *
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function legacy_complete_list( object $repository, array $criteria ): array {
		if ( ! method_exists( $repository, 'list' ) ) {
			return [];
		}

		$method = new \ReflectionMethod( $repository, 'list' );
		$params = $method->getParameters();
		if ( [] !== $params && 'limit' === $params[0]->getName() ) {
			$rows = $repository->list( PHP_INT_MAX );
		} else {
			$rows = $repository->list( $criteria );
		}

		return is_array( $rows ) ? array_values( $rows ) : [];
	}
}
