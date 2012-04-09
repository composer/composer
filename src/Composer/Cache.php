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

namespace Composer;

use Composer\IO\IOInterface;

/**
 * Reads/writes to a filesystem cache
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Cache
{
    private $io;
    private $root;
    private $enabled = true;

    public function __construct(IOInterface $io, $cacheDir)
    {
        $this->io = $io;
        $this->root = rtrim($cacheDir, '/\\') . '/';

        if (!is_dir($this->root)) {
            if (!@mkdir($this->root, 0777, true)) {
                $this->enabled = false;
            }
        }
    }

    public function getRoot()
    {
        return $this->root;
    }

    public function read($file)
    {
        if ($this->enabled && file_exists($this->root . $file)) {
            return file_get_contents($this->root . $file);
        }
    }

    public function write($file, $contents)
    {
        if ($this->enabled) {
            file_put_contents($this->root . $file, $contents);
        }
    }

    public function sha1($file)
    {
        if ($this->enabled && file_exists($this->root . $file)) {
            return sha1_file($this->root . $file);
        }
    }
}
