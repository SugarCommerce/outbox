<?php
/**
 * One worker at a time.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Class Lock
 *
 * An advisory file lock for a cron script. A queue run every minute that can
 * take longer than a minute overlaps itself; the row claims in Queue already
 * make that safe, and this makes it quiet — the second run sees the first
 * and leaves.
 *
 * Released when the process ends, however it ends, because the operating
 * system drops an flock with the file handle. A crashed run cannot leave the
 * queue locked.
 */
final class Lock {

	/**
	 * @param resource $handle The open, locked file.
	 */
	private function __construct( private $handle ) {}

	/**
	 * Take the lock, or return null when another process holds it.
	 *
	 * @param string $path Lock file. Created when missing.
	 *
	 * @return self|null
	 *
	 * @throws \RuntimeException When the file cannot be opened at all, which
	 *                           is a deployment problem worth hearing about.
	 */
	#[\NoDiscard( 'Null means another run holds the lock; a lock nobody keeps is released at once.' )]
	public static function acquire( string $path ): ?self {
		$handle = @fopen( $path, 'c' );

		if ( false === $handle ) {
			throw new \RuntimeException( 'Cannot open lock file: ' . $path );
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );

			return null;
		}

		return new self( $handle );
	}

	/**
	 * Let the next run in.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( is_resource( $this->handle ) ) {
			flock( $this->handle, LOCK_UN );
			fclose( $this->handle );
		}
	}
}
