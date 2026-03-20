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

use Composer\DependencyResolver\ReleaseAgeConfig;
use Composer\Test\TestCase;
use Composer\Util\Platform;

class ReleaseAgeConfigTest extends TestCase
{
    public function testParseDurationNull(): void
    {
        $this->assertNull(ReleaseAgeConfig::parseDuration(null));
        $this->assertNull(ReleaseAgeConfig::parseDuration(''));
        $this->assertNull(ReleaseAgeConfig::parseDuration(0));
        $this->assertNull(ReleaseAgeConfig::parseDuration('0'));
    }

    public function testParseDurationInteger(): void
    {
        $this->assertSame(3600, ReleaseAgeConfig::parseDuration(3600));
        $this->assertSame(86400, ReleaseAgeConfig::parseDuration(86400));
    }

    public function testParseDurationNumericString(): void
    {
        $this->assertSame(3600, ReleaseAgeConfig::parseDuration('3600'));
        $this->assertSame(86400, ReleaseAgeConfig::parseDuration('86400'));
    }

    public function testParseDurationHumanReadable(): void
    {
        $this->assertSame(3600, ReleaseAgeConfig::parseDuration('1 hour'));
        $this->assertSame(86400, ReleaseAgeConfig::parseDuration('1 day'));
        $this->assertSame(604800, ReleaseAgeConfig::parseDuration('7 days'));
        $this->assertSame(604800, ReleaseAgeConfig::parseDuration('1 week'));
        $this->assertSame(7200, ReleaseAgeConfig::parseDuration('2 hours'));
    }

    public function testParseDurationInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid minimum-release-age format');
        ReleaseAgeConfig::parseDuration('invalid duration string');
    }

    public function testParseDurationNegativeInteger(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duration cannot be negative');
        ReleaseAgeConfig::parseDuration(-3600);
    }

    public function testParseDurationNegativeNumericString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duration cannot be negative');
        ReleaseAgeConfig::parseDuration('-3600');
    }

    public function testParseDurationNegativeDurationString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duration cannot be negative');
        ReleaseAgeConfig::parseDuration('-2 days');
    }

    public function testParseDurationAgoString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duration cannot be negative');
        ReleaseAgeConfig::parseDuration('2 days ago');
    }

    public function testIsEnabledWithMinimumAge(): void
    {
        $config = new ReleaseAgeConfig(86400, []);
        $this->assertTrue($config->isEnabled());
    }

    public function testIsEnabledWithZeroMinimumAge(): void
    {
        $config = new ReleaseAgeConfig(0, []);
        $this->assertFalse($config->isEnabled());
    }

    public function testIsEnabledWithNullMinimumAge(): void
    {
        $config = new ReleaseAgeConfig(null, []);
        $this->assertFalse($config->isEnabled());
    }

    public function testIsPackageExceptedWithExactMatch(): void
    {
        $config = new ReleaseAgeConfig(3600, [
            ['package' => 'acme/special', 'reason' => 'We trust this package'],
        ]);

        $this->assertTrue($config->isPackageExcepted('acme/special'));
        $this->assertFalse($config->isPackageExcepted('acme/other'));
    }

    public function testIsPackageExceptedWithWildcard(): void
    {
        $config = new ReleaseAgeConfig(3600, [
            ['package' => 'internal/*', 'reason' => 'Internal packages'],
        ]);

        $this->assertTrue($config->isPackageExcepted('internal/package'));
        $this->assertTrue($config->isPackageExcepted('internal/other'));
        $this->assertFalse($config->isPackageExcepted('external/package'));
    }

    public function testIsPackageExceptedWithMultiplePatterns(): void
    {
        $config = new ReleaseAgeConfig(3600, [
            ['package' => 'internal/*', 'reason' => 'Internal packages'],
            ['package' => 'acme/special', 'reason' => 'Special package'],
        ]);

        $this->assertTrue($config->isPackageExcepted('internal/package'));
        $this->assertTrue($config->isPackageExcepted('acme/special'));
        $this->assertFalse($config->isPackageExcepted('acme/other'));
    }

    public function testGetExceptionReason(): void
    {
        $config = new ReleaseAgeConfig(3600, [
            ['package' => 'internal/*', 'reason' => 'Internal packages we trust'],
            ['package' => 'acme/special', 'reason' => 'Special trusted package'],
        ]);

        $this->assertSame('Internal packages we trust', $config->getExceptionReason('internal/package'));
        $this->assertSame('Special trusted package', $config->getExceptionReason('acme/special'));
        $this->assertNull($config->getExceptionReason('acme/other'));
    }

    public function testGetExceptionReasonWithEmptyReason(): void
    {
        $config = new ReleaseAgeConfig(3600, [
            ['package' => 'acme/special', 'reason' => ''],
        ]);

        $this->assertSame('', $config->getExceptionReason('acme/special'));
    }

    public function testFromConfigWithMinimumAge(): void
    {
        $config = $this->getConfig([
            'minimum-release-age' => [
                'minimum-age' => '7 days',
                'exceptions' => [],
            ],
        ]);

        $releaseAgeConfig = ReleaseAgeConfig::fromConfig($config);

        $this->assertSame(604800, $releaseAgeConfig->minimumReleaseAge);
        $this->assertTrue($releaseAgeConfig->isEnabled());
        $this->assertSame([], $releaseAgeConfig->exceptions);
    }

    public function testFromConfigWithExceptions(): void
    {
        $config = $this->getConfig([
            'minimum-release-age' => [
                'minimum-age' => '24 hours',
                'exceptions' => [
                    ['package' => 'vendor/*', 'reason' => 'Trusted vendor'],
                    ['package' => 'acme/special', 'reason' => 'Special package'],
                ],
            ],
        ]);

        $releaseAgeConfig = ReleaseAgeConfig::fromConfig($config);

        $this->assertSame(86400, $releaseAgeConfig->minimumReleaseAge);
        $this->assertCount(2, $releaseAgeConfig->exceptions);
        $this->assertTrue($releaseAgeConfig->isPackageExcepted('vendor/package'));
        $this->assertTrue($releaseAgeConfig->isPackageExcepted('acme/special'));
        $this->assertFalse($releaseAgeConfig->isPackageExcepted('other/package'));
    }

    public function testFromConfigWithNullMinimumAge(): void
    {
        $config = $this->getConfig([
            'minimum-release-age' => [
                'minimum-age' => null,
            ],
        ]);

        $releaseAgeConfig = ReleaseAgeConfig::fromConfig($config);

        $this->assertNull($releaseAgeConfig->minimumReleaseAge);
        $this->assertFalse($releaseAgeConfig->isEnabled());
    }

    public function testFromConfigEnvOverridesMinimumAge(): void
    {
        $originalEnv = Platform::getEnv('COMPOSER_MINIMUM_RELEASE_AGE');

        try {
            Platform::putEnv('COMPOSER_MINIMUM_RELEASE_AGE', '2 days');

            $config = $this->getConfig([
                'minimum-release-age' => [
                    'minimum-age' => '7 days',
                    'exceptions' => [
                        ['package' => 'vendor/*', 'reason' => 'Trusted'],
                    ],
                ],
            ]);

            $releaseAgeConfig = ReleaseAgeConfig::fromConfig($config);

            // Env should override the minimum-age
            $this->assertSame(172800, $releaseAgeConfig->minimumReleaseAge); // 2 days in seconds
            // But exceptions from config should still be respected
            $this->assertCount(1, $releaseAgeConfig->exceptions);
            $this->assertTrue($releaseAgeConfig->isPackageExcepted('vendor/package'));
        } finally {
            if ($originalEnv === false) {
                Platform::clearEnv('COMPOSER_MINIMUM_RELEASE_AGE');
            } else {
                Platform::putEnv('COMPOSER_MINIMUM_RELEASE_AGE', $originalEnv);
            }
        }
    }

    public function testFromConfigEnvDisablesFeature(): void
    {
        $originalEnv = Platform::getEnv('COMPOSER_MINIMUM_RELEASE_AGE');

        try {
            Platform::putEnv('COMPOSER_MINIMUM_RELEASE_AGE', '0');

            $config = $this->getConfig([
                'minimum-release-age' => [
                    'minimum-age' => '7 days',
                ],
            ]);

            $releaseAgeConfig = ReleaseAgeConfig::fromConfig($config);

            // Setting to 0 should disable the feature
            $this->assertNull($releaseAgeConfig->minimumReleaseAge);
            $this->assertFalse($releaseAgeConfig->isEnabled());
        } finally {
            if ($originalEnv === false) {
                Platform::clearEnv('COMPOSER_MINIMUM_RELEASE_AGE');
            } else {
                Platform::putEnv('COMPOSER_MINIMUM_RELEASE_AGE', $originalEnv);
            }
        }
    }
}
