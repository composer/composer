<?php

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

use JsonSchema\Validator;

/**
 * @author Rob Bast <rob.bast@gmail.com>
 */
class ComposerSchemaTest extends \PHPUnit_Framework_TestCase
{
    public function testRequiredProperties()
    {
        $json = '{ }';
        $this->assertEquals(array(
            array('property' => 'name', 'message' => 'The property name is required', 'constraint' => 'required'),
            array('property' => 'description', 'message' => 'The property description is required', 'constraint' => 'required'),
        ), $this->check($json));

        $json = '{ "name": "vendor/package" }';
        $this->assertEquals(array(
            array('property' => 'description', 'message' => 'The property description is required', 'constraint' => 'required'),
        ), $this->check($json));

        $json = '{ "description": "generic description" }';
        $this->assertEquals(array(
            array('property' => 'name', 'message' => 'The property name is required', 'constraint' => 'required'),
        ), $this->check($json));
    }

    public function testOptionalAbandonedProperty()
    {
        $json = '{"name": "name", "description": "description", "abandoned": true}';
        $this->assertTrue($this->check($json));
    }

    public function testRequireTypes()
    {
        $json = '{"name": "name", "description": "description", "require": {"a": ["b"]} }';
        $this->assertEquals(array(
            array('property' => 'require.a', 'message' => 'Array value found, but a string is required', 'constraint' => 'type'),
        ), $this->check($json));
    }

    public function testMinimumStabilityValues()
    {
        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "" }';
        $this->assertEquals(array(
            array(
                'property' => 'minimum-stability',
                'message' => 'Does not match the regex pattern ^dev|alpha|beta|rc|RC|stable$',
                'constraint' => 'pattern',
                'pattern' => '^dev|alpha|beta|rc|RC|stable$',
            ),
        ), $this->check($json), 'empty string');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "dummy" }';
        $this->assertEquals(array(
            array(
                'property' => 'minimum-stability',
                'message' => 'Does not match the regex pattern ^dev|alpha|beta|rc|RC|stable$',
                'constraint' => 'pattern',
                'pattern' => '^dev|alpha|beta|rc|RC|stable$',
            ),
        ), $this->check($json), 'dummy');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "dev" }';
        $this->assertTrue($this->check($json), 'dev');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "alpha" }';
        $this->assertTrue($this->check($json), 'alpha');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "beta" }';
        $this->assertTrue($this->check($json), 'beta');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "rc" }';
        $this->assertTrue($this->check($json), 'rc lowercase');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "RC" }';
        $this->assertTrue($this->check($json), 'rc uppercase');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "stable" }';
        $this->assertTrue($this->check($json), 'stable');
    }

    private function check($json)
    {
        $validator = new Validator();
        $validator->check(json_decode($json), (object) array('$ref' => 'file://' . __DIR__ . '/../../../../res/composer-schema.json'));

        if (!$validator->isValid()) {
            $errors = $validator->getErrors();

            // remove justinrainbow/json-schema 3.0/5.2 props so it works with all versions
            foreach ($errors as &$err) {
                unset($err['pointer']);
                unset($err['context']);
            }

            return $errors;
        }

        return true;
    }
}
