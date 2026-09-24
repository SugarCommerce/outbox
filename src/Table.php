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
 *
 * These names are interpolated into SQL — identifiers cannot be bound — so
 * they are validated here, once, and the constructor throws rather than
 * guess.
 */
final readonly class Table {

	/**
	 * @param string      $name          Table name.
	 * @param string      $sent_status   Status for a row that went, e.g.
	 *                                   'sent' or 'delivered'.
	 * @param string      $sent_column   Column that records when it went.
	 * @param string|null $status_column Column for the receiver's status
	 *                                   code, when the table keeps one.
	 *
	 * @throws \InvalidArgumentException On a name that is not a plain
	 *                                   identifier.
	 */
	public function __construct(
		public string $name,
		public string $sent_status = 'sent',
		public string $sent_column = 'date_sent',
		public ?string $status_column = null,
	) {
		foreach ( array( $name, $sent_status, $sent_column, $status_column ?? 'x' ) as $identifier ) {
			if ( 1 !== preg_match( '/^[a-z_][a-z0-9_]*$/', $identifier ) ) {
				throw new \InvalidArgumentException( 'Not a plain identifier: ' . $identifier );
			}
		}
	}
}
