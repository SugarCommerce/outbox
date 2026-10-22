<?php
/**
 * Steps of a cron run that cannot stop each other.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Class Steps
 *
 * A cron script that drains the mail, then the webhooks, then sweeps a few
 * tables is one uncaught exception away from doing none of the later ones:
 * a SQLITE_BUSY in the first step, or a client surprised by an answer, and
 * every minute after that the mail behind it waits too. Each step here runs
 * on its own; one that throws is reported, counts as its fallback, and the
 * next step runs.
 *
 * With Lock::with(), steps that must not overlap the previous minute's run
 * get a lock of their own, so a slow sweep skips only itself:
 *
 *   $steps = Steps::logging( 'bin/queue.php' );
 *
 *   $mail  = Lock::with( $dir . '/mail.lock', fn () => $steps->run( 'mail', fn () => $mailer->drain(), $none ), $none );
 *   $swept = Lock::with( $dir . '/sweeps.lock', fn () => array(
 *       'links' => $steps->run( 'links', fn () => $links->sweep(), 0 ),
 *       'clips' => $steps->run( 'clips', fn () => $clips->sweep(), 0 ),
 *   ), array() );
 *
 * @since 1.2.0
 */
final readonly class Steps {

	/**
	 * Report failures to a function of your own.
	 *
	 * @since 1.2.0
	 *
	 * @param \Closure(string, \Throwable): void $failed Told about a step
	 *                                                   that threw.
	 */
	public function __construct( private \Closure $failed ) {}

	/**
	 * Steps whose failures go to the PHP error log, named for the script.
	 *
	 * @since 1.2.0
	 *
	 * @param string $script What to call the run in the log.
	 *
	 * @return self
	 */
	public static function logging( string $script ): self {
		return new self( static function ( string $name, \Throwable $e ) use ( $script ): void {
			error_log( sprintf( '%s: %s failed: %s: %s', $script, $name, $e::class, $e->getMessage() ) );
		} );
	}

	/**
	 * Run one step, and keep going whatever it does.
	 *
	 * @since 1.2.0
	 *
	 * @param string          $name     What to call it when it fails.
	 * @param \Closure(): mixed $work     The step.
	 * @param mixed           $fallback What it counts as when it throws.
	 *
	 * @return mixed What the step returned, or the fallback.
	 */
	public function run( string $name, \Closure $work, mixed $fallback ): mixed {
		try {
			return $work();
		} catch ( \Throwable $e ) {
			( $this->failed )( $name, $e );

			return $fallback;
		}
	}
}
