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

namespace Composer\Test\IO;

use Composer\IO\BufferIO;
use Composer\Test\TestCase;
use Symfony\Component\Console\Input\StreamableInputInterface;

class BufferIOTest extends TestCase
{
    public function testSetUserInputs(): void
    {
        $bufferIO = new BufferIO();

        $refl = new \ReflectionProperty($bufferIO, 'input');
        $refl->setAccessible(true);
        $input = $refl->getValue($bufferIO);

        if (!$input instanceof StreamableInputInterface) {
            self::expectException('\RuntimeException');
            self::expectExceptionMessage('Setting the user inputs requires at least the version 3.2 of the symfony/console component.');
        }

        $bufferIO->setUserInputs([
            'yes',
            'no',
            '',
        ]);

        $this->assertTrue($bufferIO->askConfirmation('Please say yes!', false));
        $this->assertFalse($bufferIO->askConfirmation('Now please say no!', true));
        $this->assertSame('default', $bufferIO->ask('Empty string last', 'default'));
    }
}
