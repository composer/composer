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

namespace Composer\Test\Json;

use Composer\Json\JsonFile;
use JsonSchema\Validator;
use Composer\Test\TestCase;

/**
 * @author Rob Bast <rob.bast@gmail.com>
 */
class ComposerSchemaTest extends TestCase
{
    public function testNamePattern(): void
    {
        $expectedError = [
            [
                'property' => 'name',
                'message' => 'Does not match the regex pattern ^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$',
                'constraint' => [
                    'name' => 'pattern',
                    'params' => [
                        'pattern' => '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$',
                    ]
                ],
            ],
        ];

        $json = '{"name": "vendor/-pack__age", "description": "description"}';
        self::assertEquals($expectedError, $this->check($json));
        $json = '{"name": "Vendor/Package", "description": "description"}';
        self::assertEquals($expectedError, $this->check($json));
    }

    public function versionProvider(): array
    {
        return [
            ['1.0.0', true],
            ['1.0.2', true],
            ['1.1.0', true],
            ['1.0.0-dev', true],
            ['1.0.0-alpha3', true],
            ['1.0.0-beta232', true],
            ['10.4.13beta.2', true],
            ['1.0.0.RC.15-dev', true],
            ['1.0.0-RC', true],
            ['v2.0.4-p', true],
            ['dev-master', true],
            ['0.2.5.4', true],
            ['12345678-123456', true],
            ['20100102-203040-p1', true],
            ['2010-01-02.5', true],
            ['0.2.5.4-rc.2', true],
            ['dev-feature+issue-1', true],
            ['1.0.0-alpha.3.1+foo/-bar', true],
            ['00.01.03.04', true],
            ['041.x-dev', true],
            ['dev-foo bar', true],

            ['invalid', false],
            ['1.0be', false],
            ['1.0.0-meh', false],
            ['feature-foo', false],
            ['1.0 .2', false],
        ];
    }

    /**
     * @dataProvider versionProvider
     */
    public function testVersionPattern(string $version, bool $isValid): void
    {
        $json = '{"name": "vendor/package", "description": "description", "version": "' . $version . '"}';
        if ($isValid) {
            self::assertTrue($this->check($json));
        } else {
            self::assertEquals([
                [
                    'property' => 'version',
                    'message' => 'Does not match the regex pattern ^v?\\d+(?:[.-]\\d+){0,3}[._-]?(?:(?:stable|beta|b|RC|rc|alpha|a|patch|pl|p)(?:(?:[.-]?\\d+)*+)?)?(?:[.-]?dev|\\.x-dev)?(?:\\+.*)?$|^dev-.*$',
                    'constraint' => [
                        'name' => 'pattern',
                        'params' => [
                            'pattern' => '^v?\\d+(?:[.-]\\d+){0,3}[._-]?(?:(?:stable|beta|b|RC|rc|alpha|a|patch|pl|p)(?:(?:[.-]?\\d+)*+)?)?(?:[.-]?dev|\\.x-dev)?(?:\\+.*)?$|^dev-.*$',
                        ]
                    ],
                ],
            ], $this->check($json));
        }
    }

    public function testOptionalAbandonedProperty(): void
    {
        $json = '{"name": "vendor/package", "description": "description", "abandoned": true}';
        self::assertTrue($this->check($json));
    }

    public function testRequireTypes(): void
    {
        $json = '{"name": "vendor/package", "description": "description", "require": {"a": ["b"]} }';
        self::assertEquals([
            [
                'property' => 'require.a',
                'message' => 'Array value found, but a string is required',
                'constraint' => ['name' => 'type', 'params' => ['found' => 'array', 'expected' => 'a string']],
            ],
        ], $this->check($json));
    }

    public function testMinimumStabilityValues(): void
    {
        $expectedError = [
            [
                'property' => 'minimum-stability',
                'message' => 'Does not have a value in the enumeration ["dev","alpha","beta","rc","RC","stable"]',
                'constraint' => [
                    'name' => 'enum',
                    'params' => [
                        'enum' => ['dev', 'alpha', 'beta', 'rc', 'RC', 'stable'],
                    ],
                ],
            ],
        ];

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "" }';
        self::assertEquals($expectedError, $this->check($json), 'empty string');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "dummy" }';
        self::assertEquals($expectedError, $this->check($json), 'dummy');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "devz" }';
        self::assertEquals($expectedError, $this->check($json), 'devz');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "dev" }';
        self::assertTrue($this->check($json), 'dev');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "alpha" }';
        self::assertTrue($this->check($json), 'alpha');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "beta" }';
        self::assertTrue($this->check($json), 'beta');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "rc" }';
        self::assertTrue($this->check($json), 'rc lowercase');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "RC" }';
        self::assertTrue($this->check($json), 'rc uppercase');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "stable" }';
        self::assertTrue($this->check($json), 'stable');
    }

    /**
     * @return mixed
     */
    private function check(string $json)
    {
        $validator = new Validator();
        $json = json_decode($json);
        $validator->validate($json, (object) ['$ref' => 'file://' . JsonFile::COMPOSER_SCHEMA_PATH]);

        if (!$validator->isValid()) {
            $errors = $validator->getErrors();

            // remove justinrainbow/json-schema 3.0/5.2 props so it works with all versions
            foreach ($errors as &$err) {
                unset($err['pointer'], $err['context']);
            }

            return $errors;
        }

        return true;
    }
}
