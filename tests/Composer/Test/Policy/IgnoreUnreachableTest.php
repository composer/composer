<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Policy;

use Composer\Policy\IgnoreUnreachable;
use Composer\Test\TestCase;

class IgnoreUnreachableTest extends TestCase
{
    public function testFromRawAuditConfig(): void
    {
        $ignoreUnreachable = IgnoreUnreachable::fromRawAuditConfig([
            'ignore-unreachable' => true,
        ]);

        self::assertTrue($ignoreUnreachable->audit);
        self::assertFalse($ignoreUnreachable->install);
        self::assertFalse($ignoreUnreachable->update);
    }
}
