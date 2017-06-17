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
use Composer\Util\Filesystem;
use Composer\Util\Silencer;
use Symfony\Component\Finder\Finder;

/**
 * Reads/writes to a filesystem cache
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Cache
{
    private static $cacheCollected = false;
    private $io;
    private $root;
    private $enabled = true;
    private $whitelist;
    private $filesystem;

    /**
     * @param IOInterface $io
     * @param string      $cacheDir   location of the cache
     * @param string      $whitelist  List of characters that are allowed in path names (used in a regex character class)
     * @param Filesystem  $filesystem optional filesystem instance
     */
    public function __construct(IOInterface $io, $cacheDir, $whitelist = 'a-z0-9.', Filesystem $filesystem = null)
    {
        $this->io = $io;
        $this->root = rtrim($cacheDir, '/\\') . '/';
        $this->whitelist = $whitelist;
        $this->filesystem = $filesystem ?: new Filesystem();

        if (preg_match('{(^|[\\\\/])(\$null|NUL|/dev/null)([\\\\/]|$)}', $cacheDir)) {
            $this->enabled = false;

            return;
        }

        if (
            (!is_dir($this->root) && !Silencer::call('mkdir', $this->root, 0777, true))
            || !is_writable($this->root)
        ) {
            $this->io->writeError('<warning>Cannot create cache directory ' . $this->root . ', or directory is not writable. Proceeding without cache</warning>');
            $this->enabled = false;
        }
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function getRoot()
    {
        return $this->root;
    }

    public function read($file)
    {
        $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);
        if ($this->enabled && file_exists($this->root . $file)) {
            $this->io->writeError('Reading '.$this->root . $file.' from cache', true, IOInterface::DEBUG);

            return file_get_contents($this->root . $file);
        }

        return false;
    }

    public function write($file, $contents)
    {
        if ($this->enabled) {
            $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);

            $this->io->writeError('Writing '.$this->root . $file.' into cache', true, IOInterface::DEBUG);

            try {
                return file_put_contents($this->root . $file, $contents);
            } catch (\ErrorException $e) {
                $this->io->writeError('<warning>Failed to write into cache: '.$e->getMessage().'</warning>', true, IOInterface::DEBUG);
                if (preg_match('{^file_put_contents\(\): Only ([0-9]+) of ([0-9]+) bytes written}', $e->getMessage(), $m)) {
                    // Remove partial file.
                    unlink($this->root . $file);

                    $message = sprintf(
                        '<warning>Writing %1$s into cache failed after %2$u of %3$u bytes written, only %4$u bytes of free space available</warning>',
                        $this->root . $file,
                        $m[1],
                        $m[2],
                        @disk_free_space($this->root . dirname($file))
                    );

                    $this->io->writeError($message);

                    return false;
                }

                throw $e;
            }
        }

        return false;
    }

    /**
     * Copy a file into the cache
     */
    public function copyFrom($file, $source)
    {
        if ($this->enabled) {
            $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);
            $this->filesystem->ensureDirectoryExists(dirname($this->root . $file));

            if (!file_exists($source)) {
                $this->io->writeError('<error>'.$source.' does not exist, can not write into cache</error>');
            } elseif ($this->io->isDebug()) {
                $this->io->writeError('Writing '.$this->root . $file.' into cache from '.$source);
            }

            return copy($source, $this->root . $file);
        }

        return false;
    }

    /**
     * Copy a file out of the cache
     */
    public function copyTo($file, $target)
    {
        $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);
        if ($this->enabled && file_exists($this->root . $file)) {
            try {
                touch($this->root . $file, filemtime($this->root . $file), time());
            } catch (\ErrorException $e) {
                // fallback in case the above failed due to incorrect ownership
                // see https://github.com/composer/composer/issues/4070
                Silencer::call('touch', $this->root . $file);
            }

            $this->io->writeError('Reading '.$this->root . $file.' from cache', true, IOInterface::DEBUG);

            return copy($this->root . $file, $target);
        }

        return false;
    }

    public function gcIsNecessary()
    {
        return (!self::$cacheCollected && !mt_rand(0, 50));
    }

    public function remove($file)
    {
        $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);
        if ($this->enabled && file_exists($this->root . $file)) {
            return $this->filesystem->unlink($this->root . $file);
        }

        return false;
    }

    public function clear()
    {
        if ($this->enabled) {
            return $this->filesystem->removeDirectory($this->root);
        }

        return false;
    }

    public function gc($ttl, $maxSize)
    {
        if ($this->enabled) {
            $expire = new \DateTime();
            $expire->modify('-'.$ttl.' seconds');

            $finder = $this->getFinder()->date('until '.$expire->format('Y-m-d H:i:s'));
            foreach ($finder as $file) {
                $this->filesystem->unlink($file->getPathname());
            }

            $totalSize = $this->filesystem->size($this->root);
            if ($totalSize > $maxSize) {
                $iterator = $this->getFinder()->sortByAccessedTime()->getIterator();
                while ($totalSize > $maxSize && $iterator->valid()) {
                    $filepath = $iterator->current()->getPathname();
                    $totalSize -= $this->filesystem->size($filepath);
                    $this->filesystem->unlink($filepath);
                    $iterator->next();
                }
            }

            self::$cacheCollected = true;

            return true;
        }

        return false;
    }

    public function sha1($file)
    {
        $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);
        if ($this->enabled && file_exists($this->root . $file)) {
            return sha1_file($this->root . $file);
        }

        return false;
    }

    public function sha256($file)
    {
        $file = preg_replace('{[^'.$this->whitelist.']}i', '-', $file);
        if ($this->enabled && file_exists($this->root . $file)) {
            return hash_file('sha256', $this->root . $file);
        }

        return false;
    }

    protected function getFinder()
    {
        return Finder::create()->in($this->root)->files();
    }
}
