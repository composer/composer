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
use Composer\Policy\ListPolicyConfig;
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

    public function testForBlockScope(): void
    {
        $ignoreUnreachable = new IgnoreUnreachable(false, true, false);

        self::assertTrue($ignoreUnreachable->forBlockScope(ListPolicyConfig::BLOCK_SCOPE_INSTALL));
        self::assertFalse($ignoreUnreachable->forBlockScope(ListPolicyConfig::BLOCK_SCOPE_UPDATE));

        $ignoreUnreachable = new IgnoreUnreachable(false, false, true);

        self::assertFalse($ignoreUnreachable->forBlockScope(ListPolicyConfig::BLOCK_SCOPE_INSTALL));
        self::assertTrue($ignoreUnreachable->forBlockScope(ListPolicyConfig::BLOCK_SCOPE_UPDATE));
    }

    public function testWithOnlyFlipsRequestedScope(): void
    {
        $ignoreUnreachable = new IgnoreUnreachable(false, false, true);

        $updated = $ignoreUnreachable->with('audit');

        self::assertTrue($updated->audit);
        self::assertFalse($updated->install);
        self::assertTrue($updated->update);
    }

    public function testWithAcceptsMultipleScopes(): void
    {
        $ignoreUnreachable = IgnoreUnreachable::none();

        $updated = $ignoreUnreachable->with('audit', 'install');

        self::assertTrue($updated->audit);
        self::assertTrue($updated->install);
        self::assertFalse($updated->update);
    }

    public function testWithRejectsUnknownScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IgnoreUnreachable::none()->with('not-a-scope');
    }

    public function testWithRequiresAtLeastOneScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        IgnoreUnreachable::none()->with();
    }
}
