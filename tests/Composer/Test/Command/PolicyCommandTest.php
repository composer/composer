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

class PolicyCommandTest extends TestCase
{
    public function testAddSourceCreatesNewList(): void
    {
        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => 'url',
            'arg2' => 'https://example.org/list.json',
        ]);

        $appTester->assertCommandIsSuccessful($appTester->getDisplay());
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'config' => [
                'policy' => [
                    'my-list' => [
                        'sources' => [
                            ['type' => 'url', 'url' => 'https://example.org/list.json'],
                        ],
                    ],
                ],
            ],
        ], $json);
    }

    public function testAddSourceAppendsToExistingList(): void
    {
        $this->initTempComposer([
            'config' => [
                'policy' => [
                    'my-list' => [
                        'block' => true,
                        'sources' => [
                            ['type' => 'url', 'url' => 'https://first.example.org/list.json'],
                        ],
                    ],
                ],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => 'url',
            'arg2' => 'https://second.example.org/list.json',
        ]);

        $appTester->assertCommandIsSuccessful($appTester->getDisplay());
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'config' => [
                'policy' => [
                    'my-list' => [
                        'block' => true,
                        'sources' => [
                            ['type' => 'url', 'url' => 'https://first.example.org/list.json'],
                            ['type' => 'url', 'url' => 'https://second.example.org/list.json'],
                        ],
                    ],
                ],
            ],
        ], $json);
    }

    public function testAddSourceIsNoopWhenUrlAlreadyPresent(): void
    {
        $this->initTempComposer([
            'config' => [
                'policy' => [
                    'my-list' => [
                        'sources' => [
                            ['type' => 'url', 'url' => 'https://example.org/list.json'],
                        ],
                    ],
                ],
            ],
        ]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => 'url',
            'arg2' => 'https://example.org/list.json',
        ]);

        $appTester->assertCommandIsSuccessful();
        self::assertStringContainsString('already present', $appTester->getDisplay(true));
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertCount(1, $json['config']['policy']['my-list']['sources']);
    }

    public function testAddSourceWithJson(): void
    {
        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => '{"type":"url","url":"https://example.org/list.json"}',
        ]);

        $appTester->assertCommandIsSuccessful($appTester->getDisplay());
        $json = json_decode((string) file_get_contents('composer.json'), true);
        self::assertSame([
            'config' => [
                'policy' => [
                    'my-list' => [
                        'sources' => [
                            ['type' => 'url', 'url' => 'https://example.org/list.json'],
                        ],
                    ],
                ],
            ],
        ], $json);
    }

    public function testAddSourceRejectsBuiltInListName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Built-in list "advisories" does not support sources');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'advisories',
            'arg1' => 'url',
            'arg2' => 'https://example.org/list.json',
        ]);
    }

    public function testAddSourceRejectsIgnoreUnreachableName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved prefix "ignore"');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'ignore-unreachable',
            'arg1' => 'url',
            'arg2' => 'https://example.org/list.json',
        ]);
    }

    public function testAddSourceRejectsNonHttpsUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source URL must start with "https://"');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => 'url',
            'arg2' => 'http://insecure.example.org/list.json',
        ]);
    }

    public function testAddSourceRejectsUnsupportedType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported source type');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => 'file',
            'arg2' => 'https://example.org/list.json',
        ]);
    }

    public function testAddSourceRejectsJsonMissingUrl(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing a string "url"');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run([
            'command' => 'policy',
            'action' => 'add-source',
            'name' => 'my-list',
            'arg1' => '{"type":"url"}',
        ]);
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown action');

        $this->initTempComposer([]);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'policy', 'action' => 'bogus']);
    }
}
