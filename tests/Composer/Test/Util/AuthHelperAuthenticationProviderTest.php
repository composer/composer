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

namespace Composer\Test\Util;

use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Plugin\Capability\AuthenticationProvider;
use Composer\Test\TestCase;
use Composer\Util\AuthHelper;

class AuthHelperAuthenticationProviderTest extends TestCase
{
    public function testAddAuthenticationOptionsUsesProviderCredentials(): void
    {
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::any())
            ->method('getAuthentication')
            ->willReturnCallback(static function (string $origin): ?array {
                return $origin === 'example.org' ? ['username' => 'foo', 'password' => 'bar'] : null;
            });

        $io = new BufferIO();
        $io->setAuthenticationProviders([$provider]);
        $authHelper = new AuthHelper($io, new Config(false));

        $options = $authHelper->addAuthenticationOptions([], 'example.org', 'https://example.org/composer.json');

        self::assertContains(
            'Authorization: Basic '.base64_encode('foo:bar'),
            $options['http']['header']
        );
    }

    public function testFindAuthOriginResolvesCanonicalHostFromProvider(): void
    {
        $provider = $this->createMock(AuthenticationProvider::class);
        $provider->expects(self::any())
            ->method('getAuthentication')
            ->willReturnCallback(static function (string $origin): ?array {
                return $origin === 'github.com' ? ['username' => 'abc123', 'password' => 'x-oauth-basic'] : null;
            });

        $io = new BufferIO();
        $io->setAuthenticationProviders([$provider]);

        self::assertSame('github.com', AuthHelper::findAuthOrigin($io, 'api.github.com'));

        $authHelper = new AuthHelper($io, new Config(false));
        $options = $authHelper->addAuthenticationOptions([], 'api.github.com', 'https://api.github.com/repos/foo/bar/contents/composer.json');

        self::assertContains('Authorization: token abc123', $options['http']['header']);
    }
}
