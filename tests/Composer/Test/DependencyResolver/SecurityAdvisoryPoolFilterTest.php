<?php declare(strict_types=1);

namespace Composer\Test\DependencyResolver;

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\SecurityAdvisoryPoolFilter;
use Composer\Package\Package;
use Composer\Repository\PackageRepository;
use Composer\Test\TestCase;

class SecurityAdvisoryPoolFilterTest extends TestCase
{
    public function testFilterPackagesByAdvisories(): void
    {
        $auditConfig = new AuditConfig([], Auditor::ABANDONED_FAIL);
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $auditConfig);

        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => $this->generateSecurityAdvisory('acme/package', null, '>=1.0.0,<1.1.0'),
            ],
        ]);
        $pool = new Pool([
            new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage1 = new Package('acme/package', '2.0.0.0', '2.0'),
            $expectedPackage2 = new Package('acme/other', '1.0.0.0', '1.0'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository]);

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
    }

    public function testDontFilterPackagesByIgnoredAdvisories(): void
    {
        $auditConfig = new AuditConfig(['CVE-2024-1234'], Auditor::ABANDONED_FAIL);
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $auditConfig);

        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => $this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '>=1.0.0,<1.1.0'),
            ],
        ]);
        $pool = new Pool([
            $expectedPackage1 = new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage2 = new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository]);

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
    }

    private function generateSecurityAdvisory(string $packageName, ?string $cve, string $affectedVersions): array
    {
        return [
            [
                'advisoryId' => uniqid('PKSA-'),
                'packageName' => $packageName,
                'remoteId' => 'test',
                'title' => 'Security Advisory',
                'link' => null,
                'cve' => $cve,
                'affectedVersions' => $affectedVersions,
                'source' => 'Tests',
                'reportedAt' => '2024-04-31 12:37:47',
                'composerRepository' => 'Package Repository',
                'severity' => 'high',
                'sources' => [
                    [
                        'name' => 'Security Advisory',
                        'remoteId' => 'test'
                    ]
                ]
            ]
        ];
    }
}
