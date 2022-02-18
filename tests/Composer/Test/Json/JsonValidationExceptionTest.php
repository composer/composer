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

use Composer\Json\JsonValidationException;
use Composer\Test\TestCase;

class JsonValidationExceptionTest extends TestCase
{
    /**
     * @dataProvider errorProvider
     * @param string|null $message
     * @param string[]|null $errors
     */
    public function testGetErrors($message, $errors, $expectedMessage, $expectedErrors): void
    {
        $object = new JsonValidationException($message, $errors);
        $this->assertSame($expectedMessage, $object->getMessage());
        $this->assertSame($expectedErrors, $object->getErrors());
    }

    public function testGetErrorsWhenNoErrorsProvided(): void
    {
        $object = new JsonValidationException('test message');
        $this->assertEquals(array(), $object->getErrors());
    }

    public function errorProvider()
    {
        return array(
            array('test message', array(), 'test message', []),
            array(null, ['foo'], '', ['foo']),
        );
    }
}
