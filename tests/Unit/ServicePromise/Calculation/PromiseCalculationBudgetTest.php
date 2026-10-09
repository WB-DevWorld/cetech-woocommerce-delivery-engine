<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Calculation;

use CetechDeliveryEngine\Application\ServicePromise\Calculation\PromiseCalculationBudget;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\PromiseCalculationException;
use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;
use PHPUnit\Framework\TestCase;

final class PromiseCalculationBudgetTest extends TestCase {
	public function test_exact_complete_cart_budget_is_not_reset_after_exhaustion(): void {
		$budget = new PromiseCalculationBudget(); $budget->consume( PromiseLimits::CART_STEPS - 1 ); $budget->consume();
		self::assertSame( 100000, $budget->used_steps() );
		self::assertFalse( $budget->exhausted() );
		try { $budget->consume(); self::fail( 'The plus-one step must refuse.' ); }
		catch ( PromiseCalculationException $error ) { self::assertSame( 'budget_exceeded', $error->reason() ); }
		self::assertSame( 100000, $budget->used_steps() );
		self::assertTrue( $budget->exhausted() );
	}
	public function test_huge_requested_work_refuses_without_integer_wrap_or_partial_charge(): void {
		$budget = new PromiseCalculationBudget(); $budget->consume( 7 );
		try { $budget->consume( PHP_INT_MAX ); self::fail( 'Unbounded work must refuse.' ); }
		catch ( PromiseCalculationException $error ) { self::assertSame( 'budget_exceeded', $error->reason() ); }
		self::assertSame( 7, $budget->used_steps() );
		self::assertTrue( $budget->exhausted() );
		try { $budget->consume(); self::fail( 'The failed whole-cart charge must never resume.' ); }
		catch ( PromiseCalculationException $error ) { self::assertSame( 'budget_exceeded', $error->reason() ); }
		self::assertSame( 7, $budget->used_steps() );
	}
	/** @dataProvider invalid_steps */
	public function test_negative_or_zero_steps_cannot_refill_the_account( int $steps ): void {
		$budget = new PromiseCalculationBudget(); $budget->consume( 9 );
		try { $budget->consume( $steps ); self::fail( 'A step charge must be positive.' ); }
		catch ( PromiseCalculationException $error ) { self::assertSame( 'unsupported_policy', $error->reason() ); }
		self::assertSame( 9, $budget->used_steps() );
	}
	public static function invalid_steps(): array { return [ [ 0 ], [ -1 ], [ PHP_INT_MIN ] ]; }
	public function test_exception_message_is_safe_and_reason_is_a_closed_existing_code(): void {
		$error = new PromiseCalculationException( 'estimate_unavailable' );
		self::assertSame( 'Service promise calculation refused.', $error->getMessage() );
		self::assertSame( 'estimate_unavailable', $error->reason() );
		$this->expectException( \InvalidArgumentException::class ); new PromiseCalculationException( 'private calendar origin value' );
	}
}
