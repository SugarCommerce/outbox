<?php
/**
 * What happened when one row was attempted.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Class Outcome
 *
 * Returned by the function a queue drains with. Three answers, because there
 * are three things a queue can do next: mark it done, try again later, or
 * give up now without trying again — the last for a row whose destination is
 * gone, where retrying would only be noise.
 *
 * @since 1.0.0
 */
final readonly class Outcome {

	/**
	 * Hold an answer; the named constructors are the way in.
	 *
	 * @since 1.0.0
	 *
	 * @param bool     $sent        Whether it went.
	 * @param int      $status      The receiver's status code, 0 for none.
	 * @param string   $error       Why not, when it did not.
	 * @param bool     $final       Give up rather than consult the policy.
	 * @param int|null $retry_after Seconds the receiver asked us to wait.
	 */
	private function __construct(
		public bool $sent,
		public int $status = 0,
		public string $error = '',
		public bool $final = false,
		public ?int $retry_after = null,
	) {}

	/**
	 * It went.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status The receiver's status code, if there was one.
	 *
	 * @return self
	 */
	public static function sent( int $status = 0 ): self {
		return new self( true, $status );
	}

	/**
	 * It did not; the retry policy decides whether to try again.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $error       What went wrong.
	 * @param int      $status      Status code, 0 for a transport failure.
	 * @param int|null $retry_after Seconds the receiver asked for.
	 *
	 * @return self
	 */
	public static function failed( string $error, int $status = 0, ?int $retry_after = null ): self {
		return new self( false, $status, $error, retry_after: $retry_after );
	}

	/**
	 * It did not, and it never will.
	 *
	 * @since 1.0.0
	 *
	 * @param string $error Why.
	 *
	 * @return self
	 */
	public static function dropped( string $error ): self {
		return new self( false, 0, $error, true );
	}
}
