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

namespace Composer\Console;

use Composer\IO\IOInterface;
use Composer\Util\Platform;

final class GithubActionError
{
    /**
     * @var IOInterface
     */
    protected $io;

    public function __construct(IOInterface  $io)
    {
        $this->io = $io;
    }

    public function emit(string $message, ?string $file = null, ?int $line = null): void
    {
        if (Platform::getEnv('GITHUB_ACTIONS') && !Platform::getEnv('COMPOSER_TESTS_ARE_RUNNING')) {
            $message = $this->escapeData($message);

            if ($file && $line) {
                $file = $this->escapeProperty($file);
                $this->io->write("::error file=". $file .",line=". $line ."::". $message);
            } elseif ($file) {
                $file = $this->escapeProperty($file);
                $this->io->write("::error file=". $file ."::". $message);
            } else {
                $this->io->write("::error ::". $message);
            }
        }
    }

    private function escapeData(string $data): string
    {
        // see https://github.com/actions/toolkit/blob/4f7fb6513a355689f69f0849edeb369a4dc81729/packages/core/src/command.ts#L80-L85
        $data = str_replace("%", '%25', $data);
        $data = str_replace("\r", '%0D', $data);
        $data = str_replace("\n", '%0A', $data);

        return $data;
    }

    private function escapeProperty(string $property): string
    {
        // see https://github.com/actions/toolkit/blob/4f7fb6513a355689f69f0849edeb369a4dc81729/packages/core/src/command.ts#L87-L94
        $property = str_replace("%", '%25', $property);
        $property = str_replace("\r", '%0D', $property);
        $property = str_replace("\n", '%0A', $property);
        $property = str_replace(":", '%3A', $property);
        $property = str_replace(",", '%2C', $property);

        return $property;
    }
}
