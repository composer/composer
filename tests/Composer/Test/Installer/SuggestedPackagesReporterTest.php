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
use Composer\Test\TestCase;

/**
 * @coversDefaultClass Composer\Installer\SuggestedPackagesReporter
 */
class SuggestedPackagesReporterTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $io;

    /**
     * @var \Composer\Installer\SuggestedPackagesReporter
     */
    private $suggestedPackagesReporter;

    protected function setUp(): void
    {
        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->getMock();

        $this->suggestedPackagesReporter = new SuggestedPackagesReporter($this->io);
    }

    /**
     * @covers ::__construct
     */
    public function testConstructor(): void
    {
        $this->io->expects($this->once())
            ->method('write');

        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_LIST);
    }

    /**
     * @covers ::getPackages
     */
    public function testGetPackagesEmptyByDefault(): void
    {
        $this->assertEmpty($this->suggestedPackagesReporter->getPackages());
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
        $this->assertSame(
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
        $this->assertSame(
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
        $this->assertSame([
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

        $this->io->expects($this->exactly(3))
            ->method('write')
            ->withConsecutive(
                ['<comment>a</comment> suggests:'],
                [' - <info>b</info>: c'],
                ['']
            );

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputWithNoSuggestionReason(): void
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', '');

        $this->io->expects($this->exactly(3))
            ->method('write')
            ->withConsecutive(
                ['<comment>a</comment> suggests:'],
                [' - <info>b</info>'],
                ['']
            );

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputIgnoresFormatting(): void
    {
        $this->suggestedPackagesReporter->addPackage('source', 'target1', "\x1b[1;37;42m Like us\r\non Facebook \x1b[0m");
        $this->suggestedPackagesReporter->addPackage('source', 'target2', "<bg=green>Like us on Facebook</>");

        $expectedWrite = InstalledVersions::satisfies(new VersionParser(), 'symfony/console', '^4.4.37 || ~5.3.14 || ^5.4.3 || ^6.0.3')
            ? ' - <info>target2</info>: \\<bg=green\\>Like us on Facebook\\</\\>'
            : ' - <info>target2</info>: \\<bg=green>Like us on Facebook\\</>';

        $this->io->expects($this->exactly(4))
            ->method('write')
            ->withConsecutive(
                ['<comment>source</comment> suggests:'],
                [' - <info>target1</info>: [1;37;42m Like us on Facebook [0m'],
                [$expectedWrite],
                ['']
            );

        $this->suggestedPackagesReporter->output(SuggestedPackagesReporter::MODE_BY_PACKAGE);
    }

    /**
     * @covers ::output
     */
    public function testOutputMultiplePackages(): void
    {
        $this->suggestedPackagesReporter->addPackage('a', 'b', 'c');
        $this->suggestedPackagesReporter->addPackage('source package', 'target', 'because reasons');

        $this->io->expects($this->exactly(6))
            ->method('write')
            ->withConsecutive(
                ['<comment>a</comment> suggests:'],
                [' - <info>b</info>: c'],
                [''],
                ['<comment>source package</comment> suggests:'],
                [' - <info>target</info>: because reasons'],
                ['']
            );

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

        $this->io->expects($this->exactly(3))
            ->method('write')
            ->withConsecutive(
                ['<comment>source package</comment> suggests:'],
                [' - <info>target</info>: because reasons'],
                ['']
            );

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
