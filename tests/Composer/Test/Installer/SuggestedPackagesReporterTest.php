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

namespace Composer\Test\Installer;

use Composer\InstalledVersions;
use Composer\Installer\SuggestedPackagesReporter;
use Composer\Semver\VersionParser;
use Composer\Test\Mock\IOMock;
use Composer\Test\TestCase;

/**
 * @coversDefaultClass Composer\Installer\SuggestedPackagesReporter
 */
class SuggestedPackagesReporterTest extends TestCase
{
    /**
     * @var IOMock
     */
    private $io;

    /**
     * @var \Composer\Installer\SuggestedPackagesReporter
     */
    private $suggestedPackagesReporter;

    protected function setUp(): void
    {
        $this->io = $this->getIOMock();

        $this->suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
    }

    /**
     * @covers ::__construct
     */
    public function testConstructor(): void
    {
        $this->io->expects([['text' => 'b']], true);

        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_LIST);
    }

    /**
     * @covers ::getPackages
     */
    public function testGetPackagesEmptyByDefault(): void
    {
        self::assertEmpty($this->suggestedPackagesReporter->getPackages());
    }

    /**
     * @covers ::getPackages
     * @covers ::addPackage
     */
    public function testGetPackages(): void
    {
        $suggestedPackage = $this->getSuggestedPackageArray();
        $this->suggestedPackagesReporter->addPackage(
            $suggestedPackage['source'],
            $suggestedPackage['target'],
            $suggestedPackage['reason']
        );
        self::assertSame(
            [$suggestedPackage],
            $this->suggestedPackagesReporter->getPackages()
        );
    }

    /**
     * Test addPackage appends packages.
     * Also test targets can be duplicated.
     *
     * @covers ::addPackage
     */
    public function testAddPackageAppends(): void
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
        self::assertSame(
            [$suggestedPackageA, $suggestedPackageB],
            $this->suggestedPackagesReporter->getPackages()
        );
    }

    /**
     * @covers ::addSuggestionsFromPackage
     */
    public function testAddSuggestionsFromPackage(): void
    {
        $package = $this->createPackageMock();
        $package->expects($this->once())
            ->method('getSuggests')
            ->will($this->returnValue([
                'target-a' => 'reason-a',
                'target-b' => 'reason-b',
            ]));
        $package->expects($this->once())
            ->method('getPrettyName')
            ->will($this->returnValue('package-pretty-name'));

        $this->suggestedPackagesReporter->addSuggestionsFromPackage($package);
        self::assertSame([
            [
                'source' => 'package-pretty-name',
                'target' => 'target-a',
                'reason' => 'reason-a',
            ],
            [
                'source' => 'package-pretty-name',
                'target' => 'target-b',
                'reason' => 'reason-b',
            ],
        ], $this->suggestedPackagesReporter->getPackages());
    }

    /**
     * @covers ::output
     */
    public function testOutput(): void
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');

        $this->io->expects([
            ['text' => 'a suggests:'],
            ['text' => ' - b: c'],
            ['text' => ''],
        ], true);

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputWithNoSuggestionReason(): void
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', '');

        $this->io->expects([
            ['text' => 'a suggests:'],
            ['text' => ' - b'],
            ['text' => ''],
        ], true);

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputIgnoresFormatting(): void
    {
        $this->suggestedPackagesReporter->addPackage('source', 'target1', "\x1b[1;37;42m Like us\r\non Facebook \x1b[0m");
        $this->suggestedPackagesReporter->addPackage('source', 'target2', "<bg=green>Like us on Facebook</>");

        $this->io->expects([
            ['text' => 'source suggests:'],
            ['text' => ' - target1: [1;37;42m Like us on Facebook [0m'],
            ['text' => ' - target2: <bg=green>Like us on Facebook</>'],
            ['text' => ''],
        ], true);

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputMultiplePackages(): void
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->addPackage('source package', 'target', 'because reasons');

        $this->io->expects([
            ['text' => 'a suggests:'],
            ['text' => ' - b: c'],
            ['text' => ''],
            ['text' => 'source package suggests:'],
            ['text' => ' - target: because reasons'],
            ['text' => ''],
        ], true);

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputSkipInstalledPackages(): void
    {
        $repository = $this->getMockBuilder('Composer\Repository\InstalledRepository')->disableOriginalConstructor()->getMock();
        $package1 = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();
        $package2 = $this->getMockBuilder('Composer\Package\PackageInterface')->getMock();

        $package1->expects($this->once())
            ->method('getNames')
            ->will($this->returnValue(['x', 'y']));

        $package2->expects($this->once())
            ->method('getNames')
            ->will($this->returnValue(['b']));

        $repository->expects($this->once())
            ->method('getPackages')
            ->will($this->returnValue([
                $package1,
                $package2,
            ]));

        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->addPackage('source package', 'target', 'because reasons');

        $this->io->expects([
            ['text' => 'source package suggests:'],
            ['text' => ' - target: because reasons'],
            ['text' => ''],
        ], true);

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE, $repository);
    }

    /**
     * @covers ::output
     */
    public function testOutputNotGettingInstalledPackagesWhenNoSuggestions(): void
    {
        $repository = $this->getMockBuilder('Composer\Repository\InstalledRepository')->disableOriginalConstructor()->getMock();
        $repository->expects($this->exactly(0))
            ->method('getPackages');

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE, $repository);
    }

    /**
     * @return array<string, string>
     */
    private function getSuggestedPackageArray(): array
    {
        return [
            'source' => 'a',
            'target' => 'b',
            'reason' => 'c',
        ];
    }

    /**
     * @return \Composer\Package\PackageInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createPackageMock()
    {
        return $this->getMockBuilder('Composer\Package\Package')
            ->setConstructorArgs([md5((string) mt_rand()), '1.0.0.0', '1.0.0'])
            ->getMock();
    }
}
