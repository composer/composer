<?php

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

use Composer\IO\NullIO;
use Composer\Repository\ArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\PoolBuilder;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\Solver;
use Composer\DependencyResolver\SolverProblemsException;
use Composer\Package\BasePackage;
use Composer\Package\AliasPackage;
use Composer\Json\JsonFile;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;
use Composer\Semver\Constraint\MultiConstraint;

class PoolBuilderTest extends TestCase
{
    /**
     * @dataProvider getIntegrationTests
     */
    public function testPoolBuilder($file, $message, $expect, $root, $requestData, $packages, $fixed)
    {
        $rootAliases = !empty($root['aliases']) ? $root['aliases'] : array();
        $minimumStability = !empty($root['minimum-stability']) ? $root['minimum-stability'] : 'stable';
        $stabilityFlags = !empty($root['stability-flags']) ? $root['stability-flags'] : array();
        $stabilityFlags = array_map(function ($stability) {
            return BasePackage::$stabilities[$stability];
        }, $stabilityFlags);

        $parser = new VersionParser();
        $normalizedAliases = array();
        foreach ($rootAliases as $alias) {
            $normalizedAliases[$alias['package']][$parser->normalize($alias['version'])] = array(
                'alias' => $alias['alias'],
                'alias_normalized' => $parser->normalize($alias['alias']),
            );
        }

        $loader = new ArrayLoader();
        $packageIds = array();
        $loadPackage = function ($data) use ($loader, &$packageIds) {
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

        $repositorySet = new RepositorySet($normalizedAliases, array(), $minimumStability, $stabilityFlags);
        $repositorySet->addRepository($repo = new ArrayRepository());
        foreach ($packages as $package) {
            $repo->addPackage($loadPackage($package));
        }

        $request = new Request();
        foreach ($requestData as $package => $constraint) {
            $request->require($package, $parser->parseConstraints($constraint));
        }

        foreach ($fixed as $fixedPackage) {
            $request->fixPackage($loadPackage($fixedPackage));
        }

        $pool = $repositorySet->createPool($request);
        for ($i = 1, $count = count($pool); $i <= $count; $i++) {
            $result[] = $pool->packageById($i);
        }

        $result = array_map(function ($package) use ($packageIds) {
            if ($id = array_search($package, $packageIds, true)) {
                return $id;
            }

            if ($package instanceof AliasPackage && $id = array_search($package->getAliasOf(), $packageIds, true)) {
                return (string) $package->getName().'-'.$package->getVersion() .' alias of '.$id;
            }

            return (string) $package;
        }, $result);

        $this->assertSame($expect, $result);
    }

    public function getIntegrationTests()
    {
        $fixturesDir = realpath(__DIR__.'/Fixtures/poolbuilder/');
        $tests = array();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixturesDir), \RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
            if (!preg_match('/\.test$/', $file)) {
                continue;
            }

            try {
                $testData = $this->readTestFile($file, $fixturesDir);

                $message = $testData['TEST'];

                $request = JsonFile::parseJson($testData['REQUEST']);
                $root = !empty($testData['ROOT']) ? JsonFile::parseJson($testData['ROOT']) : array();

                $packages = JsonFile::parseJson($testData['PACKAGES']);
                $fixed = array();
                if (!empty($testData['FIXED'])) {
                    $fixed = JsonFile::parseJson($testData['FIXED']);
                }
                $expect = JsonFile::parseJson($testData['EXPECT']);
            } catch (\Exception $e) {
                die(sprintf('Test "%s" is not valid: '.$e->getMessage(), str_replace($fixturesDir.'/', '', $file)));
            }

            $tests[basename($file)] = array(str_replace($fixturesDir.'/', '', $file), $message, $expect, $root, $request, $packages, $fixed);
        }

        return $tests;
    }

    protected function readTestFile(\SplFileInfo $file, $fixturesDir)
    {
        $tokens = preg_split('#(?:^|\n*)--([A-Z-]+)--\n#', file_get_contents($file->getRealPath()), null, PREG_SPLIT_DELIM_CAPTURE);

        $sectionInfo = array(
            'TEST' => true,
            'ROOT' => false,
            'REQUEST' => true,
            'FIXED' => false,
            'PACKAGES' => true,
            'EXPECT' => true,
        );

        $section = null;
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
