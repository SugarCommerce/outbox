<?php
/**
 * Budget, Pass, Steps and Lock::with() test suite.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Outbox\{Budget, Lock, Pass, Steps};

/**
 * Class RunTest
 *
 * The pieces that bound one cron run: how long, how many, which steps may
 * fail without stopping the rest, and which may not overlap the last run.
 */
final class RunTest extends TestCase {

	private string $path;

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/outbox-run-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		@unlink( $this->path );
	}

	/* ─── Budget ────────────────────────────────────────────────────── */

	public function test_no_budget_is_never_spent(): void {
		$this->assertFalse( ( new Budget() )->spent() );
		$this->assertFalse( ( new Budget( -1 ) )->spent() );
	}

	public function test_a_budget_runs_out(): void {
		$budget = new Budget( 0.001 );

		usleep( 2000 );

		$this->assertTrue( $budget->spent() );
	}

	public function test_a_budget_is_not_spent_before_its_time(): void {
		$this->assertFalse( ( new Budget( 60 ) )->spent() );
	}

	/* ─── Pass ──────────────────────────────────────────────────────── */

	/**
	 * One count across the whole run, however many levels take from it.
	 */
	public function test_a_pass_allows_its_limit_and_no_more(): void {
		$pass  = new Pass( 1_000, 1_000, 3 );
		$taken = 0;

		while ( $pass->take() ) {
			++$taken;
		}

		$this->assertSame( 3, $taken );
		$this->assertTrue( $pass->over() );
	}

	public function test_a_pass_ends_when_its_time_does(): void {
		$pass = new Pass( 1_000, limit: 100, budget: new Budget( 0.001 ) );

		usleep( 2000 );

		$this->assertFalse( $pass->take() );
	}

	public function test_a_pass_carries_its_times(): void {
		$pass = new Pass( 1_000, 900 );

		$this->assertSame( 1_000, $pass->now );
		$this->assertSame( 900, $pass->due );
		$this->assertNull( ( new Pass( 1_000 ) )->due, 'No due time: every row, for a caller acting at once.' );
	}

	/* ─── Steps ─────────────────────────────────────────────────────── */

	public function test_a_step_returns_what_it_did(): void {
		$steps = new Steps( static function (): void {} );

		$this->assertSame( 7, $steps->run( 'count', static fn (): int => 7, 0 ) );
	}

	/**
	 * One that throws is reported and counts as its fallback; the next
	 * still runs.
	 */
	public function test_a_step_that_throws_does_not_stop_the_next(): void {
		$failures = array();
		$steps    = new Steps( static function ( string $name, \Throwable $e ) use ( &$failures ): void {
			$failures[] = $name . ': ' . $e->getMessage();
		} );

		$first  = $steps->run( 'mail', static fn (): int => throw new \RuntimeException( 'database is locked' ), -1 );
		$second = $steps->run( 'hooks', static fn (): int => 2, 0 );

		$this->assertSame( array( -1, 2 ), array( $first, $second ) );
		$this->assertSame( array( 'mail: database is locked' ), $failures );
	}

	public function test_logged_steps_still_fall_back(): void {
		$log = ini_set( 'error_log', '/dev/null' );

		try {
			$this->assertSame( 'none', Steps::logging( 'cron' )->run( 'x', static fn (): string => throw new \LogicException( 'no' ), 'none' ) );
		} finally {
			ini_set( 'error_log', (string) $log );
		}
	}

	/* ─── Lock::with() ──────────────────────────────────────────────── */

	public function test_work_runs_under_the_lock_and_returns(): void {
		$this->assertSame( 'done', Lock::with( $this->path, fn (): string => null === Lock::acquire( $this->path ) ? 'done' : 'unlocked' ) );
	}

	public function test_work_is_skipped_while_another_run_holds_it(): void {
		$held = Lock::acquire( $this->path );
		$ran  = false;

		$this->assertSame( 'skipped', Lock::with( $this->path, function () use ( &$ran ): string {
			$ran = true;

			return 'ran';
		}, 'skipped' ) );
		$this->assertFalse( $ran );

		$held?->release();
	}

	/**
	 * A step that throws does not keep its lock for the rest of the script.
	 */
	public function test_the_lock_is_released_when_the_work_throws(): void {
		try {
			Lock::with( $this->path, static fn (): never => throw new \RuntimeException( 'boom' ) );
		} catch ( \RuntimeException ) {
			// Expected.
		}

		$this->assertNotNull( $again = Lock::acquire( $this->path ) );
		$again->release();
	}
}
