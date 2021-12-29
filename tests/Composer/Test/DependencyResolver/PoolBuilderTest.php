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
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Pcre\Preg;
use Composer\Repository\ArrayRepository;
use Composer\Repository\FilterRepository;
use Composer\Repository\LockArrayRepository;
use Composer\DependencyResolver\Request;
use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryFactory;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;
use Composer\Util\Platform;

class PoolBuilderTest extends TestCase
{
    /**
     * @dataProvider getIntegrationTests
     * @param string[] $expect
     * @param string[] $expectOptimized
     * @param mixed[] $root
     * @param mixed[] $requestData
     * @param mixed[] $packageRepos
     * @param mixed[] $fixed
     */
    public function testPoolBuilder(string $file, string $message, array $expect, array $expectOptimized, array $root, array $requestData, array $packageRepos, array $fixed): void
    {
        $rootAliases = !empty($root['aliases']) ? $root['aliases'] : [];
        $minimumStability = !empty($root['minimum-stability']) ? $root['minimum-stability'] : 'stable';
        $stabilityFlags = !empty($root['stability-flags']) ? $root['stability-flags'] : [];
        $rootReferences = !empty($root['references']) ? $root['references'] : [];
        $stabilityFlags = array_map(static function ($stability): int {
            return BasePackage::$stabilities[$stability];
        }, $stabilityFlags);

        $parser = new VersionParser();
        foreach ($rootAliases as $index => $alias) {
            $rootAliases[$index]['version'] = $parser->normalize($alias['version']);
            $rootAliases[$index]['alias_normalized'] = $parser->normalize($alias['alias']);
        }

        $loader = new ArrayLoader(null, true);
        $packageIds = [];
        $loadPackage = static function ($data) use ($loader, &$packageIds): \Composer\Package\PackageInterface {
            /** @var ?int $id */
            $id = null;
            if (!empty($data['id'])) {
                $id = $data['id'];
                unset($data['id']);
            }

            $pkg = $loader->load($data);

            if (!empty($id)) {
                if (!empty($packageIds[$id])) {
                    throw new \LogicException('Duplicate package id '.$id.' defined');
                }
                $packageIds[$id] = $pkg;
            }

            return $pkg;
        };

        $oldCwd = Platform::getCwd();
        chdir(__DIR__.'/Fixtures/poolbuilder/');

        $repositorySet = new RepositorySet($minimumStability, $stabilityFlags, $rootAliases, $rootReferences);
        $config = new Config(false);
        $rm = RepositoryFactory::manager($io = new NullIO(), $config);
        foreach ($packageRepos as $packages) {
            if (isset($packages['type'])) {
                $repo = RepositoryFactory::createRepo($io, $config, $packages, $rm);
                $repositorySet->addRepository($repo);
                continue;
            }

            $repo = new ArrayRepository();
            if (isset($packages['canonical']) || isset($packages['only']) || isset($packages['exclude'])) {
                $options = $packages;
                $packages = $options['packages'];
                unset($options['packages']);
                $repositorySet->addRepository(new FilterRepository($repo, $options));
            } else {
                $repositorySet->addRepository($repo);
            }
            foreach ($packages as $package) {
                $repo->addPackage($loadPackage($package));
            }
        }
        $repositorySet->addRepository($lockedRepo = new LockArrayRepository());

        if (isset($requestData['locked'])) {
            foreach ($requestData['locked'] as $package) {
                $lockedRepo->addPackage($loadPackage($package));
            }
        }
        $request = new Request($lockedRepo);
        foreach ($requestData['require'] as $package => $constraint) {
            $request->requireName($package, $parser->parseConstraints($constraint));
        }
        if (isset($requestData['allowList'])) {
            $transitiveDeps = Request::UPDATE_ONLY_LISTED;
            if (isset($requestData['allowTransitiveDepsNoRootRequire']) && $requestData['allowTransitiveDepsNoRootRequire']) {
                $transitiveDeps = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS_NO_ROOT_REQUIRE;
            }
            if (isset($requestData['allowTransitiveDeps']) && $requestData['allowTransitiveDeps']) {
                $transitiveDeps = Request::UPDATE_LISTED_WITH_TRANSITIVE_DEPS;
            }
            $request->setUpdateAllowList(array_flip($requestData['allowList']), $transitiveDeps);
        }

        foreach ($fixed as $fixedPackage) {
            $request->fixPackage($loadPackage($fixedPackage));
        }

        $pool = $repositorySet->createPool($request, new NullIO());

        $result = $this->getPackageResultSet($pool, $packageIds);

        sort($expect);
        sort($result);
        $this->assertSame($expect, $result, 'Unoptimized pool does not match expected package set');

        $optimizer = new PoolOptimizer(new DefaultPolicy());
        $result = $this->getPackageResultSet($optimizer->optimize($request, $pool), $packageIds);
        sort($expectOptimized);
        sort($result);
        $this->assertSame($expectOptimized, $result, 'Optimized pool does not match expected package set');

        chdir($oldCwd);
    }

    /**
     * @param array<int, BasePackage> $packageIds
     * @return list<string|int>
     */
    private function getPackageResultSet(Pool $pool, array $packageIds): array
    {
        $result = [];
        for ($i = 1, $count = count($pool); $i <= $count; $i++) {
            $result[] = $pool->packageById($i);
        }

        return array_map(static function (BasePackage $package) use ($packageIds) {
            if ($id = array_search($package, $packageIds, true)) {
                return $id;
            }

            $suffix = '';
            if ($package->getSourceReference()) {
                $suffix = '#'.$package->getSourceReference();
            }
            if ($package->getRepository() instanceof LockArrayRepository) {
                $suffix .= ' (locked)';
            }

            if ($package instanceof AliasPackage) {
                if ($id = array_search($package->getAliasOf(), $packageIds, true)) {
                    return (string) $package->getName().'-'.$package->getVersion() . $suffix . ' (alias of '.$id . ')';
                }

                return (string) $package->getName().'-'.$package->getVersion() . $suffix . ' (alias of '.$package->getAliasOf()->getVersion().')';
            }

            return (string) $package->getName().'-'.$package->getVersion() . $suffix;
        }, $result);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function getIntegrationTests(): array
    {
        $fixturesDir = (string) realpath(__DIR__.'/Fixtures/poolbuilder/');
        $tests = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            $file = (string) $file;

            if (!Preg::isMatch('/\.test$/', $file)) {
                continue;
            }

            try {
                $testData = self::readTestFile($file, $fixturesDir);

                $message = $testData['TEST'];

                $request = JsonFile::parseJson($testData['REQUEST']);
                $root = !empty($testData['ROOT']) ? JsonFile::parseJson($testData['ROOT']) : [];

                $packageRepos = JsonFile::parseJson($testData['PACKAGE-REPOS']);
                $fixed = [];
                if (!empty($testData['FIXED'])) {
                    $fixed = JsonFile::parseJson($testData['FIXED']);
                }
                $expect = JsonFile::parseJson($testData['EXPECT']);
                $expectOptimized = !empty($testData['EXPECT-OPTIMIZED']) ? JsonFile::parseJson($testData['EXPECT-OPTIMIZED']) : $expect;
            } catch (\Exception $e) {
                die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[basename($file)] = [str_replace($fixturesDir.'/', '', $file), $message, $expect, $expectOptimized, $root, $request, $packageRepos, $fixed];
        }

        return $tests;
    }

    /**
     * @return array<string, string>
     */
    protected static function readTestFile(string $file, string $fixturesDir): array
    {
        $tokens = Preg::split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file), -1, PREG_SPLIT_DELIM_CAPTURE);

        $sectionInfo = [
            'TEST' => true,
            'ROOT' => false,
            'REQUEST' => true,
            'FIXED' => false,
            'PACKAGE-REPOS' => true,
            'EXPECT' => true,
            'EXPECT-OPTIMIZED' => false,
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
}
