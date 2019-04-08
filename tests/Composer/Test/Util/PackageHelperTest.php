<?php

namespace Composer\Test\Util;

use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;
use Composer\Util\PackageHelper;

class PackageHelperTest extends TestCase
{
    public function testFindSimilar()
    {
        $repository = new ArrayRepository();
        $repository->addPackage($this->getPackage('php-cs-fixer/diff', 1));
        $repository->addPackage($this->getPackage('fabpot/php-cs-fixer', 1));
        $repository->addPackage($this->getPackage('friendsofphp/php-cs-fixer', 1));
        $repository->addPackage($this->getPackage('vendor/foobar', 1));

        $helper = new PackageHelper($repository);
        $similar = $helper->findSimilar('php-cs-fixer');

        $this->assertInternalType('array', $similar);
        $this->assertCount(3, $similar);

        $similar = $helper->findSimilar('php-cs-fixer', 2);

        $this->assertInternalType('array', $similar);
        $this->assertCount(2, $similar);
    }

    /**
     * @dataProvider findBestVersionScenarios
     */
    public function testFindBestVersionAndNameForPackage(
        RepositoryInterface $repository,
        $name,
        $preferredStability,
        $minimumStability,
        $phpVersion,
        $constraint,
        $ignorePlatformRequirements,
        array $expected
    ) {
        $helper = new PackageHelper($repository);
        $package = $helper->findBestVersionAndNameForPackage($name, $preferredStability, $minimumStability, $phpVersion, $constraint, $ignorePlatformRequirements);

        $this->assertEquals($expected, $package);
    }

    /**
     * @return array
     */
    public function findBestVersionScenarios()
    {
        $repository = $this->createRepository();

        return array(
            'given php version 5.6.0, only version 1.0.0 should match, so we expect a constraint for ^1.0' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '5.6.0',
                null,
                false,
                array('vendor/foobar', '^1.0')
            ),
            'given php version 7.0.0, 2.1.0 is the highest matching version, so we expect a constraint for ^2.1' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '7.0.0',
                null,
                false,
                array('vendor/foobar', '^2.1')
            ),
            'given --ignore-platform-reqs, 2.1.0 is the highest matching version, so we expect a constraint for ^2.1' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                null,
                null,
                false,
                array('vendor/foobar', '^2.1')
            ),
            'given php version 7.1.0, minimum stability of alpha, 3.0-alpha is the highest matching version, so we expect a constraint for ^3.0@alpha' => array(
                $repository,
                'vendor/foobar',
                'alpha',
                'alpha',
                '7.1.0',
                null,
                false,
                array('vendor/foobar', '^3.0@alpha')
            ),
            'given php version 7.2.0, minimum stability of alpha, 3.0-beta is the highest matching version, so we expect a constraint for ^3.0@beta' => array(
                $repository,
                'vendor/foobar',
                'alpha',
                'alpha',
                '7.2.0',
                null,
                false,
                array('vendor/foobar', '^3.0@beta')
            ),
            'given php version 7.2.0, minimum stability of dev, dev-master is the highest matching version, and since it has a branch alias we expect a constraint for the alias' => array(
                $repository,
                'vendor/foobar',
                'dev',
                'dev',
                '7.2.0',
                null,
                false,
                array('vendor/foobar', '^3.0@dev')
            ),
            'given php version 7.2.0, minimum stability of stable, with stability override in constraint, 3.0-beta is the highest matching version, so we expect a constraint for ^3.0@beta' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '7.2.0',
                '^3.0@alpha',
                false,
                array('vendor/foobar', '^3.0@beta')
            ),
            'given a platform package that is not present but platform requirements have been ignored, we simply continue' => array(
                $repository,
                'ext-json',
                'stable',
                'stable',
                '7.2.0',
                null,
                true,
                array('ext-json', '*')
            )
        );
    }

    /**
     * @dataProvider findBestVersionExceptions
     */
    public function testFindBestVersionAndNameForPackageExceptions(
        RepositoryInterface $repository,
        $name,
        $preferredStability,
        $minimumStability,
        $phpVersion,
        $constraint,
        $ignorePlatformRequirements,
        $exception
    ) {
        $this->setExpectedException($exception);

        $helper = new PackageHelper($repository);
        $helper->findBestVersionAndNameForPackage($name, $preferredStability, $minimumStability, $phpVersion, $constraint, $ignorePlatformRequirements);
    }

    /**
     * @return array
     */
    public function findBestVersionExceptions()
    {
        $repository = $this->createRepository();

        return array(
            'given php version 5.5.0 and package constraint ^1.0, we cannot find a package version for this constraint and php version combination' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '5.5.0',
                '^1.0',
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchForConstraintWithPhpVersionException'
            ),
            'given php version 5.6.0 and package constraint ^2.0, we cannot find a package version for this constraint and php version combination' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '5.6.0',
                '^2.0',
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchForConstraintWithPhpVersionException'
            ),
            'given php version 7.0.0 and package constraint ^3.0, we cannot find a package version for this constraint' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '7.0.0',
                '^3.0',
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchForConstraintException'
            ),
            'given php version 5.5.0 and no package constraint, we cannot find a package version for this php version' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '5.5.0',
                null,
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchForPhpVersionException'
            ),
            'given php version 7.0.0 and package constraint ^2.0 (but incomplete package name), we cannot find a matching package, but we can provide suggestion(s)' => array(
                $repository,
                'foobar',
                'stable',
                'stable',
                '7.0.0',
                '^2.0',
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchWithSuggestionsException'
            ),
            'given php version 5.6.0 and package constraint ^1.0 (but the wrong package name), we cannot find any matching package' => array(
                $repository,
                'vendor/fuubar',
                'stable',
                'stable',
                '5.6.0',
                '^1.0',
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchException'
            ),

            // cannot get these working
            'given php version 7.2.0 and package constraint ^3.0, we cannot find a matching package due to minimum stability' => array(
                $repository,
                'vendor/foobar',
                'stable',
                'stable',
                '7.2.0',
                '^3.0',
                false,
                'Composer\Util\Exception\PackageHelper\NoMatchForMinimumStabilityException'
            ),
        );
    }

    /**
     * @return ArrayRepository
     */
    private function createRepository()
    {
        return new ArrayRepository($this->createPackages());
    }

    /**
     * @return array[Package]
     */
    private function createPackages()
    {
        $v1_0 = $this->getPackage('vendor/foobar', '1.0');
        /** @var Package $v1_0 */
        $v1_0->setRequires(array('php' => new Link('vendor/foobar', 'php', new Constraint('>=', '5.6'), 'requires', '>=5.6')));

        $v2_0 = $this->getPackage('vendor/foobar', '2.0');
        /** @var Package $v2_0 */
        $v2_0->setRequires(array('php' => new Link('vendor/foobar', 'php', new Constraint('>=', '7.0'), 'requires', '>=7.0')));

        $v2_1 = $this->getPackage('vendor/foobar', '2.1');
        /** @var Package $v2_1 */
        $v2_1->setRequires(array('php' => new Link('vendor/foobar', 'php', new Constraint('>=', '7.0'), 'requires', '>=7.0')));

        $v3_0_alpha = $this->getPackage('vendor/foobar', '3.0-alpha');
        /** @var Package $v3_0_alpha */
        $v3_0_alpha->setRequires(array('php' => new Link('vendor/foobar', 'php', new Constraint('>=', '7.1'), 'requires', '>=7.1')));

        $v3_0_beta = $this->getPackage('vendor/foobar', '3.0-beta');
        /** @var Package $v3_0_beta */
        $v3_0_beta->setRequires(array('php' => new Link('vendor/foobar', 'php', new Constraint('>=', '7.2'), 'requires', '>=7.2')));

        $devmaster = $this->getPackage('vendor/foobar', 'dev-master');
        /** @var Package $devmaster */
        $devmaster->setRequires(array('php' => new Link('vendor/foobar', 'php', new Constraint('>=', '7.2'), 'requires', '>=7.2')));
        $devmaster->setExtra(array('branch-alias' => array('dev-master' => '3.x-dev')));

        return array($v1_0, $v2_0, $v2_1, $v3_0_alpha, $v3_0_beta, $devmaster);
    }
}
