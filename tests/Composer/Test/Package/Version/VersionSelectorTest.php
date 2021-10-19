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

namespace Composer\Test\Package\Version;

use Composer\Package\Version\VersionSelector;
use Composer\Package\Package;
use Composer\Package\Link;
use Composer\Package\AliasPackage;
use Composer\Repository\PlatformRepository;
use Composer\Package\Version\VersionParser;
use Composer\Test\TestCase;

class VersionSelectorTest extends TestCase
{
    // A) multiple versions, get the latest one
    // B) targetPackageVersion will pass to repo set
    // C) No results, throw exception

    public function testLatestVersionIsReturned()
    {
        $packageName = 'foobar';

        $package1 = $this->createPackage('1.2.1');
        $package2 = $this->createPackage('1.2.2');
        $package3 = $this->createPackage('1.2.0');
        $packages = array($package1, $package2, $package3);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName);

        // 1.2.2 should be returned because it's the latest of the returned versions
        $this->assertSame($package2, $best, 'Latest version should be 1.2.2');
    }

    public function testLatestVersionIsReturnedThatMatchesPhpRequirements()
    {
        $packageName = 'foobar';

        $platform = new PlatformRepository(array(), array('php' => '5.5.0'));
        $repositorySet = $this->createMockRepositorySet();
        $versionSelector = new VersionSelector($repositorySet, $platform);

        $parser = new VersionParser;
        $package1 = $this->createPackage('1.0.0');
        $package1->setRequires(array('php' => new Link($packageName, 'php', $parser->parseConstraints('>=5.4'), Link::TYPE_REQUIRE, '>=5.4')));
        $package2 = $this->createPackage('2.0.0');
        $package2->setRequires(array('php' => new Link($packageName, 'php', $parser->parseConstraints('>=5.6'), Link::TYPE_REQUIRE, '>=5.6')));
        $packages = array($package1, $package2);

        $repositorySet->expects($this->any())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $best = $versionSelector->findBestCandidate($packageName);
        $this->assertSame($package1, $best, 'Latest version supporting php 5.5 should be returned (1.0.0)');
        $best = $versionSelector->findBestCandidate($packageName, null, 'stable', true);
        $this->assertSame($package2, $best, 'Latest version should be returned when ignoring platform reqs (2.0.0)');
    }

    public function testLatestVersionIsReturnedThatMatchesExtRequirements()
    {
        $packageName = 'foobar';

        $platform = new PlatformRepository(array(), array('ext-zip' => '5.3.0'));
        $repositorySet = $this->createMockRepositorySet();
        $versionSelector = new VersionSelector($repositorySet, $platform);

        $parser = new VersionParser;
        $package1 = $this->createPackage('1.0.0');
        $package1->setRequires(array('ext-zip' => new Link($packageName, 'ext-zip', $parser->parseConstraints('^5.2'), Link::TYPE_REQUIRE, '^5.2')));
        $package2 = $this->createPackage('2.0.0');
        $package2->setRequires(array('ext-zip' => new Link($packageName, 'ext-zip', $parser->parseConstraints('^5.4'), Link::TYPE_REQUIRE, '^5.4')));
        $packages = array($package1, $package2);

        $repositorySet->expects($this->any())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $best = $versionSelector->findBestCandidate($packageName);
        $this->assertSame($package1, $best, 'Latest version supporting ext-zip 5.3.0 should be returned (1.0.0)');
        $best = $versionSelector->findBestCandidate($packageName, null, 'stable', true);
        $this->assertSame($package2, $best, 'Latest version should be returned when ignoring platform reqs (2.0.0)');
    }

    public function testLatestVersionIsReturnedThatMatchesPlatformExt()
    {
        $packageName = 'foobar';

        $platform = new PlatformRepository();
        $repositorySet = $this->createMockRepositorySet();
        $versionSelector = new VersionSelector($repositorySet, $platform);

        $parser = new VersionParser;
        $package1 = $this->createPackage('1.0.0');
        $package2 = $this->createPackage('2.0.0');
        $package2->setRequires(array('ext-barfoo' => new Link($packageName, 'ext-barfoo', $parser->parseConstraints('*'), Link::TYPE_REQUIRE, '*')));
        $packages = array($package1, $package2);

        $repositorySet->expects($this->any())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $best = $versionSelector->findBestCandidate($packageName);
        $this->assertSame($package1, $best, 'Latest version not requiring ext-barfoo should be returned (1.0.0)');
        $best = $versionSelector->findBestCandidate($packageName, null, 'stable', true);
        $this->assertSame($package2, $best, 'Latest version should be returned when ignoring platform reqs (2.0.0)');
    }

    public function testLatestVersionIsReturnedThatMatchesComposerRequirements()
    {
        $packageName = 'foobar';

        $platform = new PlatformRepository(array(), array('composer-runtime-api' => '1.0.0'));
        $repositorySet = $this->createMockRepositorySet();
        $versionSelector = new VersionSelector($repositorySet, $platform);

        $parser = new VersionParser;
        $package1 = $this->createPackage('1.0.0');
        $package1->setRequires(array('composer-runtime-api' => new Link($packageName, 'composer-runtime-api', $parser->parseConstraints('^1.0'), Link::TYPE_REQUIRE, '^1.0')));
        $package2 = $this->createPackage('1.1.0');
        $package2->setRequires(array('composer-runtime-api' => new Link($packageName, 'composer-runtime-api', $parser->parseConstraints('^2.0'), Link::TYPE_REQUIRE, '^2.0')));
        $packages = array($package1, $package2);

        $repositorySet->expects($this->any())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $best = $versionSelector->findBestCandidate($packageName);
        $this->assertSame($package1, $best, 'Latest version supporting composer 1 should be returned (1.0.0)');
        $best = $versionSelector->findBestCandidate($packageName, null, 'stable', true);
        $this->assertSame($package2, $best, 'Latest version should be returned when ignoring platform reqs (1.1.0)');
    }

    public function testMostStableVersionIsReturned()
    {
        $packageName = 'foobar';

        $package1 = $this->createPackage('1.0.0');
        $package2 = $this->createPackage('1.1.0-beta');
        $packages = array($package1, $package2);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName);

        $this->assertSame($package1, $best, 'Latest most stable version should be returned (1.0.0)');
    }

    public function testMostStableVersionIsReturnedRegardlessOfOrder()
    {
        $packageName = 'foobar';

        $package1 = $this->createPackage('2.x-dev');
        $package2 = $this->createPackage('2.0.0-beta3');
        $packages = array($package1, $package2);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->at(0))
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $repositorySet->expects($this->at(1))
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue(array_reverse($packages)));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName);
        $this->assertSame($package2, $best, 'Expecting 2.0.0-beta3, cause beta is more stable than dev');

        $best = $versionSelector->findBestCandidate($packageName);
        $this->assertSame($package2, $best, 'Expecting 2.0.0-beta3, cause beta is more stable than dev');
    }

    public function testHighestVersionIsReturned()
    {
        $packageName = 'foobar';

        $package1 = $this->createPackage('1.0.0');
        $package2 = $this->createPackage('1.1.0-beta');
        $packages = array($package1, $package2);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName, null, 'dev');

        $this->assertSame($package2, $best, 'Latest version should be returned (1.1.0-beta)');
    }

    public function testHighestVersionMatchingStabilityIsReturned()
    {
        $packageName = 'foobar';

        $package1 = $this->createPackage('1.0.0');
        $package2 = $this->createPackage('1.1.0-beta');
        $package3 = $this->createPackage('1.2.0-alpha');
        $packages = array($package1, $package2, $package3);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName, null, 'beta');

        $this->assertSame($package2, $best, 'Latest version should be returned (1.1.0-beta)');
    }

    public function testMostStableUnstableVersionIsReturned()
    {
        $packageName = 'foobar';

        $package2 = $this->createPackage('1.1.0-beta');
        $package3 = $this->createPackage('1.2.0-alpha');
        $packages = array($package2, $package3);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName, null, 'stable');

        $this->assertSame($package2, $best, 'Latest version should be returned (1.1.0-beta)');
    }

    public function testDefaultBranchAliasIsNeverReturned()
    {
        $packageName = 'foobar';

        $package = $this->createPackage('1.1.0-beta');
        $package2 = $this->createPackage('dev-main');
        $package2Alias = new AliasPackage($package2, VersionParser::DEFAULT_BRANCH_ALIAS, VersionParser::DEFAULT_BRANCH_ALIAS);
        $packages = array($package, $package2Alias);

        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->with($packageName, null)
            ->will($this->returnValue($packages));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate($packageName, null, 'dev');

        $this->assertSame($package2, $best, 'Latest version should be returned (dev-main)');
    }

    public function testFalseReturnedOnNoPackages()
    {
        $repositorySet = $this->createMockRepositorySet();
        $repositorySet->expects($this->once())
            ->method('findPackages')
            ->will($this->returnValue(array()));

        $versionSelector = new VersionSelector($repositorySet);
        $best = $versionSelector->findBestCandidate('foobaz');
        $this->assertFalse($best, 'No versions are available returns false');
    }

    /**
     * @dataProvider getRecommendedRequireVersionPackages
     */
    public function testFindRecommendedRequireVersion($prettyVersion, $expectedVersion, $branchAlias = null, $packageName = 'foo/bar')
    {
        $repositorySet = $this->createMockRepositorySet();
        $versionSelector = new VersionSelector($repositorySet);
        $versionParser = new VersionParser();

        $package = new Package($packageName, $versionParser->normalize($prettyVersion), $prettyVersion);

        if ($branchAlias) {
            $package->setExtra(array('branch-alias' => array($prettyVersion => $branchAlias)));
        }

        $recommended = $versionSelector->findRecommendedRequireVersion($package);

        // assert that the recommended version is what we expect
        $this->assertSame($expectedVersion, $recommended);
    }

    public function getRecommendedRequireVersionPackages()
    {
        return array(
            // real version, expected recommendation, [branch-alias], [pkg name]
            array('1.2.1', '^1.2'),
            array('1.2', '^1.2'),
            array('v1.2.1', '^1.2'),
            array('3.1.2-pl2', '^3.1'),
            array('3.1.2-patch', '^3.1'),
            array('2.0-beta.1', '^2.0@beta'),
            array('3.1.2-alpha5', '^3.1@alpha'),
            array('3.0-RC2', '^3.0@RC'),
            array('0.1.0', '^0.1.0'),
            array('0.1.3', '^0.1.3'),
            array('0.0.3', '^0.0.3'),
            array('0.0.3-alpha', '^0.0.3@alpha'),
            // date-based versions are not touched at all
            array('v20121020', 'v20121020'),
            array('v20121020.2', 'v20121020.2'),
            // dev packages without alias are not touched at all
            array('dev-master', 'dev-master'),
            array('3.1.2-dev', '3.1.2-dev'),
            // dev packages with alias inherit the alias
            array('dev-master', '^2.1@dev', '2.1.x-dev'),
            array('dev-master', '^2.1@dev', '2.1-dev'),
            array('dev-master', '^2.1@dev', '2.1.3.x-dev'),
            array('dev-master', '^2.0@dev', '2.x-dev'),
            array('dev-master', '^0.3.0@dev', '0.3.x-dev'),
            array('dev-master', '^0.0.3@dev', '0.0.3.x-dev'),
            array('dev-master', 'dev-master', VersionParser::DEFAULT_BRANCH_ALIAS),
            // numeric alias
            array('3.x-dev', '^3.0@dev', '3.0.x-dev'),
            array('3.x-dev', '^3.0@dev', '3.0-dev'),
            // ext in sync with php
            array(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,  '*', null, 'ext-filter'),
            // ext versioned individually
            array('3.0.5', '^3.0', null, 'ext-xdebug'),
        );
    }

    private function createPackage($version)
    {
        $parser = new VersionParser();

        return new Package('foo', $parser->normalize($version), $version);
    }

    private function createMockRepositorySet()
    {
        return $this->getMockBuilder('Composer\Repository\RepositorySet')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
