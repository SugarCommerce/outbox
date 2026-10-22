<?php
/**
 * Incoming events handled once, however often they arrive.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

use SugarCommerce\Sqlite\Database;

/**
 * Class Inbox
 *
 * The receiving side of a webhook. Senders deliver at least once — they
 * retry on a timeout, on a 500, and sometimes for no reason anybody can
 * see — so the same event arrives twice, and a handler that grants access
 * or sends an email must not run twice.
 *
 * **Claimed by the unique index.** The usual guard, "have we seen this id?"
 * then insert, has a window between the two that two concurrent deliveries
 * both pass through. Here the claim *is* the insert, and the event id's
 * unique index lets exactly one of them win.
 *
 * **A failure is not "done".** A handler that threw marks the event failed,
 * and the sender's retry — which it sends precisely because we answered
 * 500 — takes the row over. Answering "already processed" to that retry is
 * the classic version of this bug: the first attempt failed, the retry was
 * acknowledged and dropped, and the event was never handled.
 *
 * **Nor is a crash "busy" for ever.** A row stays pending if the process
 * died mid-handler. After STALE_AFTER seconds a retry may take it over —
 * far longer than a handler runs, far shorter than a sender's schedule.
 * Every takeover is one conditional UPDATE, so two retries racing for the
 * same row still produce one owner.
 *
 *   $claim = $inbox->claim( $event->id, [ 'type' => $event->type ] );
 *
 *   if ( ! $claim->owned() ) {
 *       return Claim::Busy === $claim ? 409 : 200;
 *   }
 *
 *   try {
 *       handle( $event );
 *       $inbox->complete( $event->id );
 *   } catch ( \Throwable $e ) {
 *       $inbox->fail( $event->id, $e->getMessage() );
 *       return 500;
 *   }
 *
 * @since 1.2.0
 */
final readonly class Inbox {

	/**
	 * Seconds a claim holds before a retry may take the event over.
	 *
	 * @since 1.2.0
	 */
	public const int STALE_AFTER = 300;

	/**
	 * The table this class expects, for a schema file or a migration. Add
	 * whatever else you keep about an event — its type, its payload — as
	 * columns of your own, and pass them to claim().
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	public const string SCHEMA = "CREATE TABLE IF NOT EXISTS inbox (
	id             INTEGER PRIMARY KEY AUTOINCREMENT,
	event_id       TEXT    NOT NULL UNIQUE,
	status         TEXT    NOT NULL DEFAULT 'pending',
	attempts       INTEGER NOT NULL DEFAULT 1,
	last_error     TEXT,
	date_created   INTEGER NOT NULL,
	date_claimed   INTEGER,
	date_processed INTEGER
)";

	/**
	 * Use a table of events on a connection.
	 *
	 * @since 1.2.0
	 *
	 * @param Database $db          Connection.
	 * @param string   $table       Table name.
	 * @param string   $key         The event id's column. Must carry a
	 *                              unique index: it is the guarantee.
	 * @param int      $stale_after Seconds before a pending claim can be
	 *                              taken over.
	 *
	 * @throws \InvalidArgumentException On a name that is not a plain
	 *                                   identifier, or a stale time under
	 *                                   a second — every retry would take
	 *                                   over a handler still running.
	 */
	public function __construct(
		private Database $db,
		private string $table = 'inbox',
		private string $key = 'event_id',
		private int $stale_after = self::STALE_AFTER,
	) {
		Database::identifier( $table );
		Database::identifier( $key );

		if ( $stale_after < 1 ) {
			throw new \InvalidArgumentException( 'A claim must hold for at least a second.' );
		}
	}

	/**
	 * Take ownership of an event, or say why not.
	 *
	 * @since 1.2.0
	 *
	 * @param string               $id     The sender's event id.
	 * @param array<string, mixed> $fields Columns of your own, written
	 *                                     when the event is first seen.
	 * @param int|null             $now    Current time. For tests.
	 *
	 * @return Claim
	 *
	 * @throws \InvalidArgumentException On a field name that is not a plain
	 *                                   identifier.
	 */
	#[\NoDiscard( 'Only an Owned claim may run the handler; the others must not.' )]
	public function claim( string $id, array $fields = array(), ?int $now = null ): Claim {
		$now = $now ?? time();

		$first = $this->db->insert_or_ignore( $this->table, array(
			$this->key     => $id,
			'status'       => 'pending',
			'attempts'     => 1,
			'date_created' => $now,
			'date_claimed' => $now,
		) + $fields );

		if ( $first ) {
			return Claim::Owned;
		}

		$table = Database::identifier( $this->table );
		$key   = Database::identifier( $this->key );

		$taken = $this->db->query(
			"UPDATE {$table}
			    SET status = 'pending', attempts = attempts + 1, date_claimed = :now, last_error = NULL
			  WHERE {$key} = :id
			    AND ( status = 'failed'
			          OR ( status = 'pending' AND COALESCE( date_claimed, date_created ) < :stale ) )",
			array( 'id' => $id, 'now' => $now, 'stale' => $now - $this->stale_after )
		)->rowCount();

		if ( $taken > 0 ) {
			return Claim::Owned;
		}

		return 'done' === $this->db->value( "SELECT status FROM {$table} WHERE {$key} = :id", array( 'id' => $id ) )
			? Claim::Done
			: Claim::Busy;
	}

	/**
	 * Mark an event handled.
	 *
	 * Done is final: a delivery that lost its claim to a retry and finishes
	 * late changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $id  The sender's event id.
	 * @param int|null $now Current time. For tests.
	 *
	 * @return int Rows changed.
	 */
	public function complete( string $id, ?int $now = null ): int {
		return $this->db->update(
			$this->table,
			array( 'status' => 'done', 'date_processed' => $now ?? time() ),
			array( $this->key => $id, 'status' => 'pending' )
		);
	}

	/**
	 * Mark an event failed, so the sender's retry can take it over.
	 *
	 * Only one still pending. A delivery that ran past STALE_AFTER may
	 * fail after a retry took the event over and finished it; marking that
	 * failed would hand the next retry an event already handled, and the
	 * handler would run twice — which is what this class is for preventing.
	 *
	 * The error is stored as given. An exception's message can carry a URL
	 * with a token in it, or a customer's address: pass what is safe to keep.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id    The sender's event id.
	 * @param string $error What went wrong, for whoever looks.
	 *
	 * @return int Rows changed.
	 */
	public function fail( string $id, string $error ): int {
		return $this->db->update(
			$this->table,
			array( 'status' => 'failed', 'last_error' => $error ),
			array( $this->key => $id, 'status' => 'pending' )
		);
	}
}
