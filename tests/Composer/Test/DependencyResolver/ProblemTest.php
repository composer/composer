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

namespace Composer\Test\DependencyResolver;

use Composer\DependencyResolver\GenericRule;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Problem;
use Composer\DependencyResolver\Rule;
use Composer\FilterList\FilterListEntry;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class ProblemTest extends TestCase
{
    public function testGetMissingLockedPackageReasonForFilterListRemovedPackage(): void
    {
        $package = self::getPackage('vendor/malware', '1.0.0');
        $entry = new FilterListEntry(
            'vendor/malware',
            new MatchAllConstraint(),
            'malware',
            'https://example.org/malware/vendor-malware',
            'looks suspicious',
            'PKG-1'
        );
        $pool = new Pool([], [], [], [], [], [], [
            'vendor/malware' => ['1.0.0.0' => [$entry]],
        ]);

        [$prefix, $suffix] = Problem::getMissingLockedPackageReason($pool, $package);

        self::assertSame('- Package vendor/malware 1.0.0 (in the lock file) ', $prefix);

        self::assertStringContainsString('flagged as malware', $suffix);
        self::assertStringContainsString('https://example.org/malware/vendor-malware', $suffix);
        self::assertStringContainsString('reason: looks suspicious', $suffix);
        self::assertStringContainsString('"policy.malware.ignore"', $suffix);
        self::assertStringContainsString('"policy.malware.block"', $suffix);
    }
}
