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

use Composer\Advisory\AuditConfig;
use Composer\Advisory\Auditor;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\SecurityAdvisoryPoolFilter;
use Composer\Package\CompletePackage;
use Composer\Package\Package;
use Composer\Repository\PackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class SecurityAdvisoryPoolFilterTest extends TestCase
{
    public function testFilterPackagesByAdvisories(): void
    {
        $auditConfig = new AuditConfig(true, Auditor::FORMAT_SUMMARY, Auditor::ABANDONED_FAIL, true, true, false, [], [], [], [], [], []);
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $auditConfig);

        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [
                    $advisory1 = $this->generateSecurityAdvisory('acme/package', 'CVE-1999-1000', '>=1.0.0,<1.1.0'),
                    $advisory2 = $this->generateSecurityAdvisory('acme/package', 'CVE-1999-1001', '>=1.0.0,<1.1.0'),
                ],
            ],
        ]);
        $pool = new Pool([
            new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage1 = new Package('acme/package', '2.0.0.0', '2.0'),
            $expectedPackage2 = new Package('acme/other', '1.0.0.0', '1.0'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertTrue($filteredPool->isSecurityRemovedPackageVersion('acme/package', new Constraint('==', '1.0.0.0')));
        $this->assertCount(0, $filteredPool->getAllAbandonedRemovedPackageVersions());

        $advisoryMap = $filteredPool->getAllSecurityRemovedPackageVersions();
        $this->assertArrayHasKey('acme/package', $advisoryMap);
        $this->assertArrayHasKey('1.0.0.0', $advisoryMap['acme/package']);
        $this->assertSame([$advisory1['advisoryId'], $advisory2['advisoryId']], $filteredPool->getSecurityAdvisoryIdentifiersForPackageVersion('acme/package', new Constraint('==', '1.0.0.0')));
    }

    public function testDontFilterPackagesByIgnoredAdvisories(): void
    {
        $auditConfig = new AuditConfig(true, Auditor::FORMAT_SUMMARY, Auditor::ABANDONED_FAIL, true, true, false, ['CVE-2024-1234'], ['CVE-2024-1234'], [], [], [], []);
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $auditConfig);

        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '>=1.0.0,<1.1.0')],
            ],
        ]);
        $pool = new Pool([
            $expectedPackage1 = new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage2 = new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertCount(0, $filteredPool->getAllAbandonedRemovedPackageVersions());
        $this->assertCount(0, $filteredPool->getAllSecurityRemovedPackageVersions());
    }

    public function testDontFilterPackagesWithBlockInsecureDisabled(): void
    {
        $auditConfig = new AuditConfig(true, Auditor::FORMAT_SUMMARY, Auditor::ABANDONED_FAIL, false, true, false, [], [], [], [], [], []);
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $auditConfig);

        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '>=1.0.0,<1.1.0')],
            ],
        ]);
        $pool = new Pool([
            $expectedPackage1 = new Package('acme/package', '1.0.0.0', '1.0'),
            $expectedPackage2 = new Package('acme/package', '1.1.0.0', '1.1'),
        ]);
        $filteredPool = $filter->filter($pool, [$repository], new Request());

        $this->assertSame([$expectedPackage1, $expectedPackage2], $filteredPool->getPackages());
        $this->assertCount(0, $filteredPool->getAllAbandonedRemovedPackageVersions());
        $this->assertCount(0, $filteredPool->getAllSecurityRemovedPackageVersions());
    }

    public function testDontFilterPackagesWithAbandonedPackage(): void
    {
        $packageNameIgnoreAbandoned = 'acme/ignore-abandoned';
        $auditConfig = new AuditConfig(true, Auditor::FORMAT_SUMMARY, Auditor::ABANDONED_FAIL, true, true, false, [], [], [], [], [$packageNameIgnoreAbandoned], [$packageNameIgnoreAbandoned]);
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $auditConfig);

        $abandonedPackage = new CompletePackage('acme/package', '1.0.0.0', '1.0');
        $abandonedPackage->setAbandoned(true);
        $ignoreAbandonedPackage = new CompletePackage($packageNameIgnoreAbandoned, '1.0.0.0', '1.0');
        $ignoreAbandonedPackage->setAbandoned(true);
        $expectedPackage = new Package('acme/other', '1.1.0.0', '1.1');

        $pool = new Pool([
            $expectedPackage,
            $abandonedPackage,
            $ignoreAbandonedPackage,
        ]);
        $filteredPool = $filter->filter($pool, [], new Request());

        $this->assertSame([$expectedPackage, $ignoreAbandonedPackage], $filteredPool->getPackages());
        $this->assertCount(1, $filteredPool->getAllAbandonedRemovedPackageVersions());
        $this->assertCount(0, $filteredPool->getAllSecurityRemovedPackageVersions());
    }

    /**
     * @return array<string, mixed>
     */
    private function generateSecurityAdvisory(string $packageName, ?string $cve, string $affectedVersions): array
    {
        return [
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
                    'remoteId' => 'test',
                ],
            ],
        ];
    }
}
