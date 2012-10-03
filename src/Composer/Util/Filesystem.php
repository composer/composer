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

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Filesystem
{
    private $processExecutor;

    public function __construct(ProcessExecutor $executor = null)
    {
        $this->processExecutor = $executor ?: new ProcessExecutor();
    }

    public function remove($file)
    {
        if (is_dir($file)) {
            return $this->removeDirectory($file);
        }

        if (file_exists($file)) {
            return unlink($file);
        }

        return false;
    }

    public function removeDirectory($directory)
    {
        if (!is_dir($directory)) {
            return true;
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            $cmd = sprintf('rmdir /S /Q %s', escapeshellarg(realpath($directory)));
        } else {
            $cmd = sprintf('rm -rf %s', escapeshellarg($directory));
        }

        $result = $this->getProcess()->execute($cmd) === 0;

        // clear stat cache because external processes aren't tracked by the php stat cache
        clearstatcache();

        return $result && !is_dir($directory);
    }

    public function ensureDirectoryExists($directory)
    {
        if (!is_dir($directory)) {
            if (file_exists($directory)) {
                throw new \RuntimeException(
                    $directory.' exists and is not a directory.'
                );
            }
            if (!mkdir($directory, 0777, true)) {
                throw new \RuntimeException(
                    $directory.' does not exist and could not be created.'
                );
            }
        }
    }

    public function rename($source, $target)
    {
        if (true === @rename($source, $target)) {
            return;
        }

        if (defined('PHP_WINDOWS_VERSION_BUILD')) {
            // Try to copy & delete - this is a workaround for random "Access denied" errors.
            $command = sprintf('xcopy %s %s /E /I /Q', escapeshellarg($source), escapeshellarg($target));
            if (0 === $this->processExecutor->execute($command)) {
                $this->remove($source);

                return;
            }
        } else {
            // We do not use PHP's "rename" function here since it does not support
            // the case where $source, and $target are located on different partitions.
            $command = sprintf('mv %s %s', escapeshellarg($source), escapeshellarg($target));
            if (0 === $this->processExecutor->execute($command)) {
                return;
            }
        }

        throw new \RuntimeException(sprintf('Could not rename "%s" to "%s".', $source, $target));
    }

    /**
     * Returns the shortest path from $from to $to
     *
     * @param  string $from
     * @param  string $to
     * @param  bool   $directories if true, the source/target are considered to be directories
     * @return string
     */
    public function findShortestPath($from, $to, $directories = false)
    {
        if (!$this->isAbsolutePath($from) || !$this->isAbsolutePath($to)) {
            throw new \InvalidArgumentException(sprintf('$from (%s) and $to (%s) must be absolute paths.', $from, $to));
        }

        $from = lcfirst(rtrim(strtr($from, '\\', '/'), '/'));
        $to = lcfirst(rtrim(strtr($to, '\\', '/'), '/'));

        if ($directories) {
            $from .= '/dummy_file';
        }

        if (dirname($from) === dirname($to)) {
            return './'.basename($to);
        }

        $commonPath = $to;
        while (strpos($from, $commonPath) !== 0 && '/' !== $commonPath && !preg_match('{^[a-z]:/?$}i', $commonPath) && '.' !== $commonPath) {
            $commonPath = strtr(dirname($commonPath), '\\', '/');
        }

        if (0 !== strpos($from, $commonPath) || '/' === $commonPath || '.' === $commonPath) {
            return $to;
        }

        $commonPath = rtrim($commonPath, '/') . '/';
        $sourcePathDepth = substr_count(substr($from, strlen($commonPath)), '/');
        $commonPathCode = str_repeat('../', $sourcePathDepth);

        return ($commonPathCode . substr($to, strlen($commonPath))) ?: './';
    }

    /**
     * Returns PHP code that, when executed in $from, will return the path to $to
     *
     * @param  string $from
     * @param  string $to
     * @param  bool   $directories if true, the source/target are considered to be directories
     * @return string
     */
    public function findShortestPathCode($from, $to, $directories = false)
    {
        if (!$this->isAbsolutePath($from) || !$this->isAbsolutePath($to)) {
            throw new \InvalidArgumentException(sprintf('$from (%s) and $to (%s) must be absolute paths.', $from, $to));
        }

        $from = lcfirst(strtr($from, '\\', '/'));
        $to = lcfirst(strtr($to, '\\', '/'));

        if ($from === $to) {
            return $directories ? '__DIR__' : '__FILE__';
        }

        $commonPath = $to;
        while (strpos($from, $commonPath) !== 0 && '/' !== $commonPath && !preg_match('{^[a-z]:/?$}i', $commonPath) && '.' !== $commonPath) {
            $commonPath = strtr(dirname($commonPath), '\\', '/');
        }

        if (0 !== strpos($from, $commonPath) || '/' === $commonPath || '.' === $commonPath) {
            return var_export($to, true);
        }

        $commonPath = rtrim($commonPath, '/') . '/';
        if (strpos($to, $from.'/') === 0) {
            return '__DIR__ . '.var_export(substr($to, strlen($from)), true);
        }
        $sourcePathDepth = substr_count(substr($from, strlen($commonPath)), '/') + $directories;
        $commonPathCode = str_repeat('dirname(', $sourcePathDepth).'__DIR__'.str_repeat(')', $sourcePathDepth);
        $relTarget = substr($to, strlen($commonPath));

        return $commonPathCode . (strlen($relTarget) ? '.' . var_export('/' . $relTarget, true) : '');
    }

    /**
     * Checks if the given path is absolute
     *
     * @param  string $path
     * @return bool
     */
    public function isAbsolutePath($path)
    {
        return substr($path, 0, 1) === '/' || substr($path, 1, 1) === ':';
    }

    protected function getProcess()
    {
        return new ProcessExecutor;
    }
}
