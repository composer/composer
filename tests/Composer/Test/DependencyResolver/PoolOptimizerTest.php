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

use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolOptimizer;
use Composer\DependencyResolver\Request;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Repository\LockArrayRepository;
use Composer\Test\TestCase;

class PoolOptimizerTest extends TestCase
{
    /**
     * When a package replaces another name, it appears in identical-dependency groups for both
     * its own name and the replacement name. The kept package's removedVersionsByPackage should
     * contain versions from all name groups it participates in.
     *
     * Setup: package/a and package/c both replace package/b with the same constraint.
     * This creates two groups:
     *   - 'package/a' group: [pkg/a 1.0, pkg/a 1.1]
     *   - 'package/b' group: [pkg/a 1.0, pkg/a 1.1, pkg/c 1.5, pkg/c 1.6]
     * The kept pkg/a 1.1 should record versions from both groups.
     */
    public function testRemovedVersionsByPackageIncludesVersionsFromReplacementNameGroups(): void
    {
        $lockedRepo = new LockArrayRepository();
        $request = new Request($lockedRepo);
        $parser = new VersionParser();
        $replaceConstraint = $parser->parseConstraints('^1.0');
        $request->requireName('package/a', $parser->parseConstraints('^1.0'));
        $request->requireName('package/b', $parser->parseConstraints('^1.0'));

        // package/a 1.0.0 and 1.1.0 both replace package/b
        $packageA100 = self::getPackage('package/a', '1.0.0');
        $packageA100->setReplaces(['package/b' => new Link('package/a', 'package/b', $replaceConstraint, Link::TYPE_REPLACE)]);
        $packageA110 = self::getPackage('package/a', '1.1.0');
        $packageA110->setReplaces(['package/b' => new Link('package/a', 'package/b', $replaceConstraint, Link::TYPE_REPLACE)]);

        // package/c 1.5.0 and 1.6.0 also replace package/b (same constraint, same deps = same group under 'package/b')
        $packageC150 = self::getPackage('package/c', '1.5.0');
        $packageC150->setReplaces(['package/b' => new Link('package/c', 'package/b', $replaceConstraint, Link::TYPE_REPLACE)]);
        $packageC160 = self::getPackage('package/c', '1.6.0');
        $packageC160->setReplaces(['package/b' => new Link('package/c', 'package/b', $replaceConstraint, Link::TYPE_REPLACE)]);

        $pool = new Pool([$packageA100, $packageA110, $packageC150, $packageC160]);
        $poolOptimizer = new PoolOptimizer(new DefaultPolicy());
        $optimizedPool = $poolOptimizer->optimize($request, $pool);

        $removedVersions = $optimizedPool->getRemovedVersionsByPackage(spl_object_hash($packageA110));

        // Versions from 'package/a' name group
        self::assertArrayHasKey($packageA100->getVersion(), $removedVersions, 'Should contain package/a 1.0.0 from package/a name group');
        self::assertArrayHasKey($packageA110->getVersion(), $removedVersions, 'Should contain package/a 1.1.0 from package/a name group');
        // Versions from 'package/b' name group (via replacement — includes package/c versions)
        self::assertArrayHasKey($packageC150->getVersion(), $removedVersions, 'Should contain package/c 1.5.0 from package/b name group');
        self::assertArrayHasKey($packageC160->getVersion(), $removedVersions, 'Should contain package/c 1.6.0 from package/b name group');
    }

    public function testKeepingAnAliasRecordsRemovedVersionsForAliasOfAndSiblingAliases(): void
    {
        $lockedRepo = new LockArrayRepository();

        $request = new Request($lockedRepo);
        $parser = new VersionParser();
        $request->requireName('package/a', $parser->parseConstraints('^1.1'));

        $package110 = self::getPackage('package/a', '1.1.0');
        $package110Alias = self::getAliasPackage($package110, '1.1.x-dev');
        $package110SiblingAlias = self::getAliasPackage($package110, '1.1.x-dev');
        $package111 = self::getPackage('package/a', '1.1.1');
        $package111Alias = self::getAliasPackage($package111, '1.1.x-dev');

        $pool = new Pool([$package110, $package110Alias, $package110SiblingAlias, $package111, $package111Alias]);
        $poolOptimizer = new PoolOptimizer(new DefaultPolicy());
        $optimizedPool = $poolOptimizer->optimize($request, $pool);

        $expectedVersions = [
            $package110->getVersion() => $package110->getPrettyVersion(),
            $package110Alias->getVersion() => $package110Alias->getPrettyVersion(),
            $package111->getVersion() => $package111->getPrettyVersion(),
        ];

        self::assertSame($expectedVersions, $optimizedPool->getRemovedVersionsByPackage(spl_object_hash($package110Alias)));
        self::assertSame($expectedVersions, $optimizedPool->getRemovedVersionsByPackage(spl_object_hash($package110)));
        self::assertSame($expectedVersions, $optimizedPool->getRemovedVersionsByPackage(spl_object_hash($package110SiblingAlias)));
    }

    /**
     * @dataProvider provideIntegrationTests
     * @param mixed[] $requestData
     * @param BasePackage[] $packagesBefore
     * @param BasePackage[] $expectedPackages
     */
    public function testPoolOptimizer(array $requestData, array $packagesBefore, array $expectedPackages, string $message): void
    {
        $lockedRepo = new LockArrayRepository();

        $request = new Request($lockedRepo);
        $parser = new VersionParser();

        if (isset($requestData['locked'])) {
            foreach ($requestData['locked'] as $package) {
                $request->lockPackage(self::loadPackage($package));
            }
        }
        if (isset($requestData['fixed'])) {
            foreach ($requestData['fixed'] as $package) {
                $request->fixPackage(self::loadPackage($package));
            }
        }

        foreach ($requestData['require'] as $package => $constraint) {
            $request->requireName($package, $parser->parseConstraints($constraint));
        }

        $preferStable = $requestData['preferStable'] ?? false;
        $preferLowest = $requestData['preferLowest'] ?? false;

        $pool = new Pool($packagesBefore);
        $poolOptimizer = new PoolOptimizer(new DefaultPolicy($preferStable, $preferLowest));

        $pool = $poolOptimizer->optimize($request, $pool);

        self::assertSame(
            $this->reducePackagesInfoForComparison($expectedPackages),
            $this->reducePackagesInfoForComparison($pool->getPackages()),
            $message
        );
    }

    public static function provideIntegrationTests(): array
    {
        $fixturesDir = (string) realpath(__DIR__.'/Fixtures/pooloptimizer/');
        $tests = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            $file = (string) $file;

            if (!Preg::isMatch('/\.test$/', $file)) {
                continue;
            }

            try {
                $testData = self::readTestFile($file, $fixturesDir);
                $message = $testData['TEST'];
                $requestData = JsonFile::parseJson($testData['REQUEST']);
                $packagesBefore = self::loadPackages(JsonFile::parseJson($testData['POOL-BEFORE']));
                $expectedPackages = self::loadPackages(JsonFile::parseJson($testData['POOL-AFTER']));
            } catch (\Exception $e) {
                die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[basename($file)] = [$requestData, $packagesBefore, $expectedPackages, $message];
        }

        return $tests;
    }

    /**
     * @return mixed[]
     */
    protected static function readTestFile(string $file, string $fixturesDir): array
    {
        $tokens = Preg::split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file), -1, PREG_SPLIT_DELIM_CAPTURE);

        /** @var array<string, bool> $sectionInfo */
        $sectionInfo = [
            'TEST' => true,
            'REQUEST' => true,
            'POOL-BEFORE' => true,
            'POOL-AFTER' => true,
        ];

        $section = null;
        $data = [];
        foreach ($tokens as $i => $token) {
            if (null === $section && empty($token)) {
                continue; // skip leading blank
            }

            if (null === $section) {
                if (!isset($sectionInfo[$token])) {
                    throw new \RuntimeException(sprintf(
                        'The test file "%s" must not contain a section named "%s".',
                        str_replace($fixturesDir.'/', '', $file),
                        $token
                    ));
                }
                $section = $token;
                continue;
            }

            $sectionData = $token;

            $data[$section] = $sectionData;
            $section = $sectionData = null;
        }

        foreach ($sectionInfo as $section => $required) {
            if ($required && !isset($data[$section])) {
                throw new \RuntimeException(sprintf(
                    'The test file "%s" must have a section named "%s".',
                    str_replace($fixturesDir.'/', '', $file),
                    $section
                ));
            }
        }

        return $data;
    }

    /**
     * @param BasePackage[] $packages
     * @return string[]
     */
    private function reducePackagesInfoForComparison(array $packages): array
    {
        $packagesInfo = [];

        foreach ($packages as $package) {
            $packagesInfo[] = $package->getName() . '@' . $package->getVersion() . ($package instanceof AliasPackage ? ' (alias of '.$package->getAliasOf()->getVersion().')' : '');
        }

        sort($packagesInfo);

        return $packagesInfo;
    }

    /**
     * @param mixed[][] $packagesData
     * @return BasePackage[]
     */
    private static function loadPackages(array $packagesData): array
    {
        $packages = [];

        foreach ($packagesData as $packageData) {
            $packages[] = $package = self::loadPackage($packageData);
            if ($package instanceof AliasPackage) {
                $packages[] = $package->getAliasOf();
            }
        }

        return $packages;
    }

    /**
     * @param mixed[] $packageData
     */
    private static function loadPackage(array $packageData): BasePackage
    {
        $loader = new ArrayLoader();

        return $loader->load($packageData);
    }
}
