<?php declare(strict_types=1);

namespace Composer\Test\Util;

use Composer\Test\TestCase;
use Composer\Util\ForgejoUrl;

class ForgejoUrlTest extends TestCase
{
    /**
     * @dataProvider createProvider
     */
    public function testCreate(?string $repoUrl): void
    {
        $forgejoUrl = ForgejoUrl::tryFrom($repoUrl);

        $this->assertNotNull($forgejoUrl);
        $this->assertSame('codeberg.org', $forgejoUrl->originUrl);
        $this->assertSame('acme', $forgejoUrl->owner);
        $this->assertSame('repo', $forgejoUrl->repository);
        $this->assertSame('https://codeberg.org/api/v1/repos/acme/repo', $forgejoUrl->apiUrl);
    }

    public static function createProvider(): array
    {
        return [
            ['git@codeberg.org:acme/repo.git'],
            ['https://codeberg.org/acme/repo'],
            ['https://codeberg.org/acme/repo.git'],
        ];
    }

    public function testCreateInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ForgejoUrl::create('https://example.org');
    }

    public function testGenerateSshUrl(): void
    {
        $forgejoUrl = ForgejoUrl::create('git@codeberg.org:acme/repo.git');

        $this->assertSame('git@codeberg.org:acme/repo.git', $forgejoUrl->generateSshUr());
    }
}
