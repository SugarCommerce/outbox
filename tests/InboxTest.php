<?php
/**
 * Inbox test suite.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Outbox\{Claim, Inbox};
use SugarCommerce\Sqlite\Database;

final class InboxTest extends TestCase {

	private const NOW = 1_800_000_000;

	private Database $db;

	private Inbox $inbox;

	protected function setUp(): void {
		$this->db = Database::memory( Inbox::SCHEMA );
		$this->db->pdo()->exec( 'ALTER TABLE inbox ADD COLUMN type TEXT' );

		$this->inbox = new Inbox( $this->db );
	}

	private function row(): array {
		return (array) $this->db->row( 'SELECT status, attempts, last_error, date_claimed, date_processed, type FROM inbox' );
	}

	/* ─── The first delivery ────────────────────────────────────────── */

	public function test_the_first_delivery_owns_the_event(): void {
		$this->assertSame( Claim::Owned, $this->inbox->claim( 'evt_1', array( 'type' => 'charge.refunded' ), self::NOW ) );

		$this->assertSame(
			array( 'status' => 'pending', 'attempts' => 1, 'last_error' => null, 'date_claimed' => self::NOW, 'date_processed' => null, 'type' => 'charge.refunded' ),
			$this->row()
		);
	}

	/**
	 * The columns the inbox keeps are its own: a caller's fields cannot
	 * pretend an event was already handled.
	 */
	public function test_a_callers_fields_cannot_overwrite_the_claim(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', array( 'status' => 'done' ), self::NOW )->owned() );

		$this->assertSame( 'pending', $this->row()['status'] );
	}

	/* ─── Repeats ───────────────────────────────────────────────────── */

	/**
	 * The point of it: a sender's retry while the handler is still running
	 * must not run it a second time.
	 */
	public function test_a_repeat_while_it_runs_is_busy(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', now: self::NOW )->owned() );

		$this->assertSame( Claim::Busy, $this->inbox->claim( 'evt_1', now: self::NOW + 10 ) );
	}

	public function test_a_repeat_after_it_finished_is_done(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', now: self::NOW )->owned() );
		$this->assertSame( 1, $this->inbox->complete( 'evt_1', self::NOW + 1 ) );

		$this->assertSame( Claim::Done, $this->inbox->claim( 'evt_1', now: self::NOW + 10 ) );
		$this->assertSame( self::NOW + 1, $this->row()['date_processed'] );
	}

	/**
	 * The classic version of the bug: the first attempt failed, the
	 * sender's retry was answered "already processed", and the event was
	 * never handled.
	 */
	public function test_a_repeat_after_a_failure_takes_it_over(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', now: self::NOW )->owned() );
		$this->inbox->fail( 'evt_1', 'Database is locked' );

		$this->assertSame( 'failed', $this->row()['status'] );
		$this->assertSame( Claim::Owned, $this->inbox->claim( 'evt_1', now: self::NOW + 60 ) );

		$this->assertSame(
			array( 'status' => 'pending', 'attempts' => 2, 'last_error' => null, 'date_claimed' => self::NOW + 60 ),
			array_intersect_key( $this->row(), array_flip( array( 'status', 'attempts', 'last_error', 'date_claimed' ) ) )
		);
	}

	/**
	 * Only one of two retries racing for the same failed row wins it.
	 */
	public function test_two_retries_of_a_failure_produce_one_owner(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', now: self::NOW )->owned() );
		$this->inbox->fail( 'evt_1', 'x' );

		$this->assertSame( Claim::Owned, $this->inbox->claim( 'evt_1', now: self::NOW + 60 ) );
		$this->assertSame( Claim::Busy, $this->inbox->claim( 'evt_1', now: self::NOW + 60 ) );
	}

	/**
	 * A process that died mid-handler leaves the row pending. It is not
	 * busy for ever.
	 */
	public function test_a_stale_claim_is_taken_over(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', now: self::NOW )->owned() );

		$this->assertSame( Claim::Busy, $this->inbox->claim( 'evt_1', now: self::NOW + Inbox::STALE_AFTER ) );
		$this->assertSame( Claim::Owned, $this->inbox->claim( 'evt_1', now: self::NOW + Inbox::STALE_AFTER + 1 ) );
	}

	public function test_how_long_until_stale_can_be_chosen(): void {
		$inbox = new Inbox( $this->db, stale_after: 10 );

		$this->assertTrue( $inbox->claim( 'evt_1', now: self::NOW )->owned() );
		$this->assertSame( Claim::Owned, $inbox->claim( 'evt_1', now: self::NOW + 11 ) );
	}

	public function test_events_are_kept_apart(): void {
		$this->assertTrue( $this->inbox->claim( 'evt_1', now: self::NOW )->owned() );

		$this->assertSame( Claim::Owned, $this->inbox->claim( 'evt_2', now: self::NOW ) );
	}

	/* ─── Shape ─────────────────────────────────────────────────────── */

	public function test_another_table_and_key_can_be_used(): void {
		$db = Database::memory( str_replace( array( 'inbox (', 'event_id' ), array( 'stripe_events (', 'stripe_event_id' ), Inbox::SCHEMA ) );

		$inbox = new Inbox( $db, 'stripe_events', 'stripe_event_id' );

		$this->assertTrue( $inbox->claim( 'evt_1', now: self::NOW )->owned() );
		$this->assertSame( Claim::Busy, $inbox->claim( 'evt_1', now: self::NOW ) );
	}

	public function test_a_name_that_is_not_an_identifier_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Inbox( $this->db, 'inbox; DROP TABLE x' );
	}

	/**
	 * Anything but a duplicate is a failure the sender should retry, not
	 * "seen before".
	 */
	public function test_a_broken_table_is_an_error_not_a_repeat(): void {
		$this->expectException( \PDOException::class );

		(void) ( new Inbox( Database::memory() ) )->claim( 'evt_1' );
	}
}
