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

namespace Composer\Test\Repository;

use Composer\Downloader\TransportException;
use Composer\Package\CompletePackage;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\RepositorySet;
use Composer\Test\TestCase;

class RepositorySetTest extends TestCase
{
    public function testPreloadPackagesDoesNotQueryLowerPriorityRepositoriesForFoundNames(): void
    {
        $first = new ArrayRepository();
        $first->addPackage(self::getPackage('acme/private', '1.0.0'));

        $second = $this->getMockBuilder(ArrayRepository::class)->onlyMethods(['loadPackages'])->getMock();
        $second->expects(self::once())
            ->method('loadPackages')
            ->with(['foo/bar' => null], self::anything(), self::anything())
            ->willReturn(['namesFound' => [], 'packages' => []]);

        $third = new ArrayRepository();
        $third->addPackage(self::getPackage('foo/bar', '1.0.0'));

        $fourth = $this->getMockBuilder(ArrayRepository::class)->onlyMethods(['loadPackages'])->getMock();
        $fourth->expects(self::never())->method('loadPackages');

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository(new CompositeRepository([$first, $second, $third, $fourth]));

        $repositorySet->preloadPackages(['acme/private', 'foo/bar']);
    }

    public function testLoadCurrentPackagesOnlyUsesComposerRepositoriesWithTheSameOrigin(): void
    {
        $abandoned = self::createPackage('foo/abandoned', '1.0.0');
        $unabandoned = self::createPackage('foo/unabandoned', '1.0.0', true);
        $forked = self::createPackage('foo/forked', '1.0.0');
        $shadowed = self::createPackage('foo/shadowed', '1.0.0');
        $unknown = self::createPackage('foo/unknown', '1.0.0');

        $vcsRepo = $this->getMockBuilder(ArrayRepository::class)->onlyMethods(['loadPackages'])->getMock();
        $vcsRepo->expects(self::never())->method('loadPackages');

        $currentShadowed = self::createPackage('foo/shadowed', '1.0.0');
        $currentAbandoned = self::createPackage('foo/abandoned', '1.0.0', 'foo/replacement');
        $currentUnabandoned = self::createPackage('foo/unabandoned', '1.0.0');
        $forkUpstream = self::createPackage('foo/forked', '1.0.0', true);
        $forkUpstream->setSourceUrl('https://example.org/upstream.git');

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository($vcsRepo);
        $repositorySet->addRepository($this->getComposerRepositoryMock([$currentShadowed]));
        $repositorySet->addRepository($this->getComposerRepositoryMock([
            $currentAbandoned,
            // other versions must not replace the audited version
            self::createPackage('foo/abandoned', '2.0.0'),
            $currentUnabandoned,
            $forkUpstream,
            self::createPackage('foo/shadowed', '1.0.0', true),
        ]));

        self::assertSame(
            [$currentAbandoned, $currentUnabandoned, $forked, $currentShadowed, $unknown],
            $repositorySet->loadCurrentPackages([$abandoned, $unabandoned, $forked, $shadowed, $unknown])
        );
    }

    public function testLoadCurrentPackagesSkipsUnreachableRepositories(): void
    {
        $unreachable = $this->getMockBuilder(ComposerRepository::class)->disableOriginalConstructor()->onlyMethods(['loadPackages'])->getMock();
        $unreachable->method('loadPackages')->willThrowException(new TransportException('The "https://example.org/p2/foo/bar.json" file could not be downloaded'));
        $current = self::createPackage('foo/bar', '1.0.0', true);

        $repositorySet = new RepositorySet();
        $repositorySet->addRepository($unreachable);
        $repositorySet->addRepository($this->getComposerRepositoryMock([$current]));

        self::assertSame([$current], $repositorySet->loadCurrentPackages([self::createPackage('foo/bar', '1.0.0')]));
    }

    /**
     * @param bool|string $abandoned
     */
    private static function createPackage(string $name, string $version, $abandoned = false): CompletePackage
    {
        $package = new CompletePackage($name, self::getVersionParser()->normalize($version), $version);
        $package->setSourceUrl('https://example.org/'.$name.'.git');
        $package->setAbandoned($abandoned);

        return $package;
    }

    /**
     * @param CompletePackage[] $packages
     */
    private function getComposerRepositoryMock(array $packages): ComposerRepository
    {
        $repo = $this->getMockBuilder(ComposerRepository::class)->disableOriginalConstructor()->onlyMethods(['loadPackages'])->getMock();
        $repo->method('loadPackages')->willReturnCallback(static function (array $packageNameMap) use ($packages): array {
            $packages = array_values(array_filter($packages, static function (CompletePackage $package) use ($packageNameMap): bool {
                return isset($packageNameMap[$package->getName()]);
            }));

            return [
                'namesFound' => array_values(array_unique(array_map(static function (CompletePackage $package): string {
                    return $package->getName();
                }, $packages))),
                'packages' => $packages,
            ];
        });

        return $repo;
    }
}
