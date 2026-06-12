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

namespace Composer\Test\FilterList\FilterListProvider;

use Composer\Downloader\TransportException;
use Composer\FilterList\FilterListProvider\FilterListProviderSet;
use Composer\Package\Package;
use Composer\Repository\ArrayRepository;
use Composer\Repository\FilterListProviderInterface;
use Composer\Repository\PackageRepository;
use Composer\Test\TestCase;

class FilterListProviderSetTest extends TestCase
{
    public function testGetMatchingFilterListsOnlyReturnsConfiguredLists(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'filter' => [
                'malware' => [
                    [
                        'package' => 'acme/package',
                        'constraint' => '1.0',
                        'reason' => 'malware',
                    ],
                ],
                'typosquatting' => [
                    [
                        'package' => 'acme/package',
                        'constraint' => '1.0',
                        'reason' => 'typosquatting',
                    ],
                ],
            ],
        ]);

        $set = new FilterListProviderSet([$repository], []);

        $result = $set->getMatchingFilterLists(
            [new Package('acme/package', '1.0.0.0', '1.0')],
            ['malware']
        );

        $this->assertArrayHasKey('malware', $result['filter']);
        $this->assertArrayNotHasKey('typosquatting', $result['filter']);
        $this->assertCount(1, $result['filter']['malware']);
        $this->assertSame('malware', $result['filter']['malware'][0]->reason);
    }

    public function testUnreachableRepositoryDuringConstructionIsReportedWhenIgnored(): void
    {
        $repository = $this->createUnreachableFilterListProvider('repo.example.com could not be reached');

        $set = new FilterListProviderSet([$repository], []);

        $result = $set->getMatchingFilterLists(
            [new Package('acme/package', '1.0.0.0', '1.0')],
            ['malware'],
            true
        );

        $this->assertSame([], $result['filter']);
        $this->assertSame(['repo.example.com could not be reached'], $result['unreachableRepos']);
    }

    public function testUnreachableRepositoryDuringConstructionIsThrownWhenNotIgnored(): void
    {
        $repository = $this->createUnreachableFilterListProvider('repo.example.com could not be reached');

        $set = new FilterListProviderSet([$repository], []);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('repo.example.com could not be reached');

        $set->getMatchingFilterLists(
            [new Package('acme/package', '1.0.0.0', '1.0')],
            ['malware'],
            false
        );
    }

    private function createUnreachableFilterListProvider(string $message): ArrayRepository
    {
        return new class($message) extends ArrayRepository implements FilterListProviderInterface {
            /** @var string */
            private $message;

            public function __construct(string $message)
            {
                parent::__construct();
                $this->message = $message;
            }

            public function hasFilter(): bool
            {
                throw new TransportException($this->message);
            }

            public function getFilter(array $packageConstraintMap, array $configuredLists): array
            {
                return ['filter' => []];
            }

            public function getFilterLists(): array
            {
                return [];
            }
        };
    }
}
