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

namespace Composer\Test\Console;

use Composer\Console\OscHelper;
use Composer\Util\Platform;
use Composer\Test\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class OscHelperTest extends TestCase
{
    public function testWritesRawSequenceWithoutNewline(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        (new OscHelper())->write($output, '2', '<info>Title</info>');

        self::assertSame("\033]2;<info>Title</info>\033\\", $output->fetch());
    }

    public function testSuppressesUndecoratedAndQuietOutput(): void
    {
        foreach ([new BufferedOutput(), new BufferedOutput(OutputInterface::VERBOSITY_QUIET, true)] as $output) {
            (new OscHelper())->write($output, '7501', 'state=working');
            self::assertSame('', $output->fetch());
        }
    }

    public function testForcedAnsiDoesNotWriteToRedirectedStream(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $output = new StreamOutput($stream, OutputInterface::VERBOSITY_NORMAL, true);
        (new OscHelper())->write($output, '7501', 'state=working');
        rewind($stream);
        self::assertSame('', stream_get_contents($stream));
        fclose($stream);
    }

    /** @dataProvider provideMsysEnvironments */
    public function testMsysDoesNotEnableRedirectedOutput(string $environment): void
    {
        $original = Platform::getEnv('MSYSTEM');
        Platform::putEnv('MSYSTEM', $environment);
        try {
            $this->testForcedAnsiDoesNotWriteToRedirectedStream();
        } finally {
            if ($original === false) {
                Platform::clearEnv('MSYSTEM');
            } else {
                Platform::putEnv('MSYSTEM', $original);
            }
        }
    }

    public static function provideMsysEnvironments(): array
    {
        return [['MINGW32'], ['MINGW64']];
    }

    /**
     * @dataProvider invalidPayloadProvider
     */
    public function testRejectsControlCharacters(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OscHelper())->write(new BufferedOutput(), '2', $payload);
    }

    public static function invalidPayloadProvider(): array
    {
        return [["title\033\\"], ["title\007"], ["title\n"], ["title\xc2\x9c"]];
    }

    public function testWritesTextCommand(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        (new OscHelper())->write($output, 'future-command', 'status=working');

        self::assertSame("\033]future-command;status=working\033\\", $output->fetch());
    }

    /** @dataProvider invalidPayloadProvider */
    public function testRejectsControlCharactersInCommand(string $command): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OscHelper())->write(new BufferedOutput(), $command, 'title');
    }
}
