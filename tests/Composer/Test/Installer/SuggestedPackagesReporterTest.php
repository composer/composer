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

namespace Composer\Test\Installer;

use Composer\Installer\SuggestedPackagesReporter;

/**
 * @coversDefaultClass Composer\Installer\SuggestedPackagesReporter
 */
class SuggestedPackagesReporterTest extends \PHPUnit_Framework_TestCase
{
    private $io;
    private $suggestedPackagesReporter;

    protected function setUp()
    {
        $this->io = $this->getMock('Composer\IO\IOInterface');

        $this->suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
    }

    /**
     * @covers ::__construct
     */
    public function testContrsuctor()
    {
        $this->io->expects($this->once())
            ->method('writeError');

        $suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
        $suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $suggestedPackagesReporter->output();
    }

    /**
     * @covers ::getPackages
     */
    public function testGetPackagesEmptyByDefault()
    {
        $this->assertSame(
            array(),
            $this->suggestedPackagesReporter->getPackages()
        );
    }

    /**
     * @covers ::getPackages
     * @covers ::addPackage
     */
    public function testGetPackages()
    {
        $suggestedPackage = $this->getSuggestedPackageArray();
        $this->suggestedPackagesReporter->addPackage(
            $suggestedPackage['source'],
            $suggestedPackage['target'],
            $suggestedPackage['reason']
        );
        $this->assertSame(
            array($suggestedPackage),
            $this->suggestedPackagesReporter->getPackages()
        );
    }

    /**
     * Test addPackage appends packages.
     * Also test targets can be duplicated.
     *
     * @covers ::addPackage
     */
    public function testAddPackageAppends()
    {
        $suggestedPackageA = $this->getSuggestedPackageArray();
        $suggestedPackageB = $this->getSuggestedPackageArray();
        $suggestedPackageB['source'] = 'different source';
        $suggestedPackageB['reason'] = 'different reason';
        $this->suggestedPackagesReporter->addPackage(
            $suggestedPackageA['source'],
            $suggestedPackageA['target'],
            $suggestedPackageA['reason']
        );
        $this->suggestedPackagesReporter->addPackage(
            $suggestedPackageB['source'],
            $suggestedPackageB['target'],
            $suggestedPackageB['reason']
        );
        $this->assertSame(
            array($suggestedPackageA, $suggestedPackageB),
            $this->suggestedPackagesReporter->getPackages()
        );
    }

    /**
     * @covers ::addSuggestionsFromPackage
     */
    public function testAddSuggestionsFromPackage()
    {
        $package = $this->createPackageMock();
        $package->expects($this->once())
            ->method('getSuggests')
            ->will($this->returnValue(array(
                'target-a' => 'reason-a',
                'target-b' => 'reason-b',
            )));
        $package->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('package-pretty-name'));

        $this->suggestedPackagesReporter->addSuggestionsFromPackage($package);
        $this->assertSame(array(
            array(
                'source' => 'package-pretty-name',
                'target' => 'target-a',
                'reason' => 'reason-a',
            ),
            array(
                'source' => 'package-pretty-name',
                'target' => 'target-b',
                'reason' => 'reason-b',
            ),
        ), $this->suggestedPackagesReporter->getPackages());
    }

    /**
     * @covers ::output
     */
    public function testOutput()
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('a suggests installing b (c)');

        $this->suggestedPackagesReporter->output();
    }

    /**
     * @covers ::output
     */
    public function testOutputIgnoresFormatting()
    {
        $this->suggestedPackagesReporter->addPackage('source', 'target1', "\x1b[1;37;42m Like us\r\non Facebook \x1b[0m");
        $this->suggestedPackagesReporter->addPackage('source', 'target2', "<bg=green>Like us on Facebook</>");

        $this->io->expects($this->at(0))
            ->method('writeError')
            ->with("source suggests installing target1 ([1;37;42m Like us on Facebook [0m)");

        $this->io->expects($this->at(1))
            ->method('writeError')
            ->with('source suggests installing target2 (\\<bg=green>Like us on Facebook\\</>)');

        $this->suggestedPackagesReporter->output();
    }

    /**
     * @covers ::output
     */
    public function testOutputMultiplePackages()
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->addPackage('source package', 'target', 'because reasons');

        $this->io->expects($this->at(0))
            ->method('writeError')
            ->with('a suggests installing b (c)');

        $this->io->expects($this->at(1))
            ->method('writeError')
            ->with('source package suggests installing target (because reasons)');

        $this->suggestedPackagesReporter->output();
    }

    /**
     * @covers ::output
     */
    public function testOutputSkipInstalledPackages()
    {
        $repository = $this->getMock('Composer\Repository\RepositoryInterface');
        $package1 = $this->getMock('Composer\Package\PackageInterface');
        $package2 = $this->getMock('Composer\Package\PackageInterface');

        $package1->expects($this->once())
            ->method('getNames')
            ->will($this->returnValue(array('x', 'y')));

        $package2->expects($this->once())
            ->method('getNames')
            ->will($this->returnValue(array('b')));

        $repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue(array(
                $package1,
                $package2,
            )));

        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->addPackage('source package', 'target', 'because reasons');

        $this->io->expects($this->once())
            ->method('writeError')
            ->with('source package suggests installing target (because reasons)');

        $this->suggestedPackagesReporter->output($repository);
    }

    /**
     * @covers ::output
     */
    public function testOutputNotGettingInstalledPackagesWhenNoSuggestions()
    {
        $repository = $this->getMock('Composer\Repository\RepositoryInterface');
        $repository->expects($this->exactly(0))
            ->method('getPackages');

        $this->suggestedPackagesReporter->output($repository);
    }

    private function getSuggestedPackageArray()
    {
        return array(
            'source' => 'a',
            'target' => 'b',
            'reason' => 'c',
        );
    }

    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs(array(md5(mt_rand()), '1.0.0.0', '1.0.0'))
            ->getMock();
    }
}
