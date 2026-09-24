<?php
/**
 * A durable send queue on SQLite.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

use SugarCommerce\Sqlite\Database;
use SugarCommerce\Webhooks\RetryPolicy;

/**
 * Class Queue
 *
 * Rows go in with `status = 'pending'` and a `next_attempt_at`. A worker
 * claims what is due, attempts each row with a function it supplies, and the
 * queue settles the row from the Outcome — done, back to pending with a
 * backoff, or failed for good.
 *
 * **Claimed, not read.** The usual loop selects due rows and then sends them,
 * and two workers running at once select the same rows and send them twice.
 * Each row here is taken with one conditional UPDATE, so exactly one worker
 * wins it; the loser moves on to the next.
 *
 * **Under a lease.** A claimed row is `sending` until `claimed_until`. A
 * worker that crashes mid-send leaves its rows there, and once the lease
 * runs out they are due again — nothing has to notice or clean up. The cost
 * is that a send which succeeded just before a crash is sent once more,
 * which every receiver of a queue like this has to tolerate anyway.
 */
final readonly class Queue {

	/**
	 * Seconds a claim holds before the row is anybody's again.
	 *
	 * Comfortably longer than one attempt takes, and short enough that a
	 * crashed run's rows go out on the next cron tick but one.
	 */
	public const LEASE = 300;

	/**
	 * @param Database    $db     Connection.
	 * @param Table       $table  Which table, and its names.
	 * @param RetryPolicy $policy When to try again after a failure.
	 */
	public function __construct(
		private Database $db,
		private Table $table,
		private RetryPolicy $policy = new RetryPolicy(),
	) {}

	/**
	 * Claim and attempt everything due, settling each row.
	 *
	 * @param \Closure(array): Outcome $attempt Sends one row.
	 * @param int                      $limit   Rows to attempt in this run.
	 * @param int|null                 $now     Current time. For tests.
	 *
	 * @return array{attempted: int, sent: int, failed: int}
	 */
	public function drain( \Closure $attempt, int $limit = 100, ?int $now = null ): array {
		$now    = $now ?? time();
		$counts = array( 'attempted' => 0, 'sent' => 0, 'failed' => 0 );

		foreach ( $this->claim( $now, $limit ) as $row ) {
			++$counts['attempted'];

			try {
				$outcome = $attempt( $row );
			} catch ( \Throwable $e ) {
				// A sender that throws has failed, not crashed the run. The
				// rows after it are somebody else's and still due.
				$outcome = Outcome::failed( $e->getMessage() );
			}

			++$counts[ $this->settle( $row, $outcome, $now ) ? 'sent' : 'failed' ];
		}

		return $counts;
	}

	/**
	 * Take up to $limit due rows for this worker.
	 *
	 * Due means pending and past its time, or claimed by a worker whose lease
	 * has run out.
	 *
	 * @param int $now   Current time.
	 * @param int $limit Maximum rows.
	 * @param int $lease Seconds the claim holds.
	 *
	 * @return array<int, array> The claimed rows, as they now stand.
	 */
	public function claim( int $now, int $limit = 100, int $lease = self::LEASE ): array {
		$table = $this->table->name;
		$due   = "( status = 'pending' AND next_attempt_at <= :now )
		          OR ( status = 'sending' AND claimed_until < :now )";

		$ids = $this->db->column(
			"SELECT id FROM {$table} WHERE {$due} ORDER BY id LIMIT :limit",
			array( 'now' => $now, 'limit' => $limit )
		);

		$claimed = array();

		foreach ( $ids as $id ) {
			$won = $this->db->query(
				"UPDATE {$table} SET status = 'sending', claimed_until = :until
				  WHERE id = :id AND ( {$due} )",
				array( 'id' => (int) $id, 'now' => $now, 'until' => $now + $lease )
			)->rowCount();

			if ( 1 === $won ) {
				$claimed[] = (array) $this->db->row( "SELECT * FROM {$table} WHERE id = :id", array( 'id' => (int) $id ) );
			}
		}

		return $claimed;
	}

	/**
	 * Record how an attempt went.
	 *
	 * @param array   $row     The claimed row.
	 * @param Outcome $outcome What happened.
	 * @param int     $now     Current time.
	 *
	 * @return bool Whether it was sent.
	 */
	public function settle( array $row, Outcome $outcome, int $now ): bool {
		$attempt = (int) $row['attempts'] + 1;
		$fields  = array( 'attempts' => $attempt, 'claimed_until' => null );

		if ( null !== $this->table->status_column ) {
			$fields[ $this->table->status_column ] = $outcome->status;
		}

		if ( $outcome->sent ) {
			$fields['status']                     = $this->table->sent_status;
			$fields['last_error']                 = null;
			$fields[ $this->table->sent_column ] = $now;
		} else {
			$again = ! $outcome->final && $this->policy->should_retry( $outcome->status, $attempt );

			$fields['status']          = $again ? 'pending' : 'failed';
			$fields['last_error']      = mb_substr( $outcome->error, 0, 500 );
			$fields['next_attempt_at'] = $now + ( $again ? $this->policy->delay( $attempt, $outcome->retry_after ) : 0 );
		}

		$this->db->update( $this->table->name, $fields, array( 'id' => (int) $row['id'] ) );

		return $outcome->sent;
	}

	/**
	 * Rows that should have gone by now and have not.
	 *
	 * For a health check: a queue whose worker has stopped does not fail, it
	 * just fills up, and nothing else will say so.
	 *
	 * @param int      $grace Seconds past due before a row counts.
	 * @param int|null $now   Current time. For tests.
	 *
	 * @return int
	 */
	public function overdue( int $grace = 900, ?int $now = null ): int {
		return (int) $this->db->value(
			"SELECT COUNT(*) FROM {$this->table->name}
			  WHERE status IN ('pending', 'sending') AND next_attempt_at < :cutoff",
			array( 'cutoff' => ( $now ?? time() ) - $grace )
		);
	}
}
