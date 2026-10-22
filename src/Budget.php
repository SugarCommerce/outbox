<?php
/**
 * How long one piece of background work may run.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Class Budget
 *
 * A row limit alone does not bound a run whose rows each make network
 * calls: a hundred slow answers at eight seconds each is a run that
 * outlasts the minute, overlaps the next cron tick, and holds up whatever
 * shares its lock. Check spent() between rows and stop when it says so;
 * what is left is still due, so the next run starts there.
 *
 * On a monotonic clock, because wall time can jump under NTP and a budget
 * measured with time() can be spent before it starts or never at all.
 *
 *   $budget = new Budget( 15 );
 *
 *   foreach ( $rows as $row ) {
 *       if ( $budget->spent() ) {
 *           break;
 *       }
 *       work( $row );
 *   }
 *
 * @since 1.2.0
 */
final readonly class Budget {

	/**
	 * When the budget runs out, as hrtime seconds.
	 *
	 * @since 1.2.0
	 *
	 * @var float
	 */
	private float $until;

	/**
	 * Start the clock.
	 *
	 * @since 1.2.0
	 *
	 * @param float $seconds How long from now. 0 or less is unlimited, for
	 *                       callers and tests that want every row.
	 */
	public function __construct( float $seconds = 0.0 ) {
		$this->until = $seconds > 0 ? self::clock() + $seconds : INF;
	}

	/**
	 * Whether it has run out.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function spent(): bool {
		return self::clock() >= $this->until;
	}

	/**
	 * A monotonic clock in seconds.
	 *
	 * @since 1.2.0
	 *
	 * @return float
	 */
	private static function clock(): float {
		return hrtime( true ) / 1e9;
	}
}
