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

namespace Composer;

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\Filesystem;
use Composer\Util\Platform;
use Composer\Util\Silencer;
use Symfony\Component\Finder\Finder;

/**
 * Reads/writes to a filesystem cache
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Cache
{
    /** @var bool|null */
    private static $cacheCollected = null;
    /** @var IOInterface */
    private $io;
    /** @var string */
    private $root;
    /** @var ?bool */
    private $enabled = null;
    /** @var string */
    private $allowlist;
    /** @var Filesystem */
    private $filesystem;
    /** @var bool */
    private $readOnly;

    /**
     * @param string      $cacheDir   location of the cache
     * @param string      $allowlist  List of characters that are allowed in path names (used in a regex character class)
     * @param Filesystem  $filesystem optional filesystem instance
     * @param bool        $readOnly   whether the cache is in readOnly mode
     */
    public function __construct(IOInterface $io, string $cacheDir, string $allowlist = 'a-z0-9._', ?Filesystem $filesystem = null, bool $readOnly = false)
    {
        $this->io = $io;
        $this->root = rtrim($cacheDir, '/\\') . '/';
        $this->allowlist = $allowlist;
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->readOnly = $readOnly;

        if (!self::isUsable($cacheDir)) {
            $this->enabled = false;
        }
    }

    /**
     * @return void
     */
    public function setReadOnly(bool $readOnly)
    {
        $this->readOnly = $readOnly;
    }

    /**
     * @return bool
     */
    public function isReadOnly()
    {
        return $this->readOnly;
    }

    /**
     * @return bool
     */
    public static function isUsable(string $path)
    {
        return !Preg::isMatch('{(^|[\\\\/])(\$null|nul|NUL|/dev/null)([\\\\/]|$)}', $path);
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        if ($this->enabled === null) {
            $this->enabled = true;

            if (
                !$this->readOnly
                && (
                    (!is_dir($this->root) && !Silencer::call('mkdir', $this->root, 0777, true))
                    || !is_writable($this->root)
                )
            ) {
                $this->io->writeError('<warning>Cannot create cache directory ' . $this->root . ', or directory is not writable. Proceeding without cache. See also cache-read-only config if your filesystem is read-only.</warning>');
                $this->enabled = false;
            }
        }

        return $this->enabled;
    }

    /**
     * @return string
     */
    public function getRoot()
    {
        return $this->root;
    }

    /**
     * @return string|false
     */
    public function read(string $file)
    {
        if ($this->isEnabled()) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            if (file_exists($this->root . $file)) {
                $this->io->writeError('Reading '.$this->root . $file.' from cache', true, IOInterface::DEBUG);

                return file_get_contents($this->root . $file);
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function write(string $file, string $contents)
    {
        $wasEnabled = $this->enabled === true;

        if ($this->isEnabled() && !$this->readOnly) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);

            $this->io->writeError('Writing '.$this->root . $file.' into cache', true, IOInterface::DEBUG);

            $tempFileName = $this->root . $file . bin2hex(random_bytes(5)) . '.tmp';
            try {
                return file_put_contents($tempFileName, $contents) !== false && rename($tempFileName, $this->root . $file);
            } catch (\ErrorException $e) {
                // If the write failed despite isEnabled checks passing earlier, rerun the isEnabled checks to
                // see if they are still current and recreate the cache dir if needed. Refs https://github.com/composer/composer/issues/11076
                if ($wasEnabled) {
                    clearstatcache();
                    $this->enabled = null;
                    return $this->write($file, $contents);
                }

                $this->io->writeError('<warning>Failed to write into cache: '.$e->getMessage().'</warning>', true, IOInterface::DEBUG);
                if (Preg::isMatch('{^file_put_contents\(\): Only ([0-9]+) of ([0-9]+) bytes written}', $e->getMessage(), $m)) {
                    // Remove partial file.
                    unlink($tempFileName);

                    $message = sprintf(
                        '<warning>Writing %1$s into cache failed after %2$u of %3$u bytes written, only %4$s bytes of free space available</warning>',
                        $tempFileName,
                        $m[1],
                        $m[2],
                        function_exists('disk_free_space') ? @disk_free_space(dirname($tempFileName)) : 'unknown'
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
     *
     * @return bool
     */
    public function copyFrom(string $file, string $source)
    {
        if ($this->isEnabled() && !$this->readOnly) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            $this->filesystem->ensureDirectoryExists(dirname($this->root . $file));

            if (!file_exists($source)) {
                $this->io->writeError('<error>'.$source.' does not exist, can not write into cache</error>');
            } elseif ($this->io->isDebug()) {
                $this->io->writeError('Writing '.$this->root . $file.' into cache from '.$source);
            }

            return $this->filesystem->copy($source, $this->root . $file);
        }

        return false;
    }

    /**
     * Copy a file out of the cache
     *
     * @return bool
     */
    public function copyTo(string $file, string $target)
    {
        if ($this->isEnabled()) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            if (file_exists($this->root . $file)) {
                try {
                    touch($this->root . $file, (int) filemtime($this->root . $file), time());
                } catch (\ErrorException $e) {
                    // fallback in case the above failed due to incorrect ownership
                    // see https://github.com/composer/composer/issues/4070
                    Silencer::call('touch', $this->root . $file);
                }

                $this->io->writeError('Reading '.$this->root . $file.' from cache', true, IOInterface::DEBUG);

                return $this->filesystem->copy($this->root . $file, $target);
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function gcIsNecessary()
    {
        if (self::$cacheCollected) {
            return false;
        }

        self::$cacheCollected = true;
        if (Platform::getEnv('COMPOSER_TEST_SUITE')) {
            return false;
        }

        if (Platform::isInputCompletionProcess()) {
            return false;
        }

        return !random_int(0, 50);
    }

    /**
     * @return bool
     */
    public function remove(string $file)
    {
        if ($this->isEnabled() && !$this->readOnly) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            if (file_exists($this->root . $file)) {
                return $this->filesystem->unlink($this->root . $file);
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function clear()
    {
        if ($this->isEnabled() && !$this->readOnly) {
            $this->filesystem->emptyDirectory($this->root);

            return true;
        }

        return false;
    }

    /**
     * @return int|false
     * @phpstan-return int<0, max>|false
     */
    public function getAge(string $file)
    {
        if ($this->isEnabled()) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            if (file_exists($this->root . $file) && ($mtime = filemtime($this->root . $file)) !== false) {
                return abs(time() - $mtime);
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function gc(int $ttl, int $maxSize)
    {
        if ($this->isEnabled() && !$this->readOnly) {
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

    public function gcVcsCache(int $ttl): bool
    {
        if ($this->isEnabled()) {
            $expire = new \DateTime();
            $expire->modify('-'.$ttl.' seconds');

            $finder = Finder::create()->in($this->root)->directories()->depth(0)->date('until '.$expire->format('Y-m-d H:i:s'));
            foreach ($finder as $file) {
                $this->filesystem->removeDirectory($file->getPathname());
            }

            self::$cacheCollected = true;

            return true;
        }

        return false;
    }

    /**
     * @return string|false
     */
    public function sha1(string $file)
    {
        if ($this->isEnabled()) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            if (file_exists($this->root . $file)) {
                return hash_file('sha1', $this->root . $file);
            }
        }

        return false;
    }

    /**
     * @return string|false
     */
    public function sha256(string $file)
    {
        if ($this->isEnabled()) {
            $file = Preg::replace('{[^'.$this->allowlist.']}i', '-', $file);
            if (file_exists($this->root . $file)) {
                return hash_file('sha256', $this->root . $file);
            }
        }

        return false;
    }

    /**
     * @return Finder
     */
    protected function getFinder()
    {
        return Finder::create()->in($this->root)->files();
    }
}
