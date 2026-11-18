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

namespace Composer\Test\Command;

use Composer\Advisory\Auditor;
use Composer\Package\Link;
use Composer\Policy\ListPolicyConfig;
use Composer\Semver\Constraint\MatchAllConstraint;
use Composer\Test\TestCase;
use UnexpectedValueException;

class AuditCommandTest extends TestCase
{
    public function testSuccessfulResponseCodeWhenNoPackagesAreRequired(): void
    {
        $this->initTempComposer();

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit']);

        $appTester->assertCommandIsSuccessful();
        self::assertEquals('No packages - skipping audit.', trim($appTester->getDisplay(true)));
    }

    public function testErrorAuditingLockFileWhenItIsMissing(): void
    {
        $this->initTempComposer();
        $this->createInstalledJson([self::getPackage()]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            "Valid composer.json and composer.lock files are required to run this command with --locked"
        );

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit', '--locked' => true]);
    }

    public function testAuditPackageWithNoSecurityVulnerabilities(): void
    {
        $this->initTempComposer();
        $packages = [self::getPackage()];
        $this->createInstalledJson($packages);
        $this->createComposerLock($packages);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit', '--locked' => true]);

        self::assertStringContainsString(
            'No security vulnerability advisories found.',
            trim($appTester->getDisplay(true))
        );
    }

    public function testErrorAuditWithNoInstalledPackages(): void
    {
        $this->initTempComposer(['require' => ['dummy/pkg' => '1.0.0']]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit']);

        self::assertSame(Auditor::STATUS_FAILED, $appTester->getStatusCode());
        self::assertStringContainsString(
            'No installed packages found. Please run "composer install" before running "audit"',
            trim($appTester->getDisplay(true))
        );
    }

    public function testAuditPackageWithNoDevOptionPassed(): void
    {
        $this->initTempComposer();
        $devPackage = [self::getPackage()];
        $this->createInstalledJson([], $devPackage);
        $this->createComposerLock([], $devPackage);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit', '--no-dev' => true]);

        self::assertStringContainsString(
            'No packages - skipping audit.',
            trim($appTester->getDisplay(true))
        );
    }

    public function testAuditWithMalwareAndCustomListBothFail(): void
    {
        $packages = [
            ['name' => 'safe/pkg', 'version' => '1.0.0'],
            ['name' => 'malicious/pkg', 'version' => '1.0.0'],
            ['name' => 'banned/pkg', 'version' => '1.0.0'],
        ];
        $this->initTempComposerWithFilterLists($packages);
        $this->createComposerLockWithPackages($packages);

        $appTester = $this->getApplicationTester();
        $exitCode = $appTester->run(['command' => 'audit', '--locked' => true]);

        $display = $appTester->getDisplay(true);
        self::assertStringContainsString('Found 2 packages matching filters', $display);
        self::assertStringContainsString('malicious/pkg', $display);
        self::assertStringContainsString('banned/pkg', $display);

        self::assertSame(Auditor::STATUS_FAILED, $exitCode);
    }

    public function testAuditWithCustomListAuditReportDoesNotFail(): void
    {
        $this->initTempComposer([
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => $packages = [
                        ['name' => 'safe/pkg', 'version' => '1.0.0'],
                        ['name' => 'banned/pkg', 'version' => '1.0.0'],
                    ],
                    'filter' => [
                        'company-banned' => [
                            ['package' => 'banned/pkg', 'constraint' => '*', 'reason' => 'company policy'],
                        ],
                    ],
                ],
            ],
            'config' => [
                'policy' => [
                    'company-banned' => ['audit' => ListPolicyConfig::AUDIT_REPORT],
                ],
            ],
        ]);

        $this->createComposerLockWithPackages($packages);

        $appTester = $this->getApplicationTester();
        $exitCode = $appTester->run(['command' => 'audit', '--locked' => true]);

        $display = $appTester->getDisplay(true);
        self::assertStringContainsString('Found 1 package matching filters', $display);
        self::assertStringContainsString('banned/pkg', $display);
        self::assertSame(Auditor::STATUS_OK, $exitCode);
    }

    public function testAuditDirectSkipsTransitiveDependencies(): void
    {
        $packages = [
            ['name' => 'safe/pkg', 'version' => '1.0.0'],
            ['name' => 'malicious/pkg', 'version' => '1.0.0'],
        ];
        $this->initTempComposerWithFilterLists($packages, ['require' => ['safe/pkg' => '1.0.0']]);
        $this->createComposerLockWithPackages($packages);

        foreach ([[], ['--locked' => true]] as $options) {
            $appTester = $this->getApplicationTester();
            $exitCode = $appTester->run(['command' => 'audit', '--direct' => true] + $options);

            self::assertSame(Auditor::STATUS_OK, $exitCode);
            self::assertStringNotContainsString('malicious/pkg', $appTester->getDisplay(true));
        }

        $appTester = $this->getApplicationTester();
        self::assertSame(Auditor::STATUS_FAILED, $appTester->run(['command' => 'audit', '--locked' => true]));
    }

    public function testAuditDirectIncludesReplacingPackage(): void
    {
        $this->initTempComposerWithFilterLists([['name' => 'malicious/pkg', 'version' => '1.0.0']], ['require' => ['replaced/pkg' => '*']]);
        $package = self::getPackage('malicious/pkg', '1.0.0');
        $package->setReplaces(['replaced/pkg' => new Link('malicious/pkg', 'replaced/pkg', new MatchAllConstraint(), Link::TYPE_REPLACE, '*')]);
        $this->createInstalledJson([$package]);

        $appTester = $this->getApplicationTester();
        $exitCode = $appTester->run(['command' => 'audit', '--direct' => true]);

        self::assertSame(Auditor::STATUS_FAILED, $exitCode);
        self::assertStringContainsString('malicious/pkg', $appTester->getDisplay(true));
    }

    public function testAuditDirectWithNoDirectPackagesInstalled(): void
    {
        $this->initTempComposer(['require' => ['php' => '*']]);
        $this->createInstalledJson([self::getPackage()]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'audit', '--direct' => true]);

        $appTester->assertCommandIsSuccessful();
        self::assertEquals('No direct dependencies - skipping audit.', trim($appTester->getDisplay(true)));
    }

    /**
     * @param list<array{name: string, version: string}> $packages
     * @param array<string, mixed> $extraConfig
     */
    private function initTempComposerWithFilterLists(array $packages, array $extraConfig = []): void
    {
        $this->initTempComposer($extraConfig + [
            'repositories' => [
                'packages' => [
                    'type' => 'package',
                    'package' => $packages,
                    'filter' => [
                        'malware' => [
                            ['package' => 'malicious/pkg', 'constraint' => '*', 'reason' => 'malware sample'],
                        ],
                        'company-banned' => [
                            ['package' => 'banned/pkg', 'constraint' => '*', 'reason' => 'company policy'],
                        ],
                    ],
                ],
            ],
            'config' => [
                'policy' => [
                    'company-banned' => true,
                ],
            ],
        ]);
    }

    /**
     * @param list<array{name: string, version: string}> $packages
     */
    private function createComposerLockWithPackages(array $packages): void
    {
        $packages = array_map(function (array $package) {
            return self::getPackage($package['name'], $package['version']);
        }, $packages);

        $this->createInstalledJson($packages);
        $this->createComposerLock($packages);
    }
}
