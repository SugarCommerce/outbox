<?php
/**
 * The shape of one queue table.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Class Table
 *
 * Which table a queue lives in, and the few names that differ between one
 * queue and the next. Every queue table has `id`, `status`, `attempts`,
 * `next_attempt_at`, `claimed_until` and `last_error`; what a finished row is
 * called and where its finishing time goes are the application's own words.
 * Purging old rows also needs to know when a row was queued: `date_created`
 * unless named otherwise.
 *
 * These names are interpolated into SQL — identifiers cannot be bound — so
 * they are validated here, once, and the constructor throws rather than
 * guess.
 *
 * @since 1.0.0
 */
final readonly class Table {

	/**
	 * The statuses the queue itself moves a row through.
	 *
	 * @since 1.3.0
	 *
	 * @var list<string>
	 */
	public const array QUEUE_STATUSES = array( 'pending', 'sending', 'failed' );

	/**
	 * The columns the queue itself writes.
	 *
	 * @since 1.3.0
	 *
	 * @var list<string>
	 */
	public const array QUEUE_COLUMNS = array( 'id', 'status', 'attempts', 'next_attempt_at', 'claimed_until', 'last_error' );

	/**
	 * Name the table and the columns that differ from queue to queue.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $name           Table name.
	 * @param string      $sent_status    Status for a row that went, e.g.
	 *                                    'sent' or 'delivered'.
	 * @param string      $sent_column    Column that records when it went.
	 * @param string|null $status_column  Column for the receiver's status
	 *                                    code, when the table keeps one.
	 * @param string      $created_column Column that records when a row was
	 *                                    queued. Since 1.2.0.
	 *
	 * @throws \InvalidArgumentException On a name that is not a plain
	 *                                   identifier; a sent status the queue
	 *                                   already uses, which would send the
	 *                                   row again or never; or a column the
	 *                                   queue already writes.
	 */
	public function __construct(
		public string $name,
		public string $sent_status = 'sent',
		public string $sent_column = 'date_sent',
		public ?string $status_column = null,
		public string $created_column = 'date_created',
	) {
		foreach ( array( $name, $sent_status, $sent_column, $status_column ?? 'x', $created_column ) as $identifier ) {
			// `$` alone also matches before a final newline.
			if ( 1 !== preg_match( '/^[a-z_][a-z0-9_]*$/D', $identifier ) ) {
				throw new \InvalidArgumentException( 'Not a plain identifier: ' . $identifier );
			}
		}

		if ( in_array( $sent_status, self::QUEUE_STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'The sent status cannot be one the queue uses: ' . $sent_status );
		}

		foreach ( array( $sent_column, $status_column ) as $column ) {
			if ( in_array( $column, self::QUEUE_COLUMNS, true ) ) {
				throw new \InvalidArgumentException( 'The queue already writes the column ' . $column );
			}
		}
	}
}
