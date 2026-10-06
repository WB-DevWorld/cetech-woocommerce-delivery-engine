<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

/** Restore the test doubles so authorization cases cannot alter later suites. */
trait RestoresWordPressFixtureGlobals {

	private array $fixture_globals = [];
	private array $fixture_request = [];

	private function remember_fixture_globals(): void {
		$this->fixture_request = [ $_GET, $_POST, $_REQUEST ];
		foreach ( $GLOBALS as $key => $value ) {
			if ( str_starts_with( $key, 'cetech_de_test_' ) ) {
				$this->fixture_globals[ $key ] = $value;
			}
		}
	}

	private function restore_fixture_globals(): void {
		foreach ( array_keys( $GLOBALS ) as $key ) {
			if ( str_starts_with( $key, 'cetech_de_test_' ) ) {
				unset( $GLOBALS[ $key ] );
			}
		}
		foreach ( $this->fixture_globals as $key => $value ) {
			$GLOBALS[ $key ] = $value;
		}
		[ $_GET, $_POST, $_REQUEST ] = $this->fixture_request;
	}
}
