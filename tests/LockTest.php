<?php
/**
 * Lock tests.
 *
 * @package SugarCommerce\Outbox\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Outbox\Lock;

/**
 * Class LockTest
 */
final class LockTest extends TestCase {

	private string $path;

	protected function setUp(): void {
		$this->path = sys_get_temp_dir() . '/outbox-lock-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		@unlink( $this->path );
	}

	public function test_a_second_holder_is_turned_away(): void {
		$first = Lock::acquire( $this->path );

		$this->assertNotNull( $first );
		$this->assertNull( Lock::acquire( $this->path ) );

		$first->release();
	}

	public function test_releasing_lets_the_next_run_in(): void {
		Lock::acquire( $this->path )?->release();

		$this->assertNotNull( Lock::acquire( $this->path ) );
	}
}
