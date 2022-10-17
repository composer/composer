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

class ValidateCommandTest extends TestCase
{
    /**
     * @dataProvider provideUpdates
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

    public function provideUpdates(): \Generator
    {
        $simpleComposerConfiguration = [
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

    
        yield 'validation passing' => [
            $simpleComposerConfiguration,
            [],
            <<<OUTPUT
            ./composer.json is valid
OUTPUT
        ];

        $publishDataStripped= array_diff_key( $simpleComposerConfiguration, array( 'name' => true,'type' => true,'description' => true ,'license' => true));

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