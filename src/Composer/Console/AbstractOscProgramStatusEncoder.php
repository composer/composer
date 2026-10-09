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

use Composer\IO\ConsoleIO;
use Composer\Pcre\Preg;
use Symfony\Component\Console\Formatter\OutputFormatter;

abstract class AbstractOscProgramStatusEncoder
{
    abstract public function getCommand(): string;

    abstract public function encode(ProgramStatus $status): string;

    protected function sanitizeMessage(string $message, ?int $maxBytes = null): string
    {
        $message = Preg::replace('{[\x00-\x1f\x7f-\x9f]}u', '', ConsoleIO::sanitize($message, false));
        $message = (new OutputFormatter(false))->format($message) ?? '';

        // Keep the UTF-8 message within the protocol's decoded byte limit.
        if ($maxBytes !== null && strlen($message) > $maxBytes) {
            $message = substr($message, 0, $maxBytes);
            $message = Preg::replace('{[\xc2-\xdf]$|[\xe0-\xef][\x80-\xbf]{0,1}$|[\xf0-\xf4][\x80-\xbf]{0,2}$}', '', $message);
        }

        return trim($message);
    }
}
