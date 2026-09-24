# Outbox

A durable send queue on SQLite: rows claimed atomically under a lease so overlapping workers never send twice, a crashed worker's rows come back on their own, and retries follow a status-aware backoff policy. Plus a run lock for cron.

## Why

Anything an application sends to somebody else — webhooks, email, API calls — belongs in a table first and on the wire second, so a slow receiver is a queue rather than a request that times out halfway. The table is the easy part. Three things go wrong in the loop that drains it:

- **Two workers send the same row.** Cron runs every minute; a run that takes longer than a minute overlaps the next. Both select the same due rows and both send them.
- **A crashed worker strands its rows.** Mark a row "sending" to stop the first problem and a process that dies mid-send leaves it marked for ever.
- **Everything is retried.** A 400 fails identically on the twelfth attempt; retrying it buries the real failures and hammers a receiver that already said no.

## Features

- 🔒 **Atomic claims** — each row is taken with one conditional `UPDATE`, so exactly one worker wins it.
- ⏳ **Leases** — a claim expires; a crashed worker's rows are due again with nothing to clean up.
- 🔁 **Status-aware retries** — `sugarcommerce/webhooks`' `RetryPolicy`: backoff with jitter, `Retry-After` honoured, refusals not retried.
- 🧯 **One bad row stays one bad row** — a sender that throws fails that row, and the run goes on.
- 🚦 **A run lock** — `Lock::acquire()` for the cron script, released by the OS however the process ends.
- 📈 **Overdue count** — for a health check, because a stopped worker does not fail, its queue just fills.

## Requirements

PHP 8.5+, `sugarcommerce/sqlite`, `sugarcommerce/webhooks`

## Installation

```bash
composer require sugarcommerce/outbox
```

## The table

Your table, your extra columns. These are the ones the queue uses:

```sql
CREATE TABLE mail_outbox (
    id              INTEGER PRIMARY KEY,
    -- … whatever the message needs …
    attempts        INTEGER NOT NULL DEFAULT 0,
    next_attempt_at INTEGER NOT NULL,
    claimed_until   INTEGER,
    status          TEXT    NOT NULL DEFAULT 'pending',
    last_error      TEXT,
    date_sent       INTEGER
);
CREATE INDEX mail_outbox_due ON mail_outbox (status, next_attempt_at);
```

What a finished row is called and where its time goes are yours to name:

```php
use SugarCommerce\Outbox\Table;

new Table( 'mail_outbox' );                                       // 'sent', date_sent
new Table( 'webhook_outbox', 'delivered', 'date_delivered', 'last_status' );
```

## Draining

```php
use SugarCommerce\Outbox\{Lock, Outcome, Queue, Table};

$lock = Lock::acquire( __DIR__ . '/var/queue.lock' );

if ( null === $lock ) {
    exit; // the previous run is still going
}

$queue  = new Queue( $db, new Table( 'mail_outbox' ) );
$counts = $queue->drain( function ( array $row ): Outcome {
    $result = $mailer->send( … );

    return $result->ok ? Outcome::sent() : Outcome::failed( $result->error );
} );
```

`Outcome::failed( $error, $status, $retry_after )` asks the policy; `Outcome::dropped( $error )` gives up now — for a row whose destination is gone.

## What it does not do

It does not promise exactly-once. A send that succeeded just before its worker died is sent again when the lease runs out. Receivers of anything queued have to tolerate a duplicate; give them a stable id to deduplicate on.

## License

GPL-2.0-or-later
