<?php
/**
 * Results that must not be ignored.
 *
 * @package   SugarCommerce\Outbox
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Outbox\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Outbox\{Inbox, Lock, Pass};

/**
 * Class MustCheckTest
 *
 * These answer "did it work?" or "is it allowed?", and code that ignores the
 * answer behaves as though it were yes. #[\NoDiscard] makes PHP warn where a
 * result is dropped; this keeps the attribute from being lost in a refactor.
 */
final class MustCheckTest extends TestCase {

	/**
	 * @return array<string, array{class-string, string}>
	 */
	public static function methods(): array {
		return array(
			'Lock::acquire' => array( Lock::class, 'acquire' ),
			'Inbox::claim'  => array( Inbox::class, 'claim' ),
			'Pass::take'    => array( Pass::class, 'take' ),
		);
	}

	#[DataProvider( 'methods' )]
	public function test_the_result_must_be_used( string $class, string $method ): void {
		$attributes = ( new \ReflectionMethod( $class, $method ) )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, $class . '::' . $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message, 'Say why it matters.' );
	}
}
