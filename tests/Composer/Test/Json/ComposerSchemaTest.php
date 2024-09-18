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
                'constraint' => 'pattern',
                'pattern' => '^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$',
            ],
        ];

        $json = '{"name": "vendor/-pack__age", "description": "description"}';
        self::assertEquals($expectedError, $this->check($json));
        $json = '{"name": "Vendor/Package", "description": "description"}';
        self::assertEquals($expectedError, $this->check($json));
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
            ['property' => 'require.a', 'message' => 'Array value found, but a string is required', 'constraint' => 'type'],
        ], $this->check($json));
    }

    public function testMinimumStabilityValues(): void
    {
        $expectedError = [
            [
                'property' => 'minimum-stability',
                'message' => 'Does not have a value in the enumeration ["dev","alpha","beta","rc","RC","stable"]',
                'constraint' => 'enum',
                'enum' => ['dev', 'alpha', 'beta', 'rc', 'RC', 'stable'],
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
        $validator->check(json_decode($json), (object) ['$ref' => 'file://' . JsonFile::COMPOSER_SCHEMA_PATH]);

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
