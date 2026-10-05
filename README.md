# Outbox

A durable send queue on SQLite: rows claimed atomically under a lease so overlapping workers never send twice, a crashed worker's rows come back on their own, and retries follow a status-aware backoff policy. An inbox that handles each incoming event once. Plus the pieces that bound a cron run: a lock, a time budget and steps that cannot stop each other.

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
- 📈 **Overdue and failed counts** — for a health check, because a stopped worker does not fail, its queue just fills.
- ♻️ **Retry and purge** — put a failed row back by hand; remove finished rows after their time, with a dry run.
- 📥 **An inbox** — incoming events handled once however often they arrive, with failed and crashed handlers taken over by the sender's retry.
- ⏱️ **Bounded runs** — a monotonic time `Budget`, a `Pass` that shares one row count down a sweep, `Steps` that cannot stop each other, and `Lock::with()`.

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

    return match ( true ) {
        $result->sent       => Outcome::sent(),
        $result->retryable  => Outcome::failed( $result->error, $result->status ),
        default             => Outcome::dropped( $result->error ),
    };
} );
```

`Outcome::failed( $error, $status, $retry_after )` asks the policy; `Outcome::dropped( $error )` gives up now — for a row whose destination is gone, or a refusal the far side has said is final. **Pass the status.** A failure with no status is a transport failure to the policy, and a transport failure is always retried: report a rejected address that way and it is tried six times over a day.

## Looking after the queue

```php
$queue->failed_count();            // rows that gave up, for a badge or a health check
$queue->overdue();                 // rows that should have gone and have not
$queue->retry( $id );              // a person decided to try again: due now, attempts reset
$queue->purge( strtotime( '-30 days' ), dry: $dry );   // finished rows, sent or failed
```

`retry()` only touches a failed row: resetting one a worker is sending would hand it to a second worker. `purge()` never removes a pending or sending row, and goes by when a row was queued — `date_created`, or the `created_column` given to `Table`.

## Receiving: the inbox

The other direction. Senders deliver at least once, so the same event arrives twice, and a handler that grants access or sends an email must not run twice.

```php
use SugarCommerce\Outbox\{Claim, Inbox};

$db->pdo()->exec( Inbox::SCHEMA );        // plus columns of your own

$inbox = new Inbox( $db );                 // or new Inbox( $db, 'stripe_events', 'stripe_event_id' )
$claim = $inbox->claim( $event->id, [ 'type' => $event->type ] );

if ( ! $claim->owned() ) {
    return Claim::Busy === $claim ? 409 : 200;   // running now, or already handled
}

try {
    handle( $event );
    $inbox->complete( $event->id );
} catch ( \Throwable $e ) {
    $inbox->fail( $event->id, $e->getMessage() );
    return 500;                            // the sender's retry takes it over
}
```

- **The claim is the insert.** "Have we seen this id?" then insert has a window two concurrent deliveries both pass through; here the event id's unique index lets exactly one win.
- **A failure is not "done".** The retry a sender sends *because* you answered 500 takes the row over. Answering "already processed" to it is the classic version of this bug.
- **A crash is not "busy" for ever.** A claim older than `STALE_AFTER` (five minutes) can be taken over. Every takeover is one conditional `UPDATE`, so racing retries still produce one owner.

## Bounding a cron run

A row limit does not bound a run whose rows each make network calls, and one exception should not stop every step after it.

```php
use SugarCommerce\Outbox\{Budget, Lock, Pass, Steps};

$steps = Steps::logging( 'bin/queue.php' );   // a step that throws is logged and counts as its fallback

// Each group on its own lock: a slow sweep skips only itself, never the mail.
$mail  = Lock::with( $dir . '/mail.lock', fn () => $steps->run( 'mail', fn () => $outbox->run(), $none ), $none );
$swept = Lock::with( $dir . '/sweeps.lock', fn () => array(
    'links' => $steps->run( 'links', fn () => $links->sweep( 25, new Budget( 15 ) ), 0 ),
), array() );
```

`Budget` is a time limit on a monotonic clock — wall time jumps under NTP — checked between rows with `spent()`. `Pass` carries one row count, the budget and the due time down a sweep that walks accounts and then their rows, so its limit is the run's and not each account's; `take()` says whether the run may do another. `Lock::with()` releases its lock when the work throws, so one failing step does not hold it for the rest of the script.

For a sweep that counts its own failures rather than reading a status code, `RetryPolicy::exhausted()` and `next_attempt_at()` (in `sugarcommerce/webhooks`) say when to give up and when a row is next due.

## What it does not do

It does not promise exactly-once. A send that succeeded just before its worker died is sent again when the lease runs out. Receivers of anything queued have to tolerate a duplicate; give them a stable id to deduplicate on — and if the receiver is yours, that is what `Inbox` is for.

## Testing

```bash
composer install
composer test
```

46 tests — atomic claims and leases, retries by status and `Retry-After`, a throwing sender, overdue and failed counts, putting a failed row back, purging finished rows only, the inbox's first delivery, busy and done repeats, failure and stale takeovers with one owner, and the run bounds: budgets, passes, isolated steps and locks released on a throw.

## License

GPL-2.0-or-later
