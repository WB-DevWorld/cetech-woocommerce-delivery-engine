<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Presentation\Admin\AdminLanguage;
use PHPUnit\Framework\TestCase;

/**
 * Flags unexpected primary-UI use of internal terms.
 *
 * Allowlist: terms may appear in comments, HTML names/ids, PHP variables,
 * Technical details blocks, and the forbidden-term registry itself.
 */
final class ForbiddenPrimaryUiTermsTest extends TestCase {

	public function test_primary_translatable_strings_do_not_expose_internal_terms(): void {
		$roots = [
			dirname( __DIR__, 4 ) . '/src/Presentation/Admin',
			dirname( __DIR__, 4 ) . '/src/Presentation/Frontend',
			dirname( __DIR__, 4 ) . '/src/Application/Configuration/Admin',
			dirname( __DIR__, 4 ) . '/src/Application/Selector',
			dirname( __DIR__, 4 ) . '/src/Application/Cart',
		];

		$allowlisted_files = [
			'AdminLanguage.php',
		];

		$violations = [];

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof \SplFileInfo || 'php' !== $file->getExtension() ) {
					continue;
				}

				if ( in_array( $file->getFilename(), $allowlisted_files, true ) ) {
					continue;
				}

				$source = (string) file_get_contents( $file->getPathname() );
				$source = $this->strip_non_primary_regions( $source );

				if ( ! preg_match_all(
					'/(?:esc_html__|esc_attr__|__|_e|esc_html_e|esc_attr_e)\(\s*([\'"])(.*?)\1/s',
					$source,
					$matches
				) ) {
					continue;
				}

				foreach ( $matches[2] as $string ) {
					foreach ( AdminLanguage::forbidden_primary_terms() as $term ) {
						if ( str_contains( $string, $term ) ) {
							$violations[] = $file->getFilename() . ': "' . $string . '" contains ' . $term;
						}
					}
				}
			}
		}

		self::assertSame( [], $violations, implode( PHP_EOL, $violations ) );
	}

	public function test_label_mappers_do_not_return_forbidden_terms(): void {
		$samples = [
			\CetechDeliveryEngine\Application\Configuration\Admin\ProvenanceLabelMapper::map( 'global' ),
			\CetechDeliveryEngine\Application\Configuration\Admin\ProvenanceLabelMapper::map( 'product' ),
			\CetechDeliveryEngine\Application\Configuration\Admin\ReasonCodeLabelMapper::state_label(
				\CetechDeliveryEngine\Domain\Enum\EffectiveFieldState::Unresolved
			),
			\CetechDeliveryEngine\Application\Configuration\Admin\ReasonCodeLabelMapper::explain(
				\CetechDeliveryEngine\Domain\Configuration\ConfigurationReasonCode::UNRESOLVED_GLOBAL_VALUE
			),
			\CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices::TRANSITIONAL_MESSAGE,
			\CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationNotices::CATEGORY_WARNING_MESSAGE,
			\CetechDeliveryEngine\Presentation\Admin\FeatureFlagLabels::label( 'enable_effective_configuration_runtime' ),
			\CetechDeliveryEngine\Presentation\Admin\FeatureFlagLabels::label( 'enable_variable_product_ecr_runtime' ),
		];

		foreach ( $samples as $sample ) {
			foreach ( AdminLanguage::forbidden_primary_terms() as $term ) {
				self::assertStringNotContainsString( $term, $sample );
			}
		}
	}

	private function strip_non_primary_regions( string $source ): string {
		$source = preg_replace( '#/\*.*?\*/#s', '', $source ) ?? $source;
		$source = preg_replace( '#^\s*//.*$#m', '', $source ) ?? $source;
		$source = preg_replace(
			'#<details class="cetech-de-technical-details">.*?</details>#s',
			'',
			$source
		) ?? $source;
		$source = preg_replace(
			'#<details id="cetech-de-advanced-details".*?</details>#s',
			'',
			$source
		) ?? $source;

		return $source;
	}
}
