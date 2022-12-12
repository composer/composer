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

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionBumper;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;
use Generator;

class VersionBumperTest extends TestCase
{
    /**
     * @dataProvider provideBumpRequirementTests
     */
    public function testBumpRequirement(string $requirement, string $prettyVersion, string $expectedRequirement, ?string $branchAlias = null): void
    {
        $versionBumper = new VersionBumper();
        $versionParser = new VersionParser();

        $package = new Package('foo/bar', $versionParser->normalize($prettyVersion), $prettyVersion);

        if ($branchAlias !== null) {
            $package->setExtra(['branch-alias' => [$prettyVersion => $branchAlias]]);
        }

        $newConstraint = $versionBumper->bumpRequirement($versionParser->parseConstraints($requirement), $package);

        // assert that the recommended version is what we expect
        $this->assertSame($expectedRequirement, $newConstraint);
    }

    public static function provideBumpRequirementTests(): Generator
    {
        // constraint, version, expected recommendation, [branch-alias]
        yield 'upgrade caret' => ['^1.0', '1.2.1', '^1.2.1'];
        yield 'skip trailing .0s' => ['^1.0', '1.0.0', '^1.0'];
        yield 'skip trailing .0s/2' => ['^1.2', '1.2.0', '^1.2'];
        yield 'preserve major.minor.patch format when installed minor is 0' => ['^1.0.0', '1.2.0', '^1.2.0'];
        yield 'preserve major.minor.patch format when installed minor is 1' => ['^1.0.0', '1.2.1', '^1.2.1'];
        yield 'preserve multi constraints' => ['^1.2 || ^2.3', '1.3.2', '^1.3.2 || ^2.3'];
        yield 'preserve multi constraints/2' => ['^1.2 || ^2.3', '2.4.0', '^1.2 || ^2.4'];
        yield 'preserve multi constraints/3' => ['^1.2 || ^2.3 || ^2', '2.4.0', '^1.2 || ^2.4 || ^2.4'];
        yield '@dev is preserved' => ['^3@dev', '3.2.x-dev', '^3.2@dev'];
        yield 'non-stable versions abort upgrades' => ['~2', '2.1-beta.1', '~2'];
        yield 'dev reqs are skipped' => ['dev-main', 'dev-foo', 'dev-main'];
        yield 'dev version does not upgrade' => ['^3.2', 'dev-main', '^3.2'];
        yield 'upgrade dev version if aliased' => ['^3.2', 'dev-main', '^3.3', '3.3.x-dev'];
        yield 'upgrade major wildcard to caret' => ['2.*', '2.4.0', '^2.4'];
        yield 'upgrade major wildcard as x to caret' => ['2.x.x', '2.4.0', '^2.4'];
        yield 'leave minor wildcard alone' => ['2.4.*', '2.4.3', '2.4.*'];
        yield 'leave patch wildcard alone' => ['2.4.3.*', '2.4.3.2', '2.4.3.*'];
        yield 'upgrade tilde to caret when compatible' => ['~2.2', '2.4.3', '^2.4.3'];
        yield 'leave patch-only-tilde alone' => ['~2.2.3', '2.2.6', '~2.2.3'];
    }
}
