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
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Repository\LockArrayRepository;
use Composer\Test\TestCase;

class PoolOptimizerTest extends TestCase
{
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

        $this->assertSame(
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
