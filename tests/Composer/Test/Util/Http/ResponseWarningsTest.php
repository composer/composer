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

namespace Composer\Test\Util\Http;

use Composer\IO\BufferIO;
use Composer\Test\TestCase;
use Composer\Util\Http\ResponseWarnings;

class ResponseWarningsTest extends TestCase
{
    public function testRepeatedWarningsFromAHostAreShownOnce(): void
    {
        $io = new BufferIO();
        $rateLimited = ['warning' => 'Rate limited', 'status' => 'error', 'path' => '/a'];
        self::assertTrue(ResponseWarnings::output($io, 'example.org', $rateLimited));
        // the parts of the body which are not shown do not make a warning a different one
        self::assertTrue(ResponseWarnings::output($io, 'example.org', ['path' => '/b'] + $rateLimited));
        // repository urls on the same host share the origin's entry
        self::assertTrue(ResponseWarnings::output($io, 'https://example.org/org-a', $rateLimited));
        self::assertTrue(ResponseWarnings::output($io, 'other.org', $rateLimited));
        self::assertTrue(ResponseWarnings::output($io, 'https://other.org:8443', $rateLimited));
        self::assertTrue(ResponseWarnings::output($io, 'packagist.org', $rateLimited));
        self::assertTrue(ResponseWarnings::output($io, 'https://repo.packagist.org', $rateLimited));
        self::assertTrue(ResponseWarnings::output($io, 'example.org', ['warning' => 'Slow down']));
        self::assertFalse(ResponseWarnings::output($io, 'example.org', ['status' => 'error']));
        self::assertFalse(ResponseWarnings::output($io, 'example.org', null));

        self::assertSame(
            '<warning>Warning from example.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from other.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from https://other.org:8443: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from packagist.org: Rate limited</warning>'.PHP_EOL
            .'<warning>Warning from example.org: Slow down</warning>'.PHP_EOL,
            $io->getOutput()
        );

        // another IO does not show them again, until the state is reset
        $otherIo = new BufferIO();
        self::assertTrue(ResponseWarnings::output($otherIo, 'example.org', $rateLimited));
        self::assertSame('', $otherIo->getOutput());
        ResponseWarnings::reset();
        self::assertTrue(ResponseWarnings::output($otherIo, 'example.org', $rateLimited));
        self::assertSame('<warning>Warning from example.org: Rate limited</warning>'.PHP_EOL, $otherIo->getOutput());
    }

    public function testOutput(): void
    {
        $io = new BufferIO();
        self::assertFalse(ResponseWarnings::output($io, '$URL', []));
        self::assertSame('', $io->getOutput());

        // warning/info keys present but filtered out by version constraints => nothing written
        self::assertFalse(ResponseWarnings::output($io, '$URL', [
            'warning' => 'old warning msg',
            'warning-versions' => '<2.0',
            'warnings' => [
                ['message' => 'should not appear', 'versions' => '<2.2'],
            ],
        ]));
        self::assertSame('', $io->getOutput());

        self::assertTrue(ResponseWarnings::output($io, '$URL', [
            'warning' => 'old warning msg',
            'warning-versions' => '>=2.0',
            'info' => 'old info msg',
            'info-versions' => '>=2.0',
            'warnings' => [
                ['message' => 'should not appear', 'versions' => '<2.2'],
                ['message' => 'visible warning', 'versions' => '>=2.2-dev'],
            ],
            'infos' => [
                ['message' => 'should not appear', 'versions' => '<2.2'],
                ['message' => 'visible info', 'versions' => '>=2.2-dev'],
            ],
        ]));

        // the <info> tag are consumed by the OutputFormatter, but not <warning> as that is not a default output format
        self::assertSame(
            '<warning>Warning from $URL: old warning msg</warning>'.PHP_EOL.
            'Info from $URL: old info msg'.PHP_EOL.
            '<warning>Warning from $URL: visible warning</warning>'.PHP_EOL.
            'Info from $URL: visible info'.PHP_EOL,
            $io->getOutput()
        );
    }
}
