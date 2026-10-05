<?php
/**
 * Queue tests.
 *
 * @package SugarCommerce\Outbox\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Outbox\{Outcome, Queue, Table};
use SugarCommerce\Sqlite\Database;
use SugarCommerce\Webhooks\RetryPolicy;

/**
 * Class QueueTest
 */
final class QueueTest extends TestCase {

	private const NOW = 1_000_000;

	private Database $db;
	private Queue $queue;

	protected function setUp(): void {
		$this->db = Database::open( ':memory:' );
		$this->db->pdo()->exec(
			"CREATE TABLE outbox (
				id              INTEGER PRIMARY KEY,
				payload         TEXT    NOT NULL,
				attempts        INTEGER NOT NULL DEFAULT 0,
				next_attempt_at INTEGER NOT NULL,
				claimed_until   INTEGER,
				status          TEXT    NOT NULL DEFAULT 'pending',
				last_status     INTEGER,
				last_error      TEXT,
				date_sent       INTEGER,
				date_created    INTEGER NOT NULL DEFAULT 0
			)"
		);

		$this->queue = new Queue(
			$this->db,
			new Table( 'outbox', status_column: 'last_status' ),
			new RetryPolicy( max_attempts: 3, jitter: 0 )
		);
	}

	private function push( string $payload = 'x', int $due = self::NOW ): int {
		return $this->db->insert( 'outbox', array( 'payload' => $payload, 'next_attempt_at' => $due ) );
	}

	private function state_of( int $id ): string {
		return (string) $this->db->value( 'SELECT status FROM outbox WHERE id = :id', array( 'id' => $id ) );
	}

	/**
	 * The bug this exists for: two workers selecting the same due rows sent
	 * them twice. A claimed row is not due to anybody else.
	 */
	public function test_a_claimed_row_is_not_claimed_again(): void {
		$this->push();

		$this->assertCount( 1, $this->queue->claim( self::NOW ) );
		$this->assertCount( 0, $this->queue->claim( self::NOW ) );
	}

	/**
	 * A worker that died mid-send leaves its rows claimed. Once the lease
	 * runs out they are due again, with nobody having to clean up.
	 */
	public function test_an_expired_claim_is_due_again(): void {
		$this->push();
		$this->queue->claim( self::NOW, lease: 60 );

		$this->assertCount( 0, $this->queue->claim( self::NOW + 59 ) );
		$this->assertCount( 1, $this->queue->claim( self::NOW + 61 ) );
	}

	public function test_nothing_is_claimed_before_it_is_due(): void {
		$this->push( due: self::NOW + 10 );

		$this->assertCount( 0, $this->queue->claim( self::NOW ) );
	}

	public function test_a_sent_row_is_finished(): void {
		$id = $this->push();

		$counts = $this->queue->drain( fn(): Outcome => Outcome::sent( 204 ), now: self::NOW );

		$this->assertSame( array( 'attempted' => 1, 'sent' => 1, 'failed' => 0 ), $counts );
		$this->assertSame( 'sent', $this->state_of( $id ) );
		$this->assertSame( self::NOW, (int) $this->db->value( 'SELECT date_sent FROM outbox' ) );
		$this->assertSame( 204, (int) $this->db->value( 'SELECT last_status FROM outbox' ) );
	}

	public function test_a_failure_worth_retrying_waits_and_goes_back_to_pending(): void {
		$id = $this->push();

		$this->queue->drain( fn(): Outcome => Outcome::failed( 'Timed out' ), now: self::NOW );

		$this->assertSame( 'pending', $this->state_of( $id ) );
		$this->assertSame( self::NOW + 60, (int) $this->db->value( 'SELECT next_attempt_at FROM outbox' ) );
		$this->assertSame( 'Timed out', $this->db->value( 'SELECT last_error FROM outbox' ) );
	}

	/**
	 * A 400 is a considered refusal; the same bytes will be refused again.
	 */
	public function test_a_refusal_is_not_retried(): void {
		$id = $this->push();

		$this->queue->drain( fn(): Outcome => Outcome::failed( 'Bad request', 400 ), now: self::NOW );

		$this->assertSame( 'failed', $this->state_of( $id ) );
	}

	public function test_a_dropped_row_is_never_retried(): void {
		$id = $this->push();

		$this->queue->drain( fn(): Outcome => Outcome::dropped( 'Destination gone' ), now: self::NOW );

		$this->assertSame( 'failed', $this->state_of( $id ) );
	}

	public function test_attempts_run_out(): void {
		$id = $this->push();

		foreach ( array( 0, 100, 1_000 ) as $later ) {
			$this->queue->drain( fn(): Outcome => Outcome::failed( 'Down' ), now: self::NOW + $later );
		}

		$this->assertSame( 'failed', $this->state_of( $id ) );
		$this->assertSame( 3, (int) $this->db->value( 'SELECT attempts FROM outbox' ) );
	}

	/**
	 * A sender that throws has failed one row, not stopped the run.
	 */
	public function test_a_throwing_sender_fails_its_row_and_the_run_goes_on(): void {
		$first  = $this->push( 'boom' );
		$second = $this->push( 'fine' );

		$this->queue->drain(
			function ( array $row ): Outcome {
				if ( 'boom' === $row['payload'] ) {
					throw new \RuntimeException( 'Exploded' );
				}

				return Outcome::sent();
			},
			now: self::NOW
		);

		$this->assertSame( 'pending', $this->state_of( $first ) );
		$this->assertSame( 'sent', $this->state_of( $second ) );
	}

	public function test_the_receivers_retry_after_is_honoured(): void {
		$this->push();

		$this->queue->drain( fn(): Outcome => Outcome::failed( 'Slow down', 429, retry_after: 900 ), now: self::NOW );

		$this->assertSame( self::NOW + 900, (int) $this->db->value( 'SELECT next_attempt_at FROM outbox' ) );
	}

	/**
	 * A stopped worker does not fail; its queue just fills. Something has to
	 * say so.
	 */
	public function test_overdue_rows_are_counted(): void {
		$this->push( due: self::NOW - 2_000 );
		$this->push( due: self::NOW );

		$this->assertSame( 1, $this->queue->overdue( 900, self::NOW ) );
	}

	public function test_a_table_name_that_is_not_an_identifier_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Table( 'outbox; DROP TABLE users' );
	}

	/* ─── Failed rows (1.2.0) ───────────────────────────────────────── */

	private function failed_row(): int {
		$id = $this->push();
		$this->db->update( 'outbox', array( 'status' => 'failed', 'attempts' => 3, 'last_error' => 'Gone' ), array( 'id' => $id ) );

		return $id;
	}

	public function test_failed_rows_are_counted(): void {
		$this->failed_row();
		$this->failed_row();
		$this->push();

		$this->assertSame( 2, $this->queue->failed_count() );
	}

	/**
	 * A person decided to try again: the row is due now, with a full set of
	 * attempts.
	 */
	public function test_a_failed_row_can_be_put_back(): void {
		$id = $this->failed_row();

		$this->assertSame( 1, $this->queue->retry( $id, self::NOW ) );

		$this->assertSame( array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => self::NOW ), $this->db->row( 'SELECT status, attempts, next_attempt_at FROM outbox' ) );
		$this->assertCount( 1, $this->queue->claim( self::NOW ) );
	}

	/**
	 * Resetting a row a worker is sending would hand it to a second worker.
	 */
	public function test_only_a_failed_row_can_be_put_back(): void {
		$id = $this->push();
		$this->queue->claim( self::NOW );

		$this->assertSame( 0, $this->queue->retry( $id, self::NOW ) );
		$this->assertSame( 'sending', $this->state_of( $id ) );
	}

	/* ─── Purging (1.2.0) ───────────────────────────────────────────── */

	public function test_purging_removes_old_finished_rows_only(): void {
		$sent    = $this->push();
		$failed  = $this->failed_row();
		$waiting = $this->push();
		$young   = $this->push();

		$this->db->update( 'outbox', array( 'status' => 'sent' ), array( 'id' => $sent ) );
		$this->db->update( 'outbox', array( 'status' => 'sent', 'date_created' => self::NOW ), array( 'id' => $young ) );

		$this->assertSame( 2, $this->queue->purge( self::NOW, dry: true ) );
		$this->assertSame( 4, $this->db->count( 'outbox' ), 'A dry run changes nothing.' );

		$this->assertSame( 2, $this->queue->purge( self::NOW ) );
		$this->assertSame( array( $waiting, $young ), array_map( 'intval', $this->db->column( 'SELECT id FROM outbox ORDER BY id' ) ) );
		$this->assertNotContains( $failed, $this->db->column( 'SELECT id FROM outbox' ) );
	}

	public function test_a_queued_time_column_that_is_not_an_identifier_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Table( 'outbox', created_column: 'queued at' );
	}
}
