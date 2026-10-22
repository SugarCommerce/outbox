<?php
/**
 * One run of a sweep: what is due, and how much it may do.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Class Pass
 *
 * A sweep that walks accounts and then each account's rows needs one count
 * across all of them, or its limit is per account and means nothing: fifty
 * accounts with a limit of twenty-five is a run of twelve hundred rows. This
 * carries that count, the time budget and the due time down the calls, so
 * every level asks the same question — may the run do another? — and gets
 * the same answer.
 *
 *   $pass = new Pass( time(), due: time(), limit: 25, budget: new Budget( 15 ) );
 *
 *   foreach ( $accounts as $account ) {
 *       foreach ( rows_due( $account, $pass->due ) as $row ) {
 *           if ( ! $pass->take() ) {
 *               break 2;
 *           }
 *           work( $row );
 *       }
 *   }
 *
 * Mutable on purpose, unlike the package's value objects: it is the run's
 * running count, shared by reference down the calls.
 *
 * @since 1.2.0
 */
final class Pass {

	/**
	 * Rows still allowed.
	 *
	 * @since 1.2.0
	 *
	 * @var int
	 */
	private int $left;

	/**
	 * When the run's time is up.
	 *
	 * @since 1.2.0
	 *
	 * @var Budget
	 */
	private readonly Budget $budget;

	/**
	 * Start a run.
	 *
	 * @since 1.2.0
	 *
	 * @param int         $now    Current time.
	 * @param int|null    $due    Only rows due by then; null is every row,
	 *                            for a caller acting at once rather than
	 *                            sweeping.
	 * @param int         $limit  Most rows in the run.
	 * @param Budget|null $budget How long it may take. Null is no limit.
	 */
	public function __construct(
		public readonly int $now,
		public readonly ?int $due = null,
		int $limit = PHP_INT_MAX,
		?Budget $budget = null,
	) {
		$this->left   = $limit;
		$this->budget = $budget ?? new Budget();
	}

	/**
	 * Claim one row, if the run may do another.
	 *
	 * @since 1.2.0
	 *
	 * @return bool False once the limit or the time is used up.
	 */
	#[\NoDiscard( 'False means the run is over; a row taken anyway breaks the limit.' )]
	public function take(): bool {
		if ( $this->over() ) {
			return false;
		}

		--$this->left;

		return true;
	}

	/**
	 * Whether the run has done all it may.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function over(): bool {
		return $this->left <= 0 || $this->budget->spent();
	}
}
