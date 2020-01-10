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

namespace Composer\Util;

use Composer\Config;
use Composer\IO\IOInterface;

/**
 * @author Jonas Renaudot <jonas.renaudot@gmail.com>
 */
class Hg
{
    /**
     * @var \Composer\IO\IOInterface
     */
    private $io;

    /**
     * @var \Composer\Config
     */
    private $config;

    /**
     * @var \Composer\Util\ProcessExecutor
     */
    private $process;

    public function __construct(IOInterface $io, Config $config, ProcessExecutor $process)
    {
        $this->io = $io;
        $this->config = $config;
        $this->process = $process;
    }

    public function runCommand($commandCallable, $url, $cwd)
    {
        $this->config->prohibitUrlByConfig($url, $this->io);

        // Try as is
        $command = call_user_func($commandCallable, $url);

        if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
            return;
        }

        // Try with the authentication information available
        if (preg_match('{^(https?)://((.+)(?:\:(.+))?@)?([^/]+)(/.*)?}mi', $url, $match) && $this->io->hasAuthentication($match[5])) {
            $auth = $this->io->getAuthentication($match[5]);
            $authenticatedUrl = $match[1] . '://' . rawurlencode($auth['username']) . ':' . rawurlencode($auth['password']) . '@' . $match[5] . (!empty($match[6]) ? $match[6] : null);

            $command = call_user_func($commandCallable, $authenticatedUrl);

            if (0 === $this->process->execute($command, $ignoredOutput, $cwd)) {
                return;
            }

            $error = $this->process->getErrorOutput();
        } else {
            $error = 'The given URL (' . $url . ') does not match the required format (http(s)://(username:password@)example.com/path-to-repository)';
        }

        $this->throwException('Failed to clone ' . $url . ', ' . "\n\n" . $error, $url);
    }

    public static function sanitizeUrl($message)
    {
        return preg_replace_callback('{://(?P<user>[^@]+?):(?P<password>.+?)@}', function ($m) {
            if (preg_match('{^[a-f0-9]{12,}$}', $m[1])) {
                return '://***:***@';
            }

            return '://' . $m[1] . ':***@';
        }, $message);
    }

    private function throwException($message, $url)
    {
        if (0 !== $this->process->execute('hg --version', $ignoredOutput)) {
            throw new \RuntimeException(self::sanitizeUrl('Failed to clone ' . $url . ', hg was not found, check that it is installed and in your PATH env.' . "\n\n" . $this->process->getErrorOutput()));
        }

        throw new \RuntimeException(self::sanitizeUrl($message));
    }
}
