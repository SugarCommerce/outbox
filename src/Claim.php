<?php
/**
 * What claiming an incoming event decided.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox;

/**
 * Enum Claim
 *
 * Three answers, because a sender retrying an event needs three different
 * replies: run the handler, say it was already handled, or say it is being
 * handled right now — which is not the same as "done", since the run in
 * progress may yet fail.
 *
 * @since 1.2.0
 */
enum Claim: string {

	/**
	 * This delivery owns the event: run the handler, then complete() or
	 * fail() it.
	 */
	case Owned = 'owned';

	/**
	 * An earlier delivery finished it. Acknowledge and do nothing.
	 */
	case Done = 'done';

	/**
	 * Another delivery is running it now. Ask the sender to try later.
	 */
	case Busy = 'busy';

	/**
	 * Whether the handler should run.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function owned(): bool {
		return self::Owned === $this;
	}
}
