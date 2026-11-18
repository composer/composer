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

namespace Composer\Test\SelfUpdate;

use Composer\Config;
use Composer\SelfUpdate\Versions;
use Composer\Test\Mock\HttpDownloaderMock;
use Composer\Test\TestCase;

class VersionsTest extends TestCase
{
    /**
     * @param  array{version: string, maintenance?: string, maintenance-until?: string|null, lts?: bool} $entry
     * @param  array{type: string, version: string, until: string|null, lts: bool}|null                  $expected
     * @dataProvider provideMaintenanceWarnings
     */
    public function testGetMaintenanceWarning(array $entry, ?array $expected): void
    {
        // fixed "now" so the 6 month critical-security threshold is deterministic; +6 months is 2026-12-04
        $now = new \DateTimeImmutable('2026-06-04 00:00:00');

        self::assertSame($expected, Versions::getMaintenanceWarning($entry, $now));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null}>
     */
    public static function provideMaintenanceWarnings(): array
    {
        return [
            'eol always warns regardless of date' => [
                ['version' => '1.10.28', 'maintenance' => 'eol', 'maintenance-until' => '2026-06-30', 'lts' => false],
                ['type' => 'eol', 'version' => '1.10.28', 'until' => '2026-06-30', 'lts' => false],
            ],
            'eol with null until still warns' => [
                ['version' => '1.10.28', 'maintenance' => 'eol', 'maintenance-until' => null, 'lts' => false],
                ['type' => 'eol', 'version' => '1.10.28', 'until' => null, 'lts' => false],
            ],
            'critical-security within 6 months warns' => [
                ['version' => '2.2.28', 'maintenance' => 'critical-security', 'maintenance-until' => '2026-09-30', 'lts' => true],
                ['type' => 'critical-security', 'version' => '2.2.28', 'until' => '2026-09-30', 'lts' => true],
            ],
            'critical-security exactly 6 months away warns (inclusive)' => [
                ['version' => '2.2.28', 'maintenance' => 'critical-security', 'maintenance-until' => '2026-12-04', 'lts' => true],
                ['type' => 'critical-security', 'version' => '2.2.28', 'until' => '2026-12-04', 'lts' => true],
            ],
            'critical-security just over 6 months away stays quiet' => [
                ['version' => '2.2.28', 'maintenance' => 'critical-security', 'maintenance-until' => '2026-12-05', 'lts' => true],
                null,
            ],
            'critical-security far in the future stays quiet' => [
                ['version' => '2.2.28', 'maintenance' => 'critical-security', 'maintenance-until' => '2027-12-31', 'lts' => true],
                null,
            ],
            'critical-security with null until stays quiet' => [
                ['version' => '2.2.28', 'maintenance' => 'critical-security', 'maintenance-until' => null, 'lts' => true],
                null,
            ],
            'critical-security with malformed until stays quiet' => [
                ['version' => '2.2.28', 'maintenance' => 'critical-security', 'maintenance-until' => 'soon', 'lts' => true],
                null,
            ],
            'security tier does not warn' => [
                ['version' => '2.6.0', 'maintenance' => 'security', 'maintenance-until' => '2026-07-01', 'lts' => false],
                null,
            ],
            'active bugfix release does not warn' => [
                ['version' => '2.10.0', 'maintenance' => 'bugfix', 'maintenance-until' => null, 'lts' => false],
                null,
            ],
            'missing maintenance field does not warn' => [
                ['version' => '2.9.9', 'min-php' => 70205],
                null,
            ],
            'unknown future maintenance value does not warn' => [
                ['version' => '3.0.0', 'maintenance' => 'something-new', 'maintenance-until' => '2026-07-01'],
                null,
            ],
        ];
    }

    public function testGetLatestSkipsVersionsRequiringNewerPhp(): void
    {
        $versions = $this->versions([
            'stable' => [
                $this->entry('2.10.0', \PHP_VERSION_ID + 10000, false, 'bugfix', null),
                $this->entry('2.2.28', \PHP_VERSION_ID - 10000, true, 'critical-security', '2026-12-31'),
            ],
        ]);

        // 2.10.0 needs a newer PHP than the runtime, so the installable LTS fallback is returned instead
        self::assertSame('2.2.28', $versions->getLatest('stable')['version']);
    }

    public function testGetLatestThrowsWhenNothingIsInstallable(): void
    {
        $versions = $this->versions([
            'stable' => [
                $this->entry('2.10.0', \PHP_VERSION_ID + 10000, false, 'bugfix', null),
            ],
        ]);

        self::expectException(\UnexpectedValueException::class);
        $versions->getLatest('stable');
    }

    public function testGetNewerPhpBlockedVersionReturnsTheBlockedHead(): void
    {
        $versions = $this->versions([
            'stable' => [
                $this->entry('2.10.0', \PHP_VERSION_ID + 10000, false, 'bugfix', null),
                $this->entry('2.2.28', \PHP_VERSION_ID - 10000, true, 'critical-security', '2026-12-31'),
            ],
        ]);

        $blocked = $versions->getNewerPhpBlockedVersion('stable');
        self::assertNotNull($blocked);
        self::assertSame('2.10.0', $blocked['version']);
    }

    public function testGetNewerPhpBlockedVersionReturnsNullWhenHeadIsInstallable(): void
    {
        $versions = $this->versions([
            'stable' => [
                $this->entry('2.10.0', \PHP_VERSION_ID - 10000, false, 'bugfix', null),
                $this->entry('2.2.28', \PHP_VERSION_ID - 10000, true, 'critical-security', '2026-12-31'),
            ],
        ]);

        self::assertNull($versions->getNewerPhpBlockedVersion('stable'));
    }

    /**
     * @param  array<string, list<array<string, mixed>>> $data
     */
    private function versions(array $data): Versions
    {
        $httpDownloader = new HttpDownloaderMock();
        $httpDownloader->expects([
            ['url' => 'https://getcomposer.org/versions', 'body' => (string) json_encode($data)],
        ]);

        return new Versions(new Config(false), $httpDownloader);
    }

    /**
     * @return array{path: string, version: string, min-php: int, lts: bool, maintenance: string, maintenance-until: string|null}
     */
    private function entry(string $version, int $minPhp, bool $lts, string $maintenance, ?string $until): array
    {
        return [
            'path' => '/download/'.$version.'/composer.phar',
            'version' => $version,
            'min-php' => $minPhp,
            'lts' => $lts,
            'maintenance' => $maintenance,
            'maintenance-until' => $until,
        ];
    }
}
