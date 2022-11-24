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

use Composer\Json\JsonValidationException;
use Composer\Test\TestCase;

class JsonValidationExceptionTest extends TestCase
{
    /**
     * @dataProvider errorProvider
     * @param string[] $errors
     * @param string[] $expectedErrors
     */
    public function testGetErrors(string $message, array $errors, string $expectedMessage, array $expectedErrors): void
    {
        $object = new JsonValidationException($message, $errors);
        $this->assertSame($expectedMessage, $object->getMessage());
        $this->assertSame($expectedErrors, $object->getErrors());
    }

    public function testGetErrorsWhenNoErrorsProvided(): void
    {
        $object = new JsonValidationException('test message');
        $this->assertEquals([], $object->getErrors());
    }

    public static function errorProvider(): array
    {
        return [
            ['test message', [], 'test message', []],
            ['', ['foo'], '', ['foo']],
        ];
    }
}
