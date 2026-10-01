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
use Composer\DependencyResolver\PolicyRemovalReason;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\DependencyResolver\SecurityAdvisoryPoolFilter;
use Composer\Downloader\TransportException;
use Composer\IO\BufferIO;
use Composer\IO\NullIO;
use Composer\Package\CompleteAliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Policy\AbandonedPolicyConfig;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Policy\AdvisoriesPolicyConfig;
use Composer\Policy\IgnoreIdRule;
use Composer\Policy\IgnorePackageRule;
use Composer\Policy\IgnoreUnreachable;
use Composer\Policy\ListPolicyConfig;
use Composer\Policy\MalwarePolicyConfig;
use Composer\Policy\PolicyConfig;
use Composer\Repository\PackageRepository;
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
            CooldownPolicyConfig::disabled(),
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
        $this->assertSame(PolicyRemovalReason::ADVISORIES, self::getRemovalType($filteredPool, 'acme/package', '1.0.0.0'));
        $this->assertCount(0, self::getRemovals($filteredPool, PolicyRemovalReason::ABANDONED));

        $reason = $filteredPool->getPolicyRemovalReason('acme/package', '1.0.0.0');
        $this->assertNotNull($reason);
        $this->assertSame([$advisory1['advisoryId'], $advisory2['advisoryId']], array_map(static function ($advisory): string {
            return $advisory->advisoryId;
        }, $reason->getAdvisories()));
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
            CooldownPolicyConfig::disabled(),
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
        $this->assertCount(0, self::getRemovals($filteredPool, PolicyRemovalReason::ABANDONED));
        $this->assertCount(0, self::getRemovals($filteredPool, PolicyRemovalReason::ADVISORIES));
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
        $this->assertCount(0, self::getRemovals($filteredPool, PolicyRemovalReason::ABANDONED));
        $this->assertCount(0, self::getRemovals($filteredPool, PolicyRemovalReason::ADVISORIES));
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
        $this->assertCount(1, self::getRemovals($filteredPool, PolicyRemovalReason::ABANDONED));
        $this->assertCount(0, self::getRemovals($filteredPool, PolicyRemovalReason::ADVISORIES));
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
            CooldownPolicyConfig::disabled(),
            [],
            IgnoreUnreachable::none()
        );

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), $policyConfig, new BufferIO());

        $this->expectException(TransportException::class);
        $filter->filter(new Pool([new Package('acme/package', '1.0.0.0', '1.0')]), [$unreachable], new Request());
    }

    public function testDoesNotLoadAdvisoriesForDevPackages(): void
    {
        $repository = new class([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '>=2.0.0')],
            ],
        ]) extends PackageRepository {
            /** @var list<string> */
            public $requestedNames = [];

            public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
            {
                $this->requestedNames = array_merge($this->requestedNames, array_keys($packageConstraintMap));

                return parent::getSecurityAdvisories($packageConstraintMap, $allowPartialAdvisories);
            }
        };

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());
        $filter->filter(new Pool([
            new Package('acme/package', '1.0.0.0', '1.0'),
            new Package('acme/path-package', 'dev-main', 'dev-main'),
            new Package('acme/branch-package', '3.3.9999999.9999999-dev', '3.3.x-dev'),
        ]), [$repository], new Request());

        $this->assertSame(['acme/package', 'acme/branch-package'], $repository->requestedNames);
    }

    public function testFilterNumericDevBranchByAdvisories(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '<3.5')],
            ],
        ]);

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());
        $filteredPool = $filter->filter(new Pool([
            new Package('acme/package', '3.3.9999999.9999999-dev', '3.3.x-dev'),
            new Package('acme/package', '3.6.9999999.9999999-dev', '3.6.x-dev'),
        ]), [$repository], new Request());

        $this->assertCount(1, $filteredPool->getPackages());
        $this->assertSame('3.6.x-dev', $filteredPool->getPackages()[0]->getPrettyVersion());
        $this->assertSame(PolicyRemovalReason::ADVISORIES, self::getRemovalType($filteredPool, 'acme/package', '3.3.9999999.9999999-dev'));
    }

    public function testFilterBranchAliasOfDevPackageByAdvisories(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '<3.5')],
            ],
        ]);

        $devPackage = new CompletePackage('acme/package', 'dev-main', 'dev-main');
        $branchAlias = new CompleteAliasPackage($devPackage, '3.3.9999999.9999999-dev', '3.3.x-dev');
        // requiring the branch explicitly opts out of advisories, even if the root alias version itself would match
        $rootAlias = new CompleteAliasPackage($devPackage, '3.3.0.0', '3.3.0');
        $rootAlias->setRootPackageAlias(true);

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());
        $filteredPool = $filter->filter(new Pool([$devPackage, $branchAlias, $rootAlias]), [$repository], new Request());

        $this->assertSame([$devPackage, $rootAlias], $filteredPool->getPackages());
        $this->assertSame(PolicyRemovalReason::ADVISORIES, self::getRemovalType($filteredPool, 'acme/package', '3.3.9999999.9999999-dev'));
    }

    public function testDefaultBranchAliasIsNotFilteredByAdvisories(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '>=2.0')],
            ],
        ]);

        $devPackage = new CompletePackage('acme/package', 'dev-main', 'dev-main');
        $defaultBranchAlias = new CompleteAliasPackage($devPackage, VersionParser::DEFAULT_BRANCH_ALIAS, VersionParser::DEFAULT_BRANCH_ALIAS);

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());
        $filteredPool = $filter->filter(new Pool([$devPackage, $defaultBranchAlias]), [$repository], new Request());

        $this->assertSame([$devPackage, $defaultBranchAlias], $filteredPool->getPackages());
    }

    public function testRootAliasOfDevPackageIsNotFilteredByAliasVersion(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '*')],
            ],
        ]);

        $devPackage = new CompletePackage('acme/package', 'dev-main', 'dev-main');
        $aliasPackage = new CompleteAliasPackage($devPackage, '1.0.0.0', '1.0.0');
        $aliasPackage->setRootPackageAlias(true);

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());
        $filteredPool = $filter->filter(new Pool([$devPackage, $aliasPackage]), [$repository], new Request());

        $this->assertSame([$devPackage, $aliasPackage], $filteredPool->getPackages());
    }

    public function testFilterRootAliasByAliasedPackageVersion(): void
    {
        $repository = new PackageRepository([
            'package' => [],
            'security-advisories' => [
                'acme/package' => [$this->generateSecurityAdvisory('acme/package', 'CVE-2024-1234', '<3.5')],
                'acme/other' => [$this->generateSecurityAdvisory('acme/other', 'CVE-2024-1235', '<3.5')],
            ],
        ]);

        // vulnerable code aliased to a safe version is still filtered
        $vulnerable = new CompletePackage('acme/package', '3.3.9999999.9999999-dev', '3.3.x-dev');
        $vulnerableAlias = new CompleteAliasPackage($vulnerable, '3.9.0.0', '3.9.0');
        $vulnerableAlias->setRootPackageAlias(true);

        // safe code aliased to a vulnerable version is kept
        $safe = new CompletePackage('acme/other', '3.6.9999999.9999999-dev', '3.6.x-dev');
        $safeAlias = new CompleteAliasPackage($safe, '3.0.0.0', '3.0.0');
        $safeAlias->setRootPackageAlias(true);

        $filter = new SecurityAdvisoryPoolFilter(new Auditor(), self::policyConfig(), new NullIO());
        $filteredPool = $filter->filter(new Pool([$vulnerable, $vulnerableAlias, $safe, $safeAlias]), [$repository], new Request());

        $this->assertSame([$safe, $safeAlias], $filteredPool->getPackages());
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

    private static function getRemovalType(Pool $pool, string $packageName, string $version): ?string
    {
        $reason = $pool->getPolicyRemovalReason($packageName, $version);

        return $reason !== null ? $reason->getType() : null;
    }

    /**
     * @return list<string> "name version" of every version the given policy removed
     */
    private static function getRemovals(Pool $pool, string $type): array
    {
        $removals = [];
        foreach ($pool->getAllPolicyRemovedVersions() as $packageName => $versions) {
            foreach ($versions as $version => $reason) {
                if ($reason->getType() === $type) {
                    $removals[] = $packageName.' '.$version;
                }
            }
        }

        return $removals;
    }
}
