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

use Composer\Advisory\Auditor;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\SecurityAdvisoryPoolFilter;
use Composer\Downloader\TransportException;
use Composer\IO\BufferIO;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Package\Package;
use Composer\Policy\AbandonedPolicyConfig;
use Composer\Policy\AdvisoriesPolicyConfig;
use Composer\Policy\IgnoreIdRule;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\IgnoreUnreachable;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\MalwarePolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Repository\PackageRepository;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;

class SecurityAdvisoryPoolFilterTest extends TestCase
{
    /**
     * @param array<string, list<IgnorePackageRule>> $advisoriesIgnore
     * @param array<string, list<IgnorePackageRule>> $abandonedIgnore
     */
    private static function policyConfig(
        bool $advisoriesBlock = true,
        bool $abandonedBlock = true,
        array $advisoriesIgnore = [],
        array $abandonedIgnore = []
    ): PolicyConfig {
        return new PolicyConfig(
            true,
            new AdvisoriesPolicyConfig($advisoriesBlock, ListPolicyConfig::AUDIT_FAIL, $advisoriesIgnore, [], []),
            MalwarePolicyConfig::disabled(),
            new AbandonedPolicyConfig($abandonedBlock, ListPolicyConfig::AUDIT_FAIL, $abandonedIgnore),
            [],
            IgnoreUnreachable::default()
        );
    }

    public function testFilterPackagesByAdvisories(): void
    {
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());

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
        $policyConfig = new PolicyConfig(
            true,
            new AdvisoriesPolicyConfig(
                true,
                ListPolicyConfig::AUDIT_FAIL,
                [],
                ['CVE-2024-1234' => new IgnoreIdRule('CVE-2024-1234')],
                []
            ),
            MalwarePolicyConfig::disabled(),
            new AbandonedPolicyConfig(true, ListPolicyConfig::AUDIT_FAIL, []),
            [],
            IgnoreUnreachable::default()
        );
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $policyConfig, new NullIO());

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
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(false), new NullIO());

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
        $policyConfig = self::policyConfig(
            true,
            true,
            [],
            [$packageNameIgnoreAbandoned => [new IgnorePackageRule($packageNameIgnoreAbandoned, new MatchAllConstraint())]]
        );
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $policyConfig, new NullIO());

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

    public function testWarnsWhenUnreachableRepositoriesAreIgnored(): void
    {
        $unreachable = new class([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [
                    [
                        'advisoryId' => 'PKSA-test',
                        'packageName' => 'acme/package',
                        'remoteId' => 'r',
                        'title' => 'Security Advisory',
                        'link' => null,
                        'cve' => 'CVE-2024-9999',
                        'affectedVersions' => '>=1.0.0,<2.0.0',
                        'source' => 'Tests',
                        'reportedAt' => '2024-04-31 12:37:47',
                        'composerRepository' => 'Unreachable Repo',
                        'severity' => 'high',
                        'sources' => [['name' => 'Test', 'remoteId' => 'r']],
                    ],
                ],
            ],
        ]) extends PackageRepository {
            public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
            {
                throw new TransportException('The "https://example.org/security.json" file could not be downloaded: HTTP/1.1 502 Bad Gateway', 502);
            }

            public function getRepoName(): string
            {
                return 'unreachable advisory repo';
            }
        };

        // ignore-unreachable defaults to ["update", "install"], so the transport error is swallowed.
        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), $io = new BufferIO());
        $filter->filter(new Pool([new Package('acme/package', '1.0.0.0', '1.0')]), [$unreachable], new Request());

        $output = $io->getOutput();
        self::assertStringContainsString('Security advisory data could not be fetched from some repositories', $output);
        self::assertStringContainsString('HTTP/1.1 502 Bad Gateway', $output);
    }

    public function testRethrowsTransportErrorWhenUnreachableIsNotIgnored(): void
    {
        $unreachable = new class([
            'package' => [],
            'security-advisories' => ['acme/package' => []],
        ]) extends PackageRepository {
            public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
            {
                throw new TransportException('boom', 500);
            }

            public function getRepoName(): string
            {
                return 'unreachable advisory repo';
            }
        };

        $policyConfig = new PolicyConfig(
            true,
            new AdvisoriesPolicyConfig(true, ListPolicyConfig::AUDIT_FAIL, [], [], []),
            MalwarePolicyConfig::disabled(),
            new AbandonedPolicyConfig(true, ListPolicyConfig::AUDIT_FAIL, []),
            [],
            IgnoreUnreachable::none()
        );

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $policyConfig, new BufferIO());

        $this->expectException(TransportException::class);
        $filter->filter(new Pool([new Package('acme/package', '1.0.0.0', '1.0')]), [$unreachable], new Request());
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
