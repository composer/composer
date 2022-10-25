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
use Composer\Util\Platform;

class ValidateCommandTest extends TestCase
{
    /**
     * @dataProvider provideValidateTests
     * @param array<mixed> $composerJson
     * @param array<mixed> $command
     */
    public function testValidate(array $composerJson, array $command, string $expected): void
    {
        $this->initTempComposer($composerJson);

        $appTester = $this->getApplicationTester();
        $appTester->run(array_merge(['command' => 'validate'], $command));

        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function testValidateOnFileIssues(): void 
    {
        $directory = $this->initTempComposer(self::MINIMAL_VALID_CONFIGURATION);
        unlink($directory.'/composer.json');

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'validate']);
        $expected = './composer.json not found.';

        $this->assertSame($expected, trim($appTester->getDisplay(true)));
    }

    public function testWithComposerLock(): void 
    {
        $this->initTempComposer(self::MINIMAL_VALID_CONFIGURATION);
        $this->createComposerLock();

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'validate']);
        $expected = <<<OUTPUT
        ./composer.json is valid but your composer.lock has some errors
# Lock file errors
- Required package "root/req" is not present in the lock file.
This usually happens when composer files are incorrectly merged or the composer.json file is manually edited.
Read more about correctly resolving merge conflicts https://getcomposer.org/doc/articles/resolving-merge-conflicts.md
and prefer using the "require" command over editing the composer.json file directly https://getcomposer.org/doc/03-cli.md#require
OUTPUT;

        $this->assertSame(trim($expected), trim($appTester->getDisplay(true)));
    }

    public function testUnaccessibleFile(): void 
    {
        if (Platform::isWindows()) {
            $this->markTestSkipped('Does not run on windows');
        }
        
        $directory = $this->initTempComposer(self::MINIMAL_VALID_CONFIGURATION);
        chmod($directory.'/composer.json', 0200);

        $appTester = $this->getApplicationTester();
        $appTester->run(['command' => 'validate']);
        $expected = './composer.json is not readable.';

        $this->assertSame($expected, trim($appTester->getDisplay(true)));
        $this->assertSame(3, $appTester->getStatusCode());
        chmod($directory.'/composer.json', 0700);
    }

    private const MINIMAL_VALID_CONFIGURATION = [
        'name' => 'test/suite',
        'type' => 'library',
        'description' => 'A generical test suite',
        'license' => 'MIT',
        'repositories' => [
            'packages' => [
                'type' => 'package',
                'package' => [
                    ['name' => 'root/req', 'version' => '1.0.0', 'require' => ['dep/pkg' => '^1']],
                    ['name' => 'dep/pkg', 'version' => '1.0.0'],
                    ['name' => 'dep/pkg', 'version' => '1.0.1'],
                    ['name' => 'dep/pkg', 'version' => '1.0.2'],
                ],
            ],
        ],
        'require' => [
            'root/req' => '1.*',
        ],
    ];

    public function provideValidateTests(): \Generator
    {
    
        yield 'validation passing' => [
            self::MINIMAL_VALID_CONFIGURATION,
            [],
            './composer.json is valid',
        ];

        $publishDataStripped= array_diff_key(
            self::MINIMAL_VALID_CONFIGURATION,
            ['name' => true, 'type' => true, 'description' => true, 'license' => true]
        );

        yield 'passing but with warnings' => [
            $publishDataStripped,
            [],
            <<<OUTPUT
./composer.json is valid for simple usage with Composer but has
strict errors that make it unable to be published as a package
<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>
# Publish errors
- name : The property name is required
- description : The property description is required
<warning># General warnings</warning>
- No license specified, it is recommended to do so. For closed-source software you may use "proprietary" as license.
OUTPUT
        ];

        yield 'passing without publish-check' => [
            $publishDataStripped,
            [ '--no-check-publish' => true],
            <<<OUTPUT
./composer.json is valid for simple usage with Composer but has
strict errors that make it unable to be published as a package
<warning>See https://getcomposer.org/doc/04-schema.md for details on the schema</warning>
<warning># General warnings</warning>
- No license specified, it is recommended to do so. For closed-source software you may use "proprietary" as license.
<warning># Publish warnings</warning>
- name : The property name is required
- description : The property description is required
OUTPUT
        ];
    }
}
