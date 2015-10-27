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

/**
 * @author Kocsis Máté <kocsismate@woohoolabs.com>
 */
class Symlink
{
    protected $filesystem;

    /**
     * Initializes the symlinking utility.
     *
     * @param Filesystem  $filesystem
     */
    public function __construct(Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem();
    }

    /**
     * Creates a symlink for a binary file at a given path.
     *
     * @param string $binPath The path of the binary file to be symlinked
     * @param string $link The path where the symlink should be created
     * @throws \ErrorException
     */
    public function symlinkBin($binPath, $link)
    {
        $cwd = getcwd();

        $relativeBin = $this->filesystem->findShortestPath($link, $binPath);
        chdir(dirname($link));
        $result = @symlink($relativeBin, $link);

        chdir($cwd);
        if ($result === false) {
            throw new \ErrorException();
        }
    }
}
