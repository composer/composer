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

use Composer\IO\IOInterface;

/**
 * @author Oleg Andreyev <oleg@andreyev.lv>
 */
class BitbucketServer
{
    /** @var IOInterface */
    private $io;
    /** @var ProcessExecutor */
    private $process;

    /**
     * Constructor.
     *
     * @param IOInterface     $io      The IO instance
     * @param ProcessExecutor $process Process instance, injectable for mocking
     */
    public function __construct(IOInterface $io, ProcessExecutor $process = null)
    {
        $this->io = $io;
        $this->process = $process ?: new ProcessExecutor($io);
    }

    /**
     * Attempts to authorize a Bitbucket domain via OAuth
     *
     * @param  string $originUrl The host this Bitbucket instance is located at
     *
     * @return bool   true on success
     */
    public function authorizeOAuth($originUrl)
    {
        // if available use token from git config
        if (0 === $this->process->execute('git config bitbucket-server.accesstoken', $output)) {
            $this->io->setAuthentication($originUrl, trim($output), 'bearer');

            return true;
        }

        return false;
    }
}
