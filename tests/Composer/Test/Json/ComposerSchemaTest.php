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
            array('property' => '', 'message' => 'the property name is required'),
            array('property' => '', 'message' => 'the property description is required'),
        ), $this->check($json));

        $json = '{ "name": "vendor/package" }';
        $this->assertEquals(array(
            array('property' => '', 'message' => 'the property description is required'),
        ), $this->check($json));

        $json = '{ "description": "generic description" }';
        $this->assertEquals(array(
            array('property' => '', 'message' => 'the property name is required'),
        ), $this->check($json));
    }

    public function testMinimumStabilityValues()
    {
        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "" }';
        $this->assertEquals(array(
            array(
                'property' => 'minimum-stability',
                'message' => 'does not match the regex pattern ^dev|alpha|beta|rc|RC|stable$'
            ),
        ), $this->check($json), 'empty string');

        $json = '{ "name": "vendor/package", "description": "generic description", "minimum-stability": "dummy" }';
        $this->assertEquals(array(
            array(
                'property' => 'minimum-stability',
                'message' => 'does not match the regex pattern ^dev|alpha|beta|rc|RC|stable$'
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
        $schema = json_decode(file_get_contents(__DIR__ . '/../../../../res/composer-schema.json'));
        $validator = new Validator();
        $validator->check(json_decode($json), $schema);

        if (!$validator->isValid()) {
            return $validator->getErrors();
        }

        return true;
    }
}
