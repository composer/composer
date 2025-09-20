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

use Composer\Test\TestCase;
use RuntimeException;

class RepositoryCommandTest extends TestCase
{
    public function testListWithNoRepositories(): void
    {
        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'list']);
        $appTester->assertCommandIsSuccessful();

        self::assertStringContainsString('No repositories configured', trim($appTester->getDisplay(true)));
        // composer.json should remain unchanged
        self::assertSame([], json_decode((string) file_get_contents('composer.json'), true));
    }

    public function testListWithRepositoriesAsList(): void
    {
        $this->initTempComposer([
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://first.test'],
                ['name' => 'foo', 'type' => 'vcs', 'url' => 'https://old.example.org'],
                ['name' => 'bar', 'type' => 'vcs', 'url' => 'https://other.example.org'],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'list']);
        $appTester->assertCommandIsSuccessful();

        self::assertSame('[0] composer https://first.test
[foo] vcs https://old.example.org
[bar] vcs https://other.example.org
[packagist.org] disabled', trim($appTester->getDisplay(true)));
    }

    public function testListWithRepositoriesAsAssoc(): void
    {
        $this->initTempComposer([
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://first.test'],
                'foo' => ['type' => 'vcs', 'url' => 'https://old.example.org'],
                'bar' => ['type' => 'vcs', 'url' => 'https://other.example.org'],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'list']);
        $appTester->assertCommandIsSuccessful();

        self::assertSame('[0] composer https://first.test
[foo] vcs https://old.example.org
[bar] vcs https://other.example.org
[packagist.org] disabled', trim($appTester->getDisplay(true)));
    }

    public function testAddRepositoryWithTypeAndUrl(): void
    {
        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $result = $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'vcs',
            'arg2' => 'https://example.org/foo.git',
        ]);

        $appTester->assertCommandIsSuccessful($appTester->getDisplay());
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame(['repositories' => [
            ['name' => 'foo', 'type' => 'vcs', 'url' => 'https://example.org/foo.git'],
        ]], $json);
    }

    public function testAddRepositoryWithJson(): void
    {
        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'bar',
            'arg1' => '{"type":"composer","url":"https://repo.example.org"}',
        ]);

        $appTester->assertCommandIsSuccessful();
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame(['repositories' => [
            ['name' => 'bar', 'type' => 'composer', 'url' => 'https://repo.example.org'],
        ]], $json);
    }

    public function testRemoveRepository(): void
    {
        $this->initTempComposer(['repositories' => ['foo' => ['type' => 'vcs', 'url' => 'https://example.org']]], [], [], false);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'remove', 'name' => 'foo']);
        $appTester->assertCommandIsSuccessful();

        $json = json_decode((string) file_get_contents('composer.json'), true);
        // repositories key may still exist as empty array depending on manipulator, accept either
        if (isset($json['repositories'])) {
            self::assertSame([], $json['repositories']);
        } else {
            self::assertSame([], $json);
        }
    }

    /**
     * @dataProvider provideTestSetAndGetUrlInRepositoryAssoc
     * @param array<string, mixed> $repositories
     */
    public function testSetAndGetUrlInRepositoryAssoc(array $repositories, string $name, string $index, string $newUrl): void
    {
        $this->initTempComposer(['repositories' => $repositories], [], [], false);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'set-url', 'name' => $name, 'arg1' => $newUrl]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        // calling it still in assoc means, the repository has not been converted, which is good
        self::assertSame($newUrl, $json['repositories'][$index]['url'] ?? null);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'get-url', 'name' => $name]);
        $appTester->assertCommandIsSuccessful();
        self::assertSame($newUrl, trim($appTester->getDisplay(true)));
    }

    /**
     * @return iterable<array{0: array, 1: string, 2: string, 3: string}>
     */
    public static function provideTestSetAndGetUrlInRepositoryAssoc(): iterable
    {
        $repositories = [
            'first' => ['type' => 'composer', 'url' => 'https://first.test'],
            'foo' => ['type' => 'vcs', 'url' => 'https://old.example.org'],
            'bar' => ['type' => 'vcs', 'url' => 'https://other.example.org']
        ];

        yield 'change first of three' => [
            $repositories,
            'first',
            'first',
            'https://new.example.org'
        ];
        yield 'change middle of three' => [
            $repositories,
            'foo',
            'foo',
            'https://new.example.org'
        ];
        yield 'change last of three' => [
            $repositories,
            'bar',
            'bar',
            'https://new.example.org'
        ];
    }

    /**
     * @dataProvider provideTestSetAndGetUrlInRepositoryList
     * @param list<array<string, mixed>> $repositories
     */
    public function testSetAndGetUrlInRepositoryList(array $repositories, string $name, int $index, string $newUrl): void
    {
        $this->initTempComposer(['repositories' => $repositories], [], [], false);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'set-url', 'name' => $name, 'arg1' => $newUrl]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame($name, $json['repositories'][$index]['name'] ?? null);
        self::assertSame($newUrl, $json['repositories'][$index]['url'] ?? null);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'get-url', 'name' => $name]);
        $appTester->assertCommandIsSuccessful();
        self::assertSame($newUrl, trim($appTester->getDisplay(true)));
    }

    /**
     * @return iterable<array{0: array, 1: string, 2: int, 3: string}>
     */
    public static function provideTestSetAndGetUrlInRepositoryList(): iterable
    {
        $repositories = [
            ['name' => 'first', 'type' => 'composer', 'url' => 'https://first.test'],
            ['name' => 'foo', 'type' => 'vcs', 'url' => 'https://old.example.org'],
            ['name' => 'bar', 'type' => 'vcs', 'url' => 'https://other.example.org']
        ];

        yield 'change first of three' => [
            $repositories,
            'first',
            0,
            'https://new.example.org'
        ];
        yield 'change middle of three' => [
            $repositories,
            'foo',
            1,
            'https://new.example.org'
        ];
        yield 'change last of three' => [
            $repositories,
            'bar',
            2,
            'https://new.example.org'
        ];
    }

    public function testDisableAndEnablePackagist(): void
    {
        $this->initTempComposer([]);
        $appTester = $this->getApplicationTester();

        $appTester->run(['command' => 'repo', 'action' => 'disable', 'name' => 'packagist']);
        $appTester->assertCommandIsSuccessful();
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame(['repositories' => [['packagist.org' => false]]], $json);

        // enable packagist should remove the override
        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', 'action' => 'enable', 'name' => 'packagist']);
        $appTester->assertCommandIsSuccessful();
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([], $json);
    }

    public function testInvalidArgCombinationThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--file and --global can not be combined');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'repo', '--file' => 'alt.composer.json', '--global' => true]);
    }

    public function testPrependRepositoryByNameListToAssoc(): void
    {
        $this->initTempComposer(['repositories' => [['type' => 'git', 'url' => 'example.tld']]], [], [], false);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'path',
            'arg2' => 'foo/bar',
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'repositories' => [
                ['name' => 'foo', 'type' => 'path', 'url' => 'foo/bar'],
                ['type' => 'git', 'url' => 'example.tld'],
            ],
        ], $json);
    }

    public function testAppendRepositoryByNameListToAssoc(): void
    {
        $this->initTempComposer(['repositories' => [['type' => 'git', 'url' => 'example.tld']]], [], [], false);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'path',
            'arg2' => 'foo/bar',
            '--append' => true,
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'repositories' => [
                ['type' => 'git', 'url' => 'example.tld'],
                ['name' => 'foo', 'type' => 'path', 'url' => 'foo/bar'],
            ],
        ], $json);
    }

    public function testPrependRepositoryAssocWithPackagistDisabled(): void
    {
        $this->initTempComposer(['repositories' => [['type' => 'git', 'url' => 'example.tld'], 'packagist.org' => false]]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'path',
            'arg2' => 'foo/bar',
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'repositories' => [
                ['name' => 'foo', 'type' => 'path', 'url' => 'foo/bar'],
                ['type' => 'git', 'url' => 'example.tld'],
                ['packagist.org' => false],
            ],
        ], $json);
    }

    public function testAppendRepositoryAssocWithPackagistDisabled(): void
    {
        $this->initTempComposer(['repositories' => [['type' => 'git', 'url' => 'example.tld'], 'packagist.org' => false]]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'path',
            'arg2' => 'foo/bar',
            '--append' => true,
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'repositories' => [
                ['type' => 'git', 'url' => 'example.tld'],
                ['packagist.org' => false],
                ['name' => 'foo', 'type' => 'path', 'url' => 'foo/bar'],
            ],
        ], $json);
    }

    public function testAddBeforeAndAfterByName(): void
    {
        // Start with two repos as named-list and a disabled packagist boolean
        $this->initTempComposer(['repositories' => [
            ['name' => 'alpha', 'type' => 'vcs', 'url' => 'https://example.org/a'],
            ['name' => 'omega', 'type' => 'vcs', 'url' => 'https://example.org/o'],
            'packagist.org' => false,
        ]]);

        // Insert before omega
        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'beta',
            'arg1' => 'vcs',
            'arg2' => 'https://example.org/b',
            '--before' => 'omega',
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        // Insert after alpha
        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'gamma',
            'arg1' => 'vcs',
            'arg2' => 'https://example.org/g',
            '--after' => 'alpha',
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);
        // Expect order: alpha, gamma, beta, omega, then packagist.org boolean preserved
        self::assertSame([
            'repositories' => [
                ['name' => 'alpha', 'type' => 'vcs', 'url' => 'https://example.org/a'],
                ['name' => 'gamma', 'type' => 'vcs', 'url' => 'https://example.org/g'],
                ['name' => 'beta', 'type' => 'vcs', 'url' => 'https://example.org/b'],
                ['name' => 'omega', 'type' => 'vcs', 'url' => 'https://example.org/o'],
                ['packagist.org' => false],
            ],
        ], $json);
    }

    public function testAddSameNameReplacesExisting(): void
    {
        $this->initTempComposer([]);

        // first add
        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'vcs',
            'arg2' => 'https://example.org/old',
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        // second add with same name but different url
        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'repo',
            'action' => 'add',
            'name' => 'foo',
            'arg1' => 'vcs',
            'arg2' => 'https://example.org/new',
            '--append' => true,
        ]);
        $appTester->assertCommandIsSuccessful($appTester->getDisplay());

        $json = json_decode((string) file_get_contents('composer.json'), true);

        // repositories can be stored as assoc or named-list depending on manipulator fallbacks
        // Validate there is only one "foo" and its url is the latest
        $countFoo = 0;
        $url = null;
        foreach ($json['repositories'] as $k => $repo) {
            if (is_string($k) && $k === 'foo' && is_array($repo)) {
                $countFoo++;
                $url = $repo['url'] ?? null;
            } elseif (is_array($repo) && isset($repo['name']) && $repo['name'] === 'foo') {
                $countFoo++;
                $url = $repo['url'] ?? null;
            }
        }
        self::assertSame(1, $countFoo, 'Exactly one repository entry with name foo should exist');
        self::assertSame('https://example.org/new', $url, 'The foo repository should have been updated to the new URL');
    }
}
